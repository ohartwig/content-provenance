<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;

/**
 * Answers whether a FAL file is shown to anonymous visitors.
 *
 * A file counts as public when at least one non-deleted, non-hidden
 * sys_file_reference points at it from a record that is itself live (not
 * deleted, not hidden, inside start/end time, no frontend-group restriction)
 * on a page that is live in the same sense. Everything else — files in no
 * reference, references from hidden drafts, records behind a login — is not
 * public, and the anonymous asset endpoint must not even confirm it exists.
 *
 * The page check walks only the record's own page, not its root line: a page
 * below a hidden parent is still treated as public. That errs towards
 * answering for a file that is reachable by uid anyway, never towards
 * inspecting a file nothing references.
 */
final readonly class PublicFileReferenceChecker
{
    /** fe_group values that do not restrict anonymous visitors. */
    private const ANONYMOUS_GROUPS = ['', '0', '-1'];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function isReferencedPublicly(int $fileUid): bool
    {
        foreach ($this->references($fileUid) as [$table, $uid]) {
            if ('pages' === $table) {
                if ($this->isLive('pages', $uid)) {
                    return true;
                }

                continue;
            }

            $pid = $this->liveRecordPid($table, $uid);
            if (null !== $pid && $this->isLive('pages', $pid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string, int}>
     */
    private function references(int $fileUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->setRestrictions(new DefaultRestrictionContainer());
        $rows = $queryBuilder
            ->select('tablenames', 'uid_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq(
                'uid_local',
                $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $table = (string) $row['tablenames'];
            if (!isset($GLOBALS['TCA'][$table])) {
                continue;
            }
            $references[] = [$table, (int) $row['uid_foreign']];
        }

        return $references;
    }

    private function isLive(string $table, int $uid): bool
    {
        return null !== $this->liveRecordPid($table, $uid);
    }

    /**
     * The record's pid if it is live and not group-restricted, null otherwise.
     */
    private function liveRecordPid(string $table, int $uid): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->setRestrictions(new DefaultRestrictionContainer());

        $groupField = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['fe_group'] ?? null;
        $fields = ['pid'];
        if (is_string($groupField) && '' !== $groupField) {
            $fields[] = $groupField;
        }

        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        if (is_string($groupField) && '' !== $groupField
            && !in_array(trim((string) ($row[$groupField] ?? '')), self::ANONYMOUS_GROUPS, true)) {
            return null;
        }

        return (int) $row['pid'];
    }
}
