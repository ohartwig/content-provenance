<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AUD-025-F01: the audit-trail hook must run on every DataHandler save of a
 * signed table, revoke the edited record's own signature, and stay away from
 * every other table.
 *
 * Before the fix the hook was never registered: the first test found no
 * audit row and an unrevoked signature.
 */
final class DataHandlerHookTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/content-provenance'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AuditTrail.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function editingASignedFieldOfASignedPageWritesOneAuditRowAndRevokesItsSignature(): void
    {
        $this->save('pages', 2, ['title' => 'Changed title']);

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame('pages', $rows[0]['content_table']);
        self::assertSame(2, (int) $rows[0]['content_uid']);
        self::assertSame('update', $rows[0]['action']);
        self::assertSame('admin', $rows[0]['performed_by_name']);

        self::assertSame(1, $this->revoked('pages', 2, 0));
        self::assertSame(0, $this->revoked('pages', 3, 1), 'the translation is untouched');
    }

    #[Test]
    public function theAuditRowNamesTheChangedSignedFieldsAndCarriesNoValues(): void
    {
        $this->save('pages', 2, ['title' => 'Secret new title', 'nav_title' => 'nav']);

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        $changes = json_decode((string) $rows[0]['field_changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['fields' => ['title']], $changes);
        self::assertStringNotContainsString('Secret new title', (string) $rows[0]['field_changes']);
    }

    #[Test]
    public function editingATranslationRevokesTheTranslationsSignatureNotTheDefaultLanguages(): void
    {
        $this->save('pages', 3, ['title' => 'Geaenderter Titel']);

        self::assertSame(1, $this->revoked('pages', 3, 1));
        self::assertSame(0, $this->revoked('pages', 2, 0));
        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['content_language']);
    }

    #[Test]
    public function savingABackendUserWritesNoAuditRow(): void
    {
        $this->save('be_users', 2, ['realName' => 'Editor', 'password' => 'aVeryLongPassword-123!']);

        self::assertSame([], $this->auditRows());
    }

    #[Test]
    public function changingOnlyUnsignedFieldsOfASignedTableWritesNoAuditRowAndKeepsTheSignature(): void
    {
        $this->save('pages', 2, ['nav_title' => 'Navigation only']);

        self::assertSame([], $this->auditRows());
        self::assertSame(0, $this->revoked('pages', 2, 0));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(string $table, int $uid, array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler errors');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(): array
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_provenance_audit_log')
            ->select(['*'], 'tx_provenance_audit_log', [], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
    }

    private function revoked(string $table, int $uid, int $language): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable('tx_provenance_signature')
            ->select(['revoked'], 'tx_provenance_signature', [
                'content_table' => $table,
                'content_uid' => $uid,
                'content_language' => $language,
            ])
            ->fetchOne();
    }
}
