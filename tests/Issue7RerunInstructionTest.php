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
    // wording; pre-#17 the strip_tags-era token was '.'). The #25 usage
    // reply adds a second flatten token: its lead-in ('No Steam ID was
    // found in that [ICODE]!vac[/ICODE] command.') BBCode-strips to
    // '… !vac command.', so an unbalanced quote-reply of THAT reply
    // first-matches 'command.'. Pin those tokens and the partial manual
    // copy 'Steam64ID' through the real resolveSteamId(),
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

    foreach (['your', 'Steam64ID', 'command.'] as $token) {
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
        $decoded = html_entity_decode($bbStripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = str_replace(['<', '>'], ' ', $decoded);
        $plain = str_replace(
            [
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
            "\x00",
            $plain
        );
        $healed = preg_replace('/!\x00*v\x00*a\x00*c/i', '!vac', $plain);
        if ($healed === null) {
            $healed = $plain; // fail open per documented contract
        }
        $plain = $healed;
        $plain = str_replace("\x00", ' ', $plain);

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
    // failure instead of thirteen misleading "pipeline changed?" ones), the
    // pin count is pinned, and every pin body must be non-trivial — strpos
    // with an empty needle matches any haystack.
    //
    // The neutralization is the multi-step sentinel+heal sequence (issue #31):
    // decode -> brackets-to-space -> family-to-NUL-sentinel -> heal the '!vac'
    // literal across sentinels -> sentinels-to-space. Each load-bearing
    // statement is pinned: the entity-decode, the angle-bracket str_replace,
    // the family->sentinel str_replace (the FULL multi-line call, including
    // leading indentation, so drift in the replacement target "\x00" / the
    // needle list / the subject is caught — not just the array), the heal
    // preg_replace and its fail-open fallback, and the sentinel->space
    // str_replace. The quote-strip pin is likewise the full multi-line call.
    // The family needle list is the separator/format-control family (ADR-0001,
    // issue #31: every Zs/Zl/Zp/Cf code point at Unicode 16.0 minus U+0020 —
    // 188 entries, generated once and pasted; the #17 brackets now neutralize
    // in their own str_replace step, so the family array is exactly the 188
    // and the COMPLETENESS pin below asserts that count). Dropping any single
    // code point from either file breaks the verbatim match and fails the pin.
    // On a Unicode bump, rerun the ADR-0001 generator and re-sync all three
    // places.
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
        'entity-decode expression' => <<<'PIN'
$decoded = html_entity_decode($bbStripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
PIN,
        'angle-bracket neutralize call' => <<<'PIN'
$plain = str_replace(['<', '>'], ' ', $decoded);
PIN,
        'family->sentinel neutralize call' => <<<'PIN'
        $plain = str_replace(
            [
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
            "\x00",
            $plain
        );
PIN,
        'literal-interior heal expression' => <<<'PIN'
$healed = preg_replace('/!\x00*v\x00*a\x00*c/i', '!vac', $plain);
PIN,
        'literal-interior heal fail-open fallback' => <<<'PIN'
$healed = $plain;
PIN,
        'sentinel-to-space expression' => <<<'PIN'
$plain = str_replace("\x00", ' ', $plain);
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
    $check('BYTE-SYNC PIN: pin lists contain all 14 pinned expressions (13 two-sided + 1 entity-only)',
        count($pins) === 13 && count($entityOnlyPins) === 1);

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

    // ------------------------------------------------------------------------
    // COMPLETENESS pin (issue #31, ADR-0001 AC1).
    //
    // The byte-SYNC pins above prove the three places stay byte-IDENTICAL, but
    // identical-and-wrong still passes them: drop one needle from all three at
    // once and every check above stays green. This pin closes that gap by
    // asserting the family needle set is EXACTLY the canonical 188 code points
    // (Unicode 16.0 — every Zs/Zl/Zp/Cf code point minus U+0020). Adding or
    // removing a member fails HERE even when the three places agree.
    //
    // The canonical set is built from the ADR-0001 category RANGES — the
    // explicit spec, auditable against the ADR — and compared against the code
    // points extracted from the family->sentinel pin body, which the byte-SYNC
    // pins above tie to both Post.php and the replica. So a mismatch in any of
    // the three places surfaces as either a byte-SYNC failure (places diverged)
    // or a COMPLETENESS failure (places agree but the set is wrong).
    // ------------------------------------------------------------------------
    $canonicalRanges = [
        [0x00A0, 0x00A0], [0x00AD, 0x00AD], [0x0600, 0x0605], [0x061C, 0x061C],
        [0x06DD, 0x06DD], [0x070F, 0x070F], [0x0890, 0x0891], [0x08E2, 0x08E2],
        [0x1680, 0x1680], [0x180E, 0x180E], [0x2000, 0x200F], [0x2028, 0x202F],
        [0x205F, 0x2064], [0x2066, 0x206F], [0x3000, 0x3000], [0xFEFF, 0xFEFF],
        [0xFFF9, 0xFFFB], [0x110BD, 0x110BD], [0x110CD, 0x110CD],
        [0x13430, 0x1343F], [0x1BCA0, 0x1BCA3], [0x1D173, 0x1D17A],
        [0xE0001, 0xE0001], [0xE0020, 0xE007F],
    ]; // U+2065 deliberately absent: Cn (unassigned) inside an otherwise-Cf run
    $canonicalCps = [];
    foreach ($canonicalRanges as [$lo, $hi]) {
        for ($cp = $lo; $cp <= $hi; $cp++) {
            $canonicalCps[$cp] = true;
        }
    }
    $canonicalCps = array_keys($canonicalCps);
    sort($canonicalCps);

    $check('COMPLETENESS: canonical Zs/Zl/Zp/Cf spec expands to exactly 188 code points',
        count($canonicalCps) === 188);

    // Extract the "\u{...}" needles from the family->sentinel pin body.
    preg_match_all('~\\\\u\{([0-9A-Fa-f]+)\}~', $pins['family->sentinel neutralize call'], $needleMatches);
    $needleCps = array_map('hexdec', $needleMatches[1]);

    $check('COMPLETENESS: family needle list has exactly 188 entries'
        . ' (brackets neutralize in their own step, so they are not counted here)',
        count($needleCps) === 188);
    $check('COMPLETENESS: no duplicate needles in the family list',
        count($needleCps) === count(array_unique($needleCps)));
    sort($needleCps);
    $check('COMPLETENESS: family needle set equals the canonical Zs/Zl/Zp/Cf spec'
        . ' (a member added to OR removed from all three places at once fails here)',
        $needleCps === $canonicalCps);

    // Guard the "188 exactly" claim: the angle brackets must still be
    // neutralized in their own step, so "exactly 188 family entries" can never
    // silently mean the #17 bracket guard was dropped.
    $check('COMPLETENESS: angle brackets still neutralized in their own step (not folded away)',
        strpos($pins['angle-bracket neutralize call'], "['<', '>'], ' '") !== false);

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
