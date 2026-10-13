<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

final readonly class KeyPair
{
    public function __construct(
        public string $keyId,
        public string $publicKey,
        public string $secretKey,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}
}
