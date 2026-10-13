<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\EventListener;

use Koh\ContentProvenance\Application\Service\C2paCertificateProvider;
use Koh\ContentProvenance\Application\Service\C2paManifestSigner;
use Koh\ContentProvenance\Application\Service\StoredFileWriteBack;
use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Koh\ContentProvenance\Domain\Model\DetectedProvenance;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Re-signs TYPO3-generated derivative files (Thumbnails, Resizes, Crops) so
 * the C2PA manifest survives the FAL image-processing pipeline.
 *
 * Without this listener every `f:image width=640c` etc. silently strips the
 * provenance manifest — Frontend-Bilder hätten dann keine verifizierbare
 * Sign-Chain mehr.
 *
 * Logic:
 *   1. Original File AI-getaggt? Sonst skip (kein Sign nötig).
 *   2. Lookup `sys_file_metadata.tx_provenance_*` für das Original.
 *   3. C2paManifestSigner::sign() auf einer beschreibbaren Kopie des
 *      ProcessedFile, mit dem Original als Parent-Ingredient und der Action
 *      `c2pa.edited`. Die Bildverarbeitung entfernt jedes Manifest; ohne den
 *      Parent begänne die Kette des Derivats bei unserer Signatur, und die
 *      Generierung durch OpenAI dahinter wäre für einen Prüfer unsichtbar.
 *   4. Die signierte Kopie geht via `ProcessedFile::updateWithLocalFile()`
 *      zurück in den Processing-Folder ({@see StoredFileWriteBack}). Ohne
 *      diesen Schritt blieb die Signatur in der Temp-Kopie und das
 *      ausgelieferte Derivat unsigniert.
 *   5. Soft-fail: bei Sign-Crash bleibt das Thumbnail unsigniert + Log-Warning.
 *
 * Signiert wird nur ein Derivat, das in diesem Request frisch erzeugt wurde.
 * Das Event feuert bei jedem `processFile()`-Aufruf, also auch für längst
 * gecachte Derivate — ein Re-Sign dort würde bei jedem Seitenaufruf die Datei
 * neu schreiben und die Manifest-Kette um ein Glied verlängern. Ändert sich das
 * Original (z. B. durch dessen Signierung), veraltet das Derivat über die sha1
 * des Originals, wird neu erzeugt und dabei signiert.
 *
 * Kein Mime-Filter: WebP, AVIF, JPEG und PNG laufen gleichermaßen durch; was
 * c2patool nicht kann, endet als geloggter Soft-fail.
 */
#[AsEventListener('content-provenance/resign-derivative-files')]
final class ReSignDerivativeFiles implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly C2paManifestSigner $signer,
        private readonly ConnectionPool $connectionPool,
        private readonly C2paCertificateProvider $certProvider,
        private readonly StoredFileWriteBack $writeBack,
    ) {}

    public function __invoke(AfterFileProcessingEvent $event): void
    {
        $processed = $event->getProcessedFile();
        $original = $event->getFile();
        if (!$original instanceof File) {
            return;
        }
        // Skip if no derivative was generated in this request (cached, or
        // processing returned the original unchanged).
        if (!$this->isDerivative($processed)) {
            return;
        }

        // Early-Exit wenn Sign-Pipeline nicht konfiguriert: kein Cert → keine
        // writable-Copy + keinen Signer-Call. Spart Filesystem-Operationen und
        // verhindert dass getForLocalProcessing(true) für AI-tagged Files
        // umsonst Temp-Files erstellt.
        if (null === $this->certProvider->resolve()) {
            return;
        }

        $provenance = $this->fetchProvenance($original->getUid());
        if (null === $provenance || AssetOrigin::Human === $provenance->origin) {
            return;
        }

        // writable-Copy in /app/var/transient/ kann fehlschlagen:
        // - Source-ProcessedFile noch nicht generiert (Race-Condition)
        // - Target-Dir nicht writable
        // - Concurrent-Worker hat schon eine Copy gemacht und gelöscht
        // → defensiv: bei IOException silent skip + info-log statt FE/BE-Crash.
        try {
            $derivativePath = $processed->getForLocalProcessing(true);
        } catch (\Throwable $e) {
            $this->logger?->info('writable-copy failed for derivative — sign skipped', [
                'file_uid' => $original->getUid(),
                'task_type' => $event->getTaskType(),
                'error' => $e->getMessage(),
            ]);

            return;
        }
        if (!is_file($derivativePath)) {
            return;
        }

        // Sign returns the new c2pa_status — for derivatives we only log,
        // not persist (no DB row per ProcessedFile).
        // null = Pipeline nicht konfiguriert → silent skip (kein Log-Spam).
        // 'failed'/'stripped' = aktiv aber Crash → Log-Warning.
        $status = $this->signer->sign($derivativePath, $provenance, $this->originalPath($original));
        if (null === $status || 'failed' === $status || 'stripped' === $status) {
            $this->writeBack->discard($derivativePath);
        } elseif (!$this->writeBack->replaceDerivative($processed, $derivativePath)) {
            $status = 'failed';
        }
        if ('failed' === $status || 'stripped' === $status) {
            $this->logger?->warning('Derivative could not be signed', [
                'file_uid' => $original->getUid(),
                'task_type' => $event->getTaskType(),
                'status' => $status,
            ]);
        }
    }

    /**
     * The original, read-only: the local driver hands out the stored file
     * itself, no copy. Without it the derivative is still signed, only
     * without the chain.
     */
    private function originalPath(File $original): ?string
    {
        try {
            return $original->getForLocalProcessing(false);
        } catch (\Throwable $e) {
            $this->logger?->info('original not readable — derivative signed without its parent', [
                'file_uid' => $original->getUid(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isDerivative(ProcessedFile $processed): bool
    {
        // isUpdated() ist nur true, wenn ein Processor das File in diesem
        // Request erzeugt hat — ein aus sys_file_processedfile geladenes,
        // gecachtes Derivat hat es nicht gesetzt. usesOriginalFile() fängt
        // Identity-Processing ab (setUsesOriginalFile() setzt ebenfalls
        // updated) und das deferred BE-Processing ohne Datei (leerer
        // Identifier): dort gibt es nichts, das signiert werden könnte, und
        // updateWithLocalFile() würde werfen.
        return $processed->isUpdated() && !$processed->usesOriginalFile();
    }

    private function fetchProvenance(int $fileUid): ?DetectedProvenance
    {
        if (0 === $fileUid) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select(
            'tx_provenance_origin',
            'tx_provenance_ai_system',
            'tx_provenance_ai_prompt',
            'tx_provenance_c2pa_status',
        )
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        $origin = AssetOrigin::tryFrom((string) ($row['tx_provenance_origin'] ?? ''));
        if (null === $origin) {
            return null;
        }
        $aiSystem = (string) ($row['tx_provenance_ai_system'] ?? '');
        $aiPrompt = (string) ($row['tx_provenance_ai_prompt'] ?? '');

        return new DetectedProvenance(
            origin: $origin,
            aiSystem: '' !== $aiSystem ? $aiSystem : null,
            aiPrompt: '' !== $aiPrompt ? $aiPrompt : null,
            c2paStatus: (string) ($row['tx_provenance_c2pa_status'] ?? 'none'),
            signingChainIssuer: null,
        );
    }
}
