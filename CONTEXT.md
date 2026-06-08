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
stripped, URLs unwrapped, BBCode stripped, entities decoded once, invisible
separators and angle brackets neutralized to spaces.
_Avoid_: plain text, stripped message

**Degenerate invocation**:
A `!vac` attempt with no usable argument: the normalized post ends with a
standalone `!vac` token followed only by whitespace. Covers bare `!vac` and
arguments that dissolve entirely during normalization (`<>`, `&lt;&gt;`,
`&nbsp;`).
_Avoid_: no-arg command, empty command

**Trailing-token rule**:
The trigger condition for responding to a degenerate invocation: fires only
after the primary `!vac` match finds a genuine no-match (a PCRE error there
suppresses the rule) AND the normalized post ends with a standalone `!vac`. Conversational mentions that end a post ("just use !vac")
deliberately trigger it; punctuation-glued mentions ("use !vac.") do not.
