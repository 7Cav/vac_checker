<?php

namespace Cav7\SteamChecker\XF\Entity;

class Post extends XFCP_Post
{
    protected function _postSave()
    {
        parent::_postSave();

        // Only fire on new posts, not edits.
        if (!$this->isInsert()) {
            return;
        }

        // Never react to the bot's own posts.
        $botUserId = (int) \XF::options()->steamCheckerBotUserId;
        if ($botUserId && $this->user_id === $botUserId) {
            return;
        }

        // Only act within the configured enlistment node.
        $thread = $this->Thread;
        if (!$thread) {
            return;
        }
        $configuredNodeId = (int) \XF::options()->steamCheckerNodeId;
        if ($thread->node_id !== $configuredNodeId) {
            return;
        }

        // --- Automatic check (OP) -------------------------------------------
        // position 0 = first post of the thread. Hooking here instead of
        // Thread._postSave() ensures the OP is already in the database when
        // the bot posts, so XF correctly assigns first_post_id to the OP
        // rather than the bot reply.
        if ($this->position === 0) {
            if (\XF::options()->steamCheckerDebugLog) {
                \XF::logError('[VAC-DEBUG] Post._postSave: OP detected for thread_id='
                    . $thread->thread_id);
            }
            try {
                $checker = new \Cav7\SteamChecker\SteamChecker($thread);
                $checker->run();
            } catch (\Throwable $e) {
                \XF::logException($e, false, '[Cav7/SteamChecker] Unhandled error: ');
            }
            return;
        }

        // --- !vac command (replies) ------------------------------------------
        // Parse allowed role IDs from the option (comma- or newline-separated).
        $rawRoles = trim((string) \XF::options()->steamCheckerAllowedRoleIds);
        if ($rawRoles === '') {
            return;
        }
        $allowedGroupIds = array_values(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $rawRoles))
        ));
        if (empty($allowedGroupIds)) {
            return;
        }

        // Check whether the posting user belongs to at least one allowed group.
        $user = $this->User;
        if (!$user) {
            return;
        }
        $userGroupIds = array_merge(
            [(int) $user->user_group_id],
            array_map('intval', $user->secondary_group_ids ?: [])
        );
        if (!array_intersect($allowedGroupIds, $userGroupIds)) {
            return;
        }

        // Step 0: remove [QUOTE]...[/QUOTE] blocks (bare and attributed forms),
        // contents included — quoted text is someone else's words and must never
        // be parsed as this user's command (issue #16). Strips iteratively,
        // innermost-out, so nested quotes are fully removed. Fail-open contract:
        // unbalanced quote markup is left as-is (no match), and any PCRE failure
        // falls back to the unstripped message with a logged error rather than
        // silently dropping the command. The body uses unrolled possessive
        // quantifiers ((?:[^\[]++|\[(?!…))*+) which make backtracking exhaustion
        // vastly harder, but cannot rule it out — a large enough bracket-bomb can
        // still hit pcre.backtrack_limit. On PCRE failure, the three strips
        // below (this quote strip, the [URL] unwrap, the BBCode strip) fail
        // open with a logged error: each falls back to its input, so the
        // command is preserved. The final !vac match instead fails noisy: a
        // PCRE failure there is logged and treated as "no command" (command
        // dropped, loudly). The only non-PCRE transforms in the pipeline — the
        // entity decode and the str_replace neutralization below
        // — cannot fail, so every step now either cannot fail or fails loudly
        // (issue #17 removed the strip_tags() call that could silently drop a
        // command preceded by, containing, or wrapped in a '<…>' pseudo-tag).
        $message = $this->message;
        $strippedBlocks = 0;
        do {
            $stripped = preg_replace(
                '/\[QUOTE(?:=[^\]]*)?\](?:[^\[]++|\[(?!QUOTE|\/QUOTE\]))*+\[\/QUOTE\]/i',
                '',
                $message,
                -1,
                $quoteCount
            );
            if ($stripped === null) {
                \XF::logError('[Cav7/SteamChecker] Quote stripping failed (PCRE: '
                    . preg_last_error_msg() . ') for post_id=' . $this->post_id
                    . '; falling back to unstripped message.');
                $message = $this->message; // fail open per documented contract
                $strippedBlocks = 0; // reverted to original: 0 blocks effectively stripped
                break;
            }
            $message = $stripped;
            $strippedBlocks += $quoteCount;
        } while ($quoteCount > 0);

        // Strip BBCode from the message, then look for the !vac command.
        // XF stores messages as BBCode; auto-linked URLs may be wrapped in [URL]...[/URL].
        // Both preg_replace calls below carry the same fail-open guard as step 0:
        // a PCRE failure (e.g. a [URL bomb exhausting the backtrack limit on the
        // lazy (.*?)) returns null, so we log and fall back to the pre-call string
        // rather than let null propagate and silently swallow a valid command.
        $unwrapped = preg_replace('/\[URL[^\]]*\](.*?)\[\/URL\]/is', '$1', $message);
        if ($unwrapped === null) {
            \XF::logError('[Cav7/SteamChecker] URL unwrap failed (PCRE: '
                . preg_last_error_msg() . ') for post_id=' . $this->post_id
                . '; using message as-is.');
            $unwrapped = $message; // fail open per documented contract
        }
        $plain = $unwrapped;

        $bbStripped = preg_replace('/\[[^\]]*\]/', ' ', $plain);
        if ($bbStripped === null) {
            \XF::logError('[Cav7/SteamChecker] BBCode strip failed (PCRE: '
                . preg_last_error_msg() . ') for post_id=' . $this->post_id
                . '; using message as-is.');
            $bbStripped = $plain; // fail open per documented contract
        }
        // Decode entities, then neutralize '<', '>', U+00A0 NO-BREAK SPACE,
        // and the invisible-separator family — U+2000–U+200D (the quad/space
        // block, incl. ZWSP/ZWNJ/ZWJ), U+202F NARROW NO-BREAK SPACE, U+205F
        // MEDIUM MATHEMATICAL SPACE, U+2060 WORD JOINER, U+3000 IDEOGRAPHIC
        // SPACE, U+FEFF ZWNBSP/BOM — by replacing each with a single space
        // (issues #17, #20, #21, #23). XF messages are BBCode, not HTML, so
        // there are no real tags to strip here; under the old strip_tags()
        // call, any '<' followed by a non-whitespace character opened a
        // pseudo-tag deleted through the next '>', or to end-of-string when
        // unterminated, silently swallowing valid commands ('aww <3 !vac …',
        // '!vac <id>' typed per the bot's own old instruction).
        // Neutralization runs AFTER the decode so entity-encoded brackets
        // ('&lt;', '&#60;', …) become whitespace like literal ones instead
        // of reappearing in the captured token (issue #21), and entity-form
        // separators ('&nbsp;', '&thinsp;', '&numsp;', '&emsp;',
        // '&MediumSpace;', '&NoBreak;', '&ZeroWidthSpace;', …) — like their
        // raw code points pasted from rendered HTML — become plain spaces
        // the ASCII-only \s in the final match can see (issues #20, #23).
        // Do NOT swap this for /u on the final match: under PCRE, Unicode \s
        // matches Zs but not Cf, so U+200B/U+200C/U+200D/U+2060/U+FEFF would
        // still slip through, and it would add a PCRE failure mode to a step
        // that currently cannot fail. Safe order: a single-pass
        // html_entity_decode never decodes recursively — '&amp;lt;' yields
        // the literal text '&lt;', not a bracket — and this pipeline decodes
        // exactly once. Plain str_replace — no PCRE, so no new fail-open
        // surface. Residuals: (a) an argument made ONLY of brackets and/or
        // neutralized separators ('!vac &lt;&gt;', '!vac &nbsp;') dissolves
        // to whitespace, so the command goes unmatched silently — the same
        // known-silent contract literal '!vac <>' has had since #17 (tracked
        // in #25); (b) semicolon-less '&nbsp' (no ';') does not decode under
        // ENT_HTML5 and stays glued to '!vac' as literal text — browsers do
        // render the legacy no-semicolon form as a space, but handling it
        // would need an unrelated pre-decode special case; excluded by
        // maintainer decision (issue #23, known residual).
        $plain = str_replace(
            [
                '<', '>', "\u{00A0}",
                "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}",
                "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}",
                "\u{200A}", "\u{200B}", "\u{200C}", "\u{200D}",
                "\u{202F}", "\u{205F}", "\u{2060}", "\u{3000}", "\u{FEFF}",
            ],
            ' ',
            html_entity_decode($bbStripped, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );

        // Final match: the fourth PCRE step, carrying the same fail-open
        // observability as the three strips above. preg_match() returns false on
        // a PCRE failure (reachable only under a hardened, lowered global
        // pcre.backtrack_limit — not at defaults). Behavior is unchanged: the
        // false (error) case is treated as "no command" exactly as it was when
        // the result was compared with === 1; the only addition is a logged
        // error so the failure is observable like the strips, instead of being
        // swallowed silently.
        $vacMatchResult = preg_match('/!vac\s+(\S+)/i', $plain, $m);
        if ($vacMatchResult === false) {
            \XF::logError('[Cav7/SteamChecker] !vac match failed (PCRE: '
                . preg_last_error_msg() . ') for post_id=' . $this->post_id
                . '; treating as no command.');
        }
        $vacMatched = $vacMatchResult === 1;

        if (\XF::options()->steamCheckerDebugLog) {
            \XF::logError('[VAC-DEBUG] Post._postSave: quote strip for post_id='
                . $this->post_id
                . ' stripped_blocks=' . $strippedBlocks
                . ' vac_match=' . ($vacMatched ? 'yes' : 'no'));
        }

        if (!$vacMatched) {
            return;
        }

        $rawSteamId = trim($m[1]);

        if (\XF::options()->steamCheckerDebugLog) {
            \XF::logError('[VAC-DEBUG] !vac invoked by user_id=' . $this->user_id
                . ' in thread_id=' . $thread->thread_id
                . ' rawSteamId=' . $rawSteamId);
        }

        try {
            $checker = new \Cav7\SteamChecker\SteamChecker($thread);
            $checker->runManual($rawSteamId);
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] !vac invocation error: ');
        }
    }
}
