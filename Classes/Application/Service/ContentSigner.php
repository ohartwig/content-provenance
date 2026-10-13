<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Application\Event\ContentSignedEvent;
use Koh\ContentProvenance\Domain\Contract\AuditLoggerInterface;
use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Koh\ContentProvenance\Domain\Contract\RecordLoaderInterface;
use Koh\ContentProvenance\Domain\Contract\SignatureRepositoryInterface;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Koh\ContentProvenance\Domain\Enum\AuditAction;
use Koh\ContentProvenance\Domain\Model\Signature;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ContentSigner
{
    public function __construct(
        private KeyProviderInterface $keyProvider,
        private ContentHasher $contentHasher,
        private SignatureRepositoryInterface $signatureRepository,
        private AuditLoggerInterface $auditLogger,
        private SignedFieldsResolverInterface $signedFieldsResolver,
        private RecordLoaderInterface $recordLoader,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        // composer requires ext-sodium, but the runtime box may still be missing
        // it (e.g. shared hoster that ignored the composer platform check). Fail
        // loud at construction time rather than producing a fatal error inside
        // sign() with a stack trace pointing into the libsodium FFI layer.
        if (!\extension_loaded('sodium')) {
            throw new \RuntimeException('koh/content-provenance requires the PHP ext-sodium extension at runtime. Install php-sodium and reload PHP-FPM/Apache.', 1747600100);
        }
    }

    public function sign(
        string $table,
        int $uid,
        int $language,
        int $backendUserId,
        string $backendUserName,
    ): Signature {
        $record = $this->recordLoader->load(table: $table, uid: $uid, language: $language);
        if (null === $record) {
            throw new \RuntimeException(sprintf('Record %s:%d (language %d) not found', $table, $uid, $language));
        }

        $fieldsToSign = $this->signedFieldsResolver->getFieldsForTable(table: $table);
        $keyPair = $this->keyProvider->getSigningKeyPair();

        $contentHash = $this->contentHasher->hashRecord(
            table: $table,
            uid: $uid,
            language: $language,
            record: $record,
            fieldsToSign: $fieldsToSign,
        );

        $secretKeyBytes = sodium_base642bin($keyPair->secretKey, SODIUM_BASE64_VARIANT_ORIGINAL);
        if ('' === $secretKeyBytes) {
            throw new \InvalidArgumentException('Secret key must not be empty', 1711100010);
        }
        $signatureBytes = sodium_crypto_sign_detached($contentHash, $secretKeyBytes);
        sodium_memzero($secretKeyBytes);
        $signatureBase64 = sodium_bin2base64($signatureBytes, SODIUM_BASE64_VARIANT_ORIGINAL);

        $signedAt = new \DateTimeImmutable();

        $signature = new Signature(
            contentTable: $table,
            contentUid: $uid,
            contentLanguage: $language,
            contentHash: $contentHash,
            signature: $signatureBase64,
            signedBy: $backendUserId,
            signedByName: $backendUserName,
            signedAt: $signedAt,
            keyId: $keyPair->keyId,
        );

        $this->signatureRepository->upsert(signature: $signature);

        $this->auditLogger->log(
            table: $table,
            uid: $uid,
            language: $language,
            action: AuditAction::SIGN,
            performedBy: $backendUserId,
            performedByName: $backendUserName,
        );

        $this->eventDispatcher->dispatch(new ContentSignedEvent(
            table: $table,
            uid: $uid,
            language: $language,
            contentHash: $contentHash,
            signedByName: $backendUserName,
            signedAt: $signedAt,
        ));

        return $signature;
    }
}
