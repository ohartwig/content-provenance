<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Domain\Contract\SignatureRepositoryInterface;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'provenance:unsigned',
    description: 'Lists unsigned records for configured tables',
)]
final class UnsignedCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SignatureRepositoryInterface $signatureRepository,
        private readonly SignedFieldsResolverInterface $signedFieldsResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'table',
            't',
            InputOption::VALUE_OPTIONAL,
            'Restrict to a specific table',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Unsigned Records');

        $tableFilter = $input->getOption('table');
        $tables = null !== $tableFilter ? [(string) $tableFilter] : ['pages', 'tt_content'];

        $totalUnsigned = 0;

        foreach ($tables as $table) {
            $fields = $this->signedFieldsResolver->getFieldsForTable(table: $table);
            if ([] === $fields) {
                continue;
            }

            $connection = $this->connectionPool->getConnectionForTable($table);
            $queryBuilder = $connection->createQueryBuilder();
            $rows = $queryBuilder
                ->select('uid', 'pid')
                ->from($table)
                ->executeQuery()
                ->fetchAllAssociative();

            // Known limitation: only default language (language=0) records are checked.
            // Multi-language support requires iterating sys_language records per table.
            foreach ($rows as $row) {
                $signature = $this->signatureRepository->findByContent(
                    table: $table,
                    uid: (int) $row['uid'],
                    language: 0,
                );
                if (null === $signature) {
                    $io->writeln(sprintf('  %s:%d (pid=%d)', $table, $row['uid'], $row['pid']));
                    ++$totalUnsigned;
                }
            }
        }

        $io->info(sprintf('Found %d unsigned records', $totalUnsigned));

        return Command::SUCCESS;
    }
}
