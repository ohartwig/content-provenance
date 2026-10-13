<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\EventListener;

use Koh\ContentProvenance\Application\Service\C2paManifestSigner;
use Koh\ContentProvenance\Application\Service\ContentCredentialsReader;
use Koh\ContentProvenance\Application\Service\IptcXmpWriter;
use Koh\ContentProvenance\Application\Service\StoredFileWriteBack;
use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Koh\ContentProvenance\Domain\Model\DetectedProvenance;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataUpdatedEvent;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * On every sys_file_metadata save: write the IPTC AI-provenance tags into
 * the underlying physical file via exiftool. EU AI Act Art. 50 hard
 * compliance layer (machine-readable).
 *
 * Listener fires on `AfterFileMetaDataUpdatedEvent` which TYPO3 emits
 * after both editor-driven saves and programmatic metadata-updates.
 *
 * The work happens on a writable copy, and the copy is written back through
 * FAL afterwards ({@see StoredFileWriteBack}) — without that step the tags
 * and the signature never reach the stored file. The write-back itself makes
 * FAL re-extract the file's metadata, which dispatches this event again; the
 * listener recognises that echo via `StoredFileWriteBack::isReplacing()` and
 * returns instead of signing the file a second time (and a third, …).
 *
 * Flipping a file back to Human is honoured only while the file carries no
 * C2PA manifest that proves AI involvement. A provenance chain cannot be
 * honestly taken back: the upstream manifest (or our own earlier one) stays
 * in the file as an ingredient whatever we append. Such a file is recorded
 * and signed as AI-assisted instead — the origin that remains true. The
 * origin is persisted with a direct table update, which dispatches no FAL
 * event and so cannot re-trigger this listener.
 */
#[AsEventListener('content-provenance/embed-ai-metadata-on-file-save')]
final class EmbedAiMetadataOnFileSave implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Stored statuses that only our own signing produces — and we sign only
     * files tagged as AI-generated or AI-assisted.
     */
    private const OWN_SIGNATURE_STATUSES = ['signed_self_anchored', 'signed_self_anchored_append', 'signed_trusted'];

    public function __construct(
        private readonly IptcXmpWriter $writer,
        private readonly ResourceFactory $resourceFactory,
        private readonly C2paManifestSigner $signer,
        private readonly StoredFileWriteBack $writeBack,
        private readonly ContentCredentialsReader $reader,
    ) {}

    public function __invoke(AfterFileMetaDataUpdatedEvent $event): void
    {
        $metadata = $event->getRecord();
        $fileUid = $event->getFileUid();
        if (0 === $fileUid) {
            return;
        }
        if ($this->writeBack->isReplacing($fileUid)) {
            // Our own write-back re-indexed the file — not an edit.
            return;
        }

        $originRaw = (string) ($metadata['tx_provenance_origin'] ?? '');
        $origin = AssetOrigin::tryFrom($originRaw) ?? AssetOrigin::Human;

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable $e) {
            $this->logger?->info('File not loadable for IPTC write', ['file_uid' => $fileUid]);

            return;
        }

        // writable-Copy kann fehlschlagen (Race, Permissions, etc.) — bei
        // IOException silent skip statt FE/BE-Crash. Phase 3 IPTC-Write fällt
        // dann für diesen Save aus; der TCA-Tag in der DB bleibt aber stehen
        // und kann via manuellem Re-Save oder CLI nachgeholt werden.
        try {
            $absolutePath = $file->getForLocalProcessing(true); // writable copy
        } catch (\Throwable $e) {
            $this->logger?->info('writable-copy failed for file — IPTC+Sign skipped', [
                'file_uid' => $fileUid,
                'error' => $e->getMessage(),
            ]);

            return;
        }
        if (!is_file($absolutePath)) {
            return;
        }

        $originOverridden = false;
        if (AssetOrigin::Human === $origin) {
            $storedStatus = (string) ($metadata['tx_provenance_c2pa_status'] ?? 'none');
            if (!$this->carriesAiManifest($absolutePath, $storedStatus)) {
                // Clear stale AI-tags if editor flipped back to Human.
                $this->writer->clearProvenance($absolutePath);
                $this->writeBack->replaceOriginal($file, $absolutePath);

                return;
            }
            // The manifest proving AI involvement stays in the file whatever
            // is appended, so "human" would contradict the file itself.
            $this->logger?->info('Origin "human" refused for a file with an AI manifest — recorded as ai_assisted', [
                'file_uid' => $fileUid,
                'c2pa_status' => $storedStatus,
            ]);
            $origin = AssetOrigin::AiAssisted;
            $originOverridden = true;
        }

        $aiSystem = (string) ($metadata['tx_provenance_ai_system'] ?? '');
        $aiPrompt = (string) ($metadata['tx_provenance_ai_prompt'] ?? '');

        // Step 1: IPTC XMP write (machine-readable disclosure layer — Art. 50 minimum)
        $this->writer->writeProvenance(
            $absolutePath,
            $origin,
            '' !== $aiSystem ? $aiSystem : null,
            '' !== $aiPrompt ? $aiPrompt : null,
        );

        // Step 2: C2PA Cryptographic Signing — opt-in via vorhandenes Cert.
        // Soft-fail-Semantik:
        //   - null returnt: Sign-Pipeline nicht konfiguriert → kein DB-Update
        //   - "failed" returnt: Pipeline aktiv aber crashed → DB-Update für Sichtbarkeit
        //   - "signed_*" returnt: Erfolg → DB-Update
        // Damit ist Phase 5 echtes opt-in: auf Setups ohne C2PA-Cert läuft
        // der Listener passiv weiter (Phase-3-IPTC bleibt aktiv).
        $newStatus = $this->signer->sign(
            $absolutePath,
            new DetectedProvenance(
                origin: $origin,
                aiSystem: '' !== $aiSystem ? $aiSystem : null,
                aiPrompt: '' !== $aiPrompt ? $aiPrompt : null,
                c2paStatus: 'none',
                signingChainIssuer: null,
            ),
        );

        // Step 3: the copy goes back into the storage — IPTC tags included,
        // even where no certificate is configured. The status describes the
        // stored file, so it is written only after the write-back: a signature
        // that stayed in the copy is a failed signing, not a successful one.
        $storedFileUpdated = $this->writeBack->replaceOriginal($file, $absolutePath);

        $values = [];
        if ($originOverridden) {
            $values['tx_provenance_origin'] = AssetOrigin::AiAssisted->value;
        }
        if (null !== $newStatus) {
            $values['tx_provenance_c2pa_status'] = $storedFileUpdated ? $newStatus : 'failed';
        }
        if ([] !== $values) {
            $this->persistMetadata($fileUid, $values);
        }
    }

    /**
     * Two signals, either of which is enough: a stored status that only our
     * own (AI-only) signing produces, or a manifest in the file itself whose
     * active claim c2patool reports as AI-generated or AI-assisted. The file
     * is checked because the stored status can be stale — `failed` after a
     * crashed append still sits on a file with an upstream AI manifest.
     */
    private function carriesAiManifest(string $absolutePath, string $storedStatus): bool
    {
        if (\in_array($storedStatus, self::OWN_SIGNATURE_STATUSES, true)) {
            return true;
        }
        $detected = $this->reader->read($absolutePath);

        return 'none' !== $detected->c2paStatus && AssetOrigin::Human !== $detected->origin;
    }

    /**
     * Written straight to the table rather than through MetaDataRepository:
     * that would dispatch AfterFileMetaDataUpdatedEvent and start this
     * listener over.
     *
     * @param array<string, string> $values
     */
    private function persistMetadata(int $fileUid, array $values): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $qb = $connectionPool->getQueryBuilderForTable('sys_file_metadata');
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
        if (!is_array($row)) {
            return;
        }
        $connectionPool
            ->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', [
                ...$values,
                'tstamp' => time(),
            ], ['uid' => (int) $row['uid']]);
    }
}
