<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\KeyManagement\Provider;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Exception\KeyNotFoundException;
use Koh\ContentProvenance\Domain\Model\KeyPair;

final class FileKeyProvider implements KeyProviderInterface
{
    private ?KeyPair $cachedKeyPair = null;

    private readonly string $keyDirectory;

    public function __construct(
        string $keyDirectory = '',
    ) {
        $this->keyDirectory = '' !== $keyDirectory
            ? $keyDirectory
            : (\TYPO3\CMS\Core\Core\Environment::getVarPath() . '/provenance-keys');
    }

    public function getSigningKeyPair(): KeyPair
    {
        if (null !== $this->cachedKeyPair) {
            return $this->cachedKeyPair;
        }

        $activeKeyFile = $this->keyDirectory . '/active-key.json';

        if (file_exists($activeKeyFile)) {
            $data = file_get_contents($activeKeyFile);
            if (false === $data) {
                throw new \RuntimeException('Failed to read active key file');
            }
            $keyData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            $this->cachedKeyPair = $this->hydrateKeyPair($keyData);

            return $this->cachedKeyPair;
        }

        $this->cachedKeyPair = $this->generateAndStoreNewKey();

        return $this->cachedKeyPair;
    }

    public function getPublicKeyById(string $keyId): string
    {
        $this->validateKeyId(keyId: $keyId);

        $activeKeyPair = $this->getSigningKeyPair();
        if ($activeKeyPair->keyId === $keyId) {
            return $activeKeyPair->publicKey;
        }

        $archiveFile = $this->keyDirectory . '/archive/' . $keyId . '.json';
        if (file_exists($archiveFile)) {
            $data = file_get_contents($archiveFile);
            if (false === $data) {
                throw new \RuntimeException(sprintf('Failed to read archived key file for key ID "%s"', $keyId));
            }
            $keyData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

            return $keyData['publicKey'] ?? throw KeyNotFoundException::forKeyId(keyId: $keyId);
        }

        throw KeyNotFoundException::forKeyId(keyId: $keyId);
    }

    public function getCurrentKeyId(): string
    {
        return $this->getSigningKeyPair()->keyId;
    }

    public function rotateKey(): KeyPair
    {
        $currentKeyPair = $this->getSigningKeyPair();
        $this->validateKeyId(keyId: $currentKeyPair->keyId);

        $archiveDir = $this->keyDirectory . '/archive';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0o700, true);
        }

        $archiveFile = $archiveDir . '/' . $currentKeyPair->keyId . '.json';
        file_put_contents(
            $archiveFile,
            json_encode($this->serializeKeyPair($currentKeyPair), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        chmod($archiveFile, 0o600);

        $this->cachedKeyPair = null;

        $newKeyPair = $this->generateAndStoreNewKey();
        $this->cachedKeyPair = $newKeyPair;

        return $newKeyPair;
    }

    private function generateAndStoreNewKey(): KeyPair
    {
        $keyPairBytes = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPairBytes);
        $publicKey = sodium_crypto_sign_publickey($keyPairBytes);
        sodium_memzero($keyPairBytes);

        $keyId = substr(bin2hex(random_bytes(16)), 0, 16);
        $createdAt = new \DateTimeImmutable();

        $keyPair = new KeyPair(
            keyId: $keyId,
            publicKey: sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_ORIGINAL),
            secretKey: sodium_bin2base64($secretKey, SODIUM_BASE64_VARIANT_ORIGINAL),
            createdAt: $createdAt,
        );

        sodium_memzero($secretKey);
        sodium_memzero($publicKey);

        $activeKeyFile = $this->keyDirectory . '/active-key.json';
        file_put_contents(
            $activeKeyFile,
            json_encode($this->serializeKeyPair($keyPair), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        chmod($activeKeyFile, 0o600);

        return $keyPair;
    }

    /**
     * @return array<string, string|null>
     */
    private function serializeKeyPair(KeyPair $keyPair): array
    {
        return [
            'keyId' => $keyPair->keyId,
            'publicKey' => $keyPair->publicKey,
            'secretKey' => $keyPair->secretKey,
            'createdAt' => $keyPair->createdAt->format(\DateTimeInterface::ATOM),
            'expiresAt' => $keyPair->expiresAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateKeyPair(array $data): KeyPair
    {
        return new KeyPair(
            keyId: $data['keyId'],
            publicKey: $data['publicKey'],
            secretKey: $data['secretKey'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
            expiresAt: isset($data['expiresAt'])
                ? new \DateTimeImmutable($data['expiresAt'])
                : null,
        );
    }

    private function validateKeyId(string $keyId): void
    {
        if (1 !== preg_match('/^[a-f0-9]{16}$/', $keyId)) {
            throw new \InvalidArgumentException(sprintf('Invalid key ID format: "%s"', $keyId), 1711100003);
        }
    }
}
