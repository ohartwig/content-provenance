<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Koh\ContentProvenance\Domain\Model\DetectedProvenance;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Process\Process;

/**
 * Reads Content Credentials (C2PA manifests + IPTC XMP) from a file on disk
 * and synthesizes a {@see DetectedProvenance} DTO.
 *
 * Reading-Strategy (priority order):
 *   1. C2PA manifest (via `c2patool` subprocess) — cryptographically verifiable
 *   2. IPTC XMP `DigitalSourceType` (via `exiftool` subprocess) — fallback
 *   3. Default: {@see AssetOrigin::Human}
 *
 * Both binaries are OPTIONAL at runtime. If a binary is missing we log a
 * warning once and return `Human` — auto-detection is best-effort, editor
 * can still tag manually.
 *
 * Known external sign-chain issuers we recognise for the "signed_external"
 * status (and a friendlier label):
 *   - "OpenAI" / "ChatGPT" → ChatGPT/DALL-E signed
 *   - "Adobe" → Photoshop/Firefly signed
 *   - "Microsoft" → Bing Image Creator / Copilot signed
 */
final class ContentCredentialsReader implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const C2PATOOL_TIMEOUT = 8.0;
    private const EXIFTOOL_TIMEOUT = 5.0;

    public function __construct(
        private readonly string $c2paToolPath = 'c2patool',
        private readonly string $exifToolPath = 'exiftool',
    ) {}

    /**
     * Reads provenance from the file at $absolutePath. Returns Human-default
     * if the file doesn't carry any recognisable markers.
     */
    public function read(string $absolutePath): DetectedProvenance
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return DetectedProvenance::human();
        }

        $c2pa = $this->readC2paManifest($absolutePath);
        if (null !== $c2pa) {
            return $c2pa;
        }

        $iptc = $this->readIptcXmp($absolutePath);
        if (null !== $iptc) {
            return $iptc;
        }

        return DetectedProvenance::human();
    }

    /**
     * @return DetectedProvenance|null null if no manifest or c2patool unavailable
     */
    private function readC2paManifest(string $absolutePath): ?DetectedProvenance
    {
        // c2patool >= 0.26 gibt JSON von Haus aus aus — kein `--json` Flag
        // (das war v0.21-Syntax und wird mit `unexpected argument '--json'`
        // abgewiesen, was wiederum alle Detection-Calls killte).
        $process = new Process([$this->c2paToolPath, $absolutePath]);
        $process->setTimeout(self::C2PATOOL_TIMEOUT);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger?->info('c2patool unavailable — skipping C2PA read', ['error' => $e->getMessage()]);

            return null;
        }

        $json = trim($process->getOutput());
        if ('' === $json) {
            // Kein Manifest oder Datei-Format unsupported — c2patool schreibt in
            // dem Fall "Error: No claim found" nach stderr und liefert exit 1.
            // Keine Fehlerbedingung für uns.
            return null;
        }
        // Falls Validation-Warnings (z.B. signingCredential.untrusted) ein
        // non-zero exit produzieren, das Manifest aber trotzdem in stdout liegt
        // — wir parsen es, weil "untrusted" für unsere Detection-Logik egal ist
        // (Trust-Chain ist Sache der C2PA-Validator-UIs, nicht der
        // Origin-Klassifikation).
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger?->warning('c2patool returned non-JSON output', ['path' => $absolutePath]);

            return null;
        }
        if (!is_array($manifest)) {
            return null;
        }

        return $this->parseC2paManifest($manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function parseC2paManifest(array $manifest): ?DetectedProvenance
    {
        $activeManifest = $manifest['active_manifest'] ?? null;
        $manifests = $manifest['manifests'] ?? [];
        if (!is_string($activeManifest) || !is_array($manifests) || !isset($manifests[$activeManifest])) {
            return null;
        }
        $active = $manifests[$activeManifest];
        if (!is_array($active)) {
            return null;
        }

        // C2PA 2.x: claim_generator_info ist ein Array von {name, version, ...}.
        // C2PA 1.x: claim_generator ist ein einzelner String "ChatGPT/1.0 (OpenAI)".
        // Beide unterstützen, weil je nach Signer-Tool das eine oder andere kommt.
        $claimGenerator = $this->resolveClaimGenerator($active);
        $assertions = is_array($active['assertions'] ?? null) ? $active['assertions'] : [];

        $origin = AssetOrigin::Human;
        $aiSystem = null;
        $aiPrompt = null;

        foreach ($assertions as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }
            $label = (string) ($assertion['label'] ?? '');
            $data = is_array($assertion['data'] ?? null) ? $assertion['data'] : [];

            // c2pa.training-mining: presence is a strong AI-generated signal
            if ('c2pa.training-mining' === $label || str_starts_with($label, 'c2pa.ai-')) {
                $origin = AssetOrigin::AiGenerated;
            }

            // c2pa.actions (v1) und c2pa.actions.v2 (C2PA 2.x) gleichermaßen parsen.
            if (('c2pa.actions' === $label || 'c2pa.actions.v2' === $label)
                && is_array($data['actions'] ?? null)
            ) {
                foreach ($data['actions'] as $action) {
                    if (!is_array($action)) {
                        continue;
                    }
                    $name = (string) ($action['action'] ?? '');

                    // Goldener Detection-Path (C2PA 2.x): digitalSourceType direkt
                    // im Action — IPTC-NewsCode Vocabulary, robust gegen
                    // Generator-Naming-Variationen.
                    $digitalSourceType = (string) ($action['digitalSourceType'] ?? '');
                    if ('' !== $digitalSourceType) {
                        $detected = AssetOrigin::fromIptcDigitalSourceType($digitalSourceType);
                        if (null !== $detected && AssetOrigin::Human !== $detected) {
                            $origin = $detected;
                        }
                    }

                    // Per-action softwareAgent (C2PA 2.x) — präziser als
                    // top-level claim_generator weil pro Edit-Step.
                    if ('c2pa.created' === $name && is_array($action['softwareAgent'] ?? null)) {
                        $agentName = (string) ($action['softwareAgent']['name'] ?? '');
                        $agentVer = (string) ($action['softwareAgent']['version'] ?? '');
                        if ('' !== $agentName && null === $aiSystem) {
                            $aiSystem = '' !== $agentVer ? trim($agentName . ' ' . $agentVer) : $agentName;
                        }
                    }

                    // Fallback (C2PA 1.x): c2pa.created + non-empty claimGenerator.
                    if ('c2pa.created' === $name && '' !== $claimGenerator && AssetOrigin::Human === $origin) {
                        $origin = AssetOrigin::AiGenerated;
                    }
                }
            }

            // Some signers expose the prompt in custom assertions
            if (str_contains(strtolower($label), 'prompt') && isset($data['prompt']) && is_string($data['prompt'])) {
                $aiPrompt ??= $data['prompt'];
            }
        }

        if (AssetOrigin::Human === $origin) {
            // Manifest exists but no AI-related assertions — likely a regular
            // photo with provenance. Don't auto-tag.
            return new DetectedProvenance(
                origin: AssetOrigin::Human,
                aiSystem: null,
                aiPrompt: null,
                c2paStatus: 'signed_external',
                signingChainIssuer: $this->extractIssuer($active, $claimGenerator),
            );
        }

        // ai_system: Per-Action softwareAgent gewinnt; nur wenn leer → top-level
        // claim_generator_info / claim_generator als Fallback.
        if (null === $aiSystem && '' !== $claimGenerator) {
            $aiSystem = $this->humanizeClaimGenerator($claimGenerator);
        }

        return new DetectedProvenance(
            origin: $origin,
            aiSystem: $aiSystem,
            aiPrompt: $aiPrompt,
            c2paStatus: 'signed_external',
            signingChainIssuer: $this->extractIssuer($active, $claimGenerator),
        );
    }

    /**
     * @param array<string, mixed> $active
     */
    private function resolveClaimGenerator(array $active): string
    {
        // C2PA 2.x: claim_generator_info is an array of objects {name, version, ...}.
        $info = $active['claim_generator_info'] ?? null;
        if (is_array($info) && isset($info[0]) && is_array($info[0])) {
            $name = (string) ($info[0]['name'] ?? '');
            if ('' !== $name) {
                return $name;
            }
        }

        // C2PA 1.x: claim_generator is a free-form string.
        return (string) ($active['claim_generator'] ?? '');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function extractIssuer(array $manifest, string $claimGenerator): ?string
    {
        $cert = $manifest['signature_info']['issuer'] ?? null;
        if (is_string($cert) && '' !== $cert) {
            return $cert;
        }
        // Fallback: try to derive from claim_generator string
        foreach (['OpenAI', 'ChatGPT', 'Adobe', 'Microsoft', 'Stability'] as $known) {
            if (false !== stripos($claimGenerator, $known)) {
                return $known;
            }
        }

        return null;
    }

    private function humanizeClaimGenerator(string $raw): string
    {
        // Examples: "ChatGPT/1.0 (OpenAI)" → "ChatGPT (DALL-E 3)"
        //           "Adobe Firefly 2.0 (Adobe Inc.)" → "Adobe Firefly 2.0"
        // Heuristic: drop trailing "(...)" wrapper, return rest.
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $raw));
    }

    /**
     * @return DetectedProvenance|null null if no IPTC tag or exiftool unavailable
     */
    private function readIptcXmp(string $absolutePath): ?DetectedProvenance
    {
        $process = new Process([
            $this->exifToolPath,
            '-j',
            '-XMP-iptcExt:DigitalSourceType',
            '-XMP-iptcExt:AISystemUsed',
            '-XMP-iptcExt:AIPromptInformation',
            $absolutePath,
        ]);
        $process->setTimeout(self::EXIFTOOL_TIMEOUT);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger?->info('exiftool unavailable — skipping IPTC read', ['error' => $e->getMessage()]);

            return null;
        }
        if (!$process->isSuccessful()) {
            return null;
        }

        $json = trim($process->getOutput());
        if ('' === $json) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            return null;
        }
        $row = $decoded[0];

        $rawType = (string) ($row['DigitalSourceType'] ?? '');
        if ('' === $rawType) {
            return null;
        }
        $origin = AssetOrigin::fromIptcDigitalSourceType($rawType);
        if (null === $origin || AssetOrigin::Human === $origin) {
            return null;
        }

        $aiSystem = isset($row['AISystemUsed']) && is_string($row['AISystemUsed']) && '' !== $row['AISystemUsed']
            ? $row['AISystemUsed']
            : null;
        $aiPrompt = isset($row['AIPromptInformation']) && is_string($row['AIPromptInformation']) && '' !== $row['AIPromptInformation']
            ? $row['AIPromptInformation']
            : null;

        return new DetectedProvenance(
            origin: $origin,
            aiSystem: $aiSystem,
            aiPrompt: $aiPrompt,
            c2paStatus: 'none', // no C2PA found, only IPTC
            signingChainIssuer: null,
        );
    }
}
