<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Application\Service\ContentVerifier;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'provenance:verify-all',
    description: 'Verify all signed records',
)]
final class VerifyAllCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ContentVerifier $contentVerifier,
        private readonly SignedFieldsResolverInterface $signedFieldsResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Restrict to a specific table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Verify All Signatures');

        $tableFilter = $input->getOption('table');
        $tables = null !== $tableFilter ? [(string) $tableFilter] : ['pages', 'tt_content'];

        $verified = 0;
        $failed = 0;
        $unsigned = 0;

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

            // Known limitation: only default language (language=0) records are verified.
            // Multi-language support requires iterating sys_language records per table.
            foreach ($rows as $row) {
                $result = $this->contentVerifier->verify(
                    table: $table,
                    uid: (int) $row['uid'],
                    language: 0,
                );

                if (!$result->signed) {
                    ++$unsigned;
                    continue;
                }

                if ($result->isFullyVerified()) {
                    ++$verified;
                } else {
                    ++$failed;
                    $io->warning(sprintf(
                        '%s:%d — valid=%s, intact=%s, revoked=%s',
                        $table,
                        $row['uid'],
                        $result->signatureValid ? 'yes' : 'no',
                        $result->contentIntact ? 'yes' : 'no',
                        $result->revoked ? 'yes' : 'no',
                    ));
                }
            }
        }

        $io->table(
            ['Status', 'Count'],
            [
                ['Verified', (string) $verified],
                ['Failed', (string) $failed],
                ['Unsigned', (string) $unsigned],
            ],
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
