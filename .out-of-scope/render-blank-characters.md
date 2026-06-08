# Render-Blank Characters (invisible look-alikes outside Zs/Zl/Zp/Cf)

The `!vac` parser does not chase characters that merely render blank or
space-like but are not Unicode separators (Zs/Zl/Zp) or format controls (Cf).

## Why this is out of scope

ADR-0001 closed the neutralization family by category: invisible *by design*
(Zs/Zl/Zp/Cf) is in; invisible *by font accident* is not. The known
look-alikes outside the family:

- U+115F / U+1160 HANGUL CHOSEONG/JUNGSEONG FILLER, U+3164 HANGUL FILLER,
  U+FFA0 HALFWIDTH HANGUL FILLER — category Lo (letters)
- U+2800 BRAILLE PATTERN BLANK — category So (symbol)
- U+FE00–FE0F variation selectors, U+034F COMBINING GRAPHEME JOINER —
  category Mn (combining marks)

These cannot arrive by accidentally copy-pasting whitespace out of rendered
HTML, a word processor, or RTL text — the realistic paste vectors all produce
Zs/Zl/Zp/Cf characters, which the family covers. A render-blank *letter* in a
`!vac` post means someone constructed it on purpose. The bot is staff-invoked:
the "attacker" would be a staffer sabotaging their own command, and the
failure mode (the check silently doesn't fire for them) costs the saboteur,
not the applicant.

Neutralizing these would also change the contract's shape: Lo/So/Mn are
categories that legitimately appear *inside* text, so replacing them mutates
token content rather than token separation — a riskier transform than
replacing separators with spaces.

If a real accidental paste vector for one of these ever shows up, reopen via
ADR-0001's boundary discussion rather than adding the character ad hoc.

## Prior requests

- #31 — boundary set during triage; the in-family part of #31 proceeded, this
  file records what was deliberately left outside
