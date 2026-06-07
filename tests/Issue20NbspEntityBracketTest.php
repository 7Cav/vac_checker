<?php

/**
 * Issues #20 + #21 — !vac normalization must run AFTER entity decode.
 *
 * #20: the final match's `\s` is ASCII-only (no /u), so a U+00A0 NO-BREAK
 * SPACE separator — pasted raw from rendered HTML, or produced by the
 * decode step from `&nbsp;` — silently dropped the command (no check, no
 * reply, no log). Fix: neutralize U+00A0 to a regular space alongside the
 * angle brackets, applied to the DECODED string.
 *
 * #21: bracket neutralization formerly ran BEFORE html_entity_decode, so
 * entity-encoded brackets (`&lt;`/`&gt;`/`&#60;`…) escaped it — the decode
 * reintroduced literal brackets into the captured token and resolveSteamId()
 * rejected it. Fix: same reorder — neutralize the decoded string, so entity
 * brackets become whitespace exactly like literal ones (#17 contract:
 * brackets become whitespace, never token characters).
 *
 * Both fixed by one plain str_replace on the decoded string — no new PCRE,
 * preserving the "every step either cannot fail or fails loudly" property.
 *
 * Self-contained: predefines a stub XFCP_Post proxy base class and a spy
 * \Cav7\SteamChecker\SteamChecker (the real SteamChecker.php is never loaded)
 * before requiring the real Post.php, then drives the protected _postSave()
 * via reflection. No framework, no network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue20NbspEntityBracketTest.php
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

    $logsClean = function (): bool {
        return \XF::$loggedErrors === [] && \XF::$loggedExceptions === [];
    };

    $realId = '76561198000000001';
    $nbsp   = "\u{00A0}"; // U+00A0 NO-BREAK SPACE (UTF-8: C2 A0)

    // -----------------------------------------------------------------------
    // AC (#20a): literal U+00A0 separator — pasted raw from rendered HTML —
    // triggers the check exactly as a plain space does. Pre-fix: ASCII-only
    // \s failed on U+00A0 → silent no-match (no check, no reply, no log).
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac' . $nbsp . $realId]);
    $invoke($post);
    $check('repro (#20a) "!vac<U+00A0>id": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (#20a): check runs against the typed id',
        $manuals() === [$realId]);
    $check('repro (#20a): logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // AC (#20b): entity-encoded NBSP — `&nbsp;` decodes to U+00A0 inside the
    // pipeline itself, so this repro forces the neutralization to run AFTER
    // the decode (pre-decode normalization could never see the U+00A0).
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac&nbsp;' . $realId]);
    $invoke($post);
    $check('repro (#20b) "!vac&nbsp;id": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (#20b): check runs against the typed id',
        $manuals() === [$realId]);
    $check('repro (#20b): logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // NBSP entity siblings (#20c): the numeric ('&#160;', '&#xA0;') and
    // named ('&NonBreakingSpace;') forms all decode to U+00A0 and must
    // behave exactly like '&nbsp;'. Kill power: special-casing '&nbsp;'
    // (e.g. a targeted str_replace) or downgrading the decode flags from
    // ENT_HTML5 — which alone drops '&NonBreakingSpace;' — would re-drop
    // these forms while the '&nbsp;' checks above keep passing.
    // -----------------------------------------------------------------------
    foreach (['&#160;', '&#xA0;', '&NonBreakingSpace;'] as $entity) {
        $resetOptions();
        $post = $makePost(['message' => '!vac' . $entity . $realId]);
        $invoke($post);
        $check('NBSP sibling (#20c) "!vac' . $entity . 'id": exactly one check fires with the bare id',
            $manuals() === [$realId]);
        $check('NBSP sibling (#20c) "' . $entity . '": logs stay clean',
            $logsClean());
    }

    // -----------------------------------------------------------------------
    // AC (#21a): entity-encoded brackets are neutralized to whitespace —
    // never token characters. Pre-fix, the decode reintroduced literal
    // brackets AFTER the neutralization had run, so the captured token was
    // '<id>' with brackets and resolveSteamId() rejected it (loud, but
    // violates the #17 contract). Post-fix: same outcome as literal
    // '!vac <id>' — the BARE id is captured.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac &lt;' . $realId . '&gt;']);
    $invoke($post);
    $check('repro (#21a) "!vac &lt;id&gt;": exactly one manual check fires',
        count($manuals()) === 1);
    $check('repro (#21a): captured token is the BARE id (no angle brackets)',
        $manuals() === [$realId]);
    $check('repro (#21a): logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // AC (#21b): numeric-entity brackets ('&#60;'/'&#62;') get the same
    // treatment — html_entity_decode covers them identically.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac &#60;' . $realId . '&#62;']);
    $invoke($post);
    $check('repro (#21b) "!vac &#60;id&#62;": captured token is the BARE id',
        $manuals() === [$realId]);
    $check('repro (#21b): logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // Decode-order pin: a single-pass html_entity_decode never decodes
    // recursively — '&amp;lt;' yields the literal TEXT '&lt;', not a
    // bracket — and the pipeline decodes exactly once, so the literal text
    // survives in the token and the neutralization correctly leaves it
    // alone. Guards against a future "decode harder" change silently
    // widening the neutralization.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac &amp;lt;' . $realId]);
    $invoke($post);
    $check('decode-order pin: "&amp;lt;" yields the literal text "&lt;" glued to the token',
        $manuals() === ['&lt;' . $realId]);
    $check('decode-order pin: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // Regression (#17 contract preserved): plain-space command and literal
    // bracket neutralization behave exactly as the existing #17 tests pin.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac ' . $realId]);
    $invoke($post);
    $check('regression: plain "!vac id" still fires with the id',
        $manuals() === [$realId]);
    $check('regression plain: logs stay clean', $logsClean());

    $resetOptions();
    $post = $makePost(['message' => '!vac <' . $realId . '>']);
    $invoke($post);
    $check('regression: literal "!vac <id>" still captures the BARE id',
        $manuals() === [$realId]);
    $check('regression literal brackets: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // Degenerate-argument characterization: an argument made ONLY of entity
    // brackets/NBSP dissolves to whitespace under the post-decode
    // neutralization, so the command goes unmatched — NO check fires, NO
    // reply is sent, and nothing is logged. Deliberate, characterized
    // contract: consistent with literal '!vac <>' (known-silent since #17).
    // A follow-up issue tracks possibly replying with the re-run
    // instruction instead.
    // -----------------------------------------------------------------------
    foreach (['!vac &lt;&gt;', '!vac &nbsp;'] as $degenerate) {
        $resetOptions();
        $post = $makePost(['message' => $degenerate]);
        $invoke($post);
        $check('degenerate "' . $degenerate . '": no check fires and no reply is sent',
            $manuals() === []
            && \Cav7\SteamChecker\SteamChecker::$constructed === []);
        $check('degenerate "' . $degenerate . '": logs stay clean (silent contract)',
            $logsClean());
    }

    // -----------------------------------------------------------------------
    // NBSP-inside-token characterization: U+00A0 in the middle of an id is
    // neutralized to a space like any other, so the token SPLITS there and
    // the check fires with the truncated prefix — runManual('7656119') —
    // which downstream resolution rejects loudly (resolveSteamId() returns
    // null; runManual() posts the unresolvable reply). Chosen behavior,
    // not accidental: pinned so the truncation stays a visible contract.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac 7656119' . $nbsp . '8000000001']);
    $invoke($post);
    $check('NBSP inside token: check fires with the truncated token "7656119"',
        $manuals() === ['7656119']);
    $check('NBSP inside token: logs stay clean (rejection happens downstream)',
        $logsClean());

    // -----------------------------------------------------------------------
    // Silent-failure audit (#20): the pre-fix NBSP no-match produced no
    // observable trail. Post-fix the command fires, and the fix introduces
    // no new error-log noise on the happy path.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac' . $nbsp . $realId]);
    $invoke($post);
    $check('NBSP happy path logs no errors and throws nothing',
        \XF::$loggedErrors === [] && \XF::$loggedExceptions === []);

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
