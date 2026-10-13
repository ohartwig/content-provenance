# Content Credentials icon

`cr-icon.svg` is the official monochrome Content Credentials pin, taken
byte-for-byte on 2026-07-26 from the codebase behind contentcredentials.org:

  https://github.com/contentauth/verify-site — `assets/svg/monochrome/cr-icon.svg`

It is `viewBox="0 0 18 18"` and fills with `currentColor`, so it inherits the
surrounding text colour. That is upstream's own design, not a change we made.

## What it means, and what it does not

The pin says **this asset carries Content Credentials** — a C2PA manifest is
attached and can be inspected. It is not a statement that the content is
AI-generated. That statement is the EU icon next door in `../EuAiLabel/`, and
the two are driven by different data:

| Mark | Driven by | Says |
|------|-----------|------|
| EU icon | `origin` (ai_generated / ai_assisted) | this content is AI-generated or AI-modified |
| CR pin | `c2paStatus` (manifest present) | provenance is attached and verifiable |

An asset can carry one, both, or neither. Showing the pin where no manifest
exists would be a false claim, so it is bound to `c2paStatus` and nothing else.

## Trademark

The mark is administered by the Joint Development Foundation. Its policy
(https://jointdevelopment.org/policies/trademark-policy/) permits use without a
separate licence agreement, subject to conditions. The ones that bind us:

- **Unmodified.** "A trademark should not be altered or amended in any way… A
  logo should not be displayed with color variations." This file is upstream's
  byte-for-byte; the earlier hand-drawn lookalike it replaces was already a
  breach of exactly this clause.
- **No implied endorsement.** Displaying it must not suggest that C2PA or the
  Joint Development Foundation sponsors or endorses this product.
- **Notice on first appearance.** ™ on first use in a given surface.

One point deliberately left open rather than assumed away: the same policy says
"Compliance Marks may only be used pursuant to the applicable requirements
published by JDF Projects for each such Compliance Mark", and neither
c2pa.org/conformance nor contentcredentials.org states whether this pin is
classified as such a mark. The owner's position, recorded 2026-07-26, is that it
marks the presence of a signature rather than certification of the product, and
that use is therefore appropriate. If the C2PA conformance programme later says
otherwise, this asset and its usage come back up — the write-up to point at is
I-086. `trademarks@jointdevelopment.org` is the address the policy names for
clarification.
