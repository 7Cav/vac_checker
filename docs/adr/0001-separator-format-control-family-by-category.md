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
2. **The final match keeps ASCII `\s`** — `/u` is not a substitute: under
   PCRE, Unicode `\s` covers the family's Zs/Zl/Zp members (and, by a PCRE2
   legacy quirk, the Cf U+180E MONGOLIAN VOWEL SEPARATOR) but misses the rest
   of its Cf format characters — ZWSP/ZWNJ/ZWJ, LRM/RLM, the bidi embedding
   controls, the astral tag block, … — and it would move separator handling
   into a fallible step.

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
its own infallible `str_replace` to the NUL sentinel (the "sentinel + heal"
steps below); `'<'`/`'>'` from #17 are neutralized to a space in a separate
prior `str_replace`, not mixed into this needle list. No runtime derivation:
the CI image (`php:8.3-cli`) has no intl extension, and deriving at runtime
would add a failure mode to a step whose entire value is that it cannot fail.

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

### Neutralization shape: sentinel + heal

Identifying the family is one thing; *closing* it for every position is
another. Replacing each family code point directly with a space (the original
#23/#31 shape) closes the family only when the char sits **between** `!vac`
and its argument. A family char **inside** the literal (`!v<U+00AD>ac`,
`!<U+200B>vac`, `!va<U+2028>c`, `!v<astral-tag>ac`) splits `!vac` into `!v ac`
and the post goes fully silent — no check, no usage reply, no log.

So the neutralization is a **sentinel + heal** sequence (issue #31):

1. `html_entity_decode` once.
2. `str_replace('<' / '>' → ' ')` (issue #17 behavior, unchanged).
3. `str_replace(<188 family needles> → "\x00")` — a NUL **sentinel**, not a
   space. This is the infallible family-identification step the invariants
   above require; only the replacement target changed.
4. **Heal** the literal with an ASCII-only `preg_replace('/!\x00*v\x00*a\x00*c/i'
   → '!vac')`: a sentinel between the letters of `!vac` is deleted. This is
   literal repair on an ASCII sentinel, **not** family identification (that
   already happened, infallibly, in step 3), so it does not breach invariant 1
   or 2. It carries the same fail-open-with-logged-error guard as the strips.
   A *typed* space is not a sentinel, so real traffic — `lol! VAC banned him`,
   `! v a c`, `got a ! VAC ban` — is untouched (no flexible `!\s*v\s*a\s*c`
   false-fire).
5. `str_replace("\x00" → ' ')` — surviving sentinels were true separators and
   become the spaces the ASCII `\s` match sees. Any pre-existing NUL is mapped
   here too, harmlessly; only `!vac`-adjacent sentinels are ever healed.

The family is therefore closed for **every** position: separator, trailing,
interior, and inside-the-id-token (where the char truncates the id and the
truncated prefix is rejected loudly downstream).

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
- **Semicolon-less `&nbsp` and `&shy`**: a different mechanism (entity
  decoding, not a raw code point) — the legacy no-semicolon forms do not
  decode under ENT_HTML5, so the bytes never become the U+00A0 / U+00AD code
  point the family `str_replace` would catch; maintainer-excluded in #23 and
  pinned as residual (b). Their semicolon-terminated forms `&nbsp;` / `&shy;`
  DO decode to in-family code points and are neutralized normally.
- **Cn (unassigned) / Co (private use) / Cs (surrogates)**: unassigned code
  points cannot be enumerated ahead of assignment; surrogates cannot occur in
  valid UTF-8.

## Consequences

- **Closes the discovery class, for every position.** With the sentinel+heal
  shape above, an in-family char is neutralized wherever it lands — separator,
  trailing, interior of the `!vac` literal, or inside the id token. A future
  "invisible character silently drops `!vac`" report is therefore either in
  Zs/Zl/Zp/Cf (a sync bug — fix the pasted list) or outside the family (out of
  scope — point at the boundary doc). No more per-character issues of the
  #20/#23/#31 shape, and no more per-position ones.
- **The pasted list is the fixture.** The mechanized BYTE-SYNC pins verify
  the entity list, the test replica, and the pin nowdocs stay byte-identical,
  so the "generated once" list cannot silently drift.
- **Unicode version bumps are explicit maintenance**, not emergencies: rerun
  the generator, diff, re-sync. Zs/Zl/Zp/Cf membership is stable in practice;
  changes are rare and additive.
- `str_replace` with ~190 needles runs once per post-save — negligible for
  forum-post sizes.
