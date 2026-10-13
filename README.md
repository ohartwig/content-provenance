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

### AI Asset Provenance (EU AI Act Art. 50, ab v0.6.0)

- **TCA Asset-Origin-Tagging** — neuer „AI Provenance"-Tab auf `sys_file_metadata`, `sys_file_reference` und `pages` mit den Feldern Origin (Human/AiGenerated/AiAssisted), AI-System, AI-Prompt, C2PA-Status.
- **Auto-Detection beim Upload** — `AfterFileAddedEvent`-Listener liest C2PA-Manifests (via `c2patool`) + IPTC-XMP-Tags (via `exiftool`) und seedet die TCA-Felder automatisch. ChatGPT/DALL-E/Adobe-Firefly-Bilder werden ohne Editor-Eingriff korrekt klassifiziert.
- **IPTC XMP Write-Back** — Beim Save eines AI-tagged Files schreibt der `EmbedAiMetadataOnFileSave`-Listener `Iptc4xmpExt:DigitalSourceType` + `AISystemUsed` + `AIPromptInformation` zurück ins File. Maschinen-lesbare Disclosure, erfüllt Art. 50.
- **Defensive Binary-Handling** — Wenn `c2patool` oder `exiftool` im Container fehlen, loggen die Listener einen Hinweis und überspringen — Editor-Manual-Tag bleibt möglicher Fallback.

### Sonstiges

- **DDD 4-Layer Architecture** — Strict layer separation enforced by deptrac

## Installation

```bash
composer require koh/content-provenance
```

### Requirements

- PHP 8.3+
- TYPO3 14.0
- `ext-sodium` (required — Ed25519 cryptographic operations)
- `symfony/process` ^6 || ^7 (für die c2patool/exiftool-Subprocess-Wrapper)

### Container-Binaries (für AI Provenance — optional)

Für die Auto-Detection + XMP-Write-Funktionen müssen im httpd-Container vorhanden sein:

- **`exiftool`** — Lesen + Schreiben von IPTC XMP-Tags (alle Image/Video/Audio-Formate)
- **`c2patool`** — Lesen + Schreiben von C2PA-Manifests. Download von [`contentauth/c2pa-rs`-Releases](https://github.com/contentauth/c2pa-rs/releases). Wolfi-Beispiel-Snippet in `docker/httpd/Containerfile`:
  ```dockerfile
  ARG C2PATOOL_VERSION=0.21.0
  RUN curl -fsSL "https://github.com/contentauth/c2pa-rs/releases/download/c2patool-v${C2PATOOL_VERSION}/c2patool-v${C2PATOOL_VERSION}-x86_64-unknown-linux-musl.zip" \
        -o /tmp/c2patool.zip && \
      unzip -j /tmp/c2patool.zip -d /tmp/x && \
      install -m 0755 /tmp/x/c2patool /usr/bin/c2patool
  ```

Ohne die Binaries läuft die Extension weiter, nur die Auto-Detection/IPTC-Write-Steps werden zu No-Ops (mit log warning).

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

## C2PA Manifest Signing (Enterprise Provenance Layer, ab v0.7.0)

**Positionierung:** Diese Schicht ist **kein** regulatorisches Muss für
EU AI Act Art. 50 — die maschinen-lesbare Disclosure (IPTC-XMP + Schema.org)
+ sichtbares FE-Badge aus Phase 1–4 deckt den regulatorischen Kern bereits ab.
C2PA-Signing ist die Enterprise-Authenticity-Schicht: **Beweisbarkeit,
Anti-Stripping, Chain-of-Custody, Forensik, Interoperabilität**.

Ab v0.7.0 enthält das Package eine kryptografische C2PA-Sign-Pipeline:

- **`C2paCertificateProvider`** — resolved Cert/Key aus `/run/c2pa/` (vom
  cert-manager beschickt, 90d Lifetime, quartalsweise Leaf-Rotation).
- **`C2paManifestSigner`** — subprocess-wrapper um `c2patool`. Auto-detected
  `c2pa.created` (fresh) vs. `c2pa.metadata` mit `parent_ingredient`
  (append) — upstream-Signaturen (OpenAI, Adobe) bleiben in der Chain.
- **`ReSignDerivativeFiles`** Listener auf `AfterFileProcessingEvent` —
  signiert auch TYPO3-generierte Thumbnails/Resizes (WebP, AVIF, JPEG, PNG),
  sodass die Provenance-Chain die FAL-Image-Processing-Pipeline überlebt.
  Signiert wird nur ein im selben Request frisch erzeugtes Derivat, nicht
  jedes gecachte bei jedem Seitenaufruf. Das Original ist das
  Parent-Ingredient des Derivats (Action `c2pa.edited`): die Bildverarbeitung
  entfernt jedes Manifest, und ohne Parent begänne die Kette des Derivats bei
  unserer Signatur statt bei der Generierung (OpenAI o. ä.).
- **`StoredFileWriteBack`** — IPTC-Tags und Signatur entstehen in der
  beschreibbaren Kopie aus `getForLocalProcessing(true)` (auch beim lokalen
  Treiber eine Temp-Datei unter `var/transient/`). Die Kopie geht danach über
  FAL zurück (`ResourceStorage::replaceFile()` bzw.
  `ProcessedFile::updateWithLocalFile()`); der `c2pa_status` wird erst nach
  erfolgreichem Zurückschreiben gesetzt, sonst `failed`.
- **Kein Zurück zu „Human" bei AI-Manifest** — setzt ein Editor den Origin
  auf `human`, obwohl die Datei ein C2PA-Manifest mit AI-Beteiligung trägt
  (eigene Signatur laut `c2pa_status`, oder ein AI-Claim im aktiven Manifest
  der Datei), wird stattdessen `ai_assisted` gespeichert und mit dem
  AI-assisted-`digitalSourceType` signiert. Die Provenance-Chain lässt sich
  nicht ehrlich entfernen. Dateien ohne solches Manifest behalten den
  Human-Pfad (IPTC-Tags werden geleert).
- **`provenance:resign`** CLI für opt-in Backfill bestehender AI-tagged
  Files (idempotent, `--force` für Re-Sign). Das Ersetzen eines Originals
  löscht in TYPO3 alle seine Derivate; der Befehl baut die vorher
  vorhandenen sofort neu, und sie werden dabei signiert
  (`DerivativeRegenerator`). `--derivatives-only` lässt bereits signierte
  Originale unverändert und baut nur ihre Derivate neu — für Derivate, die
  vor der Parent-Verknüpfung entstanden sind.

### `c2pa_status` Statusmodell

| Wert | Bedeutung |
|---|---|
| `none` | Default — kein Sign-Versuch erfolgt |
| `signed_external` | Upstream-Signatur erkannt (OpenAI, Adobe), wir haben noch nicht signiert |
| `signed_self_anchored` | Frisch von uns signiert (Step-CA), kein Parent-Manifest |
| `signed_self_anchored_append` | Edit-Action auf upstream Manifest appended (parent-source preserved) |
| `signed_trusted` | **Reserviert für Phase 5b** — Trust-List-konformes CA-Cert |
| `stripped` | War signed, Manifest wurde nachträglich entfernt (z.B. durch Re-Encode außerhalb TYPO3) |
| `failed` | Sign-Versuch crashte (Cert missing, c2patool exit ≠ 0, etc.) |

### Trust-Model (wichtig!)

Die Signatur-Pipeline etabliert eine **Private Provenance Chain**:

- Signatur-Cert kommt von der projekteigenen Step-CA → **self-anchored**
- Tamper-evident via Hash-Anchored Manifest
- Append-Mode preserved upstream Trust-Chain (OpenAI/Adobe bleiben sichtbar)
- Strip-Detection: gestrippte Manifeste werden via `c2pa_status=stripped`
  im BE sichtbar gemacht → starkes Forensik-Signal

**Externe Validatoren** (z.B. `contentcredentials.org/verify`) zeigen
unsere Signatur als **"untrusted (unknown CA)"** — die Signatur ist
kryptografisch valid und tamper-evident, aber unsere Root steht nicht
in der [C2PA Trust List](https://opensource.contentauthenticity.org/docs/conformance/trust-lists).
Das ist gewollt für Phase 1: interne Beweisbarkeit + Forensik ohne
externes Onboarding-Programm.

### Roadmap (Public Trusted Content Credentials)

Wenn echtes externes Branchen-Vertrauen gefordert wird:

1. **C2PA Conformance Program** für unser Generator-Produkt durchlaufen
   ([CAI Conformance](https://opensource.contentauthenticity.org/docs/conformance/))
2. **Trust-List-konformes Signing-Cert** beziehen — Step-CA bleibt intern,
   externe Sign-Calls nutzen die Trust-List-CA
3. **Trusted Timestamping (TSA)** ergänzen — sonst nicht beweisbar dass
   Signatur während Cert-Gültigkeit erzeugt wurde
4. **Key-Isolation** — Private Key aus httpd-Container raus, in HSM/KMS/
   PKCS#11 oder isolierten Signing-Worker (Sidecar-Pattern)
5. **Async Queue/Worker** statt synchronem BE-Save — Mass-Upload-UX +
   bessere Failure-Isolation
6. **Validator-CI-Gates** — `c2patool verify` + contentcredentials.org-
   Roundtrip als Quality-Gate

Diese Punkte sind Out-of-Scope für die initiale Implementierung und
benötigen externe Programme/Dienstleister.

## EU AI Act Art. 50 Compliance

Das **regulatorische Minimum** für Art. 50 (gültig ab August 2026) ist
„maschinen-lesbar gekennzeichnet UND als künstlich erzeugt erkennbar".
Das wird durch die **Phasen 1–4** dieses Packages + den Site-Packages
abgedeckt:

| Anforderung | Erfüllt durch |
|---|---|
| Maschinen-lesbar | IPTC PhotoMetadata 2025.1 XMP (`DigitalSourceType`, `AISystemUsed`) — Phase 3 |
| Detektierbar | Auto-Detection aus C2PA/IPTC beim Upload — Phase 2 |
| Sichtbar für User | FE-Badge via `moselwal/sitepackage-base` ≥ 3.22.0 — Phase 4 |
| Strukturiert für Crawler | Schema.org `ImageObject.creator` via `koh/structured-content` ≥ 1.5.10 — Phase 4 |

Art. 50 fordert nach aktuellem Stand **nicht** kryptografische Provenance,
keine PKI, keine Trust-List-CA, keine Watermarks, keine Blockchain.
Die C2PA-Sign-Schicht (Phase 5) ist eine **strategische Ergänzung** für
Beweisbarkeit, Forensik und Enterprise-Authenticity-Use-Cases, **nicht**
eine regulatorische Pflicht-Schicht.

### Google AI Search Konformität (Mai 2026)

Google's offizielle Guides bestätigen den Stack-Ansatz:

- [AI Optimization Guide](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide) — „You don't need to create new machine readable files, AI text files, markup, or Markdown to appear in generative AI search" + „Structured data isn't required for generative AI search, and there's no special schema.org markup you need to add"
- [Using Gen-AI Content](https://developers.google.com/search/docs/fundamentals/using-gen-ai-content) — explizit für AI-Bilder: „AI-generated images must contain metadata using the IPTC `DigitalSourceType` `TrainedAlgorithmicMedia` metadata"

→ Der einzige harte technische Pflicht-Punkt von Google (`IPTC DigitalSourceType`) wird durch **Phase 3** (`IptcXmpWriter`) abgedeckt. Schema.org-Erweiterungen (Phase 4) bleiben für klassische SERP-Features sinnvoll, sind aber für Generative AI Search nicht erforderlich. C2PA-Signing (Phase 5) wird von Google aktuell nicht für Search-Ranking ausgewertet — bleibt strategisch als Enterprise-Authenticity-Layer.

**E-commerce Edge-Case (nicht relevant für unsere Sites):** „AI-generated product data such as title and description attributes must be specified separately and labeled as AI-generated" — würde bei künftigen Product-Pages den Need für separate AI-Labeling-Felder in Product-CB triggern.

**Hinweis:** Diese Doku ist keine juristische Beratung. Für die
formelle Art.-50-Bewertung empfehlen wir eine eigene Risikoanalyse +
Disclosure-Konzept, das die im Projekt umgesetzten Layer benennt und
die Strip-Detection-Strategie (`c2pa_status=stripped`) dokumentiert.

## License

GPL-2.0-or-later — see the [SPDX licence text](https://spdx.org/licenses/GPL-2.0-or-later.html).
