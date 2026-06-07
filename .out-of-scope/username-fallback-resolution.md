# Username Fallback Resolution

The VAC checker does not attempt to resolve arbitrary strings (display names,
login names, "steam usernames") into Steam accounts. Input that doesn't match
a recognised identifier format — bare SteamID64, `/profiles/<id>` URL,
`/id/<vanity>` URL, or `s.team` short link — fails cleanly with a
"could not determine a valid Steam ID" reply.

## Why this is out of scope

The only API available for name-based lookup is `ResolveVanityURL`, which
resolves **custom-URL (vanity) names** — not display names or login names.
Treating an arbitrary username string as a vanity name can silently resolve to
the **wrong account**: whichever unrelated account happens to own that vanity
URL. A ban-screening tool that sometimes reports on the wrong person is worse
than one that fails loudly — a "verify this profile" warning still normalises
trusting the guess, and the wrong-account result looks identical to a right
one.

The failure mode this would have papered over is already handled by the
human-in-the-loop path: the bot's failure replies instruct staff how to re-run
the check with `!vac <Steam64ID or profile URL>` (#7), so staff can grab the
correct ID from the applicant and re-run with an unambiguous identifier.
Explicit re-run with a verified ID beats implicit resolution with a guessed
one.

## Prior requests

- #8 — "Handle username input better"
