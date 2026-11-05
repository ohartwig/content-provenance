<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Hooks;

use Doctrine\DBAL\ParameterType;
use Koh\ContentProvenance\Application\Service\SignatureInvalidator;
use Koh\ContentProvenance\Domain\Contract\AuditLoggerInterface;
use Koh\ContentProvenance\Domain\Contract\SignedFieldsResolverInterface;
use Koh\ContentProvenance\Domain\Enum\AuditAction;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Audit trail and signature invalidation for DataHandler saves.
 *
 * Registered as processDatamapClass in ext_localconf.php (and public in
 * Services.yaml, so the DataHandler gets it from the container).
 *
 * Only tables with signed fields are looked at, and only when a signed field
 * is part of the save. The audit row names the changed signed fields; it never
 * stores their values — the log has a retention (provenance:prune-audit) but
 * must still not become a second copy of content or, for other tables,
 * personal data such as password hashes.
 */
final readonly class DataHandlerHook
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private SignatureInvalidator $signatureInvalidator,
        private SignedFieldsResolverInterface $signedFieldsResolver,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed>|string $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array|string $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if (!is_array($fieldArray)) {
            return;
        }

        $signedFields = $this->signedFieldsResolver->getFieldsForTable(table: $table);
        if ([] === $signedFields) {
            return;
        }

        $changedSignedFields = array_values(array_intersect($signedFields, array_keys($fieldArray)));
        if ([] === $changedSignedFields) {
            return;
        }

        $uid = is_numeric($id) ? (int) $id : ($dataHandler->substNEWwithIDs[$id] ?? null);
        if (null === $uid) {
            return;
        }

        $language = $this->languageOf(table: $table, uid: $uid, fieldArray: $fieldArray);

        $action = 'new' === $status ? AuditAction::CREATE : AuditAction::UPDATE;

        $backendUser = $dataHandler->BE_USER;
        $backendUserId = (int) ($backendUser->user['uid'] ?? 0);
        $backendUserName = (string) ($backendUser->user['username'] ?? 'unknown');

        $this->auditLogger->log(
            table: $table,
            uid: $uid,
            language: $language,
            action: $action,
            performedBy: $backendUserId,
            performedByName: $backendUserName,
            fieldChanges: ['fields' => $changedSignedFields],
        );

        if ('new' !== $status) {
            $this->signatureInvalidator->checkAndInvalidate(
                table: $table,
                uid: $uid,
                language: $language,
                changedFields: $fieldArray,
            );
        }
    }

    /**
     * The record's own language. An update's field array rarely carries the
     * language field, so it is read back from the saved row; defaulting to 0
     * would revoke the default language's signature on a translation's edit.
     *
     * @param array<string, mixed> $fieldArray
     */
    private function languageOf(string $table, int $uid, array $fieldArray): int
    {
        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? null;
        if (!is_string($languageField) || '' === $languageField) {
            return 0;
        }

        if (isset($fieldArray[$languageField]) && is_numeric($fieldArray[$languageField])) {
            return (int) $fieldArray[$languageField];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $language = $queryBuilder
            ->select($languageField)
            ->from($table)
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchOne();

        return false === $language ? 0 : (int) $language;
    }
}
