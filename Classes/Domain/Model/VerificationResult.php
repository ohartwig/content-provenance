<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

final readonly class VerificationResult
{
    public function __construct(
        public bool $signed,
        public bool $signatureValid,
        public bool $contentIntact,
        public bool $revoked,
        public ?string $signedBy,
        public ?\DateTimeImmutable $signedAt,
        public ?string $keyId,
        public ?string $contentHash,
        public ?\DateTimeImmutable $revokedAt,
        public ?string $revokedReason,
    ) {}

    public function isFullyVerified(): bool
    {
        return $this->signed
            && $this->signatureValid
            && $this->contentIntact
            && !$this->revoked;
    }

    public static function unsigned(): self
    {
        return new self(
            signed: false,
            signatureValid: false,
            contentIntact: false,
            revoked: false,
            signedBy: null,
            signedAt: null,
            keyId: null,
            contentHash: null,
            revokedAt: null,
            revokedReason: null,
        );
    }

    public static function revoked(\DateTimeImmutable $at, string $reason): self
    {
        return new self(
            signed: true,
            signatureValid: false,
            contentIntact: false,
            revoked: true,
            signedBy: null,
            signedAt: null,
            keyId: null,
            contentHash: null,
            revokedAt: $at,
            revokedReason: $reason,
        );
    }
}
