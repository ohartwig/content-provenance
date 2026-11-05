<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

use Koh\ContentProvenance\Domain\Model\AuditEntry;

interface AuditLogRepositoryInterface
{
    public function log(AuditEntry $entry): void;

    /**
     * @return list<AuditEntry>
     */
    public function findByContent(string $table, int $uid, int $language, int $limit = 50): array;

    /**
     * @return list<AuditEntry>
     */
    public function findRecent(int $limit = 20): array;

    /**
     * Retention: removes entries performed before $cutoff, returns how many.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
