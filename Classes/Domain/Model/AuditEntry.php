<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

use Koh\ContentProvenance\Domain\Enum\AuditAction;

final readonly class AuditEntry
{
    /**
     * @param array<string, mixed>|null $fieldChanges
     */
    public function __construct(
        public string $contentTable,
        public int $contentUid,
        public int $contentLanguage,
        public AuditAction $action,
        public int $performedBy,
        public string $performedByName,
        public \DateTimeImmutable $performedAt,
        public ?array $fieldChanges,
        public bool $aiGenerated,
        public ?string $aiProvider,
        public ?string $aiModel,
        public ?int $aiReviewedBy,
        public ?string $aiReviewedByName,
        public ?\DateTimeImmutable $aiReviewedAt,
    ) {}
}
