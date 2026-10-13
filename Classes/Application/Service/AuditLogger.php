<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Contract\AuditLoggerInterface;
use Koh\ContentProvenance\Domain\Contract\AuditLogRepositoryInterface;
use Koh\ContentProvenance\Domain\Enum\AuditAction;
use Koh\ContentProvenance\Domain\Model\AuditEntry;

final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param array<string, mixed>|null $fieldChanges
     */
    public function log(
        string $table,
        int $uid,
        int $language,
        AuditAction $action,
        int $performedBy,
        string $performedByName,
        ?array $fieldChanges = null,
    ): void {
        $entry = new AuditEntry(
            contentTable: $table,
            contentUid: $uid,
            contentLanguage: $language,
            action: $action,
            performedBy: $performedBy,
            performedByName: $performedByName,
            performedAt: new \DateTimeImmutable(),
            fieldChanges: $fieldChanges,
            aiGenerated: false,
            aiProvider: null,
            aiModel: null,
            aiReviewedBy: null,
            aiReviewedByName: null,
            aiReviewedAt: null,
        );

        $this->auditLogRepository->log(entry: $entry);
    }

    public function logAiGeneration(
        string $table,
        int $uid,
        int $language,
        string $aiProvider,
        string $aiModel,
        ?int $reviewedBy = null,
        ?string $reviewedByName = null,
    ): void {
        $entry = new AuditEntry(
            contentTable: $table,
            contentUid: $uid,
            contentLanguage: $language,
            action: AuditAction::AI_GENERATE,
            performedBy: 0,
            performedByName: 'System',
            performedAt: new \DateTimeImmutable(),
            fieldChanges: null,
            aiGenerated: true,
            aiProvider: $aiProvider,
            aiModel: $aiModel,
            aiReviewedBy: $reviewedBy,
            aiReviewedByName: $reviewedByName,
            aiReviewedAt: null !== $reviewedBy ? new \DateTimeImmutable() : null,
        );

        $this->auditLogRepository->log(entry: $entry);
    }

    public function logAiReview(
        string $table,
        int $uid,
        int $language,
        int $reviewedBy,
        string $reviewedByName,
    ): void {
        $entry = new AuditEntry(
            contentTable: $table,
            contentUid: $uid,
            contentLanguage: $language,
            action: AuditAction::AI_REVIEW,
            performedBy: $reviewedBy,
            performedByName: $reviewedByName,
            performedAt: new \DateTimeImmutable(),
            fieldChanges: null,
            aiGenerated: false,
            aiProvider: null,
            aiModel: null,
            aiReviewedBy: $reviewedBy,
            aiReviewedByName: $reviewedByName,
            aiReviewedAt: new \DateTimeImmutable(),
        );

        $this->auditLogRepository->log(entry: $entry);
    }
}
