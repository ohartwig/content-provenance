<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Koh\ContentProvenance\Domain\Contract\SignatureRepositoryInterface;
use Koh\ContentProvenance\Domain\Model\Signature;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DoctrineSignatureRepository implements SignatureRepositoryInterface
{
    private const TABLE_NAME = 'tx_provenance_signature';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByContent(string $table, int $uid, int $language): ?Signature
    {
        $connection = $this->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'content_table',
                    $queryBuilder->createNamedParameter($table),
                ),
                $queryBuilder->expr()->eq(
                    'content_uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'content_language',
                    $queryBuilder->createNamedParameter($language, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->hydrateSignature($row);
    }

    public function upsert(Signature $signature): void
    {
        $connection = $this->getConnection();

        $existing = $this->findByContent(
            table: $signature->contentTable,
            uid: $signature->contentUid,
            language: $signature->contentLanguage,
        );

        $data = [
            'content_table' => $signature->contentTable,
            'content_uid' => $signature->contentUid,
            'content_language' => $signature->contentLanguage,
            'content_hash' => $signature->contentHash,
            'signature' => $signature->signature,
            'signed_by' => $signature->signedBy,
            'signed_by_name' => $signature->signedByName,
            'signed_at' => $signature->signedAt->getTimestamp(),
            'key_id' => $signature->keyId,
            'revoked' => $signature->revoked ? 1 : 0,
            'revoked_at' => $signature->revokedAt?->getTimestamp() ?? 0,
            'revoked_by' => $signature->revokedBy ?? 0,
            'revoked_reason' => $signature->revokedReason ?? '',
        ];

        if (null !== $existing) {
            $connection->update(
                self::TABLE_NAME,
                $data,
                [
                    'content_table' => $signature->contentTable,
                    'content_uid' => $signature->contentUid,
                    'content_language' => $signature->contentLanguage,
                ],
            );
        } else {
            $connection->insert(self::TABLE_NAME, $data);
        }
    }

    public function revoke(string $table, int $uid, int $language, int $revokedBy, string $reason): void
    {
        $connection = $this->getConnection();

        $connection->update(
            self::TABLE_NAME,
            [
                'revoked' => 1,
                'revoked_at' => new \DateTimeImmutable()->getTimestamp(),
                'revoked_by' => $revokedBy,
                'revoked_reason' => $reason,
            ],
            [
                'content_table' => $table,
                'content_uid' => $uid,
                'content_language' => $language,
            ],
        );
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSignature(array $row): Signature
    {
        $revokedAt = (int) $row['revoked_at'] > 0
            ? new \DateTimeImmutable()->setTimestamp((int) $row['revoked_at'])
            : null;

        $revokedBy = (int) $row['revoked_by'] > 0 ? (int) $row['revoked_by'] : null;
        $revokedReason = '' !== $row['revoked_reason'] ? (string) $row['revoked_reason'] : null;

        return new Signature(
            contentTable: (string) $row['content_table'],
            contentUid: (int) $row['content_uid'],
            contentLanguage: (int) $row['content_language'],
            contentHash: (string) $row['content_hash'],
            signature: (string) $row['signature'],
            signedBy: (int) $row['signed_by'],
            signedByName: (string) $row['signed_by_name'],
            signedAt: new \DateTimeImmutable()->setTimestamp((int) $row['signed_at']),
            keyId: (string) $row['key_id'],
            revoked: (bool) $row['revoked'],
            revokedAt: $revokedAt,
            revokedBy: $revokedBy,
            revokedReason: $revokedReason,
        );
    }
}
