<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\EventListener;

use Koh\ContentProvenance\Application\Service\ContentCredentialsReader;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\File;

/**
 * On every newly-uploaded FAL file: read embedded Content Credentials
 * (C2PA + IPTC) and seed `sys_file_metadata.tx_provenance_*` columns.
 *
 * Runs once per upload — subsequent edits of the same file in the BE don't
 * re-trigger this (the metadata is already there). Editor can still
 * overwrite the auto-detected values manually.
 *
 * Defensive: if c2patool/exiftool are missing or the file isn't supported,
 * the reader returns `Human` and no metadata is written — file stays
 * untagged and the editor can manual-tag it.
 */
#[AsEventListener('content-provenance/auto-detect-ai-content')]
final class AutoDetectAiContentOnFileAdded implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ContentCredentialsReader $reader,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function __invoke(AfterFileAddedEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }
        $absolutePath = $file->getForLocalProcessing(false);
        if (!is_file($absolutePath)) {
            return;
        }

        try {
            $detected = $this->reader->read($absolutePath);
        } catch (\Throwable $e) {
            $this->logger?->error('Auto-detect failed', [
                'file_uid' => $file->getUid(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $metaUid = $this->resolveFileMetadataUid($file->getUid());
        if (0 === $metaUid) {
            $this->logger?->warning('sys_file_metadata row missing for file', ['file_uid' => $file->getUid()]);

            return;
        }

        // detected_at + c2pa_status IMMER schreiben (auch wenn Human/none) —
        // damit das BE unterscheiden kann zwischen "noch nicht geprüft" und
        // "geprüft, keine AI-Marker gefunden". `origin` nur überschreiben wenn
        // wir was Konkretes gefunden haben, sonst bleibt der TCA-Default und
        // der Editor kann manuell taggen.
        // tstamp wird mit-gesetzt damit TYPO3-Caches + BE-„geändert"-Indikator
        // den Record als modifiziert sehen.
        $now = time();
        $update = [
            'tstamp' => $now,
            'tx_provenance_detected_at' => $now,
            'tx_provenance_c2pa_status' => $detected->c2paStatus,
        ];
        if (\Koh\ContentProvenance\Domain\Model\AssetOrigin::Human !== $detected->origin) {
            $update['tx_provenance_origin'] = $detected->origin->value;
            $update['tx_provenance_ai_system'] = $detected->aiSystem ?? '';
            $update['tx_provenance_ai_prompt'] = $detected->aiPrompt ?? '';
        }

        $this->connectionPool
            ->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', $update, ['uid' => $metaUid]);
    }

    private function resolveFileMetadataUid(int $fileUid): int
    {
        if (0 === $fileUid) {
            return 0;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int) $row['uid'] : 0;
    }
}
