<?php

/**
 * Issue #25 — degenerate invocations get the usage reply (trailing-token rule).
 *
 * A post whose !vac argument is missing or dissolves entirely during
 * normalization (a "degenerate invocation": the normalized post ends with a
 * standalone !vac token followed only by whitespace) formerly got no response
 * at all. The trailing-token rule converts that silence into a two-line
 * usage reply: a hardcoded lead-in plus the existing re-run instruction,
 * verbatim from its single source.
 *
 * Unlike Issue17/20/23 (which stub the SteamChecker class), this suite loads
 * the REAL SteamChecker.php so the new reply path is observed end-to-end:
 * Post.php's trailing-token detection → SteamChecker::replyDegenerateInvocation()
 * → the real message builders → postReply() → a fake entity manager that
 * captures the posted reply bytes.
 *
 * No network: every path exercised here returns before any HTTP call. The
 * one case that would reach the Steam API — a valid id — runs with a blank
 * API key (runManual() exits at its config check) and proves it reached
 * runManual() with the exact token via the debug log instead. The invalid
 * token cases never resolve to an API call (resolveSteamId() rejects them
 * locally; no fixture contains 's.team/', the raw-curl path).
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue25DegenerateInvocationTest.php
 *
 * Exits non-zero on any failure.
 */

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
// Stub Thread entity — the REAL SteamChecker constructor type-hints
// \XF\Entity\Thread, so (unlike the stub-checker suites) the Thread relation
// must be an actual instance of that class, not an anonymous object.
// ---------------------------------------------------------------------------

namespace XF\Entity {
    class Thread
    {
        public $thread_id = 7;
        public $node_id = 42;
        public $first_post_id = 1;
        public $reply_count = 0;
    }
}

// ---------------------------------------------------------------------------
// \XF static facade stub with a reply-capturing fake entity manager: the
// spy surface for this suite is the MESSAGE BYTES the real postReply()
// persists, not stubbed method calls.
// ---------------------------------------------------------------------------

namespace {
    /**
     * Entity created by FakeEm::create('XF:Post'); save() captures the
     * message postReply() assembled. Properties are declared (not dynamic)
     * to stay deprecation-clean on PHP 8.3.
     */
    class FakeCreatedPost
    {
        public $post_id = 555;
        public $thread_id;
        public $user_id;
        public $username;
        public $post_date;
        public $message;
        public $message_state;
        public $ip_id;
        public $position;

        public function save(): void
        {
            \XF::$postedMessages[] = $this->message;
        }
    }

    class FakeEm
    {
        public function find($type, $id)
        {
            if ($type === 'XF:User') {
                // Bot user looked up by postReply().
                return ((int) $id === 99)
                    ? (object) ['user_id' => 99, 'username' => 'VAC Bot']
                    : null;
            }
            if ($type === 'XF:Post') {
                // OP looked up by run() (automatic check). Empty message →
                // no Platform field → run() returns silently, no network.
                return (object) ['message' => ''];
            }
            return null;
        }

        public function create($type)
        {
            if (\XF::$emCreateThrows) {
                throw new \RuntimeException('entity create failed (forced by test)');
            }
            return new FakeCreatedPost();
        }
    }

    class FakeDb
    {
        public function fetchOne($sql, $params = [])
        {
            return 0; // no OP row → postReply() skips the first_post_id fix-up
        }

        public function query($sql, $params = [])
        {
        }
    }

    class XF
    {
        public static $time = 1700000000;
        public static $optionsData = [];
        public static $loggedErrors = [];
        public static $loggedExceptions = [];
        /** @var string[] message bytes captured from postReply() saves */
        public static $postedMessages = [];
        /** @var bool when true, FakeEm::create() throws (error-branch tests) */
        public static $emCreateThrows = false;

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

        public static function em()
        {
            return new FakeEm();
        }

        public static function db()
        {
            return new FakeDb();
        }
    }

    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';
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
            'Thread'   => new \XF\Entity\Thread(),
            'User'     => (object) ['user_group_id' => 3, 'secondary_group_ids' => [8]],
        ];
        $post = new \Cav7\SteamChecker\XF\Entity\Post();
        $post->stubValues = array_merge($defaults, $overrides);
        return $post;
    };

    $resetState = function () {
        \XF::$optionsData = [
            'steamCheckerApiKey'        => 'TESTKEY',
            'steamCheckerBotUserId'     => 99,
            'steamCheckerNodeId'        => 42,
            'steamCheckerAllowedRoleIds' => '3, 8',
            'steamCheckerDebugLog'      => false,
        ];
        \XF::$loggedErrors = [];
        \XF::$loggedExceptions = [];
        \XF::$postedMessages = [];
        \XF::$emCreateThrows = false;
    };

    $invoke = function ($post) {
        $ref = new \ReflectionMethod($post, '_postSave');
        $ref->setAccessible(true);
        $ref->invoke($post);
    };

    $logsClean = function (): bool {
        return \XF::$loggedErrors === [] && \XF::$loggedExceptions === [];
    };

    // The expected usage reply, assembled from its two single sources:
    // the lead-in (exact bytes pinned by the #25 brief) and the re-run
    // instruction taken LIVE from buildRerunInstructionLine() — the posted
    // second line must equal whatever that single source currently says.
    $leadIn = 'No Steam ID was found in that [ICODE]!vac[/ICODE] command.';
    $resetState(); // options must exist before the real constructor reads them
    $rerunRef = new \ReflectionMethod(\Cav7\SteamChecker\SteamChecker::class, 'buildRerunInstructionLine');
    $rerunRef->setAccessible(true);
    $rerunLine = $rerunRef->invoke(
        new \Cav7\SteamChecker\SteamChecker(new \XF\Entity\Thread())
    );
    $expectedUsageReply = $leadIn . "\n" . $rerunLine;

    $realId = '76561198000000001';

    // -----------------------------------------------------------------------
    // AC1: each degenerate form — bare '!vac', literal '!vac <>', entity
    // '!vac &lt;&gt;', entity '!vac &nbsp;' — posts exactly the two-line
    // usage reply (lead-in + re-run instruction) instead of silence.
    // -----------------------------------------------------------------------
    $degenerateForms = [
        'bare "!vac"'                   => '!vac',
        'literal "!vac <>"'             => '!vac <>',
        'entity brackets "!vac &lt;&gt;"' => '!vac &lt;&gt;',
        'entity NBSP "!vac &nbsp;"'     => '!vac &nbsp;',
    ];
    foreach ($degenerateForms as $label => $message) {
        $resetState();
        $post = $makePost(['message' => $message]);
        $invoke($post);
        $check("degenerate $label: exactly one reply posted",
            count(\XF::$postedMessages) === 1);
        $check("degenerate $label: reply is byte-exact lead-in + re-run instruction",
            \XF::$postedMessages === [$expectedUsageReply]);
        $check("degenerate $label: logs stay clean", $logsClean());
    }

    // -----------------------------------------------------------------------
    // AC2: reply shape. Two lines exactly; lead-in bytes pinned; second line
    // is the re-run instruction verbatim; the new reply mentions !vac twice
    // (lead-in [ICODE] + instruction) — a DISTINCT message from the existing
    // failure replies, whose "mentions !vac exactly once" characterization in
    // Issue7RerunInstructionTest is untouched.
    // -----------------------------------------------------------------------
    $resetState();
    $post = $makePost(['message' => '!vac']);
    $invoke($post);
    $reply = \XF::$postedMessages[0] ?? '';
    $replyLines = explode("\n", $reply);
    $check('usage reply has exactly two lines', count($replyLines) === 2);
    $check('usage reply line 1 is the exact lead-in bytes',
        ($replyLines[0] ?? '') === $leadIn);
    $check('usage reply line 2 is the re-run instruction verbatim from its single source',
        ($replyLines[1] ?? '') === $rerunLine);
    $check('usage reply mentions !vac exactly twice (lead-in + instruction)',
        substr_count(strtolower($reply), '!vac') === 2);

    // Anti-fork pin: byte-equality above cannot tell the single source from
    // an identical-bytes fork, so pin at source level — the re-run
    // instruction literal must appear exactly once in SteamChecker.php
    // (inside buildRerunInstructionLine()).
    $checkerSource = (string) file_get_contents(
        __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php'
    );
    $check('re-run instruction text appears exactly once in SteamChecker.php (no fork)',
        substr_count($checkerSource, 'Staff can re-run this check by replying in this thread with') === 1);

    // -----------------------------------------------------------------------
    // AC3: conversational trailing mention — post ENDS with a standalone
    // !vac token — deliberately triggers the reply (accepted in triage: the
    // staff/node/reply gates bound the surface, and the usage reply is
    // on-topic there). Newline counts as the preceding whitespace too. The
    // matcher is case-insensitive like the primary match.
    // -----------------------------------------------------------------------
    foreach ([
        'trailing mention "…just use !vac"' => 'If the check fails, just use !vac',
        'trailing mention after newline'    => "Thanks for checking!\nJust use !vac",
        'uppercase bare "!VAC"'             => '!VAC',
    ] as $label => $message) {
        $resetState();
        $post = $makePost(['message' => $message]);
        $invoke($post);
        $check("$label: usage reply posted",
            \XF::$postedMessages === [$expectedUsageReply]);
        $check("$label: logs stay clean", $logsClean());
    }

    // ACCEPTED-loud characterization: a trailing [ICODE]!vac[/ICODE] — the
    // bot's own teaching markup — triggers the usage reply too. The BBCode
    // strip turns both [ICODE] tags into spaces, leaving the token
    // standalone-trailing, exactly the shape the rule answers. Accepted per
    // the triage decision: conversational mentions deliberately trigger,
    // and this is one, merely dressed in the markup the bot itself uses.
    $resetState();
    $post = $makePost(['message' => 'see [ICODE]!vac[/ICODE]']);
    $invoke($post);
    $check('trailing [ICODE]!vac[/ICODE] (accepted-loud): exact two-line usage reply posted',
        \XF::$postedMessages === [$expectedUsageReply]);
    $check('trailing [ICODE]!vac[/ICODE]: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // AC4: punctuation-glued mentions stay silent — the token is not
    // standalone, so the trailing-token rule must not fire.
    // -----------------------------------------------------------------------
    foreach ([
        'punctuation-glued "use !vac."'    => 'When in doubt, use !vac.',
        'punctuation-glued "use !vac!"'    => 'Remember: use !vac!',
        'mid-token glue "ends with x!vac"' => 'this sentence ends with x!vac',
    ] as $label => $message) {
        $resetState();
        $post = $makePost(['message' => $message]);
        $invoke($post);
        $check("$label: nothing posted (silent)",
            \XF::$postedMessages === []);
        $check("$label: logs stay clean", $logsClean());
    }

    // Quoted-only trailing mention: a [QUOTE] block whose quoted text ends
    // in a standalone !vac, with NOTHING typed below it, stays silent — the
    // step-0 quote strip removes the block before detection. A quote-strip
    // regression here would otherwise make the bot answer someone else's
    // words (issue #16's contract, restated for the #25 rule).
    $resetState();
    $post = $makePost(['message' =>
        '[QUOTE="Staff A, post: 200, member: 5"]if the check fails, just use !vac[/QUOTE]']);
    $invoke($post);
    $check('quoted-only trailing !vac: nothing posted (quoted words are not this user\'s command)',
        \XF::$postedMessages === []);
    $check('quoted-only trailing !vac: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // AC5: a !vac with any non-whitespace argument is untouched.
    //
    // (a) Valid id: runManual() must be reached with the exact token and the
    // usage reply must never be posted. The API key is left BLANK for this
    // case only, so the real runManual() exits at its config check before
    // any network I/O; the debug log proves it received the exact token.
    // (Valid-id routing with full assertions is already pinned by the
    // stub-checker suites: Issue17/20/23.)
    // -----------------------------------------------------------------------
    $resetState();
    \XF::$optionsData['steamCheckerApiKey'] = '';
    \XF::$optionsData['steamCheckerDebugLog'] = true;
    $post = $makePost(['message' => '!vac ' . $realId]);
    $invoke($post);
    $runManualDebugLines = array_values(array_filter(\XF::$loggedErrors, function ($msg) use ($realId) {
        return strpos($msg, 'SteamChecker::runManual() rawSteamId=' . $realId) !== false;
    }));
    $check('valid id: runManual() reached with the exact token (debug-log proof)',
        count($runManualDebugLines) === 1);
    $check('valid id: usage reply never posted',
        \XF::$postedMessages === []);
    $check('valid id: no degenerate-invocation debug line emitted',
        array_filter(\XF::$loggedErrors, function ($msg) {
            return strpos($msg, 'degenerate !vac invocation') !== false;
        }) === []);

    // -----------------------------------------------------------------------
    // (b) Invalid token: the OLD "could not determine" reply is posted —
    // real bytes through the real resolver (no network: the token matches
    // none of the resolvable patterns) — and never the new usage reply.
    // -----------------------------------------------------------------------
    $resetState();
    $post = $makePost(['message' => '!vac bogus-token']);
    $invoke($post);
    $check('invalid token: exactly one reply posted',
        count(\XF::$postedMessages) === 1);
    $check('invalid token: reply is the OLD unresolvable reply',
        strpos(\XF::$postedMessages[0] ?? '', 'Could not determine a valid Steam ID') !== false
        && strpos(\XF::$postedMessages[0] ?? '', 'Raw value: bogus-token') !== false);
    $check('invalid token: reply is never the new usage lead-in',
        strpos(\XF::$postedMessages[0] ?? '', $leadIn) === false);
    $check('invalid token: logs stay clean', $logsClean());

    // -----------------------------------------------------------------------
    // (c) Fallback strictness: when the primary match succeeds, the
    // trailing-token rule never runs — even if the post ALSO ends with a
    // standalone !vac. First match wins, old reply only.
    // -----------------------------------------------------------------------
    $resetState();
    $post = $makePost(['message' => '!vac bogus-token and if that fails just use !vac']);
    $invoke($post);
    $check('primary match + trailing !vac: only the OLD reply is posted',
        count(\XF::$postedMessages) === 1
        && strpos(\XF::$postedMessages[0], 'Could not determine a valid Steam ID') !== false
        && strpos(\XF::$postedMessages[0], $leadIn) === false);

    // -----------------------------------------------------------------------
    // AC6: all existing gates are unchanged — none of them may produce the
    // usage reply, even for a degenerate '!vac' message.
    // -----------------------------------------------------------------------
    $bareCommand = '!vac';

    // Gate: author outside all allowed groups.
    $resetState();
    $post = $makePost([
        'message' => $bareCommand,
        'User'    => (object) ['user_group_id' => 2, 'secondary_group_ids' => [4, 6]],
    ]);
    $invoke($post);
    $check('gate: non-staff author never gets the usage reply',
        \XF::$postedMessages === [] && $logsClean());

    // Gate: post outside the enlistment node.
    $resetState();
    $wrongNodeThread = new \XF\Entity\Thread();
    $wrongNodeThread->node_id = 43;
    $post = $makePost(['message' => $bareCommand, 'Thread' => $wrongNodeThread]);
    $invoke($post);
    $check('gate: wrong node never gets the usage reply',
        \XF::$postedMessages === [] && $logsClean());

    // Gate: OP (position 0) routes to the automatic check, never the
    // command parser — the fake OP has no Platform field, so run() returns
    // silently without posting.
    $resetState();
    $post = $makePost(['message' => $bareCommand, 'position' => 0]);
    $invoke($post);
    $check('gate: OP (position 0) never gets the usage reply',
        \XF::$postedMessages === [] && $logsClean());

    // Gate: the bot's own posts never fire.
    $resetState();
    $post = $makePost(['message' => $bareCommand, 'user_id' => 99]);
    $invoke($post);
    $check('gate: bot self-post never gets the usage reply',
        \XF::$postedMessages === [] && $logsClean());

    // Gate: edits (not inserts) never fire.
    $resetState();
    $post = $makePost(['message' => $bareCommand]);
    $post->stubIsInsert = false;
    $invoke($post);
    $check('gate: edit (not insert) never gets the usage reply',
        \XF::$postedMessages === [] && $logsClean());

    // -----------------------------------------------------------------------
    // AC7: debug observability — with steamCheckerDebugLog on, the
    // degenerate-invocation path logs its decision like the surrounding
    // steps; with it off (every case above), it stays quiet.
    // -----------------------------------------------------------------------
    $resetState();
    \XF::$optionsData['steamCheckerDebugLog'] = true;
    $post = $makePost(['message' => $bareCommand]);
    $invoke($post);
    $degenerateDebugLines = array_values(array_filter(\XF::$loggedErrors, function ($msg) {
        return strpos($msg, '[VAC-DEBUG]') !== false
            && strpos($msg, 'degenerate !vac invocation') !== false;
    }));
    $check('debug on: exactly one degenerate-invocation decision line emitted',
        count($degenerateDebugLines) === 1);
    $check('debug on: decision line carries user, thread and post ids',
        isset($degenerateDebugLines[0])
        && strpos($degenerateDebugLines[0], 'user_id=5') !== false
        && strpos($degenerateDebugLines[0], 'thread_id=7') !== false
        && strpos($degenerateDebugLines[0], 'post_id=101') !== false);
    $check('debug on: usage reply still posted',
        \XF::$postedMessages === [$expectedUsageReply]);

    // -----------------------------------------------------------------------
    // AC8: error branches of the reply path.
    //
    // (a) try/catch containment: a throw inside replyDegenerateInvocation()
    // (forced here by making the fake entity manager's create() throw, so
    // the real postReply() blows up mid-flight) must be caught by Post.php's
    // wrapper — exactly one logException with the 'degenerate !vac reply
    // error: ' prefix, and the script continues (no fatal): reaching the
    // assertions below IS the no-fatal proof.
    // -----------------------------------------------------------------------
    $resetState();
    \XF::$emCreateThrows = true;
    $post = $makePost(['message' => '!vac']);
    $invoke($post);
    $degenerateExceptionLogs = array_values(array_filter(\XF::$loggedExceptions, function ($msg) {
        return strpos($msg, '[Cav7/SteamChecker] degenerate !vac reply error: ') === 0;
    }));
    $check('reply-path throw: exactly one logException with the degenerate-reply prefix',
        count($degenerateExceptionLogs) === 1
        && \XF::$loggedExceptions === $degenerateExceptionLogs);
    $check('reply-path throw: no reply was posted',
        \XF::$postedMessages === []);

    // -----------------------------------------------------------------------
    // (b) bot user unconfigured: with steamCheckerBotUserId = 0, the
    // trailing-token rule still fires, but replyDegenerateInvocation()
    // refuses at its config check — no reply, one configuration error
    // logged, no exception.
    // -----------------------------------------------------------------------
    $resetState();
    \XF::$optionsData['steamCheckerBotUserId'] = 0;
    $post = $makePost(['message' => '!vac']);
    $invoke($post);
    $botConfigErrors = array_values(array_filter(\XF::$loggedErrors, function ($msg) {
        return strpos($msg, 'Bot user ID is not configured.') !== false;
    }));
    $check('bot user unconfigured: no reply posted',
        \XF::$postedMessages === []);
    $check('bot user unconfigured: the configuration error is logged (and nothing else)',
        count($botConfigErrors) === 1
        && \XF::$loggedErrors === $botConfigErrors
        && \XF::$loggedExceptions === []);

    // -----------------------------------------------------------------------
    // (c) detection PCRE-failure log branch (preg_match -> false on the
    // trailing-token pattern): covered by convention, not by fixture. The
    // pattern follows the exact fail-loud shape of the final-match false
    // branch — log, treat as no-trigger — and, like that branch, is
    // exercised nowhere at PHP defaults: /(?:^|\s)!vac\s*$/i has no
    // backtracking to exhaust under JIT, so no pcre.backtrack_limit fixture
    // reaches it without the awkward interpreter-mode pre-warm dance.
    // Mirrors how Issue16QuoteStrippingTest documents its BBCode-strip
    // guard (its case (c)) instead of forcing an unreachable branch.
    // -----------------------------------------------------------------------

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
