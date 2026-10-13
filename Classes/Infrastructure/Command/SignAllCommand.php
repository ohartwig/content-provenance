<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Application\Service\ContentSigner;
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
    name: 'provenance:sign-all',
    description: 'Batch sign all unsigned records',
)]
final class SignAllCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ContentSigner $contentSigner,
        private readonly SignatureRepositoryInterface $signatureRepository,
        private readonly SignedFieldsResolverInterface $signedFieldsResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Restrict to a specific table');
        $this->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'Backend user ID for signing', '0');
        $this->addOption('username', null, InputOption::VALUE_OPTIONAL, 'Backend user name', 'CLI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Batch Sign Records');

        $tableFilter = $input->getOption('table');
        $tables = null !== $tableFilter ? [(string) $tableFilter] : ['pages', 'tt_content'];
        $userId = (int) $input->getOption('user');
        $userName = (string) $input->getOption('username');

        $signed = 0;

        foreach ($tables as $table) {
            $fields = $this->signedFieldsResolver->getFieldsForTable(table: $table);
            if ([] === $fields) {
                continue;
            }

            $connection = $this->connectionPool->getConnectionForTable($table);
            $rows = $connection->createQueryBuilder()
                ->select('uid')
                ->from($table)
                ->executeQuery()
                ->fetchAllAssociative();

            // Known limitation: only default language (language=0) records are signed.
            // Multi-language support requires iterating sys_language records per table.
            foreach ($rows as $row) {
                $existing = $this->signatureRepository->findByContent(
                    table: $table,
                    uid: (int) $row['uid'],
                    language: 0,
                );
                if (null !== $existing && !$existing->revoked) {
                    continue;
                }

                $this->contentSigner->sign(
                    table: $table,
                    uid: (int) $row['uid'],
                    language: 0,
                    backendUserId: $userId,
                    backendUserName: $userName,
                );
                ++$signed;
                $io->writeln(sprintf('  Signed %s:%d', $table, $row['uid']));
            }
        }

        $io->success(sprintf('Signed %d records', $signed));

        return Command::SUCCESS;
    }
}
