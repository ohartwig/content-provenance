<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Tests\Functional;

use Koh\ContentProvenance\Infrastructure\Command\PruneAuditCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AUD-025-F01: the audit trail has a retention.
 */
final class PruneAuditCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/content-provenance'];

    #[Test]
    public function entriesOlderThanTheRetentionAreDeletedAndNewerOnesKept(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AuditRetention.csv');

        $tester = new CommandTester($this->get(PruneAuditCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '30']));

        $uids = $this->getConnectionPool()->getConnectionForTable('tx_provenance_audit_log')
            ->select(['uid'], 'tx_provenance_audit_log')
            ->fetchFirstColumn();
        self::assertSame([2], array_map(intval(...), $uids));
    }

    #[Test]
    public function aRetentionBelowOneDayIsRejected(): void
    {
        $tester = new CommandTester($this->get(PruneAuditCommand::class));
        self::assertSame(Command::INVALID, $tester->execute(['--days' => '0']));
    }
}
