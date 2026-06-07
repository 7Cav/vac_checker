<?php

/**
 * Issue #16 — quoted text must be invisible to the !vac command parser.
 *
 * Self-contained: predefines a stub XFCP_Post proxy base class and a spy
 * \Cav7\SteamChecker\SteamChecker (the real SteamChecker.php is never loaded)
 * before requiring the real Post.php, then drives the protected _postSave()
 * via reflection. No framework, no network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue16QuoteStrippingTest.php
 *
 * Exits non-zero on any failure.
 */

// ---------------------------------------------------------------------------
// Spy SteamChecker — stands in for the real class so "a check fired" is
// observable. Post.php constructs it and calls run() / runManual().
// ---------------------------------------------------------------------------

namespace Cav7\SteamChecker {
    class SteamChecker
    {
        /** @var object[] */
        public static $constructed = [];
        /** @var int */
        public static $runCalls = 0;
        /** @var string[] */
        public static $runManualCalls = [];

        public function __construct($thread)
        {
            self::$constructed[] = $thread;
        }

        public function run(): void
        {
            self::$runCalls++;
        }

        public function runManual(string $rawSteamId): void
        {
            self::$runManualCalls[] = $rawSteamId;
        }

        public static function reset(): void
        {
            self::$constructed = [];
            self::$runCalls = 0;
            self::$runManualCalls = [];
        }
    }
}

// ---------------------------------------------------------------------------
// Stub XFCP proxy base — must exist before Post.php is required.
// ---------------------------------------------------------------------------

namespace Cav7\SteamChecker\XF\Entity {
    class XFCP_Post
    {
        /** @var array<string, mixed> entity properties served via __get */
        public $stubValues = [];
        /** @var bool */
        public $stubIsInsert = true;

        public function __get($name)
        {
            return $this->stubValues[$name] ?? null;
        }

        public function isInsert(): bool
        {
            return $this->stubIsInsert;
        }

        protected function _postSave()
        {
        }
    }
}

// ---------------------------------------------------------------------------
// \XF static facade stub
// ---------------------------------------------------------------------------

namespace {
    class XF
    {
        public static $optionsData = [];
        public static $loggedErrors = [];
        public static $loggedExceptions = [];

        public static function options()
        {
            return (object) self::$optionsData;
        }

        public static function logError($msg)
        {
            self::$loggedErrors[] = $msg;
        }

        public static function logException($e, $rollback = false, $prefix = '')
        {
            self::$loggedExceptions[] = $prefix . $e->getMessage();
        }
    }

    require __DIR__ . '/../src/addons/Cav7/SteamChecker/XF/Entity/Post.php';

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    $failures = 0;
    $check = function (string $label, bool $ok) use (&$failures) {
        echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
        if (!$ok) {
            $failures++;
        }
    };

    /**
     * Build a Post entity wired through the permission/routing gates:
     * insert, non-bot author, configured node, author in an allowed group,
     * reply position. Override per-test to exercise each gate.
     */
    $makePost = function (array $overrides = []) {
        $defaults = [
            'post_id'  => 101,
            'user_id'  => 5,
            'position' => 1,
            'message'  => '',
            'Thread'   => (object) ['node_id' => 42, 'thread_id' => 7],
            'User'     => (object) ['user_group_id' => 3, 'secondary_group_ids' => [8]],
        ];
        $post = new \Cav7\SteamChecker\XF\Entity\Post();
        $post->stubValues = array_merge($defaults, $overrides);
        return $post;
    };

    $resetOptions = function () {
        \XF::$optionsData = [
            'steamCheckerBotUserId'     => 99,
            'steamCheckerNodeId'        => 42,
            'steamCheckerAllowedRoleIds' => '3, 8',
            'steamCheckerDebugLog'      => false,
        ];
        \XF::$loggedErrors = [];
        \XF::$loggedExceptions = [];
    };

    $invoke = function ($post) use ($resetOptions) {
        \Cav7\SteamChecker\SteamChecker::reset();
        $ref = new \ReflectionMethod($post, '_postSave');
        $ref->setAccessible(true);
        $ref->invoke($post);
    };

    $spy = function () {
        return [
            \Cav7\SteamChecker\SteamChecker::$runCalls,
            \Cav7\SteamChecker\SteamChecker::$runManualCalls,
        ];
    };

    // Realistic bot failure reply (wording pinned by Issue7 suite). The
    // instruction line is what gets quoted into staff replies.
    $failureReply = implode("\n", [
        '[B]Steam VAC Check[/B]',
        'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]',
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Steam API error — could not complete the ban check. Manual check required.[/B][/COLOR]',
        '[I]Staff can re-run this check by replying in this thread with [ICODE]!vac <Steam64ID or profile URL>[/ICODE].[/I]',
    ]);
    $quotedFailureReply = '[QUOTE="VAC Bot, post: 123, member: 99"]' . "\n"
        . $failureReply . "\n"
        . '[/QUOTE]';

    $realId = '76561198000000001';

    // -----------------------------------------------------------------------
    // AC1: quote of the bot's failure reply + real !vac <id> typed below the
    // quote → the check runs against that id (pre-fix: shadowed by the quoted
    // instruction, which flattens to "!vac ." and first-match captures '.').
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => $quotedFailureReply . "\n" . '!vac ' . $realId]);
    $invoke($post);
    [$runs, $manuals] = $spy();
    $check('quote + real command: exactly one manual check fires',
        count($manuals) === 1);
    $check('quote + real command: check runs against the typed id, not the quoted token',
        ($manuals[0] ?? '(none)') === $realId);
    $check('quote + real command: automatic OP check does not fire',
        $runs === 0);

    // -----------------------------------------------------------------------
    // AC2: bare quote-reply of a failure reply → no check fires at all
    // (pre-fix: fires a pointless manual check on the literal token '.').
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => $quotedFailureReply]);
    $invoke($post);
    [$runs, $manuals] = $spy();
    $check('bare quote-reply: no manual check fires',
        $manuals === []);
    $check('bare quote-reply: no checker constructed at all',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // -----------------------------------------------------------------------
    // AC3: nested quote blocks fully stripped; command outside them works.
    // -----------------------------------------------------------------------
    $nested = '[QUOTE="Staff A, post: 200, member: 5"]' . "\n"
        . $quotedFailureReply . "\n"
        . 'I already tried !vac 76561197999999999 earlier.' . "\n"
        . '[/QUOTE]' . "\n"
        . '!vac ' . $realId;
    $resetOptions();
    $post = $makePost(['message' => $nested]);
    $invoke($post);
    [$runs, $manuals] = $spy();
    $check('nested quotes: check runs against the id typed outside the quotes',
        $manuals === [$realId]);

    $nestedOnly = '[QUOTE]outer text [QUOTE]inner !vac 76561197999999999[/QUOTE] more outer[/QUOTE]';
    $resetOptions();
    $post = $makePost(['message' => $nestedOnly]);
    $invoke($post);
    [$runs, $manuals] = $spy();
    $check('command only inside nested quotes: no check fires',
        $manuals === [] && \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // -----------------------------------------------------------------------
    // AC4: both bare [QUOTE] and attributed [QUOTE="…"] forms stripped.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE]use !vac 76561197999999999 please[/QUOTE]' . "\n" . '!vac ' . $realId]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('bare [QUOTE] form stripped; typed command wins',
        $manuals === [$realId]);

    $resetOptions();
    $post = $makePost(['message' =>
        '[quote="Someone, post: 1"]use !vac 76561197999999999[/quote]' . "\n" . '!vac ' . $realId]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('attributed lowercase [quote="…"] form stripped; typed command wins',
        $manuals === [$realId]);

    // -----------------------------------------------------------------------
    // Sibling quotes with the real command typed BETWEEN them: each block is
    // stripped individually, so the command survives. Kills the greedy mutant
    // (\[QUOTE[\s\S]*\[\/QUOTE\]) that would strip from the first opener to
    // the last closer and eat the command.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE="Staff A, post: 200"]first quoted !vac 76561197999999991[/QUOTE]' . "\n"
        . '!vac ' . $realId . "\n"
        . '[QUOTE="Staff B, post: 201"]second quoted !vac 76561197999999992[/QUOTE]']);
    $invoke($post);
    [, $manuals] = $spy();
    $check('sibling quotes: command typed between them fires with exactly that id',
        $manuals === [$realId]);

    // Command typed ABOVE the quote: pins the first-match + strip interplay
    // (strip happens before matching, so position relative to the quote is
    // irrelevant — the typed id always wins).
    $resetOptions();
    $post = $makePost(['message' => '!vac ' . $realId . "\n" . $quotedFailureReply]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('command above the quote: typed id wins',
        $manuals === [$realId]);

    // -----------------------------------------------------------------------
    // Characterization: attribute containing ']' — e.g. a username with a
    // bracketed tag. The opener pattern only matches up to the first ']'
    // ([QUOTE="name [admin) and the attribute residue (, post: 1"]) is then
    // consumed as quote BODY, so the block is still fully stripped up to its
    // [/QUOTE]. This works by that opener/residue interaction, not by design;
    // this test protects it from a future regex cleanup.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE="name [admin], post: 1"]quoted !vac 76561197999999993[/QUOTE]' . "\n"
        . '!vac ' . $realId]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('attribute containing "]": block fully stripped, command below fires',
        $manuals === [$realId]);

    // -----------------------------------------------------------------------
    // Characterization (accepted behavior): stray [QUOTE]/[/QUOTE] markers
    // inside [CODE] blocks pair across them — the stripper is BBCode-naive
    // and removes from the [QUOTE] in the first code block to the [/QUOTE]
    // in the second, eating a command typed between. Accepted because it
    // fails SAFE (no check fires; staff can repost without the code blocks).
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' =>
        '[CODE]example [QUOTE] marker[/CODE]' . "\n"
        . '!vac ' . $realId . "\n"
        . '[CODE]example [/QUOTE] marker[/CODE]']);
    $invoke($post);
    [, $manuals] = $spy();
    $check('stray quote markers in [CODE] blocks eat the command between (accepted, fails safe)',
        $manuals === [] && \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // -----------------------------------------------------------------------
    // AC5: !vac in a quote-free reply behaves byte-for-byte as today.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => 'Please check !vac ' . $realId . ' thanks']);
    $invoke($post);
    [, $manuals] = $spy();
    $check('quote-free reply: plain id captured as today',
        $manuals === [$realId]);

    // Auto-linked URL argument: [URL]…[/URL] unwrap still applies.
    $profileUrl = 'https://steamcommunity.com/profiles/' . $realId;
    $resetOptions();
    $post = $makePost(['message' => '!vac [URL]' . $profileUrl . '[/URL]']);
    $invoke($post);
    [, $manuals] = $spy();
    $check('quote-free reply: URL-wrapped argument unwrapped as today',
        $manuals === [$profileUrl]);

    // First !vac match wins within the user's own (unquoted) words, as today.
    $resetOptions();
    $post = $makePost(['message' => '!vac firsttoken and !vac secondtoken']);
    $invoke($post);
    [, $manuals] = $spy();
    $check('quote-free reply: first match wins as today',
        $manuals === ['firsttoken']);

    // -----------------------------------------------------------------------
    // Accepted edge case (characterization, not a requirement): unbalanced
    // quote markup fails open — degrades to current flatten-everything
    // behavior, where the quoted instruction's '!vac .' is the first match.
    // -----------------------------------------------------------------------
    $unbalanced = '[QUOTE="VAC Bot, post: 123, member: 99"]' . "\n"
        . $failureReply . "\n"
        . '!vac ' . $realId; // no [/QUOTE]
    $resetOptions();
    $post = $makePost(['message' => $unbalanced]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('unbalanced quote markup fails open (degrades to first-match behavior)',
        $manuals === ['.']);

    // -----------------------------------------------------------------------
    // Large-input regression (issue #16 hardening): the original lazy
    // per-character body pattern exhausted the PCRE JIT stack at ~24.6KB of
    // quote body on PHP 8.3 defaults, making preg_replace return null and
    // silently swallowing valid commands. Quote stripping must survive
    // realistic large posts.
    // -----------------------------------------------------------------------

    // (i) Well-formed quote with a >=64KB body + real command below it: the
    // command must fire with exactly the typed id. The 64KB body embeds a
    // COMPETING quoted '!vac <otherId>' positioned ABOVE the real command, so
    // an inert pass (strip skipped / failed-open to flatten) would first-match
    // the quoted otherId. Asserting the REAL id therefore proves the large
    // balanced quote was actually stripped — not merely that nothing exploded.
    $otherId = '76561197999999999';
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE="VAC Bot, post: 123, member: 99"]'
        . '!vac ' . $otherId . ' ' . str_repeat('a', 65536)
        . '[/QUOTE]' . "\n" . '!vac ' . $realId]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('large input: 64KB balanced quote (with a competing quoted command) stripped; real typed id fires',
        $manuals === [$realId]);

    // (ii) Unclosed [QUOTE] opener followed by a >=64KB tail containing the
    // quoted instruction text: must degrade to the documented fail-open
    // behavior (old flatten path -> the instruction's '!vac .' token), NOT
    // silence.
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE="VAC Bot, post: 123, member: 99"]' . "\n"
        . $failureReply . "\n"
        . str_repeat('x', 65536)]); // no [/QUOTE]
    $invoke($post);
    [, $manuals] = $spy();
    $check('large input: unclosed opener + 64KB tail fails open (token \'.\'), not silent',
        $manuals === ['.']);

    // -----------------------------------------------------------------------
    // Debug observability: with steamCheckerDebugLog on, the strip phase
    // emits ONE [VAC-DEBUG] summary line (post_id, blocks stripped, whether
    // !vac matched post-strip) so silent-no-fire cases are diagnosable.
    // With the option off, no such line is emitted.
    // -----------------------------------------------------------------------
    $stripDebugLines = function (): array {
        return array_values(array_filter(\XF::$loggedErrors, function ($msg) {
            return strpos($msg, '[VAC-DEBUG]') !== false
                && strpos($msg, 'quote strip') !== false;
        }));
    };

    // Debug on, quote + command: one summary line, correct fields.
    $resetOptions();
    \XF::$optionsData['steamCheckerDebugLog'] = true;
    $post = $makePost(['message' => $quotedFailureReply . "\n" . '!vac ' . $realId]);
    $invoke($post);
    $lines = $stripDebugLines();
    $check('debug on: exactly one quote-strip summary line emitted',
        count($lines) === 1);
    $check('debug on: summary reports post_id, blocks stripped and a match',
        isset($lines[0])
        && strpos($lines[0], 'post_id=101') !== false
        && strpos($lines[0], 'stripped_blocks=1') !== false
        && strpos($lines[0], 'vac_match=yes') !== false);

    // Debug on, bare quote-reply (nothing fires): summary still emitted,
    // reporting no match — the silent path is observable.
    $resetOptions();
    \XF::$optionsData['steamCheckerDebugLog'] = true;
    $post = $makePost(['message' => $quotedFailureReply]);
    $invoke($post);
    $lines = $stripDebugLines();
    $check('debug on: bare quote-reply still emits the summary, reporting no match',
        count($lines) === 1
        && strpos($lines[0], 'vac_match=no') !== false);

    // Debug off: no quote-strip summary line.
    $resetOptions();
    $post = $makePost(['message' => $quotedFailureReply . "\n" . '!vac ' . $realId]);
    $invoke($post);
    $check('debug off: no quote-strip summary line emitted',
        $stripDebugLines() === []);

    // -----------------------------------------------------------------------
    // PCRE fail-open guards: every preg step in the !vac pipeline (step-0
    // quote strip, [URL] unwrap, BBCode strip) must, on a PCRE failure
    // (preg_replace -> null), log a [Cav7/SteamChecker] error and fall back to
    // the pre-call string — NEVER let null propagate and silently swallow a
    // valid command typed below. Each case forces a real null by squeezing
    // pcre.backtrack_limit so a tag-heavy body exhausts the targeted step.
    //
    // CRITICAL: ini_set('pcre.*') is process-global. $withPcreLimits saves the
    // prior values and restores them in a finally so a leaked low limit cannot
    // bleed into any later check in this same run.
    // -----------------------------------------------------------------------
    $withPcreLimits = function (array $limits, callable $fn) {
        $saved = [];
        foreach ($limits as $name => $value) {
            $saved[$name] = ini_get($name);
            ini_set($name, (string) $value);
        }
        try {
            return $fn();
        } finally {
            foreach ($saved as $name => $value) {
                ini_set($name, $value);
            }
        }
    };

    $pcreGuardErrors = function (string $needle): array {
        return array_values(array_filter(\XF::$loggedErrors, function ($msg) use ($needle) {
            return strpos($msg, '[Cav7/SteamChecker]') !== false
                && strpos($msg, 'failed (PCRE:') !== false
                && strpos($msg, $needle) !== false;
        }));
    };

    // (a) [URL]-unwrap (the HIGH): a [URL ...] opener + long body exhausts the
    // backtrack limit on the lazy (.*?), nulling the unwrap. Without the guard,
    // null flows through the rest of the pipeline and the real command below is
    // SILENTLY swallowed; with it, the unwrap fails open and the command fires.
    $resetOptions();
    $urlBombPost = $makePost(['message' =>
        '!vac ' . $realId . "\n"
        . '[URL]' . str_repeat('a', 5000) . '[/URL]']);
    $withPcreLimits(['pcre.backtrack_limit' => 100], function () use ($invoke, $urlBombPost) {
        $invoke($urlBombPost);
    });
    [, $manuals] = $spy();
    $check('PCRE fail-open: [URL]-unwrap null does NOT swallow the command (real id fires)',
        $manuals === [$realId]);
    $check('PCRE fail-open: [URL]-unwrap null logs one [Cav7/SteamChecker] URL PCRE error',
        count($pcreGuardErrors('URL unwrap')) === 1);

    // (b) step-0 quote strip: a [QUOTE] block whose body is thousands of lone
    // '[' forces that many iterations of the possessive loop, exhausting the
    // backtrack limit and nulling the strip. The command typed BELOW the quote
    // must still fire via the documented fail-open (revert to unstripped msg).
    $resetOptions();
    $quoteBombPost = $makePost(['message' =>
        '[QUOTE]' . str_repeat('[a', 5000) . '[/QUOTE]' . "\n" . '!vac ' . $realId]);
    $withPcreLimits(['pcre.backtrack_limit' => 100], function () use ($invoke, $quoteBombPost) {
        $invoke($quoteBombPost);
    });
    [, $manuals] = $spy();
    $check('PCRE fail-open: step-0 quote-strip null does NOT swallow the command (real id fires)',
        $manuals === [$realId]);
    $check('PCRE fail-open: step-0 quote-strip null logs one [Cav7/SteamChecker] PCRE error',
        count($pcreGuardErrors('Quote stripping')) === 1);

    // step-0 fallback must ALSO reset the debug block accumulator. This needs a
    // genuine partial-strip-THEN-error (a single-iteration bomb nulls atomically
    // with strippedBlocks still 0, so it cannot distinguish the reset): iteration
    // 1 strips the inner clean quote (strippedBlocks=1), which MERGES the two
    // surrounding 60-bracket runs into one 120-bracket run; iteration 2's attempt
    // on that merged run exceeds the backtrack limit and nulls -> fallback to the
    // original message (0 strips effectively applied). Without the reset the
    // summary misreports stripped_blocks=1 while the message handed downstream
    // had nothing removed.
    $resetOptions();
    \XF::$optionsData['steamCheckerDebugLog'] = true;
    $run = str_repeat('[a', 60); // 60 < limit=100 < 120 merged (per-attempt steps)
    $mergeThenErrPost = $makePost(['message' =>
        '[QUOTE]' . $run . '[QUOTE]x[/QUOTE]' . $run . '[/QUOTE]'
        . "\n" . '!vac ' . $realId]);
    $withPcreLimits(['pcre.backtrack_limit' => 100], function () use ($invoke, $mergeThenErrPost) {
        $invoke($mergeThenErrPost);
    });
    [, $manuals] = $spy();
    $lines = $stripDebugLines();
    $check('PCRE fail-open: partial-strip-then-error still fires the command (fail-open)',
        $manuals === [$realId]);
    $check('PCRE fail-open: step-0 fallback resets stripped_blocks to 0 in the debug summary',
        count($lines) === 1 && strpos($lines[0], 'stripped_blocks=0') !== false);

    // (c) BBCode strip (/\[[^\]]*\]/): this guard exists in Post.php for the
    // same fail-open reason, but is NOT behaviorally coverable in this suite.
    // PCRE2 auto-possessifies [^\]]* (since ']' cannot follow it), eliminating
    // backtracking, so it only nulls at backtrack_limit=1 AND only while the
    // pattern runs un-JIT'd. By the time these cases run the pattern is already
    // JIT-compiled by earlier checks (toggling pcre.jit cannot recompile a
    // cached pattern mid-process), so no input reliably nulls it here. The
    // guard is retained as cheap defense-in-depth (PCRE config/version drift).

    // -----------------------------------------------------------------------
    // AC6: permission/routing gates — characterization, behavior unchanged.
    // Every gate test uses a quote-free valid command so only the gate varies.
    // -----------------------------------------------------------------------
    $validCommand = '!vac ' . $realId;

    // Gate: insert-only (edits never fire).
    $resetOptions();
    $post = $makePost(['message' => $validCommand]);
    $post->stubIsInsert = false;
    $invoke($post);
    $check('gate: edit (not insert) fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: bot's own posts never fire.
    $resetOptions();
    $post = $makePost(['message' => $validCommand, 'user_id' => 99]);
    $invoke($post);
    $check('gate: bot self-post fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: missing thread fires nothing.
    $resetOptions();
    $post = $makePost(['message' => $validCommand, 'Thread' => null]);
    $invoke($post);
    $check('gate: missing thread fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: wrong node fires nothing.
    $resetOptions();
    $post = $makePost([
        'message' => $validCommand,
        'Thread'  => (object) ['node_id' => 43, 'thread_id' => 7],
    ]);
    $invoke($post);
    $check('gate: post outside the enlistment node fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: empty allowed-roles option disables the command.
    $resetOptions();
    \XF::$optionsData['steamCheckerAllowedRoleIds'] = '   ';
    $post = $makePost(['message' => $validCommand]);
    $invoke($post);
    $check('gate: blank allowed-roles option fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: author in no allowed group fires nothing.
    $resetOptions();
    $post = $makePost([
        'message' => $validCommand,
        'User'    => (object) ['user_group_id' => 2, 'secondary_group_ids' => [4, 6]],
    ]);
    $invoke($post);
    $check('gate: author outside all allowed groups fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Gate: secondary group membership grants access.
    $resetOptions();
    $post = $makePost([
        'message' => $validCommand,
        'User'    => (object) ['user_group_id' => 2, 'secondary_group_ids' => [8]],
    ]);
    $invoke($post);
    [, $manuals] = $spy();
    $check('gate: secondary-group membership allows the command',
        $manuals === [$realId]);

    // Gate: missing User relation fires nothing.
    $resetOptions();
    $post = $makePost(['message' => $validCommand, 'User' => null]);
    $invoke($post);
    $check('gate: missing User relation fires nothing',
        \Cav7\SteamChecker\SteamChecker::$constructed === []);

    // Routing: position 0 is the automatic OP check (run, not runManual),
    // and it ignores the allowed-roles gate.
    $resetOptions();
    $post = $makePost(['message' => 'an enlistment application', 'position' => 0]);
    $invoke($post);
    [$runs, $manuals] = $spy();
    $check('routing: OP (position 0) fires the automatic check',
        $runs === 1 && $manuals === []);

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
