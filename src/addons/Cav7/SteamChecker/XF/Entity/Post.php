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
        // dropped, loudly). The degenerate-invocation detection (#25, below
        // the match) follows the same fail-noisy convention — logged,
        // treated as no-trigger. The only non-PCRE transforms in the pipeline — the
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
        // Decode entities, then neutralize '<', '>' (issue #17) and the
        // separator/format-control family — every code point with Unicode
        // general category Zs, Zl, Zp, or Cf, minus U+0020: 188 code points
        // pinned at Unicode 16.0, generated once with the ADR-0001 script
        // and pasted below as literal needles — by replacing each with a
        // single space (issues #17, #20, #21, #23, #31; ADR-0001 closed the
        // family by category after three rounds of discovery-driven lists).
        // No runtime derivation: the CI image has no intl extension, and
        // this step's contract is that it cannot fail — a Unicode bump means
        // rerunning the generator and re-syncing the three BYTE-SYNC places
        // (this needle list, the test replica, the mechanized pin nowdoc).
        // XF messages are BBCode, not HTML, so
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
        // covers the family's Zs spaces but none of its Cf format
        // characters — ZWSP/ZWNJ/ZWJ, LRM/RLM, the bidi embedding controls,
        // the astral tag block, … would still slip through — and it would
        // move separator handling from this
        // infallible str_replace into PCRE, adding a new failure mode to the
        // match. Safe order: a single-pass
        // html_entity_decode never decodes recursively — '&amp;lt;' yields
        // the literal text '&lt;', not a bracket — and this pipeline decodes
        // exactly once. Plain str_replace — no PCRE, so no new fail-open
        // surface. Residuals: (a) an argument made ONLY of brackets and/or
        // neutralized separators ('!vac &lt;&gt;', '!vac &nbsp;') dissolves
        // to whitespace, so the primary match fails — a degenerate
        // invocation. Literal '!vac <>' silent since #17; the entity forms
        // were loud (unresolvable-ID reply on a garbage token) until
        // #20/#21 made them dissolve; all silent through #24. Since #25 the
        // trailing-token rule below answers them with the usage reply;
        // (b) semicolon-less '&nbsp' (no ';') does not decode under
        // ENT_HTML5 and stays glued to '!vac' as literal text — browsers do
        // render the legacy no-semicolon form as a space, but handling it
        // would need an unrelated pre-decode special case; excluded by
        // maintainer decision (issue #23, known residual). The glued token
        // also keeps the #25 trailing-token rule from firing: the post ends
        // with '!vac&nbsp…', not a standalone '!vac', so it stays silent.
        // The former residual (c) — invisibles outside the discovery-driven
        // list (U+2028/U+2029 et al.) — was closed by #31's family closure.
        // Characters that merely RENDER blank but sit outside Zs/Zl/Zp/Cf
        // (Hangul fillers, braille blank, variation selectors) are out of
        // scope by the ADR-0001 boundary; see
        // .out-of-scope/render-blank-characters.md.
        $plain = str_replace(
            [
                '<', '>',
                "\u{00A0}", "\u{00AD}", "\u{0600}", "\u{0601}", "\u{0602}",
                "\u{0603}", "\u{0604}", "\u{0605}", "\u{061C}", "\u{06DD}",
                "\u{070F}", "\u{0890}", "\u{0891}", "\u{08E2}", "\u{1680}",
                "\u{180E}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}",
                "\u{2004}", "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}",
                "\u{2009}", "\u{200A}", "\u{200B}", "\u{200C}", "\u{200D}",
                "\u{200E}", "\u{200F}", "\u{2028}", "\u{2029}", "\u{202A}",
                "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{202F}",
                "\u{205F}", "\u{2060}", "\u{2061}", "\u{2062}", "\u{2063}",
                "\u{2064}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
                "\u{206A}", "\u{206B}", "\u{206C}", "\u{206D}", "\u{206E}",
                "\u{206F}", "\u{3000}", "\u{FEFF}", "\u{FFF9}", "\u{FFFA}",
                "\u{FFFB}", "\u{110BD}", "\u{110CD}", "\u{13430}", "\u{13431}",
                "\u{13432}", "\u{13433}", "\u{13434}", "\u{13435}", "\u{13436}",
                "\u{13437}", "\u{13438}", "\u{13439}", "\u{1343A}", "\u{1343B}",
                "\u{1343C}", "\u{1343D}", "\u{1343E}", "\u{1343F}", "\u{1BCA0}",
                "\u{1BCA1}", "\u{1BCA2}", "\u{1BCA3}", "\u{1D173}", "\u{1D174}",
                "\u{1D175}", "\u{1D176}", "\u{1D177}", "\u{1D178}", "\u{1D179}",
                "\u{1D17A}", "\u{E0001}", "\u{E0020}", "\u{E0021}", "\u{E0022}",
                "\u{E0023}", "\u{E0024}", "\u{E0025}", "\u{E0026}", "\u{E0027}",
                "\u{E0028}", "\u{E0029}", "\u{E002A}", "\u{E002B}", "\u{E002C}",
                "\u{E002D}", "\u{E002E}", "\u{E002F}", "\u{E0030}", "\u{E0031}",
                "\u{E0032}", "\u{E0033}", "\u{E0034}", "\u{E0035}", "\u{E0036}",
                "\u{E0037}", "\u{E0038}", "\u{E0039}", "\u{E003A}", "\u{E003B}",
                "\u{E003C}", "\u{E003D}", "\u{E003E}", "\u{E003F}", "\u{E0040}",
                "\u{E0041}", "\u{E0042}", "\u{E0043}", "\u{E0044}", "\u{E0045}",
                "\u{E0046}", "\u{E0047}", "\u{E0048}", "\u{E0049}", "\u{E004A}",
                "\u{E004B}", "\u{E004C}", "\u{E004D}", "\u{E004E}", "\u{E004F}",
                "\u{E0050}", "\u{E0051}", "\u{E0052}", "\u{E0053}", "\u{E0054}",
                "\u{E0055}", "\u{E0056}", "\u{E0057}", "\u{E0058}", "\u{E0059}",
                "\u{E005A}", "\u{E005B}", "\u{E005C}", "\u{E005D}", "\u{E005E}",
                "\u{E005F}", "\u{E0060}", "\u{E0061}", "\u{E0062}", "\u{E0063}",
                "\u{E0064}", "\u{E0065}", "\u{E0066}", "\u{E0067}", "\u{E0068}",
                "\u{E0069}", "\u{E006A}", "\u{E006B}", "\u{E006C}", "\u{E006D}",
                "\u{E006E}", "\u{E006F}", "\u{E0070}", "\u{E0071}", "\u{E0072}",
                "\u{E0073}", "\u{E0074}", "\u{E0075}", "\u{E0076}", "\u{E0077}",
                "\u{E0078}", "\u{E0079}", "\u{E007A}", "\u{E007B}", "\u{E007C}",
                "\u{E007D}", "\u{E007E}", "\u{E007F}",
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
            // Trailing-token rule (issue #25): a degenerate invocation — the
            // normalized post ends with a standalone !vac token (preceded by
            // start-of-string or whitespace) followed only by whitespace —
            // gets the two-line usage reply instead of silence. Runs strictly
            // as a fallback after a GENUINE primary no-match (0): on a
            // primary-match PCRE failure (false, already logged above) the
            // parse state is unknown — the post may contain a real command
            // the matcher could not see, and a "no Steam ID found" reply
            // would mislabel it — so the fallback is skipped. Conversational
            // trailing mentions ("just use !vac") deliberately trigger;
            // punctuation-glued ones ("use !vac.") do not, because the token
            // is not standalone. No rate limiting by design (stateless, like
            // the invalid-ID failure path). Fifth PCRE step in the pipeline,
            // same fail-loud convention as the final match: on PCRE failure,
            // log the error and treat as no-trigger.
            if ($vacMatchResult === 0) {
                $degenerateResult = preg_match('/(?:^|\s)!vac\s*$/i', $plain);
                if ($degenerateResult === false) {
                    \XF::logError('[Cav7/SteamChecker] degenerate !vac detection failed (PCRE: '
                        . preg_last_error_msg() . ') for post_id=' . $this->post_id
                        . '; treating as no trigger.');
                } elseif ($degenerateResult === 1) {
                    if (\XF::options()->steamCheckerDebugLog) {
                        \XF::logError('[VAC-DEBUG] degenerate !vac invocation (trailing-token rule)'
                            . ' by user_id=' . $this->user_id
                            . ' in thread_id=' . $thread->thread_id
                            . ' post_id=' . $this->post_id);
                    }
                    try {
                        $checker = new \Cav7\SteamChecker\SteamChecker($thread);
                        $checker->replyDegenerateInvocation();
                    } catch (\Throwable $e) {
                        \XF::logException($e, false, '[Cav7/SteamChecker] degenerate !vac reply error: ');
                    }
                }
            }
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
