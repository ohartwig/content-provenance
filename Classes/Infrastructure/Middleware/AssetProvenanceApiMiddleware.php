<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Middleware;

use Koh\ContentProvenance\Application\Service\ContentCredentialsReader;
use Koh\ContentProvenance\Infrastructure\Persistence\PublicFileReferenceChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Detection endpoint for media files: given a FAL file UID, reads the C2PA
 * manifest and IPTC provenance tags out of the file itself and reports what is
 * there.
 *
 * Companion to VerificationApiMiddleware, which verifies signed *records*
 * (table + uid). That one cannot answer anything about a media file, because a
 * media file's provenance lives in the file, not in our database.
 *
 * Why it inspects the file rather than reading sys_file_metadata: the stored
 * columns are our own conclusion from upload time. A detection endpoint that
 * returns our conclusion proves nothing — if the file was re-encoded and lost
 * its manifest since, the database would still cheerfully say "signed". So this
 * reads the artefact, which is the only thing that can contradict us.
 *
 * The Code of Practice on Transparency of AI-Generated Content expects a
 * provider to offer exactly this: a way for deployers, end users, fact-checkers
 * and authorities to check whether content carries its markings. It is
 * therefore deliberately unauthenticated and free to call.
 *
 * That makes cost the security question, not access. `ContentCredentialsReader`
 * spawns c2patool and exiftool, so an uncached endpoint would be a cheap way to
 * fork processes on our server. Every answer is cached against the file's
 * identity AND its size and modification time, so a re-upload invalidates it
 * while a flood of repeat requests costs one lookup.
 *
 * The cache answers repeat requests for one file, not a walk over all uids
 * (AUD-025-F02). Three more bounds therefore apply, in this order:
 *
 * 1. Only files a live page shows are answered at all
 *    (PublicFileReferenceChecker, plus a public storage). Every other uid
 *    answers 404 — the same 404 as a uid that does not exist, so the endpoint
 *    no longer tells which files exist.
 * 2. Cache misses are rate limited per client address (sliding window,
 *    `$missesPerMinute`, overridable through
 *    `$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['content-provenance-asset']`).
 *    Past the limit: 429 with Retry-After.
 * 3. At most `$maxConcurrentInspections` inspections run at once per
 *    application instance (non-blocking file locks, one per slot). All slots
 *    taken: 429 with Retry-After, never a queue of waiting processes.
 *
 * What it does NOT return: the stored prompt. It is recorded at upload time and
 * is internal wording; disclosing that AI was used is a different act from
 * publishing what was typed. Same reasoning as the frontend panel.
 */
final class AssetProvenanceApiMiddleware implements MiddlewareInterface
{
    private const API_PATH = '/_provenance/api/asset';

    /**
     * Long by design: the answer only changes when the file changes, and the
     * cache key already contains the file's size and modification time, so a
     * changed file misses rather than serving a stale answer.
     */
    private const CACHE_LIFETIME = 86400;

    /** Muss mit dem Bezeichner in `ext_localconf.php` uebereinstimmen. */
    private const CACHE_IDENTIFIER = 'content_provenance_asset';

    private const RATE_LIMITER_ID = 'content-provenance-asset';

    public function __construct(
        private readonly ContentCredentialsReader $reader,
        private readonly ResourceFactory $resourceFactory,
        private readonly CacheManager $cacheManager,
        private readonly PublicFileReferenceChecker $publicFileReferenceChecker,
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly LockFactory $lockFactory,
        private readonly int $missesPerMinute = 20,
        private readonly int $maxConcurrentInspections = 2,
    ) {}

    /**
     * Der Cache wird zur Laufzeit geholt, nicht ueber den Container.
     *
     * Seine Konfiguration entsteht in `ext_localconf.php`, und die laeuft
     * *nach* dem Bau des Containers. Ein `@cache.content_provenance_asset` im
     * Dienst-Argument verlangt den Eintrag also zu einem Zeitpunkt, zu dem es
     * ihn noch nicht gibt — der Container laesst sich dann gar nicht erst
     * bauen, und zwar fuer die ganze Installation, nicht nur fuer diese
     * Middleware. Genau das hat einmal jede Seite mit einem 500er beantwortet.
     */
    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::API_PATH !== $request->getUri()->getPath() || 'GET' !== $request->getMethod()) {
            return $handler->handle($request);
        }

        $fileUid = (int) ($request->getQueryParams()['file'] ?? 0);
        if ($fileUid <= 0) {
            return $this->json(['error' => 'Missing or invalid required parameter: file'], 400);
        }

        // FAL resolution is the access control here: only files this
        // installation actually manages can be named, and a UID cannot be bent
        // into a path outside the configured storages.
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return $this->json(['error' => 'Unknown file'], 404);
        }

        if (!$file->exists()
            || !$file->getStorage()->isPublic()
            || !$this->publicFileReferenceChecker->isReferencedPublicly($fileUid)) {
            return $this->json(['error' => 'Unknown file'], 404);
        }

        $cacheKey = sprintf(
            'asset-%d-%s',
            $fileUid,
            sha1($file->getSize() . '-' . $file->getModificationTime() . '-' . $file->getSha1()),
        );

        $cache = $this->cache();
        $payload = $cache->get($cacheKey);
        if (\is_array($payload)) {
            return $this->json($payload, 200);
        }

        $limit = $this->rateLimiterFactory->createRequestBasedLimiter($request, [
            'id' => self::RATE_LIMITER_ID,
            'policy' => 'sliding_window',
            'limit' => $this->missesPerMinute,
            'interval' => '1 minute',
        ])->consume();
        if (!$limit->isAccepted()) {
            return $this->tooManyRequests(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $slot = $this->acquireInspectionSlot();
        if (null === $slot) {
            return $this->tooManyRequests(5);
        }

        try {
            $payload = $this->inspect($file->getForLocalProcessing(false), $fileUid);
        } finally {
            $slot->release();
            $slot->destroy();
        }
        $cache->set($cacheKey, $payload, [], self::CACHE_LIFETIME);

        return $this->json($payload, 200);
    }

    /**
     * One of $maxConcurrentInspections non-blocking locks, or null when all
     * are held by running inspections.
     */
    private function acquireInspectionSlot(): ?LockingStrategyInterface
    {
        for ($slot = 0; $slot < $this->maxConcurrentInspections; ++$slot) {
            $locker = $this->lockFactory->createLocker(
                'content_provenance_inspect_' . $slot,
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
            );
            try {
                if ($locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                    return $locker;
                }
            } catch (LockAcquireWouldBlockException) {
                // slot busy, try the next one
            }
        }

        return null;
    }

    private function tooManyRequests(int $retryAfter): ResponseInterface
    {
        return $this->json(['error' => 'Too many inspections, retry later'], 429)
            ->withHeader('Retry-After', (string) $retryAfter)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(string $absolutePath, int $fileUid): array
    {
        $detected = $this->reader->read($absolutePath);

        return [
            'file' => $fileUid,
            // The manifest states are none / signed_own / signed_external /
            // stripped. "stripped" is reported rather than smoothed away: it
            // means credentials were there and something removed them, which is
            // the failure worth knowing about.
            'c2paStatus' => $detected->c2paStatus,
            'hasManifest' => \in_array($detected->c2paStatus, ['signed_own', 'signed_external'], true),
            'origin' => $detected->origin->value,
            'aiSystem' => $detected->aiSystem,
            'signingChainIssuer' => $detected->signingChainIssuer,
            // Said in the payload, not just in a README: these manifests are
            // signed by this site's own CA, so a validator checking the public
            // C2PA trust list will report an unknown issuer. A caller deserves
            // to know that without having to ask us.
            'trustAnchor' => 'self-hosted',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): ResponseInterface
    {
        $response = new JsonResponse($data, $status);

        // Verification is meant to be usable by fact-checkers and authorities,
        // which in practice means from a page that is not ours.
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
