<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Tests\Functional;

use Koh\ContentProvenance\Application\Service\ContentCredentialsReader;
use Koh\ContentProvenance\Infrastructure\Middleware\AssetProvenanceApiMiddleware;
use Koh\ContentProvenance\Infrastructure\Persistence\PublicFileReferenceChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AUD-025-F02: the anonymous asset endpoint answers only for files a live
 * page shows, rate-limits cache misses per address and caps concurrent
 * inspections.
 *
 * The inspection tools are replaced by a script that appends one line per
 * call to a log, so "how many c2patool processes ran" is a line count.
 * Before the fix the unreferenced file was inspected (one c2patool call, 200)
 * and there was neither a 429 nor a cap.
 */
final class AssetProvenanceApiMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/content-provenance'];

    private string $toolLog;

    private string $fakeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AssetEndpoint.csv');
        // The rate limiter's state lives in a cache that outlives one test.
        $this->get(CacheManager::class)->getCache('ratelimiter')->flush();
        $this->get(CacheManager::class)->getCache('content_provenance_asset')->flush();

        $this->getConnectionPool()->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => 1,
            'pid' => 0,
            'name' => 'fileadmin',
            'driver' => 'Local',
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                . '<field index="basePath"><value index="vDEF">fileadmin/</value></field>'
                . '<field index="pathType"><value index="vDEF">relative</value></field>'
                . '<field index="caseSensitive"><value index="vDEF">1</value></field>'
                . '</language></sheet></data></T3FlexForms>',
            'is_browsable' => 1,
            'is_public' => 1,
            'is_writable' => 1,
            'is_online' => 1,
        ]);

        $fileadmin = Environment::getPublicPath() . '/fileadmin';
        @mkdir($fileadmin, 0o777, true);
        foreach (['shown', 'unreferenced', 'hidden-element', 'hidden-page', 'members', 'shown-too', 'shown-three'] as $name) {
            file_put_contents($fileadmin . '/' . $name . '.txt', 'hello');
        }

        $this->toolLog = Environment::getVarPath() . '/fake-tool.log';
        @unlink($this->toolLog);
        $this->fakeTool = Environment::getVarPath() . '/fake-c2patool';
        file_put_contents($this->fakeTool, "#!/bin/sh\necho \"\$0 \$1\" >> " . escapeshellarg($this->toolLog) . "\nexit 1\n");
        chmod($this->fakeTool, 0o755);
    }

    #[Test]
    public function aFileShownOnALivePageIsInspectedAndAnswered(): void
    {
        $response = $this->call($this->middleware(), 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->toolCalls(), 'one c2patool run for the cache miss');

        self::assertSame(200, $this->call($this->middleware(), 1)->getStatusCode());
        self::assertSame(1, $this->toolCalls(), 'the second request is a cache hit');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function notPubliclyShownFiles(): array
    {
        return [
            'no reference at all' => [2],
            'referenced by a hidden element' => [3],
            'referenced on a hidden page' => [4],
            'referenced on a members-only page' => [5],
            'no such file' => [999],
        ];
    }

    #[Test]
    #[DataProvider('notPubliclyShownFiles')]
    public function aFileNoLivePageShowsAnswers404WithoutInspection(int $fileUid): void
    {
        $response = $this->call($this->middleware(), $fileUid);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->toolCalls(), 'no c2patool run');
    }

    #[Test]
    public function cacheMissesPastTheLimitAnswer429(): void
    {
        $middleware = $this->middleware(missesPerMinute: 2);

        self::assertSame(200, $this->call($middleware, 1)->getStatusCode());
        self::assertSame(200, $this->call($middleware, 6)->getStatusCode());
        $limited = $this->call($middleware, 7);

        self::assertSame(429, $limited->getStatusCode());
        self::assertNotSame('', $limited->getHeaderLine('Retry-After'));
        self::assertSame(2, $this->toolCalls(), 'the limited request ran no c2patool');

        self::assertSame(200, $this->call($middleware, 1)->getStatusCode(), 'cache hits are not limited');
        self::assertSame(200, $this->call($middleware, 1, '192.0.2.99')->getStatusCode());
        self::assertSame(200, $this->call($middleware, 7, '192.0.2.99')->getStatusCode(), 'the limit is per address');
    }

    #[Test]
    public function allInspectionSlotsTakenAnswers429WithoutSpawningAProcess(): void
    {
        $held = [];
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        for ($slot = 0; $slot < 2; ++$slot) {
            $locker = $this->get(LockFactory::class)->createLocker('content_provenance_inspect_' . $slot, $mode);
            self::assertTrue($locker->acquire($mode));
            $held[] = $locker;
        }

        $response = $this->call($this->middleware(maxConcurrentInspections: 2), 1);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame(0, $this->toolCalls());

        $held[0]->release();
        self::assertSame(200, $this->call($this->middleware(maxConcurrentInspections: 2), 1)->getStatusCode(), 'a freed slot is used');
        self::assertSame(1, $this->toolCalls());
        $held[1]->release();
    }

    private function middleware(int $missesPerMinute = 100, int $maxConcurrentInspections = 2): AssetProvenanceApiMiddleware
    {
        return new AssetProvenanceApiMiddleware(
            new ContentCredentialsReader($this->fakeTool, $this->fakeTool),
            $this->get(ResourceFactory::class),
            $this->get(CacheManager::class),
            new PublicFileReferenceChecker($this->getConnectionPool()),
            $this->get(RateLimiterFactory::class),
            $this->get(LockFactory::class),
            $missesPerMinute,
            $maxConcurrentInspections,
        );
    }

    private function call(AssetProvenanceApiMiddleware $middleware, int $fileUid, string $remoteAddress = '192.0.2.1'): ResponseInterface
    {
        $request = (new ServerRequest('https://example.org/_provenance/api/asset?file=' . $fileUid, 'GET', null, [], ['REMOTE_ADDR' => $remoteAddress]))
            ->withQueryParams(['file' => (string) $fileUid]);

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        });
    }

    /**
     * c2patool runs per inspection; exiftool follows only when c2patool found
     * nothing, which the fake always reports. Count c2patool-path lines with
     * the first argument, i.e. one line per tool call, halved.
     */
    private function toolCalls(): int
    {
        if (!is_file($this->toolLog)) {
            return 0;
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($this->toolLog)));

        return intdiv(\count($lines), 2);
    }
}
