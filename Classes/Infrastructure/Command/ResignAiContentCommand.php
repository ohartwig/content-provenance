<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Application\Service\C2paManifestSigner;
use Koh\ContentProvenance\Application\Service\DerivativeRegenerator;
use Koh\ContentProvenance\Application\Service\StoredFileWriteBack;
use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Koh\ContentProvenance\Domain\Model\DetectedProvenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Backfill-Command für die opt-in Migration existierender AI-getaggter Files
 * auf die C2PA-Sign-Chain.
 *
 *   vendor/bin/typo3 provenance:resign                # alle AI-tagged Files, idempotent
 *   vendor/bin/typo3 provenance:resign --dry-run      # nur listen, nichts schreiben
 *   vendor/bin/typo3 provenance:resign --file-uid=6   # einzelnes File
 *   vendor/bin/typo3 provenance:resign --since=YYYY-MM-DD
 *   vendor/bin/typo3 provenance:resign --force        # auch signed_own neu signieren
 *   vendor/bin/typo3 provenance:resign --derivatives-only  # signierte Originale bleiben, ihre Derivate werden neu gebaut
 *
 * Idempotent: skipped `signed_own_fresh` / `signed_own_append` Files es sei
 * denn `--force`. Foreign-signed Files (OpenAI etc.) werden im Append-Mode
 * neu signiert — die fremde Sign-Chain bleibt erhalten.
 *
 * Replacing an original deletes its derivatives. The command rebuilds the
 * ones that existed before, and they are signed as they are built
 * ({@see DerivativeRegenerator}).
 */
#[AsCommand(
    name: 'provenance:resign',
    description: 'Re-signs existing AI-tagged FAL files with the C2PA leaf cert of the site.',
)]
final class ResignAiContentCommand extends Command
{
    private const SIGNED_STATUSES = ['signed_self_anchored', 'signed_self_anchored_append', 'signed_trusted'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly C2paManifestSigner $signer,
        private readonly ResourceFactory $resourceFactory,
        private readonly StoredFileWriteBack $writeBack,
        private readonly DerivativeRegenerator $derivatives,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Listet was signiert würde, ohne zu schreiben')
            ->addOption('file-uid', null, InputOption::VALUE_REQUIRED, 'Nur ein einzelnes sys_file.uid signieren')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Nur Files mit creation_date >= YYYY-MM-DD')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Auch bereits signed_own Files neu signieren')
            ->addOption('derivatives-only', null, InputOption::VALUE_NONE, 'Leave already signed originals as they are and rebuild their derivatives');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $fileUidOption = $input->getOption('file-uid');
        $fileUidFilter = is_string($fileUidOption) ? (int) $fileUidOption : null;
        $since = $input->getOption('since');

        if (true === $input->getOption('derivatives-only')) {
            return $this->rebuildDerivatives($io, $dryRun, $fileUidFilter);
        }

        $candidates = $this->fetchCandidates(
            $fileUidFilter,
            is_string($since) ? $since : null,
            $force,
        );

        if ([] === $candidates) {
            $io->success('Keine AI-getaggten Files zu signieren.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Re-Sign %d AI-getaggte File(s)%s', count($candidates), $dryRun ? ' (dry-run)' : ''));

        $signed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $row) {
            $fileUid = (int) $row['file'];
            $currentStatus = (string) $row['tx_provenance_c2pa_status'];
            $origin = AssetOrigin::tryFrom((string) $row['tx_provenance_origin']) ?? AssetOrigin::Human;

            $label = sprintf('uid=%d origin=%s status=%s', $fileUid, $origin->value, $currentStatus);
            if ($dryRun) {
                $io->writeln('would sign: ' . $label);
                continue;
            }

            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable $e) {
                $io->writeln('skip (file not loadable): ' . $label);
                ++$skipped;
                continue;
            }

            // A writable copy, never the stored file — it has to be written
            // back below, or the signature is lost with the copy.
            $absolutePath = $file->getForLocalProcessing(true);
            if (!is_file($absolutePath)) {
                $io->writeln('skip (path missing): ' . $label);
                ++$skipped;
                continue;
            }

            $provenance = new DetectedProvenance(
                origin: $origin,
                aiSystem: ($row['tx_provenance_ai_system'] ?? '') !== '' ? (string) $row['tx_provenance_ai_system'] : null,
                aiPrompt: ($row['tx_provenance_ai_prompt'] ?? '') !== '' ? (string) $row['tx_provenance_ai_prompt'] : null,
                c2paStatus: $currentStatus,
                signingChainIssuer: null,
            );

            $newStatus = $this->signer->sign($absolutePath, $provenance);
            if (null === $newStatus) {
                $this->writeBack->discard($absolutePath);
                $io->writeln('skip (Sign-Pipeline nicht konfiguriert): ' . $label);
                ++$skipped;
                continue;
            }
            if ('failed' === $newStatus || 'stripped' === $newStatus) {
                $this->writeBack->discard($absolutePath);
                $io->writeln('failed: ' . $label);
                ++$failed;
                $this->updateStatus($fileUid, $newStatus);
                continue;
            }
            // Read before the replace: the replace deletes them.
            $derivatives = $this->derivatives->snapshot($file);
            // The status describes the stored file, so it follows the
            // write-back, not the signer.
            if (!$this->writeBack->replaceOriginal($file, $absolutePath)) {
                $io->writeln('failed (write-back): ' . $label);
                ++$failed;
                $this->updateStatus($fileUid, 'failed');
                continue;
            }

            $this->updateStatus($fileUid, $newStatus);
            $rebuilt = $this->derivatives->regenerate($file, $derivatives);
            $io->writeln(sprintf(
                'signed (%s): %s derivatives=%d%s',
                $newStatus,
                $label,
                $rebuilt['regenerated'],
                $rebuilt['failed'] > 0 ? sprintf(' (failed=%d)', $rebuilt['failed']) : '',
            ));
            ++$signed;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry-run: %d Kandidaten gefunden, kein Write erfolgt.', count($candidates)));
        } else {
            $io->success(sprintf('Done. signed=%d skipped=%d failed=%d', $signed, $skipped, $failed));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * For originals that are already signed: a second signature on them would
     * only lengthen their chain, but their derivatives may predate the parent
     * link. Only the derivatives are rebuilt.
     */
    private function rebuildDerivatives(SymfonyStyle $io, bool $dryRun, ?int $fileUidFilter): int
    {
        $rows = array_filter(
            $this->fetchCandidates($fileUidFilter, null, true),
            static fn(array $row): bool => in_array((string) $row['tx_provenance_c2pa_status'], self::SIGNED_STATUSES, true),
        );
        $io->title(sprintf('Rebuild the derivatives of %d signed file(s)%s', count($rows), $dryRun ? ' (dry-run)' : ''));

        $failed = 0;
        foreach ($rows as $row) {
            $fileUid = (int) $row['file'];
            if ($dryRun) {
                $io->writeln('would rebuild: uid=' . $fileUid);
                continue;
            }
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable) {
                $io->writeln('skip (file not loadable): uid=' . $fileUid);
                continue;
            }
            $rebuilt = $this->derivatives->rebuild($file);
            $failed += $rebuilt['failed'];
            $io->writeln(sprintf('rebuilt: uid=%d derivatives=%d failed=%d', $fileUid, $rebuilt['regenerated'], $rebuilt['failed']));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCandidates(?int $fileUidFilter, ?string $since, bool $force): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();

        $qb->select(
            'file',
            'tx_provenance_origin',
            'tx_provenance_ai_system',
            'tx_provenance_ai_prompt',
            'tx_provenance_c2pa_status',
        )
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->in('tx_provenance_origin', $qb->createNamedParameter(
                    ['ai_generated', 'ai_assisted'],
                    \Doctrine\DBAL\ArrayParameterType::STRING,
                )),
            );

        if (null !== $fileUidFilter) {
            $qb->andWhere($qb->expr()->eq('file', $qb->createNamedParameter($fileUidFilter, \Doctrine\DBAL\ParameterType::INTEGER)));
        }

        if (!$force) {
            // Skip Files die schon erfolgreich signed sind. `failed` + `stripped` werden
            // re-versucht. `signed_trusted` ist Phase-5b-Future-State (Trust-List-CA).
            $qb->andWhere($qb->expr()->notIn('tx_provenance_c2pa_status', $qb->createNamedParameter(
                self::SIGNED_STATUSES,
                \Doctrine\DBAL\ArrayParameterType::STRING,
            )));
        }

        if (null !== $since && '' !== $since) {
            $ts = strtotime($since);
            if (false !== $ts) {
                // sys_file.creation_date filter requires join
                $qb->innerJoin(
                    'sys_file_metadata',
                    'sys_file',
                    'sf',
                    $qb->expr()->eq('sf.uid', $qb->quoteIdentifier('sys_file_metadata.file')),
                );
                $qb->andWhere(
                    $qb->expr()->gte('sf.creation_date', $qb->createNamedParameter($ts, \Doctrine\DBAL\ParameterType::INTEGER)),
                );
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    private function updateStatus(int $fileUid, string $status): void
    {
        $this->connectionPool
            ->getConnectionForTable('sys_file_metadata')
            ->update(
                'sys_file_metadata',
                ['tx_provenance_c2pa_status' => $status, 'tstamp' => time()],
                ['file' => $fileUid, 'sys_language_uid' => 0],
            );
    }
}
