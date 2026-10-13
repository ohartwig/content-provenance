<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Application\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Resolves the PEM contents of the C2PA signing certificate + private key
 * issued by the site's Step-CA (Intermediate-CA-signed leaf cert with
 * Document Signing EKU `1.3.6.1.5.5.7.3.36`). Both files are written by the
 * cert-manager container (24h lifetime, daily rotation) into the
 * tls_c2pa_ram volume, mounted into httpd at `/run/c2pa/` read-only.
 *
 * The key is exposed in PKCS#8 form (`c2pa-pkcs8.key`) because c2pa-rs's
 * PEM decoder rejects the SEC1 form (`BEGIN EC PRIVATE KEY`) that Step-CA
 * emits by default.
 *
 * Returns PEM **content** (not paths) so the signer can embed them inline
 * in c2patool's settings JSON — c2pa-rs settings expects the PEM string
 * directly, not a file path.
 *
 * Defensive: returns `null` if either file is missing or unreadable —
 * Signer falls back to Soft-fail and the BE-Save still succeeds.
 */
final class C2paCertificateProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly string $certPath = '/run/c2pa/c2pa.crt',
        private readonly string $keyPath = '/run/c2pa/c2pa-pkcs8.key',
    ) {}

    /**
     * @return array{cert: string, key: string}|null null if not available
     */
    public function resolve(): ?array
    {
        if (!is_file($this->certPath) || !is_readable($this->certPath)) {
            $this->logger?->info('C2PA cert file missing — Sign-Step skipped', ['cert' => $this->certPath]);

            return null;
        }
        if (!is_file($this->keyPath) || !is_readable($this->keyPath)) {
            $this->logger?->info('C2PA key file missing — Sign-Step skipped', ['key' => $this->keyPath]);

            return null;
        }
        $cert = file_get_contents($this->certPath);
        $key = file_get_contents($this->keyPath);
        if (false === $cert || false === $key || '' === $cert || '' === $key) {
            $this->logger?->warning('C2PA cert/key empty or unreadable');

            return null;
        }

        return ['cert' => $cert, 'key' => $key];
    }
}
