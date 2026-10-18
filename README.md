# Content Provenance — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Content integrity and provenance tracking for TYPO3 using Ed25519 digital signatures. Provides an audit trail, verification API, and key management — built with EU AI Act compliance in mind.

## Features

### Content Signing (Ed25519)

- **Ed25519 Digital Signatures** — Cryptographic content signing using libsodium
- **Audit Trail** — Immutable log of all content changes with actor, timestamp, and context
- **Verification API** — Public endpoint for third-party content integrity verification
- **Key Provider Abstraction** — Pluggable key storage backends (file, environment, vault)
- **Revocation Checking** — Key revocation support for compromised signing keys
- **Well-Known Endpoint** — Public key discovery via `/.well-known/provenance-keys`

### AI Asset Provenance (EU AI Act Art. 50, since v0.6.0)

- **TCA asset-origin tagging** — a new "AI Provenance" tab on `sys_file_metadata`, `sys_file_reference` and `pages` with the fields origin (human/AI-generated/AI-assisted), AI system, AI prompt and C2PA status.
- **Auto-detection on upload** — an `AfterFileAddedEvent` listener reads C2PA manifests (via `c2patool`) and IPTC XMP tags (via `exiftool`) and seeds the TCA fields. Images from ChatGPT, DALL-E or Adobe Firefly are classified correctly without editor input.
- **IPTC XMP write-back** — when an AI-tagged file is saved, the `EmbedAiMetadataOnFileSave` listener writes `Iptc4xmpExt:DigitalSourceType`, `AISystemUsed` and `AIPromptInformation` back into the file. This is the machine-readable disclosure Art. 50 asks for.
- **Defensive binary handling** — if `c2patool` or `exiftool` is missing from the container, the listeners log a notice and skip; tagging by hand in the editor remains the fallback.

### Other

- **DDD 4-Layer Architecture** — Strict layer separation enforced by deptrac

## Installation

```bash
composer require koh/content-provenance
```

### Requirements

- PHP 8.3+
- TYPO3 14.0
- `ext-sodium` (required — Ed25519 cryptographic operations)
- `symfony/process` ^6 || ^7 (for the c2patool/exiftool subprocess wrappers)

### Container binaries (for AI provenance — optional)

Auto-detection and XMP write-back need these in the httpd container:

- **`exiftool`** — reads and writes IPTC XMP tags (all image, video and audio formats)
- **`c2patool`** — reads and writes C2PA manifests. Download it from the [`contentauth/c2pa-rs` releases](https://github.com/contentauth/c2pa-rs/releases). Example snippet for a Wolfi `docker/httpd/Containerfile`:
  ```dockerfile
  ARG C2PATOOL_VERSION=0.21.0
  RUN curl -fsSL "https://github.com/contentauth/c2pa-rs/releases/download/c2patool-v${C2PATOOL_VERSION}/c2patool-v${C2PATOOL_VERSION}-x86_64-unknown-linux-musl.zip" \
        -o /tmp/c2patool.zip && \
      unzip -j /tmp/c2patool.zip -d /tmp/x && \
      install -m 0755 /tmp/x/c2patool /usr/bin/c2patool
  ```

Without the binaries the extension keeps working; only the auto-detection and IPTC write-back steps become no-ops (with a log warning).

## Architecture

```
Classes/
├── Domain/          # Signature models, key value objects, contracts
├── Application/     # Signing services, verification orchestration
├── Infrastructure/  # Key providers, repositories, API middleware
└── Presentation/    # Controllers, event listeners
```

### API Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/_provenance/api/verify` | Verify content signatures |
| `/.well-known/provenance-keys` | Public key discovery |

### Database Tables

| Table | Purpose |
|-------|---------|
| `tx_provenance_signature` | Content signatures (Ed25519) |
| `tx_provenance_audit_log` | Audit trail entries |

### Extended TCA Columns (AI Provenance, v0.6.0+)

| Table | Column | Purpose |
|---|---|---|
| `sys_file_metadata` | `tx_provenance_origin` | AssetOrigin enum (`human`/`ai_generated`/`ai_assisted`) |
| `sys_file_metadata` | `tx_provenance_ai_system` | Free-text model name (e.g. "DALL-E 3") |
| `sys_file_metadata` | `tx_provenance_ai_prompt` | Optional prompt for audit/reproducibility |
| `sys_file_metadata` | `tx_provenance_detected_at` | Auto-detection timestamp |
| `sys_file_metadata` | `tx_provenance_c2pa_status` | `none` / `signed_own` / `signed_external` / `stripped` |
| `sys_file_reference` | `tx_provenance_origin` | Per-reference override (rarely needed) |
| `pages` | `tx_provenance_origin` | Page-body origin (RTE / generated articles) |
| `pages` | `tx_provenance_ai_system` | Model name for the page body |

## Configuration

Configure key providers and signing policies through TYPO3 site settings. Supports multiple key providers for different environments (development, staging, production).

## Development

```bash
composer install
composer test                    # Unit tests
composer phpstan                 # Static analysis (level 8)
composer deptrac                 # DDD layer enforcement
vendor/bin/php-cs-fixer fix      # Code style (PER-CS3x0)
```

## Dependencies

| Package | Type | Purpose |
|---------|------|---------|
| `ext-sodium` | Required | Ed25519 cryptographic operations |
| `symfony/process` | Required | c2patool/exiftool subprocess wrappers (AI Provenance) |
| `koh/dev` | Dev | Shared QA tooling |

## C2PA Manifest Signing (Enterprise Provenance Layer, since v0.7.0)

**Positioning:** this layer is **not** a regulatory requirement of
EU AI Act Art. 50 — the machine-readable disclosure (IPTC XMP + Schema.org)
plus the visible frontend badge from phases 1–4 already cover the regulatory core.
C2PA signing is the enterprise authenticity layer: **provability,
anti-stripping, chain of custody, forensics, interoperability**.

Since v0.7.0 the package contains a cryptographic C2PA signing pipeline:

- **`C2paCertificateProvider`** — resolves certificate and key from `/run/c2pa/`
  (supplied by cert-manager, 90-day lifetime, quarterly leaf rotation).
- **`C2paManifestSigner`** — subprocess wrapper around `c2patool`. Detects
  `c2pa.created` (fresh) vs. `c2pa.metadata` with a `parent_ingredient`
  (append) — upstream signatures (OpenAI, Adobe) stay in the chain.
- **`ReSignDerivativeFiles`** listener on `AfterFileProcessingEvent` —
  also signs thumbnails and resizes generated by TYPO3 (WebP, AVIF, JPEG, PNG),
  so the provenance chain survives the FAL image processing pipeline.
  Only a derivative created fresh in the same request is signed, not
  every cached one on every page view. The original is the derivative's
  parent ingredient (action `c2pa.edited`): image processing removes every
  manifest, and without a parent the derivative's chain would start at
  our signature instead of at the generation (OpenAI or similar).
- **`StoredFileWriteBack`** — IPTC tags and signature are written into the
  writable copy from `getForLocalProcessing(true)` (a temporary file under
  `var/transient/`, even with the local driver). The copy then goes back
  through FAL (`ResourceStorage::replaceFile()` or
  `ProcessedFile::updateWithLocalFile()`); `c2pa_status` is set only after
  the write-back succeeded, otherwise `failed`.
- **No return to "human" with an AI manifest** — if an editor sets the origin
  to `human` although the file carries a C2PA manifest with AI involvement
  (our own signature according to `c2pa_status`, or an AI claim in the file's
  active manifest), `ai_assisted` is stored instead and signed with the
  AI-assisted `digitalSourceType`. The provenance chain cannot be removed
  honestly. Files without such a manifest keep the human path (the IPTC tags
  are cleared).
- **`provenance:resign`** CLI for an opt-in backfill of existing AI-tagged
  files (idempotent, `--force` to re-sign). Replacing an original
  deletes all its derivatives in TYPO3; the command rebuilds the ones that
  existed before right away, and they are signed in the process
  (`DerivativeRegenerator`). `--derivatives-only` leaves already signed
  originals unchanged and rebuilds only their derivatives — for derivatives
  created before the parent link existed.

### `c2pa_status` state model

| Value | Meaning |
|---|---|
| `none` | Default — no signing attempt yet |
| `signed_external` | Upstream signature detected (OpenAI, Adobe), not yet signed by us |
| `signed_self_anchored` | Freshly signed by us (Step CA), no parent manifest |
| `signed_self_anchored_append` | Edit action appended to an upstream manifest (parent source preserved) |
| `signed_trusted` | **Reserved for phase 5b** — certificate from a CA on the trust list |
| `stripped` | Was signed, the manifest was removed afterwards (e.g. by a re-encode outside TYPO3) |
| `failed` | The signing attempt failed (certificate missing, c2patool exit ≠ 0, etc.) |

### Trust model (important!)

The signing pipeline establishes a **private provenance chain**:

- The signing certificate comes from the project's own Step CA → **self-anchored**
- Tamper-evident through a hash-anchored manifest
- Append mode preserves the upstream trust chain (OpenAI/Adobe stay visible)
- Strip detection: stripped manifests are surfaced in the backend via
  `c2pa_status=stripped` → a strong forensic signal

**External validators** (e.g. `contentcredentials.org/verify`) show
our signature as **"untrusted (unknown CA)"** — the signature is
cryptographically valid and tamper-evident, but our root is not on
the [C2PA Trust List](https://opensource.contentauthenticity.org/docs/conformance/trust-lists).
That is intended for phase 1: internal provability and forensics without
an external onboarding programme.

### Roadmap (public trusted Content Credentials)

When real external industry trust is required:

1. Go through the **C2PA Conformance Program** for our generator product
   ([CAI Conformance](https://opensource.contentauthenticity.org/docs/conformance/))
2. Obtain a **signing certificate from a trust-list CA** — the Step CA stays internal,
   external signing calls use the trust-list CA
3. Add **trusted timestamping (TSA)** — otherwise it cannot be proven that
   the signature was created while the certificate was valid
4. **Key isolation** — move the private key out of the httpd container into an
   HSM/KMS/PKCS#11 or an isolated signing worker (sidecar pattern)
5. **Async queue/worker** instead of a synchronous backend save — better
   mass-upload UX and failure isolation
6. **Validator CI gates** — `c2patool verify` plus a contentcredentials.org
   round trip as a quality gate

These points are out of scope for the initial implementation and
require external programmes or service providers.

## EU AI Act Art. 50 Compliance

The **regulatory minimum** for Art. 50 (applicable from August 2026) is
"marked in a machine-readable format AND detectable as artificially generated".
It is covered by **phases 1–4** of this package plus the site packages:

| Requirement | Covered by |
|---|---|
| Machine-readable | IPTC Photo Metadata 2025.1 XMP (`DigitalSourceType`, `AISystemUsed`) — phase 3 |
| Detectable | Auto-detection from C2PA/IPTC on upload — phase 2 |
| Visible to users | Frontend badge via `moselwal/sitepackage-base` ≥ 3.22.0 — phase 4 |
| Structured for crawlers | Schema.org `ImageObject.creator` via `koh/structured-content` ≥ 1.5.10 — phase 4 |

As things stand, Art. 50 does **not** require cryptographic provenance,
a PKI, a trust-list CA, watermarks or a blockchain.
The C2PA signing layer (phase 5) is a **strategic addition** for
provability, forensics and enterprise authenticity use cases, **not**
a mandatory regulatory layer.

### Google AI Search conformance (May 2026)

Google's official guides confirm the approach of this stack:

- [AI Optimization Guide](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide) — "You don't need to create new machine readable files, AI text files, markup, or Markdown to appear in generative AI search" and "Structured data isn't required for generative AI search, and there's no special schema.org markup you need to add"
- [Using Gen-AI Content](https://developers.google.com/search/docs/fundamentals/using-gen-ai-content) — explicitly for AI images: "AI-generated images must contain metadata using the IPTC `DigitalSourceType` `TrainedAlgorithmicMedia` metadata"

→ Google's only hard technical requirement (`IPTC DigitalSourceType`) is covered by **phase 3** (`IptcXmpWriter`). The Schema.org extensions (phase 4) remain useful for classic SERP features but are not required for generative AI search. C2PA signing (phase 5) is currently not evaluated by Google for search ranking — it remains strategic as an enterprise authenticity layer.

**E-commerce edge case (not relevant to sites built on this package so far):** "AI-generated product data such as title and description attributes must be specified separately and labeled as AI-generated" — future product pages would need separate AI labelling fields in the product content block.

**Note:** this documentation is not legal advice. For a formal
Art. 50 assessment we recommend your own risk analysis and a
disclosure concept that names the layers implemented in the project and
documents the strip-detection strategy (`c2pa_status=stripped`).

## License

GPL-2.0-or-later — see the [SPDX licence text](https://spdx.org/licenses/GPL-2.0-or-later.html).
