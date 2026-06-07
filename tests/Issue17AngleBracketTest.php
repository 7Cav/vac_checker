<?php

/**
 * Issue #17 — literal '<'/'>' must never swallow a !vac command.
 *
 * The parser formerly ran strip_tags() over the normalized message, which
 * deletes everything from an unterminated '<' (a '<' followed by a
 * non-whitespace char) to the next '>' or end-of-string — silently dropping
 * valid commands. The fix neutralizes literal '<' and '>' by replacing each
 * with a single space (plain str_replace — no PCRE, no fail-open surface)
 * and removes strip_tags() from the pipeline entirely.
 *
 * Self-contained: predefines a stub XFCP_Post proxy base class and a spy
 * \Cav7\SteamChecker\SteamChecker (the real SteamChecker.php is never loaded)
 * before requiring the real Post.php, then drives the protected _postSave()
 * via reflection. No framework, no network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue17AngleBracketTest.php
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
     * reply position.
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

    $manuals = function (): array {
        return \Cav7\SteamChecker\SteamChecker::$runManualCalls;
    };

    $realId     = '76561198000000001';
    $profileUrl = 'https://steamcommunity.com/profiles/' . $realId;

    // -----------------------------------------------------------------------
    // AC1: each formerly-swallowed repro input fires exactly one manual check
    // with the correct identifier. Pre-fix, strip_tags() ate from the
    // unterminated '<' to the next '>' or EOL and the command vanished
    // silently (no check, no log).
    // -----------------------------------------------------------------------

    // (a) '<3' heart in chat: strip_tags ate '<3 !vac …' to EOL.
    $resetOptions();
    $post = $makePost(['message' => 'aww <3 !vac ' . $realId]);
    $invoke($post);
    $check('repro (a) "aww <3 !vac <id>": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (a): check runs against the typed id',
        $manuals() === [$realId]);

    // (b) bare less-than in prose: strip_tags ate '<b do !vac …' to EOL.
    $resetOptions();
    $post = $makePost(['message' => 'if a<b do !vac ' . $realId]);
    $invoke($post);
    $check('repro (b) "if a<b do !vac <id>": exactly one manual check fires with the id',
        $manuals() === [$realId]);

    // (c) staffer copies the old instruction's angle brackets around a bare
    // id: strip_tags ate '<76561198000000001>' as a pseudo-tag. The BARE id
    // must be captured — the brackets become whitespace, never token chars.
    $resetOptions();
    $post = $makePost(['message' => '!vac <' . $realId . '>']);
    $invoke($post);
    $check('repro (c) "!vac <id>": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (c): captured token is the BARE id (no angle brackets)',
        $manuals() === [$realId]);

    // (d) same with a full profile URL inside the brackets.
    $resetOptions();
    $post = $makePost(['message' => '!vac <' . $profileUrl . '>']);
    $invoke($post);
    $check('repro (d) "!vac <profile URL>": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (d): captured token is the full profile URL (no angle brackets)',
        $manuals() === [$profileUrl]);

    // -----------------------------------------------------------------------
    // AC2: boundary cases that already matched pre-fix must still match.
    // -----------------------------------------------------------------------

    // '< ' (lt + space) before the command: never a pseudo-tag.
    $resetOptions();
    $post = $makePost(['message' => '< !vac ' . $realId]);
    $invoke($post);
    $check('boundary: "< " (lt+space) before the command still matches',
        $manuals() === [$realId]);

    // Closed '<b>…</b>' span before the command: pre-fix the tags were
    // stripped but the command after the closing '>' survived; post-fix the
    // brackets are spaces and the command still fires.
    $resetOptions();
    $post = $makePost(['message' => 'see <b>important</b> !vac ' . $realId]);
    $invoke($post);
    $check('boundary: closed <b>…</b> span before the command still matches',
        $manuals() === [$realId]);

    // -----------------------------------------------------------------------
    // AC3: neutralized-to-spaces text must never PREVENT a !vac match — text
    // formerly inside a closed '<…>' span no longer disappears, and a command
    // inside such a span now fires (improvement over strip_tags, which
    // deleted the whole span including the command).
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '<!vac ' . $realId . '>']);
    $invoke($post);
    $check('command inside a closed <…> span fires (was deleted by strip_tags)',
        $manuals() === [$realId]);

    // -----------------------------------------------------------------------
    // AC4: angle brackets never act as token separators retroactively — a
    // command whose argument abuts a '<' captures only up to the bracket.
    // '!vac<id>' (no space) gains its separator from the neutralized '<'.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac<' . $realId . '>']);
    $invoke($post);
    $check('characterization: "!vac<id>" (no space) fires with the bare id',
        $manuals() === [$realId]);

    // -----------------------------------------------------------------------
    // AC5: interplay with the earlier pipeline steps is preserved — quote
    // stripping (issue #16) and [URL] unwrapping still run before the
    // angle-bracket neutralization.
    // -----------------------------------------------------------------------

    // Quoted command containing angle brackets is still invisible; the typed
    // command below (also bracketed) fires with the bare id.
    $resetOptions();
    $post = $makePost(['message' =>
        '[QUOTE="Bot, post: 1, member: 99"]!vac <76561197999999999>[/QUOTE]' . "\n"
        . '!vac <' . $realId . '>']);
    $invoke($post);
    $check('quote strip still precedes neutralization: typed bracketed id wins',
        $manuals() === [$realId]);

    // [URL]-wrapped argument still unwraps; surrounding '<…>' neutralized.
    $resetOptions();
    $post = $makePost(['message' => '!vac <[URL]' . $profileUrl . '[/URL]>']);
    $invoke($post);
    $check('[URL] unwrap still precedes neutralization: URL captured bare',
        $manuals() === [$profileUrl]);

    // -----------------------------------------------------------------------
    // AC6: no silent regression for the plain command (no brackets at all).
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => 'Please check !vac ' . $realId . ' thanks']);
    $invoke($post);
    $check('plain bracket-free command behaves as today',
        $manuals() === [$realId]);

    // -----------------------------------------------------------------------
    // AC7: no strip_tags() call remains in the !vac parsing pipeline.
    // Source-level pin: tokenize Post.php and assert no strip_tags identifier
    // appears in CODE (doc comments may still reference it historically).
    // -----------------------------------------------------------------------
    $postSource = (string) file_get_contents(
        __DIR__ . '/../src/addons/Cav7/SteamChecker/XF/Entity/Post.php'
    );
    $stripTagsInCode = false;
    foreach (token_get_all($postSource) as $token) {
        if (is_array($token)
            && $token[0] === T_STRING
            && strcasecmp($token[1], 'strip_tags') === 0
        ) {
            $stripTagsInCode = true;
            break;
        }
    }
    $check('no strip_tags() call remains in Post.php code (comments excluded)',
        !$stripTagsInCode);

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
