<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Application\Event\SignatureInvalidatedEvent;
use Koh\ContentProvenance\Domain\Contract\SignatureRepositoryInterface;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class SignatureInvalidator
{
    public function __construct(
        private SignatureRepositoryInterface $signatureRepository,
        private SignedFieldsResolverInterface $signedFieldsResolver,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param array<string, mixed> $changedFields
     */
    public function checkAndInvalidate(
        string $table,
        int $uid,
        int $language,
        array $changedFields,
    ): void {
        $signedFields = $this->signedFieldsResolver->getFieldsForTable(table: $table);
        if ([] === $signedFields) {
            return;
        }

        $changedFieldNames = array_keys($changedFields);
        $affectedSignedFields = array_intersect($signedFields, $changedFieldNames);

        if ([] === $affectedSignedFields) {
            return;
        }

        $signature = $this->signatureRepository->findByContent(
            table: $table,
            uid: $uid,
            language: $language,
        );

        if (null === $signature) {
            return;
        }

        if ($signature->revoked) {
            return;
        }

        $this->signatureRepository->revoke(
            table: $table,
            uid: $uid,
            language: $language,
            revokedBy: 0,
            reason: 'Signed fields were modified',
        );

        $this->eventDispatcher->dispatch(new SignatureInvalidatedEvent(
            table: $table,
            uid: $uid,
            language: $language,
            changedFields: array_values($affectedSignedFields),
        ));
    }
}
