<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

use Koh\ContentProvenance\Domain\Enum\AuditAction;

interface AuditLoggerInterface
{
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
    ): void;

    public function logAiGeneration(
        string $table,
        int $uid,
        int $language,
        string $aiProvider,
        string $aiModel,
        ?int $reviewedBy = null,
        ?string $reviewedByName = null,
    ): void;

    public function logAiReview(
        string $table,
        int $uid,
        int $language,
        int $reviewedBy,
        string $reviewedByName,
    ): void;
}
