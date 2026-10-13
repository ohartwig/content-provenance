<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads asset-provenance fields from sys_file_metadata for a given sys_file
 * (FAL file) UID. Used by FE rendering to decide whether to display the
 * Content Credentials badge.
 *
 * The provenance columns are owned by `koh/content-provenance`
 * (tx_provenance_origin / tx_provenance_ai_system / tx_provenance_ai_prompt /
 * tx_provenance_c2pa_status). This fetcher is consumer-side — no coupling to
 * the writer code path.
 *
 * Returns an associative array with the same keys used in the AiContentBadge
 * partial: `origin`, `aiSystem`, `c2paStatus`. Empty for non-AI files.
 *
 * @phpstan-type ProvenanceData array{origin: string, aiSystem: string, c2paStatus: string}
 */
final class AssetProvenanceFetcher
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{origin: string, aiSystem: string, c2paStatus: string}
     */
    public function fetchForFile(int $fileUid, int $languageId = 0): array
    {
        if ($fileUid <= 0) {
            return $this->empty();
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $row = $qb
            ->select('tx_provenance_origin', 'tx_provenance_ai_system', 'tx_provenance_c2pa_status')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return $this->empty();
        }

        return [
            'origin' => (string) ($row['tx_provenance_origin'] ?? 'human'),
            'aiSystem' => (string) ($row['tx_provenance_ai_system'] ?? ''),
            'c2paStatus' => (string) ($row['tx_provenance_c2pa_status'] ?? 'none'),
        ];
    }

    /**
     * @return array{origin: string, aiSystem: string, c2paStatus: string}
     */
    private function empty(): array
    {
        return ['origin' => 'human', 'aiSystem' => '', 'c2paStatus' => 'none'];
    }
}
