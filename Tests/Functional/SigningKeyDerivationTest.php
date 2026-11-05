<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Tests\Functional;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Exception\KeyNotFoundException;
use Koh\ContentProvenance\Infrastructure\KeyManagement\Provider\Typo3KeyProvider;
use Koh\ContentProvenance\Infrastructure\Middleware\WellKnownKeysMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AUD-025-F03, decision: documented derivation. The README's "Signing key"
 * section is the contract; these tests pin it.
 *
 * Before the fix the site set offered a key-provider setting nothing read and
 * `provenance:rotate-key` existed and failed on every installation.
 */
final class SigningKeyDerivationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/content-provenance'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['encryptionKey' => 'documented-derivation-test-key'],
    ];

    #[Test]
    public function theSigningKeyIsTheDocumentedDerivationOfTheEncryptionKey(): void
    {
        $seed = substr(hash('sha256', 'content-provenance-signing-key:documented-derivation-test-key', true), 0, 32);
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

        $keyPair = $this->get(KeyProviderInterface::class)->getSigningKeyPair();

        self::assertSame(sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_ORIGINAL), $keyPair->publicKey);
        self::assertSame(substr(hash('sha256', $publicKey), 0, 16), $keyPair->keyId);
    }

    #[Test]
    public function theTypo3ProviderIsTheOnlyKeyProvider(): void
    {
        self::assertInstanceOf(Typo3KeyProvider::class, $this->get(KeyProviderInterface::class));
    }

    #[Test]
    public function anotherEncryptionKeyYieldsAnotherKeyAndTheOldKeyIdNoLongerResolves(): void
    {
        $old = new Typo3KeyProvider('documented-derivation-test-key');
        $new = new Typo3KeyProvider('a-rotated-encryption-key');

        self::assertNotSame($old->getCurrentKeyId(), $new->getCurrentKeyId());
        $this->expectException(KeyNotFoundException::class);
        $new->getPublicKeyById($old->getCurrentKeyId());
    }

    #[Test]
    public function theWellKnownDocumentPublishesExactlyTheDerivedKey(): void
    {
        $keyPair = $this->get(KeyProviderInterface::class)->getSigningKeyPair();

        $response = $this->get(WellKnownKeysMiddleware::class)->process(
            new ServerRequest('https://example.org/.well-known/provenance-keys', 'GET'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response();
                }
            },
        );

        $document = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['keys' => [['keyId' => $keyPair->keyId, 'publicKey' => $keyPair->publicKey, 'algorithm' => 'Ed25519']]],
            $document,
        );
    }

    #[Test]
    public function thereIsNoRotateKeyCommand(): void
    {
        $registry = $this->get(CommandRegistry::class);

        self::assertTrue($registry->has('provenance:export-public-key'), 'the registry lists this extension\'s commands');
        self::assertFalse($registry->has('provenance:rotate-key'));
    }

    #[Test]
    public function theSiteSetOffersNoKeyProviderSetting(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Configuration/Sets/ContentProvenance';
        $definitions = Yaml::parseFile($directory . '/settings.definitions.yaml');
        $defaults = Yaml::parseFile($directory . '/settings.yaml');

        self::assertSame([], array_filter(
            array_keys($definitions['settings'] ?? []),
            static fn(string $key): bool => str_starts_with($key, 'contentProvenance.keyProvider'),
        ));
        self::assertArrayNotHasKey('keyProvider', $defaults['contentProvenance'] ?? []);
    }
}
