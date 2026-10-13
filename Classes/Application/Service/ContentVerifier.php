<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Contract\RecordLoaderInterface;
use Koh\ContentProvenance\Domain\Contract\SignatureRepositoryInterface;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Koh\ContentProvenance\Domain\Model\VerificationResult;

final readonly class ContentVerifier
{
    public function __construct(
        private SignatureRepositoryInterface $signatureRepository,
        private KeyProviderInterface $keyProvider,
        private ContentHasher $contentHasher,
        private SignedFieldsResolverInterface $signedFieldsResolver,
        private RecordLoaderInterface $recordLoader,
    ) {}

    public function verify(
        string $table,
        int $uid,
        int $language,
    ): VerificationResult {
        $signature = $this->signatureRepository->findByContent(
            table: $table,
            uid: $uid,
            language: $language,
        );

        if (null === $signature) {
            return VerificationResult::unsigned();
        }

        if ($signature->revoked) {
            return VerificationResult::revoked(
                at: $signature->revokedAt ?? $signature->signedAt,
                reason: $signature->revokedReason ?? 'Signature was revoked',
            );
        }

        $publicKeyBase64 = $this->keyProvider->getPublicKeyById(keyId: $signature->keyId);
        $publicKey = sodium_base642bin($publicKeyBase64, SODIUM_BASE64_VARIANT_ORIGINAL);
        $signatureBytes = sodium_base642bin($signature->signature, SODIUM_BASE64_VARIANT_ORIGINAL);
        if ('' === $signatureBytes) {
            throw new \InvalidArgumentException('Signature bytes must not be empty', 1711100011);
        }
        if ('' === $publicKey) {
            throw new \InvalidArgumentException('Public key must not be empty', 1711100012);
        }

        $signatureValid = sodium_crypto_sign_verify_detached(
            $signatureBytes,
            $signature->contentHash,
            $publicKey,
        );

        $currentRecord = $this->recordLoader->load(table: $table, uid: $uid, language: $language);
        $fieldsToSign = $this->signedFieldsResolver->getFieldsForTable(table: $table);

        $contentIntact = false;
        if (null !== $currentRecord) {
            $currentHash = $this->contentHasher->hashRecord(
                table: $table,
                uid: $uid,
                language: $language,
                record: $currentRecord,
                fieldsToSign: $fieldsToSign,
            );
            $contentIntact = hash_equals($signature->contentHash, $currentHash);
        }

        return new VerificationResult(
            signed: true,
            signatureValid: $signatureValid,
            contentIntact: $contentIntact,
            revoked: false,
            signedBy: $signature->signedByName,
            signedAt: $signature->signedAt,
            keyId: $signature->keyId,
            contentHash: $signature->contentHash,
            revokedAt: null,
            revokedReason: null,
        );
    }
}
