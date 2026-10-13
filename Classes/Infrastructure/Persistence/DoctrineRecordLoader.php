<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\ContentProvenance\Domain\Contract\RecordLoaderInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DoctrineRecordLoader implements RecordLoaderInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $table, int $uid, int $language): ?array
    {
        $connection = $this->connectionPool->getConnectionForTable($table);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
            );

        if ($this->tableHasLanguageField($table)) {
            $languageField = $this->getLanguageField($table);
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter($language, ParameterType::INTEGER),
                ),
            );
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $row;
    }

    private function tableHasLanguageField(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['languageField']);
    }

    private function getLanguageField(string $table): string
    {
        return (string) ($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? 'sys_language_uid');
    }
}
