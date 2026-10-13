# EU AI label icons

The official icons the AI Office publishes for Article 50(4) disclosure of
AI-generated content. Downloaded 2026-07-26 from

  https://digital-strategy.ec.europa.eu/en/policies/eu-icons-labelling-ai-generated-content
  (SVG pack: https://ec.europa.eu/newsroom/dae/redirection/document/129546)

Free to use, no attribution to the Commission or the AI Office required.

## Filenames

Upstream ships `LABEL_AI GENERATED_black transparent.svg` and friends — spaces
in filenames, and a typo in one of them (`LABEL_AI MOFIFIED_black.svg`). They
are renamed here to a predictable pattern so the partial can build the path
from the origin value, and the typo is corrected:

  ai-{|generated-|modified-}{black|white}[-transparent].svg

Artwork is byte-identical to upstream; only the filenames changed.

## Three variants, and which to use

| File | Upstream name | When |
|------|---------------|------|
| `ai-*` | Basic icon | Combined with your own label text, or as a compact marker |
| `ai-generated-*` | Fully AI-Generated | The whole asset is AI-created, no human-made parts |
| `ai-modified-*` | Partially AI-Modified | Pre-existing human content altered by AI |

The two labelled variants carry the words "AI GENERATED" / "AI MODIFIED" in the
artwork itself, so they need no text beside them. Their aspect ratio is wide
(roughly 3.2:1 and 3:1) — size them by height and let the width follow, never
as a square.

## Why these are referenced and not inlined

Each file carries an internal `<style>` block using generic class names
(`.cls-1`, `.cls-2`). Inlining two of them into one document makes those rules
collide and one icon silently restyles the other. Referencing them as `<img>`
keeps each SVG in its own styling scope.

## One caveat

The icons are free to use, but using them does not make anyone a signatory to
the Code of Practice on Transparency of AI-Generated Content, and placement
must not be presented as if it did.
