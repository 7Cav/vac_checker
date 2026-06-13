# Steam VAC Checker

XenForo addon that checks Steam accounts for VAC/game bans on enlistment
applications: automatically on each new thread's OP in the enlistment node,
and on demand when staff post the `!vac` command in a reply.

## Language

**Re-run instruction**:
The one-line staff instruction appended to failure replies, telling staff how
to invoke the check via `!vac`. Single-sourced; its exact text is a
characterized contract.
_Avoid_: help text, usage message

**Normalized post**:
A reply's message after the full `!vac` parse pipeline has run: quotes
stripped, URLs unwrapped, BBCode stripped, entities decoded once, the
separator/format-control family and angle brackets neutralized to spaces.
_Avoid_: plain text, stripped message

**Separator/format-control family**:
The closed set of code points neutralized to spaces during normalization:
every code point with Unicode general category Zs, Zl, Zp, or Cf, minus
U+0020 — generated once at Unicode 16.0 (188 code points) and pasted as
literal needles (ADR-0001). Defined by category, not by discovery.
_Avoid_: invisible separators (the historical discovery-driven subset),
invisible characters (broader — includes out-of-scope render-blank
look-alikes, see `.out-of-scope/render-blank-characters.md`)

**Degenerate invocation**:
A `!vac` attempt with no usable argument: the normalized post ends with a
standalone `!vac` token followed only by whitespace. Covers bare `!vac` and
arguments that dissolve entirely during normalization (`<>`, `&lt;&gt;`,
`&nbsp;`).
_Avoid_: no-arg command, empty command

**Last-ban age**:
The `Last Ban:` line of a ban report: Steam's raw `DaysSinceLastBan` count
rendered as a calendar-accurate duration (`2 years, 3 months, 24 days ago`)
with the raw figure kept as a parenthetical. Anchored to the XF clock and
broken down against the real Gregorian calendar, so leap days land where they
actually fall — never fixed 365-day years or 30-day months. Leading zero units
are omitted; `0` reads as `today`. Shown only when the report has bans.
_Avoid_: days since last ban (the raw count), ban age in days.

**Trailing-token rule**:
The trigger condition for responding to a degenerate invocation: fires only
after the primary `!vac` match finds a genuine no-match (a PCRE error there
suppresses the rule) AND the normalized post ends with a standalone `!vac`. Conversational mentions that end a post ("just use !vac")
deliberately trigger it; punctuation-glued mentions ("use !vac.") do not.

**Steam-field presence gate**:
The structural check on a PC enlistment's OP that decides between a silent skip
and a loud failure (#36). When the Steam-identifier field label
(`Steam64ID or Steam Account URL/Link`) is _absent_ from the post — a non-Steam
PC form such as Star Citizen, which carries an RSI Profile link instead — there
is no Steam identity to screen, so the checker returns quietly, the same as a
non-PC platform. When the label is _present_ but its value is empty or won't
resolve, the loud "manual check required" reply (with the Re-run instruction)
still fires: that is the "fail loudly, never guess" case
(`.out-of-scope/username-fallback-resolution.md`, #8). Keyed on the label's
absence, never on a hardcoded game name.
_Avoid_: Star Citizen check, game-name skip (the discriminator is the missing
field label, not the title).
