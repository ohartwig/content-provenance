<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Domain\Contract\AuditLogRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'provenance:export-audit',
    description: 'Export audit log as CSV',
)]
final class ExportAuditCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'Content table name');
        $this->addArgument('uid', InputArgument::REQUIRED, 'Content UID');
        $this->addOption('language', 'l', InputOption::VALUE_OPTIONAL, 'Language UID', '0');
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum entries', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = (string) $input->getArgument('table');
        $uid = (int) $input->getArgument('uid');
        $language = (int) $input->getOption('language');
        $limit = (int) $input->getOption('limit');

        if ('' === $table) {
            $output->writeln('<error>Table name must not be empty</error>');

            return Command::FAILURE;
        }
        if ($uid < 0) {
            $output->writeln('<error>UID must be a non-negative integer</error>');

            return Command::FAILURE;
        }
        if ($limit < 1) {
            $output->writeln('<error>Limit must be a positive integer</error>');

            return Command::FAILURE;
        }

        $entries = $this->auditLogRepository->findByContent(
            table: $table,
            uid: $uid,
            language: $language,
            limit: $limit,
        );

        $output->writeln('table,uid,language,action,performed_by,performed_by_name,performed_at,ai_generated,ai_provider,ai_model');

        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                '%s,%d,%d,%s,%d,%s,%s,%s,%s,%s',
                $entry->contentTable,
                $entry->contentUid,
                $entry->contentLanguage,
                $entry->action->value,
                $entry->performedBy,
                $this->escapeCsv($entry->performedByName),
                $entry->performedAt->format(\DateTimeInterface::ATOM),
                $entry->aiGenerated ? 'true' : 'false',
                $this->escapeCsv($entry->aiProvider ?? ''),
                $this->escapeCsv($entry->aiModel ?? ''),
            ));
        }

        return Command::SUCCESS;
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
