<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Koh\ContentProvenance\Domain\Model\AssetOrigin;
use Koh\ContentProvenance\Domain\Model\DetectedProvenance;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Process\Process;

/**
 * Signs C2PA manifests onto media files using `c2patool` + the site
 * Step-CA leaf cert provided by {@see C2paCertificateProvider}.
 *
 * Two modes, auto-detected:
 *   - **Fresh**: file has no existing manifest → action `c2pa.created`
 *   - **Append**: file already carries a foreign manifest (e.g. OpenAI) →
 *     action `c2pa.metadata`, parent-source pinned to preserve the upstream
 *     sign-chain. C2PA validators show "Created by OpenAI → Metadata edited
 *     by the site operator" — full lineage retained.
 *   - **Derived**: the caller names the file this one was made from (a
 *     TYPO3 derivative and its original) → action `c2pa.edited`, the original
 *     is the parent ingredient. Image processing strips every manifest, so
 *     without the parent a derivative's chain would start at our signature
 *     and the OpenAI generation behind it would be gone.
 *
 * All file I/O happens in-place via tempfile + atomic rename. The tempfile
 * sits next to the asset and keeps its extension — see {@see outputPathFor()}.
 * On any error
 * (cert missing, c2patool crash, hash collision) the method returns `false`
 * and logs a warning — the caller's BE-Save still succeeds (Soft-fail).
 *
 * Re-entrant safe: the signer never modifies its own input parameters and
 * cleans up temp files on every code path (including exceptions).
 */
final class C2paManifestSigner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SIGN_TIMEOUT = 15.0;
    private const GENERATOR_NAME = 'Koh Content Provenance';
    private const GENERATOR_VERSION = '0.7.1';
    private const SIGNATURE_ALG = 'es256';

    public function __construct(
        private readonly C2paCertificateProvider $certProvider,
        private readonly ContentCredentialsReader $reader,
        private readonly string $c2paToolPath = 'c2patool',
    ) {}

    /**
     * Signs `$absolutePath` in-place. Returns:
     *   - `null` if Sign-Pipeline nicht konfiguriert (Cert/Key fehlen) ODER
     *     File nicht zugreifbar — Caller soll DB-Status nicht mutieren
     *   - `'signed_self_anchored'` / `'signed_self_anchored_append'` bei Erfolg
     *   - `'failed'` bei aktiver Pipeline aber c2patool-Crash/Output-Empty
     *
     * Never throws.
     *
     * Der null-vs-string-Unterschied ist absichtlich: er ermöglicht ein
     * sauberes opt-in von Phase 5 — auf Setups ohne C2PA-Cert läuft die
     * Pipeline transparent passiv (kein "failed"-Noise im BE oder Log).
     *
     * `$derivedFrom` is the file `$absolutePath` was generated from. It is only
     * read. A path that is not a readable file is ignored, and the file is
     * signed as if none had been given — a missing chain is better than a
     * missing signature.
     */
    public function sign(string $absolutePath, DetectedProvenance $provenance, ?string $derivedFrom = null): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath) || !is_writable($absolutePath)) {
            $this->logger?->info('Sign skipped — file not accessible', ['path' => $absolutePath]);

            return null;
        }

        $certs = $this->certProvider->resolve();
        if (null === $certs) {
            // Sign-Pipeline nicht konfiguriert (Cert fehlt) — transparenter Skip.
            // CertProvider hat den Hinweis bereits geloggt.
            return null;
        }

        if (null !== $derivedFrom && (!is_file($derivedFrom) || !is_readable($derivedFrom))) {
            $this->logger?->info('Parent of the derivative not readable — signed without it', ['path' => $absolutePath, 'parent' => $derivedFrom]);
            $derivedFrom = null;
        }

        // Detect existing manifest to decide Fresh vs Append. A derivative
        // takes its chain from the file it was made from, not from itself.
        $parent = $derivedFrom ?? $absolutePath;
        $existing = $this->reader->read($parent);
        $isAppend = 'none' !== $existing->c2paStatus;

        $manifestJson = $this->buildManifestJson($provenance, $isAppend, null !== $derivedFrom);
        $settingsJson = $this->buildSettingsJson($certs['cert'], $certs['key']);

        $manifestFile = $this->writeTempFile($manifestJson, '.json');
        $settingsFile = $this->writeTempFile($settingsJson, '.json');
        $outputFile = $this->outputPathFor($absolutePath);

        try {
            $args = [
                $this->c2paToolPath,
                $absolutePath,
                '-m', $manifestFile,
                '--settings', $settingsFile,
                '-o', $outputFile,
                '-f',
            ];
            if ($isAppend || null !== $derivedFrom) {
                // Parent-source preserves the upstream manifest (OpenAI, Adobe etc.)
                // as `parent_ingredient` in our new manifest.
                $args[] = '-p';
                $args[] = $parent;
            }

            $process = new Process($args);
            $process->setTimeout(self::SIGN_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger?->warning('c2patool sign failed', [
                    'path' => $absolutePath,
                    'stderr' => $process->getErrorOutput(),
                    'exit' => $process->getExitCode(),
                ]);

                return 'failed';
            }
            if (!is_file($outputFile) || 0 === filesize($outputFile)) {
                $this->logger?->warning('c2patool produced empty output', ['path' => $absolutePath]);

                return 'failed';
            }

            // Atomic in-place replace
            if (!rename($outputFile, $absolutePath)) {
                $this->logger?->error('Could not move signed file in place', ['path' => $absolutePath]);
                @unlink($outputFile);

                return 'failed';
            }

            return $isAppend ? 'signed_self_anchored_append' : 'signed_self_anchored';
        } finally {
            @unlink($manifestFile);
            @unlink($settingsFile);
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }
        }
    }

    /**
     * Where c2patool writes the signed result before it is renamed over the
     * asset.
     *
     * Two constraints, both load-bearing. The file keeps the asset's extension:
     * c2patool derives the output format from it and refuses with "Output type
     * must match source type" when it differs — a `.tmp` suffix made every
     * signing attempt fail with exit 1. And it lives in the asset's directory,
     * so the final rename() stays on one filesystem and therefore atomic.
     */
    private function outputPathFor(string $absolutePath): string
    {
        $directory = dirname($absolutePath);
        $name = pathinfo($absolutePath, PATHINFO_FILENAME);
        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);

        return '' === $extension
            ? $absolutePath . '.c2pa-signing'
            : $directory . '/' . $name . '.c2pa-signing.' . $extension;
    }

    private function buildManifestJson(DetectedProvenance $provenance, bool $isAppend, bool $isDerived = false): string
    {
        $when = gmdate('Y-m-d\TH:i:s\Z');

        $action = match (true) {
            $isDerived => 'c2pa.edited',
            $isAppend => 'c2pa.metadata',
            default => 'c2pa.created',
        };
        $actionData = [
            'action' => $action,
            'when' => $when,
            'softwareAgent' => [
                'name' => self::GENERATOR_NAME,
                'version' => self::GENERATOR_VERSION,
            ],
        ];
        if (AssetOrigin::Human !== $provenance->origin) {
            $actionData['digitalSourceType'] = $provenance->origin->toIptcDigitalSourceType();
        }

        $manifest = [
            'claim_generator_info' => [[
                'name' => self::GENERATOR_NAME,
                'version' => self::GENERATOR_VERSION,
            ]],
            'claim_version' => 2,
            'assertions' => [[
                'label' => 'c2pa.actions.v2',
                'data' => [
                    'actions' => [$actionData],
                ],
            ]],
        ];

        return json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Builds the c2patool `--settings` JSON. c2patool >= 0.26 accepts JSON or
     * TOML; we use JSON because PEM contents have newlines which are far easier
     * to embed in JSON strings than TOML literals.
     *
     * Why inline PEM and not file paths: c2pa-rs's settings parser expects the
     * `sign_cert` / `private_key` fields to be the PEM **content**, not a path.
     * Passing a path produces a misleading "No supported data to decode" error.
     *
     * `verify_trust = false` disables trust-chain verification at sign-time;
     * we self-anchor and validators show "untrusted but valid". The
     * `trust_config` lists our cert's actual EKU (Document Signing) so that
     * c2pa-rs's internal sign-time sanity check accepts it.
     */
    private function buildSettingsJson(string $certPem, string $keyPem): string
    {
        $settings = [
            'signer' => [
                'local' => [
                    'alg' => self::SIGNATURE_ALG,
                    'sign_cert' => $certPem,
                    'private_key' => $keyPem,
                ],
            ],
            'verify' => [
                'verify_trust' => false,
                'verify_timestamp_trust' => false,
            ],
            'trust' => [
                // Code Signing EKU (1.3.6.1.5.5.7.3.3) — Step-CA's CLI-Template-
                // Parser kennt `1.3.6.1.5.5.7.3.36` (Document Signing, RFC 9336)
                // nicht als Named-EKU, akzeptiert nur die klassische OpenSSL-
                // Liste. `codeSigning` ist semantisch verwandt und wird von
                // c2pa-rs via trust_config explicit anerkannt.
                'trust_config' => '1.3.6.1.5.5.7.3.3',
            ],
        ];

        return json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function writeTempFile(string $contents, string $suffix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'c2pa-');
        if (false === $tmp) {
            throw new \RuntimeException('Could not allocate temp file for C2PA signer');
        }
        $final = $tmp . $suffix;
        rename($tmp, $final);
        file_put_contents($final, $contents);

        return $final;
    }
}
