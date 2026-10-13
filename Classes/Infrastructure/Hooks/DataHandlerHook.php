<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Hooks;

use Koh\ContentProvenance\Application\Service\SignatureInvalidator;
use Koh\ContentProvenance\Domain\Contract\AuditLoggerInterface;
use Koh\ContentProvenance\Domain\Enum\AuditAction;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final readonly class DataHandlerHook
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private SignatureInvalidator $signatureInvalidator,
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

        $uid = is_numeric($id) ? (int) $id : ($dataHandler->substNEWwithIDs[$id] ?? null);
        if (null === $uid) {
            return;
        }

        $language = (int) ($fieldArray['sys_language_uid'] ?? 0);

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
            fieldChanges: $fieldArray,
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
}
