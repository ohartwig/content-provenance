<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\KeyManagement\Provider;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Exception\KeyNotFoundException;
use Koh\ContentProvenance\Domain\Exception\KeyRotationNotSupportedException;
use Koh\ContentProvenance\Domain\Model\KeyPair;

final readonly class Typo3KeyProvider implements KeyProviderInterface
{
    private string $encryptionKey;

    public function __construct(
        string $encryptionKey = '',
    ) {
        $resolvedKey = '' !== $encryptionKey
            ? $encryptionKey
            : (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        if ('' === $resolvedKey) {
            throw new \RuntimeException('TYPO3 encryptionKey must not be empty. Configure $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'encryptionKey\'].', 1711100020);
        }

        $this->encryptionKey = $resolvedKey;
    }

    public function getSigningKeyPair(): KeyPair
    {
        $seed = hash('sha256', 'content-provenance-signing-key:' . $this->encryptionKey, true);
        $seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);

        $keyPairBytes = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keyPairBytes);
        $publicKey = sodium_crypto_sign_publickey($keyPairBytes);
        sodium_memzero($seed);
        sodium_memzero($keyPairBytes);

        $keyId = substr(hash('sha256', $publicKey), 0, 16);

        $keyPair = new KeyPair(
            keyId: $keyId,
            publicKey: sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_ORIGINAL),
            secretKey: sodium_bin2base64($secretKey, SODIUM_BASE64_VARIANT_ORIGINAL),
            createdAt: new \DateTimeImmutable('2000-01-01 00:00:00'),
            expiresAt: null,
        );

        sodium_memzero($secretKey);
        sodium_memzero($publicKey);

        return $keyPair;
    }

    public function getPublicKeyById(string $keyId): string
    {
        $currentKeyPair = $this->getSigningKeyPair();

        if ($currentKeyPair->keyId !== $keyId) {
            throw KeyNotFoundException::forKeyId(keyId: $keyId);
        }

        return $currentKeyPair->publicKey;
    }

    public function getCurrentKeyId(): string
    {
        return $this->getSigningKeyPair()->keyId;
    }

    public function rotateKey(): KeyPair
    {
        throw KeyRotationNotSupportedException::forProvider(providerName: 'typo3');
    }
}
