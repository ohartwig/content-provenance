<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Contract;

use Koh\ContentProvenance\Domain\Model\Signature;

interface SignatureRepositoryInterface
{
    public function findByContent(string $table, int $uid, int $language): ?Signature;

    public function upsert(Signature $signature): void;

    public function revoke(string $table, int $uid, int $language, int $revokedBy, string $reason): void;
}
