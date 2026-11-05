<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Domain\Contract\AuditLogRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for tx_provenance_audit_log.
 *
 * The audit trail records who changed which signed field when — names, not
 * values — and is kept for a bounded time. Run this daily (scheduler task
 * "Execute console commands" or a CronJob); without a run the table grows
 * without bound.
 */
#[AsCommand(
    name: 'provenance:prune-audit',
    description: 'Delete audit-trail entries older than the retention period',
)]
final class PruneAuditCommand extends Command
{
    public const DEFAULT_RETENTION_DAYS = 730;

    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Retention in days; older entries are deleted',
            (string) self::DEFAULT_RETENTION_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days');
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1) {
            $io->error('--days must be a positive whole number');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', (int) $days));
        $deleted = $this->auditLogRepository->deleteOlderThan(cutoff: $cutoff);

        $io->success(sprintf(
            'Deleted %d audit entr%s older than %s',
            $deleted,
            1 === $deleted ? 'y' : 'ies',
            $cutoff->format(\DATE_ATOM),
        ));

        return Command::SUCCESS;
    }
}
