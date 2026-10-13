<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Process\Process;

/**
 * Writes IPTC PhotoMetadata 2025.1 / IPTC Video Metadata Hub AI-provenance
 * tags into a media file via `exiftool`. EU AI Act Article 50 compliance:
 * machine-readable + detectable AI-content marker.
 *
 * Tag mapping (delegated to exiftool which picks the right metadata
 * container per file-type — XMP for image/video, BWF/ID3 for audio):
 *
 *   - Iptc4xmpExt:DigitalSourceType = IPTC NewsCodes URI (per AssetOrigin)
 *   - Iptc4xmpExt:AISystemUsed      = free-text model name (when given)
 *   - Iptc4xmpExt:AIPromptInformation = free-text prompt (when given)
 *
 * Defensive: if exiftool is missing or the file format is unsupported,
 * write fails silently and is logged — IPTC is "best-effort" disclosure,
 * C2PA signing in Layer 5 is the hard guarantee.
 *
 * Idempotent: writing the same value twice is a no-op for exiftool. Safe
 * to call on every File-Save without checking previous state.
 */
final class IptcXmpWriter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const EXIFTOOL_TIMEOUT = 10.0;

    public function __construct(
        private readonly string $exifToolPath = 'exiftool',
    ) {}

    /**
     * Returns true on successful write, false otherwise (logged).
     */
    public function writeProvenance(
        string $absolutePath,
        AssetOrigin $origin,
        ?string $aiSystem = null,
        ?string $aiPrompt = null,
    ): bool {
        if (!is_file($absolutePath) || !is_writable($absolutePath)) {
            return false;
        }

        $digitalSourceType = $origin->toIptcDigitalSourceType();
        if (null === $digitalSourceType) {
            // Human origin → don't overwrite existing tags. Caller decides
            // whether to clear via `clearProvenance()` separately.
            return true;
        }

        $args = [
            $this->exifToolPath,
            '-overwrite_original',
            '-XMP-iptcExt:DigitalSourceType=' . $digitalSourceType,
        ];
        if (null !== $aiSystem && '' !== $aiSystem) {
            $args[] = '-XMP-iptcExt:AISystemUsed=' . $aiSystem;
        }
        if (null !== $aiPrompt && '' !== $aiPrompt) {
            $args[] = '-XMP-iptcExt:AIPromptInformation=' . $aiPrompt;
        }
        $args[] = $absolutePath;

        return $this->runExiftool($args, $absolutePath);
    }

    /**
     * Removes our three provenance tags. Used when an editor flips an
     * existing AI-tagged file back to "Human" — we shouldn't leave the
     * stale machine-readable markers in the file.
     */
    public function clearProvenance(string $absolutePath): bool
    {
        if (!is_file($absolutePath) || !is_writable($absolutePath)) {
            return false;
        }
        $args = [
            $this->exifToolPath,
            '-overwrite_original',
            '-XMP-iptcExt:DigitalSourceType=',
            '-XMP-iptcExt:AISystemUsed=',
            '-XMP-iptcExt:AIPromptInformation=',
            $absolutePath,
        ];

        return $this->runExiftool($args, $absolutePath);
    }

    /**
     * @param list<string> $args
     */
    private function runExiftool(array $args, string $absolutePath): bool
    {
        $process = new Process($args);
        $process->setTimeout(self::EXIFTOOL_TIMEOUT);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger?->warning('exiftool unavailable for IPTC write', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
        if (!$process->isSuccessful()) {
            $this->logger?->warning('exiftool write failed', [
                'path' => $absolutePath,
                'stderr' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ]);

            return false;
        }

        return true;
    }
}
