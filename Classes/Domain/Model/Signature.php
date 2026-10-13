<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

final readonly class Signature
{
    public function __construct(
        public string $contentTable,
        public int $contentUid,
        public int $contentLanguage,
        public string $contentHash,
        public string $signature,
        public int $signedBy,
        public string $signedByName,
        public \DateTimeImmutable $signedAt,
        public string $keyId,
        public bool $revoked = false,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?int $revokedBy = null,
        public ?string $revokedReason = null,
    ) {}
}
