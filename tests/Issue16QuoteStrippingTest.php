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
