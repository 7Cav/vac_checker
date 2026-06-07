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
        // still hit pcre.backtrack_limit. Each of the four PCRE steps below (this
        // quote strip, the [URL] unwrap, the BBCode strip, and the final !vac
        // match) fails open with a logged error on PCRE failure. NOTE: this is a
        // per-PCRE-step guarantee, not a pipeline-wide one — the strip_tags()
        // call further down can still drop a command containing an unterminated
        // '<…>' (a known separate issue, not addressed here).
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

        // Strip BBCode and HTML from the message, then look for the !vac command.
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
        $plain = html_entity_decode(strip_tags($bbStripped), ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
