<?php

/**
 * Issue #7 — failure replies must include a one-line !vac re-run instruction.
 *
 * Self-contained: stubs the \XF static facade and \XF\Entity\Thread inline,
 * then drives the protected message builders via reflection. No network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue7RerunInstructionTest.php
 */

namespace XF\Entity {
    class Thread
    {
        public $thread_id = 1;
        public $first_post_id = 1;
        public $reply_count = 0;
    }
}

namespace {
    class XF
    {
        public static function options(): object
        {
            return (object) [
                'steamCheckerApiKey'    => 'test-key',
                'steamCheckerBotUserId' => 99,
                'steamCheckerDebugLog'  => false,
            ];
        }

        public static function logError(string $msg): void
        {
        }
    }

    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';

    /**
     * Network spy: overrides both outbound-I/O paths reachable from
     * resolveSteamId() (httpGet for the vanity-URL API, resolveSteamShortLink
     * for s.team links — the latter uses raw curl, not httpGet). Any attempted
     * call is recorded and aborted, so tests can assert zero network I/O.
     */
    class NetworkSpySteamChecker extends \Cav7\SteamChecker\SteamChecker
    {
        /** @var string[] */
        public $networkCalls = [];

        protected function httpGet(string $url): ?string
        {
            $this->networkCalls[] = 'httpGet: ' . $url;
            throw new \RuntimeException('Unexpected network I/O: ' . $url);
        }

        protected function resolveSteamShortLink(string $url): ?string
        {
            $this->networkCalls[] = 'resolveSteamShortLink: ' . $url;
            throw new \RuntimeException('Unexpected network I/O: ' . $url);
        }
    }

    $checker = new \Cav7\SteamChecker\SteamChecker(new \XF\Entity\Thread());

    $call = function (string $method, ...$args) use ($checker) {
        $ref = new \ReflectionMethod($checker, $method);
        $ref->setAccessible(true);
        return $ref->invoke($checker, ...$args);
    };

    $failures = 0;
    $check = function (string $label, bool $ok) use (&$failures) {
        echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
        if (!$ok) {
            $failures++;
        }
    };

    $unresolvable = $call('buildUnresolvableMessage', 'bogus-value');
    $apiError     = $call('buildApiErrorMessage', '76561197960287930');

    // Pull the instruction line (the line mentioning !vac) from each message.
    $instructionLine = function (string $message): ?string {
        foreach (explode("\n", $message) as $line) {
            if (stripos($line, '!vac') !== false) {
                return $line;
            }
        }
        return null;
    };

    $unresolvableLine = $instructionLine($unresolvable);
    $apiErrorLine     = $instructionLine($apiError);

    // AC1: unresolvable-ID reply includes a one-line !vac re-run instruction.
    $check('unresolvable reply contains a !vac instruction line',
        $unresolvableLine !== null);

    // AC2: Steam-API-error reply includes the same instruction.
    $check('API-error reply contains a !vac instruction line',
        $apiErrorLine !== null);
    $check('both replies use the identical instruction line',
        $unresolvableLine !== null && $unresolvableLine === $apiErrorLine);

    // AC3: instruction names the command, where to post it, and argument forms.
    $line = (string) $unresolvableLine;
    $check('instruction names the !vac command',
        stripos($line, '!vac') !== false);
    $check('instruction says to post a reply in this thread',
        strpos($line, 'replying in this thread') !== false);
    $check('instruction names Steam64ID as an accepted argument',
        stripos($line, 'Steam64ID') !== false);
    $check('instruction names profile URL as an accepted argument',
        stripos($line, 'profile URL') !== false);

    // Edge-case guard: no literal valid-looking Steam64ID in the instruction.
    $check('instruction contains no literal valid Steam64ID',
        !preg_match('/7656119\d{10}/', $line));

    // Issue #17 guard: no literal '<' or '>' anywhere in the instruction, so
    // a staffer copying the placeholder verbatim never feeds angle brackets
    // to the parser (the old '<Steam64ID or profile URL>' placeholder was a
    // product-induced trigger for the strip_tags swallow).
    $check('instruction contains no literal angle brackets',
        strpos($line, '<') === false && strpos($line, '>') === false);

    // Uniqueness + placement: instruction appears exactly once and is the
    // final line of each failure reply.
    foreach (['unresolvable' => $unresolvable, 'API-error' => $apiError] as $name => $reply) {
        $check($name . ' reply contains the instruction line exactly once',
            substr_count($reply, $line) === 1);
        $check($name . ' reply mentions !vac exactly once',
            substr_count(strtolower($reply), '!vac') === 1);
        $replyLines = explode("\n", $reply);
        $check($name . ' reply ends with the instruction line',
            end($replyLines) === $line);
    }

    // ------------------------------------------------------------------------
    // Placeholder non-resolution through the REAL resolver.
    //
    // The instruction's safety property is that no token a quote-reply can
    // extract from it resolves to a real Steam account. Since issue #16 the
    // parser strips well-formed [QUOTE] blocks before matching, but unbalanced
    // quote markup fails open to the old flatten-everything behaviour, which
    // yields the token 'your' (the first word after '!vac' in the issue-17
    // wording; pre-#17 the strip_tags-era token was '.'). Pin that token and
    // the partial manual copy 'Steam64ID' through the real resolveSteamId(),
    // asserting null AND zero network I/O. A future rewording whose
    // placeholder accidentally matches the vanity-URL pattern would attempt a
    // network call and fail here.
    // ------------------------------------------------------------------------
    $resolveWithSpy = function (string $token): array {
        $spy = new NetworkSpySteamChecker(new \XF\Entity\Thread());
        $ref = new \ReflectionMethod($spy, 'resolveSteamId');
        $ref->setAccessible(true);
        try {
            $result = $ref->invoke($spy, $token);
            $threw  = false;
        } catch (\Throwable $e) {
            $result = '(threw: ' . $e->getMessage() . ')';
            $threw  = true;
        }
        return [$result, $spy->networkCalls, $threw];
    };

    foreach (['your', 'Steam64ID'] as $token) {
        // resolveSteamShortLink (raw curl, not spied by httpGet) is only
        // reachable when the token contains 's.team/' — guard against that.
        $check("token '$token' does not contain s.team/ (raw-curl shortlink path unreachable)",
            stripos($token, 's.team/') === false);

        [$resolved, $calls, $threw] = $resolveWithSpy($token);
        $check("resolveSteamId('$token') returns null without throwing",
            $resolved === null && !$threw);
        $check("resolveSteamId('$token') performs zero network I/O",
            $calls === []);
    }

    // ------------------------------------------------------------------------
    // Characterization: staff quote-reply of a failure post.
    //
    // Since issue #16, Post.php strips [QUOTE]…[/QUOTE] blocks (contents
    // included, innermost-out) BEFORE the normalization pipeline, so a quoted
    // instruction line never matches the !vac command at all: a bare
    // quote-reply fires no check, and a real command typed below the quote is
    // no longer shadowed.
    //
    // BYTE-SYNC PIN: $commandPipeline below is the SINGLE replica of the
    // quote-strip + normalization + match pipeline from
    // src/addons/Cav7/SteamChecker/XF/Entity/Post.php (step-0 block through
    // the !vac preg_match). It is behavior-equivalent on the strip/normalize/
    // match path: $this->message becomes $storedMessage, the three strip PCRE
    // guards (step-0, [URL]-unwrap, BBCode strip) keep their fail-open fallback,
    // and the final !vac preg_match collapses both no-match (0) and PCRE-error
    // (false) to "no command" via `if (!preg_match(...)) return null;` — the
    // same outcome as Post.php's === 1 result plus its error-logging guard, so
    // the captured token is identical. The observability bookkeeping those four
    // guards wrap — the logError calls (including the final-match parity log),
    // the [VAC-DEBUG] summary, and the stripped-blocks accumulator — is omitted
    // because it never affects the captured token. BOTH fixtures below route
    // through this one closure — there must never be a second inline copy. If
    // Post.php changes, update this closure and re-pin.
    //
    // The pin is MECHANIZED (issue #22): the assertions right after this
    // closure verify each load-bearing transform expression appears verbatim
    // in both this replica and Post.php, so drift fails the test instead of
    // relying on reviewer memory.
    // ------------------------------------------------------------------------
    $commandPipeline = function (string $storedMessage): ?string {
        $message = $storedMessage;
        do {
            $stripped = preg_replace(
                '/\[QUOTE(?:=[^\]]*)?\](?:[^\[]++|\[(?!QUOTE|\/QUOTE\]))*+\[\/QUOTE\]/i',
                '',
                $message,
                -1,
                $quoteCount
            );
            if ($stripped === null) {
                $message = $storedMessage; // fail open per documented contract
                break;
            }
            $message = $stripped;
        } while ($quoteCount > 0);

        $unwrapped = preg_replace('/\[URL[^\]]*\](.*?)\[\/URL\]/is', '$1', $message);
        if ($unwrapped === null) {
            $unwrapped = $message; // fail open per documented contract
        }
        $plain = $unwrapped;

        $bbStripped = preg_replace('/\[[^\]]*\]/', ' ', $plain);
        if ($bbStripped === null) {
            $bbStripped = $plain; // fail open per documented contract
        }
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

        if (!preg_match('/!vac\s+(\S+)/i', $plain, $m)) {
            return null;
        }
        return trim($m[1]);
    };

    // ------------------------------------------------------------------------
    // Mechanized BYTE-SYNC PIN (issue #22).
    //
    // Each load-bearing transform expression of the pipeline is listed once
    // below (as a nowdoc, so the bytes are literal) and asserted verbatim
    // against BOTH sources of truth:
    //   1. the $commandPipeline replica above (its source is sliced out of
    //      the file ReflectionFunction reports, via its line numbers), and
    //   2. src/addons/Cav7/SteamChecker/XF/Entity/Post.php.
    // Comments are stripped from both before matching (token_get_all, same
    // technique as AC7 in Issue17AngleBracketTest), so a doc comment quoting
    // an old expression can never keep a stale pin green.
    //
    // If either side changes, the corresponding check fails and names the
    // BYTE-SYNC PIN: re-sync the replica closure, then update the pin list.
    //
    // Guards on the mechanism itself: the replica slice must contain the
    // closure signature, the entity file must exist (one clear "missing"
    // failure instead of eight misleading "pipeline changed?" ones), the pin
    // count is pinned, and every pin body must be non-trivial — strpos with
    // an empty needle matches any haystack.
    //
    // The quote-strip and neutralize/decode pins are the FULL multi-line
    // calls, including leading indentation: the call bytes are identical in
    // both files (the replica deliberately mirrors Post.php's indentation),
    // and pinning the whole call catches drift in the replacement string /
    // needle list / flags / count variable, not just the pattern. The
    // neutralize needle list is the issue-#23 invisible-separator family
    // (U+2000–U+200D, U+202F, U+205F, U+2060, U+3000, U+FEFF) on top of the
    // #17/#20 set ('<', '>', U+00A0); dropping any single code point from
    // either file breaks the verbatim match and fails the pin.
    // ------------------------------------------------------------------------
    $pins = [
        'quote-strip preg_replace call' => <<<'PIN'
            $stripped = preg_replace(
                '/\[QUOTE(?:=[^\]]*)?\](?:[^\[]++|\[(?!QUOTE|\/QUOTE\]))*+\[\/QUOTE\]/i',
                '',
                $message,
                -1,
                $quoteCount
            );
PIN,
        'quote-strip loop guard' => <<<'PIN'
} while ($quoteCount > 0);
PIN,
        '[URL]-unwrap expression' => <<<'PIN'
$unwrapped = preg_replace('/\[URL[^\]]*\](.*?)\[\/URL\]/is', '$1', $message);
PIN,
        '[URL]-unwrap fail-open fallback' => <<<'PIN'
$unwrapped = $message;
PIN,
        'BBCode-strip expression' => <<<'PIN'
$bbStripped = preg_replace('/\[[^\]]*\]/', ' ', $plain);
PIN,
        'BBCode-strip fail-open fallback' => <<<'PIN'
$bbStripped = $plain;
PIN,
        'neutralize/decode call' => <<<'PIN'
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
PIN,
        'final !vac match expression' => <<<'PIN'
preg_match('/!vac\s+(\S+)/i', $plain, $m)
PIN,
    ];

    // Entity-only pin (issue #25), deliberately SINGLE-SIDED: the
    // degenerate-invocation detection (trailing-token rule) consumes the
    // same normalized $plain the final match does, but it produces a REPLY
    // DECISION, not a captured token — the $commandPipeline replica models
    // token extraction only, and no fixture in this suite routes through
    // the detection. Its behavior is pinned end-to-end (through the real
    // Post.php) in Issue25DegenerateInvocationTest; here only the expression
    // bytes in Post.php are pinned, so the detection cannot drift silently.
    // A negative replica assertion below keeps the single-sidedness honest:
    // if the detection is ever added to the replica, that check fails and
    // this pin must be promoted to the two-sided list.
    $entityOnlyPins = [
        'degenerate-invocation detection expression (#25)' => <<<'PIN'
preg_match('/(?:^|\s)!vac\s*$/i', $plain)
PIN,
    ];

    // Source bytes with comments removed (code + whitespace only).
    $codeOnly = function (string $phpSource): string {
        $code = '';
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }
        return $code;
    };

    $replicaRef    = new \ReflectionFunction($commandPipeline);
    $replicaLines  = (array) file($replicaRef->getFileName());
    $replicaSource = $codeOnly('<?php ' . implode('', array_slice(
        $replicaLines,
        $replicaRef->getStartLine() - 1,
        $replicaRef->getEndLine() - $replicaRef->getStartLine() + 1
    )));

    // Slice sanity: the reflection-driven slice must actually contain the
    // closure, or every replica-side pin below would scan the wrong bytes.
    $check('BYTE-SYNC PIN: replica slice contains the closure signature',
        strpos($replicaSource, 'function (string $storedMessage): ?string') !== false);

    // Missing-entity diagnosis: fail with ONE clear label if Post.php moved
    // or was renamed, instead of eight misleading "pipeline changed?" failures.
    $entityPath   = __DIR__ . '/../src/addons/Cav7/SteamChecker/XF/Entity/Post.php';
    $entityExists = is_file($entityPath);
    $check('BYTE-SYNC PIN: entity file Post.php exists at pinned path'
        . ' (entity file missing — moved/renamed? update the path here)',
        $entityExists);
    $entitySource = $entityExists
        ? $codeOnly((string) file_get_contents($entityPath))
        : '';

    // Anti-vacuity: a deleted pin entry must fail here, not pass silently.
    $check('BYTE-SYNC PIN: pin lists contain all 9 pinned expressions (8 two-sided + 1 entity-only)',
        count($pins) === 8 && count($entityOnlyPins) === 1);

    foreach ($pins as $pinName => $pinExpression) {
        // Anti-vacuity: an emptied pin body would make both strpos checks
        // below pass against any haystack (strpos($h, '') === 0).
        $check('BYTE-SYNC PIN: ' . $pinName . ' pin body is non-trivial',
            strlen(trim($pinExpression)) >= 10);
        $check('BYTE-SYNC PIN: ' . $pinName . ' appears verbatim in the $commandPipeline'
            . ' replica (replica changed? re-sync this pin list)',
            strpos($replicaSource, $pinExpression) !== false);
        $check('BYTE-SYNC PIN: ' . $pinName . ' appears verbatim in Post.php'
            . ' (entity pipeline changed? re-sync the BYTE-SYNC PIN replica, then this pin list)',
            strpos($entitySource, $pinExpression) !== false);
    }

    foreach ($entityOnlyPins as $pinName => $pinExpression) {
        // Same anti-vacuity guard as the two-sided pins.
        $check('BYTE-SYNC PIN: ' . $pinName . ' pin body is non-trivial',
            strlen(trim($pinExpression)) >= 10);
        $check('BYTE-SYNC PIN: ' . $pinName . ' appears verbatim in Post.php'
            . ' (detection changed? re-sync Issue25DegenerateInvocationTest, then this pin)',
            strpos($entitySource, $pinExpression) !== false);
        // Single-sidedness guard: the replica deliberately excludes the
        // detection (see the pin's comment). If it ever appears there,
        // promote this entry to the two-sided $pins list instead.
        $check('BYTE-SYNC PIN: ' . $pinName . ' is absent from the $commandPipeline'
            . ' replica (deliberately single-sided — promote to two-sided if added)',
            strpos($replicaSource, $pinExpression) === false);
    }

    $fixture = '[QUOTE="VAC Bot, post: 123, member: 99"]' . "\n"
        . $apiError . "\n"
        . '[/QUOTE]' . "\n"
        . 'Looks like the check failed — can someone take a look?';

    $check('quote-reply pipeline does NOT match !vac in the quoted instruction (issue #16)',
        $commandPipeline($fixture) === null);

    // Coherence: the flatten path (unbalanced quote markup, or the bare
    // instruction line itself) extracts exactly the placeholder token pinned
    // through resolveSteamId() above. If the wording changes, this fails
    // until the pinned token list is re-synced.
    $check('flatten path extracts the pinned placeholder token from the instruction line',
        $commandPipeline($line) === 'your');

    // Same fixture with a real command typed below the quote: the typed
    // token must be the one captured (no shadowing by the quoted line).
    $check('quote-reply pipeline captures the command typed below the quote',
        $commandPipeline($fixture . "\n" . '!vac 76561198000000001')
            === '76561198000000001');

    // Existing failure content must remain.
    $check('unresolvable reply keeps its header',
        strpos($unresolvable, '[B]Steam VAC Check[/B]') === 0);
    $check('unresolvable reply keeps the manual-check warning',
        strpos($unresolvable,
            'Could not determine a valid Steam ID from the application. Manual check required.') !== false);
    $check('unresolvable reply keeps the raw value line',
        strpos($unresolvable, 'Raw value: bogus-value') !== false);
    // SteamID line is a clickable profile link since issue #5.
    $check('API-error reply keeps the SteamID line',
        strpos($apiError,
            'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]') !== false);
    $check('API-error reply keeps the manual-check warning',
        strpos($apiError,
            'Steam API error — could not complete the ban check. Manual check required.') !== false);

    // AC4: successful ban report is unchanged (banned and clean variants).
    $bannedReport = $call('buildBanReportMessage', '76561197960287930', [
        'NumberOfVACBans'  => 2,
        'NumberOfGameBans' => 1,
        'CommunityBanned'  => true,
        'EconomyBan'       => 'banned',
        'DaysSinceLastBan' => 30,
    ]);
    $expectedBanned = implode("\n", [
        '[B]Steam VAC Check[/B]',
        'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]', // linked since issue #5
        'Profile Name: (unknown)', // null persona name (issue #6) — builder called without a fetch
        'VAC Bans: 2',
        'Game Bans: 1',
        'Days Since Last Ban: 30',
        'Community Banned: Yes',
        'Economy Ban: banned',
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Ban(s) detected — review required.[/B][/COLOR]',
    ]);
    $check('ban report (bans detected) is byte-for-byte unchanged',
        $bannedReport === $expectedBanned);

    $cleanReport = $call('buildBanReportMessage', '76561197960287930', [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => false,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 0,
    ]);
    $expectedClean = implode("\n", [
        '[B]Steam VAC Check[/B]',
        'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]', // linked since issue #5
        'Profile Name: (unknown)', // null persona name (issue #6) — builder called without a fetch
        'VAC Bans: 0',
        'Game Bans: 0',
        'Community Banned: No',
        'Economy Ban: none',
        '[COLOR=rgb(39, 179, 11)][B]✅ No bans found.[/B][/COLOR]',
    ]);
    $check('ban report (no bans) is byte-for-byte unchanged',
        $cleanReport === $expectedClean);

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
