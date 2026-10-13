<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Model;

/**
 * Origin classification for a media asset (image, video, audio, PDF).
 *
 * Semantically distinct from `Koh\ContentIntelligence\Domain\Model\ContentOrigin`,
 * which classifies WHO performed an edit-operation (audit-trail). This enum
 * classifies HOW the underlying asset was created.
 *
 * Examples:
 *   - Editor uploads a stock photograph → AssetOrigin::Human
 *   - Editor uploads a DALL-E-3 image from ChatGPT → AssetOrigin::AiGenerated
 *   - Editor uploads an own photo and applies AI-based denoising in Lightroom
 *     (assistive function, semantics unchanged) → AssetOrigin::AiAssisted
 *
 * Value strings match the IPTC NewsCodes vocabulary so they can be passed
 * 1:1 to exiftool when writing DigitalSourceType:
 *   - Human → `humanEdits` (or absent)
 *   - AiGenerated → `trainedAlgorithmicMedia`
 *   - AiAssisted → `compositeWithTrainedAlgorithmicMedia`
 *
 * EU AI Act Article 50 (effective Aug 2026) requires the AiGenerated case
 * be machine-readable + detectable. AiAssisted is generally carved out
 * unless semantics are substantially altered.
 */
enum AssetOrigin: string
{
    case Human = 'human';
    case AiGenerated = 'ai_generated';
    case AiAssisted = 'ai_assisted';

    /**
     * IPTC DigitalSourceType URI from https://cv.iptc.org/newscodes/digitalsourcetype/.
     */
    public function toIptcDigitalSourceType(): ?string
    {
        return match ($this) {
            self::AiGenerated => 'https://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
            self::AiAssisted => 'https://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia',
            self::Human => null, // intentional: don't overwrite an existing tag with "human"
        };
    }

    /**
     * Inverse of toIptcDigitalSourceType for auto-detection from existing files.
     */
    public static function fromIptcDigitalSourceType(string $iptcValue): ?self
    {
        // Accept both http and https prefix and short-form
        $normalized = (string) preg_replace('#^https?://cv\.iptc\.org/newscodes/digitalsourcetype/#i', '', $iptcValue);

        return match ($normalized) {
            'trainedAlgorithmicMedia' => self::AiGenerated,
            'compositeWithTrainedAlgorithmicMedia' => self::AiAssisted,
            'humanEdits', 'digitalCapture', 'softwareImage', 'minorHumanEdits' => self::Human,
            default => null,
        };
    }

    /**
     * Whether the EU AI Act Article 50 marking obligation applies.
     * AiGenerated → always. AiAssisted → depends on semantic significance
     * (editor-discretion via a separate TCA flag).
     */
    public function requiresMandatoryDisclosure(): bool
    {
        return self::AiGenerated === $this;
    }
}
