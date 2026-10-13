<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

/**
 * Result of reading C2PA + IPTC metadata from a media file at upload time.
 *
 * All fields are optional except `origin` — a file without any AI markers
 * is `AssetOrigin::Human` by default.
 *
 * `c2paStatus` distinguishes the three relevant sign-states so editors and
 * downstream signers can decide whether to append to an existing chain
 * (signed_external — e.g. OpenAI signed it) or sign fresh.
 */
final readonly class DetectedProvenance
{
    public function __construct(
        public AssetOrigin $origin,
        public ?string $aiSystem,
        public ?string $aiPrompt,
        public string $c2paStatus, // 'none' | 'signed_own' | 'signed_external'
        public ?string $signingChainIssuer = null, // e.g. "Adobe", "OpenAI", "Moselwal" — for editor visibility
    ) {}

    public static function human(): self
    {
        return new self(AssetOrigin::Human, null, null, 'none', null);
    }

    public function isAiTagged(): bool
    {
        return AssetOrigin::Human !== $this->origin;
    }

    /**
     * @return array{
     *     origin: string,
     *     ai_system: string|null,
     *     ai_prompt: string|null,
     *     c2pa_status: string,
     *     signing_chain_issuer: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin->value,
            'ai_system' => $this->aiSystem,
            'ai_prompt' => $this->aiPrompt,
            'c2pa_status' => $this->c2paStatus,
            'signing_chain_issuer' => $this->signingChainIssuer,
        ];
    }
}
