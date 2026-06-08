<?php

/**
 * Issue #23 — invisible Unicode separators beyond U+00A0 must not silently
 * drop !vac commands.
 *
 * The final match's `\s` is ASCII-only (no /u), and #20 neutralized exactly
 * one non-ASCII code point (U+00A0). Every other separator in the known
 * invisible-rendering family (#23's scoped list) —
 * the U+2000–U+200D quad/space block (incl. ZWSP/ZWNJ/ZWJ), U+202F NARROW
 * NO-BREAK SPACE, U+205F MEDIUM MATHEMATICAL SPACE, U+2060 WORD JOINER,
 * U+3000 IDEOGRAPHIC SPACE, U+FEFF ZWNBSP/BOM — glued `!vac` and its
 * argument into one token: silent no-match (no check, no reply, no log).
 *
 * Fix: extend the post-decode str_replace neutralization with the family
 * above. Plain str_replace — no PCRE, preserving the "every step either
 * cannot fail or fails loudly" property. /u on the final match is NOT a
 * substitute: Unicode \s covers the family's Zs spaces but not its five Cf
 * format characters (U+200B/U+200C/U+200D/U+2060/U+FEFF), and it adds a
 * PCRE failure mode.
 *
 * Entity forms (&thinsp;, &numsp;, &emsp;, &MediumSpace;, &NoBreak;,
 * &ZeroWidthSpace;) are covered for free because the neutralization runs
 * AFTER the single entity decode — pinned here so a pipeline reorder that
 * breaks the free coverage fails this test.
 *
 * Issue #31 (ADR-0001) later closed the family by category: the needle list
 * is now every Zs/Zl/Zp/Cf code point at Unicode 16.0 minus U+0020 (188
 * entries, generated once and pasted) plus the #17 brackets. The
 * family-closure section below samples the post-#23 members — incl. bidi
 * embedding controls and an astral tag character — in both shapes
 * (glued-with-id, trailing-no-id) and flips the former #31 known-residual
 * pins to loud.
 *
 * Self-contained: predefines a stub XFCP_Post proxy base class and a spy
 * \Cav7\SteamChecker\SteamChecker (the real SteamChecker.php is never loaded)
 * before requiring the real Post.php, then drives the protected _postSave()
 * via reflection. No framework, no network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue23InvisibleSeparatorTest.php
 *
 * Exits non-zero on any failure.
 */

// ---------------------------------------------------------------------------
// Spy SteamChecker — stands in for the real class so "a check fired" is
// observable. Post.php constructs it and calls run() / runManual() /
// replyDegenerateInvocation() (the #25 usage-reply path; its message bytes
// are characterized in Issue25DegenerateInvocationTest).
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
        /** @var int */
        public static $degenerateReplies = 0;

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

        public function replyDegenerateInvocation(): void
        {
            self::$degenerateReplies++;
        }

        public static function reset(): void
        {
            self::$constructed = [];
            self::$runCalls = 0;
            self::$runManualCalls = [];
            self::$degenerateReplies = 0;
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

    // The invisible-separator family pinned by issue #23 (exact code-point
    // list from the agent brief): U+2000–U+200D, U+202F, U+205F, U+2060,
    // U+3000, U+FEFF. This is the historical #23 discovery subset; the
    // post-#23 members of the full ADR-0001 family are sampled in the
    // family-closure section below (#31).
    $invisibles = [
        0x2000 => 'EN QUAD',
        0x2001 => 'EM QUAD',
        0x2002 => 'EN SPACE',
        0x2003 => 'EM SPACE',
        0x2004 => 'THREE-PER-EM SPACE',
        0x2005 => 'FOUR-PER-EM SPACE',
        0x2006 => 'SIX-PER-EM SPACE',
        0x2007 => 'FIGURE SPACE',
        0x2008 => 'PUNCTUATION SPACE',
        0x2009 => 'THIN SPACE',
        0x200A => 'HAIR SPACE',
        0x200B => 'ZERO WIDTH SPACE',
        0x200C => 'ZERO WIDTH NON-JOINER',
        0x200D => 'ZERO WIDTH JOINER',
        0x202F => 'NARROW NO-BREAK SPACE',
        0x205F => 'MEDIUM MATHEMATICAL SPACE',
        0x2060 => 'WORD JOINER',
        0x3000 => 'IDEOGRAPHIC SPACE',
        0xFEFF => 'ZERO WIDTH NO-BREAK SPACE (BOM)',
    ];

    // -----------------------------------------------------------------------
    // AC1: every raw code point in the family as separator —
    // "!vac<sep>id" triggers exactly one check with the bare id, exactly as
    // a plain space does. Pre-fix: ASCII-only \s saw one glued token →
    // silent no-match (no check, no reply, no log).
    // -----------------------------------------------------------------------
    foreach ($invisibles as $cp => $name) {
        $sep = mb_chr($cp, 'UTF-8');
        $label = sprintf('U+%04X %s', $cp, $name);
        $resetOptions();
        $post = $makePost(['message' => '!vac' . $sep . $realId]);
        $invoke($post);
        $check("raw separator $label: exactly one check fires with the bare id",
            $manuals() === [$realId]);
        $check("raw separator $label: logs stay clean", $logsClean());
    }

    // -----------------------------------------------------------------------
    // AC2: entity-form separators decode to family code points and must
    // behave exactly like their raw forms. Covered "for free" because the
    // neutralization runs AFTER the single ENT_HTML5 decode — pinned so a
    // pipeline reorder (neutralize-before-decode) or a decode-flag downgrade
    // (ENT_HTML5 → default drops the named HTML5-only forms) fails here.
    // -----------------------------------------------------------------------
    $entityForms = [
        '&thinsp;'         => 'U+2009 THIN SPACE',
        '&numsp;'          => 'U+2007 FIGURE SPACE',
        '&emsp;'           => 'U+2003 EM SPACE',
        '&MediumSpace;'    => 'U+205F MEDIUM MATHEMATICAL SPACE',
        '&NoBreak;'        => 'U+2060 WORD JOINER',
        '&ZeroWidthSpace;' => 'U+200B ZERO WIDTH SPACE',
    ];
    foreach ($entityForms as $entity => $decodesTo) {
        $resetOptions();
        $post = $makePost(['message' => '!vac' . $entity . $realId]);
        $invoke($post);
        $check("entity form \"$entity\" ($decodesTo): exactly one check fires with the bare id",
            $manuals() === [$realId]);
        $check("entity form \"$entity\": logs stay clean", $logsClean());
    }

    // -----------------------------------------------------------------------
    // AC3 (regression): U+00A0 (#20) and plain-ASCII-space separators are
    // unchanged by the needle-list extension.
    // -----------------------------------------------------------------------
    $nbsp = "\u{00A0}"; // U+00A0 NO-BREAK SPACE (UTF-8: C2 A0)
    $resetOptions();
    $post = $makePost(['message' => '!vac' . $nbsp . $realId]);
    $invoke($post);
    $check('regression: "!vac<U+00A0>id" still fires with the bare id (#20)',
        $manuals() === [$realId]);
    $check('regression U+00A0: logs stay clean', $logsClean());

    $resetOptions();
    $post = $makePost(['message' => '!vac ' . $realId]);
    $invoke($post);
    $check('regression: plain "!vac id" still fires with the id',
        $manuals() === [$realId]);
    $check('regression plain space: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // Degenerate-invocation contract (flipped by #25): "!vac" followed by
    // ONLY invisible characters dissolves entirely to whitespace under the
    // neutralization, so the primary match fails — and because the
    // neutralization runs BEFORE the trailing-token detection, the
    // normalized post ends with a standalone !vac and the rule fires: NO
    // check runs, but the usage reply (lead-in + re-run instruction) IS
    // posted. Same family as the #20 degenerate cases ('!vac &lt;&gt;',
    // '!vac &nbsp;') and literal '!vac <>'. An invisible separator defeats
    // naive bare-"!vac" detection only when it GLUES to a following
    // argument (see the AC1 cases and the residual below); in-family
    // separator-only arguments are exactly what the trailing-token rule
    // answers (since #31 "in-family" is the whole Zs/Zl/Zp/Cf set —
    // out-of-scope render-blank look-alikes stay silent, see ADR-0001 and
    // .out-of-scope/render-blank-characters.md). Reply bytes are
    // characterized in Issue25DegenerateInvocationTest.
    // -----------------------------------------------------------------------
    $separatorOnly = [
        '"!vac<U+202F>"'                   => '!vac' . mb_chr(0x202F, 'UTF-8'),
        '"!vac<U+200B><U+3000>"'           => '!vac' . mb_chr(0x200B, 'UTF-8') . mb_chr(0x3000, 'UTF-8'),
        '"!vac &thinsp;" (entity-only arg)' => '!vac &thinsp;',
    ];
    foreach ($separatorOnly as $label => $message) {
        $resetOptions();
        $post = $makePost(['message' => $message]);
        $invoke($post);
        $check("degenerate $label: no check fires, exactly one usage reply (#25)",
            $manuals() === []
            && \Cav7\SteamChecker\SteamChecker::$degenerateReplies === 1
            && count(\Cav7\SteamChecker\SteamChecker::$constructed) === 1);
        $check("degenerate $label: logs stay clean",
            $logsClean());
    }

    // -----------------------------------------------------------------------
    // Known-residual characterization: semicolon-less '&nbsp' (no ';') does
    // NOT decode under ENT_HTML5, stays glued to '!vac' as literal text, and
    // the command goes unmatched silently. The #25 trailing-token rule does
    // NOT fire either: '!vac&nbsp<id>' normalizes to ONE glued token, so the
    // post ends with that token, not with a standalone '!vac' — this is a
    // GLUED token (the #23 family), not a degenerate invocation, and the
    // silence persists post-#25. Excluded by maintainer decision (issue #23
    // out-of-scope; documented as residual (b) in Post.php) — pinned here so
    // the documented residual stays honest against future entity-decode or
    // detection changes.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac&nbsp' . $realId]);
    $invoke($post);
    $check('known residual "!vac&nbsp<id>" (no semicolon): stays glued, no check fires, no usage reply',
        $manuals() === []
        && \Cav7\SteamChecker\SteamChecker::$degenerateReplies === 0
        && \Cav7\SteamChecker\SteamChecker::$constructed === []);
    $check('known residual "!vac&nbsp<id>": logs stay clean (silent contract)',
        $logsClean());

    // Standalone variant of the same residual: '!vac&nbsp' alone (no id).
    // The undecoded '&nbsp' glues to '!vac' as one token, so the primary
    // match fails AND the post does not end with a standalone '!vac' — the
    // #25 trailing-token rule skips it too. Fully silent.
    $resetOptions();
    $post = $makePost(['message' => '!vac&nbsp']);
    $invoke($post);
    $check('known residual standalone "!vac&nbsp" (no id): glued token, fully silent'
        . ' (no manual, no degenerate reply, no construction)',
        $manuals() === []
        && \Cav7\SteamChecker\SteamChecker::$degenerateReplies === 0
        && \Cav7\SteamChecker\SteamChecker::$constructed === []);
    $check('known residual standalone "!vac&nbsp": logs stay clean (silent contract)',
        $logsClean());

    // -----------------------------------------------------------------------
    // Family closure (issue #31, ADR-0001): the needle list is no longer the
    // #23 discovery set but the full separator/format-control family — every
    // Zs/Zl/Zp/Cf code point at Unicode 16.0 minus U+0020. Sampled members
    // beyond the #23 list — soft hyphen, Ogham space mark, Mongolian vowel
    // separator, LRM/RLM, line/paragraph separators, two bidi embedding
    // controls, and an astral tag character (pins that multibyte AND astral
    // needles actually work in the byte-oriented str_replace) — must behave
    // exactly like the #23 members. Two shapes each:
    //   glued-with-id  "!vac<C><id>" -> C separates; the check fires on the id
    //   trailing-no-id "!vac<C>"     -> degenerate invocation -> usage reply
    //                                   via the #25 trailing-token rule
    // The trailing U+2028/U+2029 cases FLIP the former #31 known-residual
    // pins (fully-silent characterization) to loud — scenarios retained,
    // expectations inverted, per the #31 brief.
    // -----------------------------------------------------------------------
    $familyClosure = [
        0x00AD  => 'SOFT HYPHEN',
        0x1680  => 'OGHAM SPACE MARK',
        0x180E  => 'MONGOLIAN VOWEL SEPARATOR',
        0x200E  => 'LEFT-TO-RIGHT MARK',
        0x200F  => 'RIGHT-TO-LEFT MARK',
        0x2028  => 'LINE SEPARATOR',
        0x2029  => 'PARAGRAPH SEPARATOR',
        0x202A  => 'LEFT-TO-RIGHT EMBEDDING',
        0x202E  => 'RIGHT-TO-LEFT OVERRIDE',
        0xE0041 => 'TAG LATIN CAPITAL LETTER A',
    ];
    foreach ($familyClosure as $cp => $name) {
        $label = sprintf('U+%04X %s', $cp, $name);

        // Shape 1: glued-with-id — the member acts as a separator.
        $resetOptions();
        $post = $makePost(['message' => '!vac' . mb_chr($cp, 'UTF-8') . $realId]);
        $invoke($post);
        $check("family closure $label glued-with-id: exactly one check fires with the bare id (#31)",
            $manuals() === [$realId]);
        $check("family closure $label glued-with-id: logs stay clean", $logsClean());

        // Shape 2: trailing-no-id — the member dissolves, the post ends with
        // a standalone !vac, the #25 trailing-token rule answers.
        $resetOptions();
        $post = $makePost(['message' => '!vac' . mb_chr($cp, 'UTF-8')]);
        $invoke($post);
        $check("family closure $label trailing-no-id: no check, exactly one usage reply (#31)",
            $manuals() === []
            && \Cav7\SteamChecker\SteamChecker::$degenerateReplies === 1
            && count(\Cav7\SteamChecker\SteamChecker::$constructed) === 1);
        $check("family closure $label trailing-no-id: logs stay clean", $logsClean());
    }

    // -----------------------------------------------------------------------
    // Invisible-char-inside-token characterization: a family code point in
    // the middle of an id is neutralized to a space like any other, so the
    // token SPLITS there and the check fires with the truncated prefix —
    // runManual('7656119') — which downstream resolution rejects loudly
    // (resolveSteamId() returns null; runManual() posts the unresolvable
    // reply). Chosen behavior, not accidental: same contract as the #20
    // NBSP-in-token pin, pinned so the truncation stays visible.
    // -----------------------------------------------------------------------
    $resetOptions();
    $post = $makePost(['message' => '!vac 7656119' . mb_chr(0x200B, 'UTF-8') . '8000000001']);
    $invoke($post);
    $check('U+200B inside token: check fires with the truncated token "7656119"',
        $manuals() === ['7656119']);
    $check('U+200B inside token: logs stay clean (rejection happens downstream)',
        $logsClean());

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
