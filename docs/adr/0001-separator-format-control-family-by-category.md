# ADR-0001: Define the neutralization family by Unicode category (Zs/Zl/Zp/Cf), generated once

- **Status:** Accepted
- **Date:** 2026-06-07
- **Issues:** boundary set during triage of #31; supersedes the discovery-driven
  lists of #17, #20, #21, #23

## Context

Five rounds of issues and PRs extended the `!vac` parser's invisible-character
neutralization by discovery:

| Round | Issue → PR | What got neutralized |
| --- | --- | --- |
| 1 | #17 → #19 | `<`, `>` (strip_tags removal) |
| 2 | #20/#21 → #26 | U+00A0; entity-decode ordering |
| 3 | #23 → #30 | U+2000–200D, U+202F, U+205F, U+2060, U+3000, U+FEFF |
| 4 | #25 → #32 | (reply layer — but its silent-failure audit spawned #31) |
| 5 | #31 | U+2028/U+2029 verified silent, plus five more candidates |

Root cause of the recurrence: the needle list was enumerated by discovery, not
by definition. Every silent-failure audit found the next batch, because "the
family" had no closed form.

Two invariants constrain any fix (established across #17–#23 and documented in
the entity's neutralization comment):

1. **Neutralization must be infallible** — plain `str_replace` after the
   single entity decode. No PCRE, no new fail-open surface.
2. **The final match keeps ASCII `\s`** — `/u` is not a substitute: Unicode
   `\s` misses the family's Cf members and would move separator handling into
   a fallible step.

## Decision

The neutralization family is **every code point whose Unicode general category
is Zs, Zl, Zp, or Cf**, minus U+0020 (the replacement character itself),
pinned at **Unicode 16.0**: **188 code points**.

```
U+00A0, U+00AD, U+0600–0605, U+061C, U+06DD, U+070F, U+0890–0891, U+08E2,
U+1680, U+180E, U+2000–200F, U+2028–202F, U+205F–2064, U+2066–206F, U+3000,
U+FEFF, U+FFF9–FFFB, U+110BD, U+110CD, U+13430–1343F, U+1BCA0–1BCA3,
U+1D173–1D17A, U+E0001, U+E0020–E007F
```

(The hole at U+2065 is real — it is unassigned (Cn) inside an otherwise-Cf
run, which is exactly the kind of detail hand-listing gets wrong.)

The list is **generated once and pasted** as literal `"\u{…}"` needles into
the infallible `str_replace`, alongside the `'<'`/`'>'` entries from #17. No
runtime derivation: the CI image (`php:8.3-cli`) has no intl extension, and
deriving at runtime would add a failure mode to a step whose entire value is
that it cannot fail.

Generator — rerun to re-derive on a Unicode bump, diff against the pasted
list, then walk the three-place BYTE-SYNC sync (entity needle list, test
replica, mechanized pin nowdocs):

```sh
python3 - <<'PY'
import unicodedata as u
cps = [c for c in range(0x110000)
       if u.category(chr(c)) in ('Zs', 'Zl', 'Zp', 'Cf') and c != 0x20]
print(f"// Unicode {u.unidata_version}: {len(cps)} code points")
print(',\n'.join(f'"\\u{{{c:04X}}}"' for c in cps))
PY
```

### The boundary

**In scope:** characters that are invisible *by design* — separators
(Zs/Zl/Zp) and format controls (Cf). These are what accidental copy-paste
produces: rendered HTML (NBSP, thin spaces), word processors and JS/ICU
contexts (U+2028/U+2029), RTL text (LRM/RLM, bidi embedding controls),
justified text (soft hyphen).

**Out of scope:** characters that merely *render* blank or space-like in some
fonts but belong to other categories — Hangul fillers (U+115F, U+1160,
U+3164, U+FFA0 — category Lo), BRAILLE PATTERN BLANK (U+2800 — So), variation
selectors and U+034F COMBINING GRAPHEME JOINER (Mn). These do not arrive by
pasting whitespace; they arrive deliberately, and the bot is staff-invoked —
deliberately sabotaging your own command is not a supported threat. See
`.out-of-scope/render-blank-characters.md`.

Also excluded:

- **Cc controls beyond ASCII whitespace** (C0 remnants, the C1 block): ASCII
  `\t \n \r \f \v` already separate via `\s`; XenForo's input filtering is
  relied on for the rest. Revisit only on a concrete sighting.
- **Semicolon-less `&nbsp`**: a different mechanism (entity decoding, not a
  raw code point); maintainer-excluded in #23 and pinned as residual (b).
- **Cn (unassigned) / Co (private use) / Cs (surrogates)**: unassigned code
  points cannot be enumerated ahead of assignment; surrogates cannot occur in
  valid UTF-8.

## Consequences

- **Closes the discovery class.** A future "invisible character silently
  drops `!vac`" report is either in Zs/Zl/Zp/Cf (a sync bug — fix the pasted
  list) or outside the family (out of scope — point at the boundary doc).
  No more per-character issues of the #20/#23/#31 shape.
- **The pasted list is the fixture.** The mechanized BYTE-SYNC pins verify
  the entity list, the test replica, and the pin nowdocs stay byte-identical,
  so the "generated once" list cannot silently drift.
- **Unicode version bumps are explicit maintenance**, not emergencies: rerun
  the generator, diff, re-sync. Zs/Zl/Zp/Cf membership is stable in practice;
  changes are rare and additive.
- `str_replace` with ~190 needles runs once per post-save — negligible for
  forum-post sizes.
