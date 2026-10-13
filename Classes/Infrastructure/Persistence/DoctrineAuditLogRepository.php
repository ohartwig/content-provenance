<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Koh\ContentProvenance\Domain\Contract\AuditLogRepositoryInterface;
use Koh\ContentProvenance\Domain\Enum\AuditAction;
use Koh\ContentProvenance\Domain\Model\AuditEntry;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DoctrineAuditLogRepository implements AuditLogRepositoryInterface
{
    private const TABLE_NAME = 'tx_provenance_audit_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function log(AuditEntry $entry): void
    {
        $connection = $this->getConnection();

        $connection->insert(self::TABLE_NAME, [
            'content_table' => $entry->contentTable,
            'content_uid' => $entry->contentUid,
            'content_language' => $entry->contentLanguage,
            'action' => $entry->action->value,
            'performed_by' => $entry->performedBy,
            'performed_by_name' => $entry->performedByName,
            'performed_at' => $entry->performedAt->getTimestamp(),
            'field_changes' => null !== $entry->fieldChanges
                ? json_encode($entry->fieldChanges, JSON_THROW_ON_ERROR)
                : null,
            'ai_generated' => $entry->aiGenerated ? 1 : 0,
            'ai_provider' => $entry->aiProvider ?? '',
            'ai_model' => $entry->aiModel ?? '',
            'ai_reviewed_by' => $entry->aiReviewedBy ?? 0,
            'ai_reviewed_by_name' => $entry->aiReviewedByName ?? '',
            'ai_reviewed_at' => $entry->aiReviewedAt?->getTimestamp() ?? 0,
        ]);
    }

    /**
     * @return list<AuditEntry>
     */
    public function findByContent(string $table, int $uid, int $language, int $limit = 50): array
    {
        $connection = $this->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'content_table',
                    $queryBuilder->createNamedParameter($table),
                ),
                $queryBuilder->expr()->eq(
                    'content_uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'content_language',
                    $queryBuilder->createNamedParameter($language, ParameterType::INTEGER),
                ),
            )
            ->orderBy('performed_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery();

        $entries = [];
        while ($row = $result->fetchAssociative()) {
            $entries[] = $this->hydrateEntry($row);
        }

        return $entries;
    }

    /**
     * @return list<AuditEntry>
     */
    public function findRecent(int $limit = 20): array
    {
        $connection = $this->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('performed_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery();

        $entries = [];
        while ($row = $result->fetchAssociative()) {
            $entries[] = $this->hydrateEntry($row);
        }

        return $entries;
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEntry(array $row): AuditEntry
    {
        $fieldChanges = null !== $row['field_changes'] && '' !== $row['field_changes']
            ? json_decode((string) $row['field_changes'], true, 512, JSON_THROW_ON_ERROR)
            : null;

        $aiReviewedAt = (int) $row['ai_reviewed_at'] > 0
            ? new \DateTimeImmutable()->setTimestamp((int) $row['ai_reviewed_at'])
            : null;

        $aiReviewedBy = (int) $row['ai_reviewed_by'] > 0 ? (int) $row['ai_reviewed_by'] : null;
        $aiReviewedByName = '' !== (string) $row['ai_reviewed_by_name']
            ? (string) $row['ai_reviewed_by_name']
            : null;

        return new AuditEntry(
            contentTable: (string) $row['content_table'],
            contentUid: (int) $row['content_uid'],
            contentLanguage: (int) $row['content_language'],
            action: AuditAction::from((string) $row['action']),
            performedBy: (int) $row['performed_by'],
            performedByName: (string) $row['performed_by_name'],
            performedAt: new \DateTimeImmutable()->setTimestamp((int) $row['performed_at']),
            fieldChanges: $fieldChanges,
            aiGenerated: (bool) $row['ai_generated'],
            aiProvider: '' !== (string) $row['ai_provider'] ? (string) $row['ai_provider'] : null,
            aiModel: '' !== (string) $row['ai_model'] ? (string) $row['ai_model'] : null,
            aiReviewedBy: $aiReviewedBy,
            aiReviewedByName: $aiReviewedByName,
            aiReviewedAt: $aiReviewedAt,
        );
    }
}
