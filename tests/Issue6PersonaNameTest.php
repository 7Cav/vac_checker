<?php

/**
 * Issue #6 — persona name in ban report.
 *
 * Self-contained test script. No framework. No network for the code paths
 * under test: httpGet is stubbed in the testable subclass. Caveat: do not
 * feed s.team URLs into these tests — resolveSteamShortLink() uses raw curl
 * directly (not httpGet) and would hit the real network. Run with:
 *   docker run --rm -v /path/to/repo:/app -w /app php:8.3-cli php tests/Issue6PersonaNameTest.php
 *
 * Exits non-zero on any failure.
 */

// ---------------------------------------------------------------------------
// Inline XF stubs
// ---------------------------------------------------------------------------

namespace {
    class XF
    {
        public static $time = 1700000000;
        public static $optionsData = [
            'steamCheckerApiKey'    => 'TESTKEY',
            'steamCheckerBotUserId' => 99,
            'steamCheckerDebugLog'  => false,
        ];
        public static $loggedErrors = [];
        public static $loggedExceptions = [];
        public static $em = null;

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
            return self::$em;
        }
    }
}

namespace XF\Entity {
    class Thread
    {
        public $thread_id = 1;
        public $first_post_id = 10;
        public $reply_count = 0;
    }
}

namespace Issue6Tests {
    class FakeEm
    {
        /** @var array<string, object> "type:id" => entity */
        public $entities = [];

        public function find($type, $id)
        {
            return $this->entities[$type . ':' . $id] ?? null;
        }
    }

    class FakePost
    {
        public $message = '';
    }
}

// ---------------------------------------------------------------------------
// Testable subclass — stubs HTTP + reply posting, exposes protected methods
// ---------------------------------------------------------------------------

namespace Cav7\SteamChecker {
    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';

    class Issue6TestableChecker extends SteamChecker
    {
        /** @var array<string, ?string> URL-substring => response body (null = request failure) */
        public $httpResponses = [];

        /** @var string[] URL substrings for which httpGet throws (catch-path coverage) */
        public $httpThrows = [];

        /** @var string[] messages passed to postReply */
        public $posted = [];

        protected function httpGet(string $url): ?string
        {
            foreach ($this->httpThrows as $needle) {
                if (strpos($url, $needle) !== false) {
                    throw new \RuntimeException('stubbed httpGet failure: ' . $needle);
                }
            }
            foreach ($this->httpResponses as $needle => $response) {
                if (strpos($url, $needle) !== false) {
                    return $response;
                }
            }
            return null;
        }

        protected function postReply(string $message): void
        {
            $this->posted[] = $message;
        }

        public function callBuildBanReportMessage(...$args): string
        {
            return $this->buildBanReportMessage(...$args);
        }

        public function callFetchPlayerSummary(string $steamId64): ?string
        {
            return $this->fetchPlayerSummary($steamId64);
        }
    }
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

namespace Issue6Tests {

    use Cav7\SteamChecker\Issue6TestableChecker;

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        }
    }

    function makeChecker(): Issue6TestableChecker
    {
        return new Issue6TestableChecker(new \XF\Entity\Thread());
    }

    function resetLogs(): void
    {
        \XF::$loggedErrors = [];
        \XF::$loggedExceptions = [];
    }

    const STEAM_ID = '76561198000000001';

    // Stub needle for the summaries success response. Keyed on the endpoint
    // plus 'steamids=' . STEAM_ID (not the bare 'GetPlayerSummaries'
    // substring) so passing a wrong variable into the fetch fails the test.
    // The endpoint prefix is needed because the GetPlayerBans URL also
    // contains 'steamids=' . STEAM_ID.
    const SUMMARIES_NEEDLE = 'GetPlayerSummaries/v2/?key=TESTKEY&steamids=' . STEAM_ID;

    const CLEAN_BAN_DATA = [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => false,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 0,
    ];

    // --- Test 1: builder shows persona name line near SteamID line ----------
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, 'GamerDude');
    // The persona name and the clickable SteamID link (issue #5) now share one
    // merged profile line.
    $profileLine = profileNameLine($report);
    check(
        'builder includes a profile line',
        $profileLine !== null,
        "report was:\n$report"
    );
    check(
        'profile line carries the persona name and the SteamID link together',
        $profileLine !== null
            && strpos($profileLine, '[PLAIN]GamerDude[/PLAIN]') !== false
            && strpos(
                $profileLine,
                '[URL="https://steamcommunity.com/profiles/' . STEAM_ID . '"]' . STEAM_ID . '[/URL]'
            ) !== false,
        "profile line: " . var_export($profileLine, true)
    );

    // --- Test 2: null persona name renders degraded "(unknown)" line --------
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, null);
    check(
        'null persona name renders (unknown)',
        strpos($report, '[B]Profile:[/B] (unknown)') !== false,
        "report was:\n$report"
    );
    check(
        'null persona name still produces full ban report',
        strpos($report, '[*][B]VAC bans:[/B] 0') !== false && strpos($report, 'No bans found') !== false,
        "report was:\n$report"
    );

    // --- Test 2b: empty / whitespace-only persona names render (unknown) -----
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, '');
    check(
        'empty-string persona name renders (unknown)',
        strpos($report, '[B]Profile:[/B] (unknown)') !== false,
        "report was:\n$report"
    );

    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, "  \t ");
    check(
        'whitespace-only persona name renders (unknown)',
        strpos($report, '[B]Profile:[/B] (unknown)') !== false,
        "report was:\n$report"
    );

    // --- Test 3: BBCode in persona name is neutralized -----------------------
    /** @return string[] every merged profile line (persona name + SteamID link). */
    function profileNameLines(string $report): array
    {
        $found = [];
        foreach (explode("\n", $report) as $line) {
            if (strpos($line, '[B]Profile:[/B] ') === 0) {
                $found[] = $line;
            }
        }
        return $found;
    }

    function profileNameLine(string $report): ?string
    {
        $found = profileNameLines($report);
        return $found[0] ?? null;
    }

    /**
     * Content between the [PLAIN] wrapper tags on the profile line, or null if
     * the name is not [PLAIN]-wrapped. Non-greedy up to the first ASCII
     * [/PLAIN] (the real closer; any adversarial one is fullwidth-neutralized),
     * which is immediately followed by the "   [B]·[/B]   " separator + link.
     */
    function plainContent(?string $nameLine): ?string
    {
        if ($nameLine === null
            || !preg_match('/^\[B\]Profile:\[\/B\] \[PLAIN\](.*?)\[\/PLAIN\]   \[B\]·\[\/B\]   /s', $nameLine, $m)
        ) {
            return null;
        }
        return $m[1];
    }

    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, '[B]x[/B]');
    $nameLine = profileNameLine($report);
    $content = plainContent($nameLine);
    check(
        'bold-injection name is [PLAIN]-wrapped',
        $content !== null,
        'name line: ' . var_export($nameLine, true)
    );
    check(
        'bold-injection name content has no ASCII brackets',
        $content !== null && strpos($content, '[') === false && strpos($content, ']') === false,
        'content: ' . var_export($content, true)
    );
    // Neutralize, not strip: the fullwidth lookalikes must be visible. A
    // refactor that *deletes* brackets (eating [7Cav]-style clan tags) fails.
    check(
        'bold-injection name keeps fullwidth lookalike brackets',
        $content !== null && strpos($content, '［B］x［/B］') !== false,
        'content: ' . var_export($content, true)
    );

    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(
        STEAM_ID,
        CLEAN_BAN_DATA,
        '[URL=https://evil.example]y[/URL]'
    );
    $nameLine = profileNameLine($report);
    $content = plainContent($nameLine);
    check(
        'URL-injection name content has no ASCII brackets',
        $content !== null && strpos($content, '[') === false && strpos($content, ']') === false,
        'content: ' . var_export($content, true)
    );
    check(
        'URL-injection visible text and neutralized URL still present',
        $content !== null
            && strpos($content, 'y') !== false
            && strpos($content, '［URL=https://evil.example］y［/URL］') !== false,
        'content: ' . var_export($content, true)
    );

    // --- Test 3b: PLAIN wrapper integrity -------------------------------------
    // An adversarial name containing a literal [/PLAIN] must not be able to
    // close the wrapper: neutralizeBbCode fullwidth-swaps its brackets first.
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(
        STEAM_ID,
        CLEAN_BAN_DATA,
        'evil[/PLAIN][B]bold[/B]'
    );
    $nameLine = profileNameLine($report);
    check(
        'adversarial [/PLAIN] cannot close the wrapper',
        $nameLine !== null
            && substr_count($nameLine, '[/PLAIN]') === 1
            // The single real closer sits exactly where the builder put it:
            // right after the name, before the "·" separator and the link.
            && strpos($nameLine, '[/PLAIN]   [B]·[/B]   ') !== false
            && strpos($nameLine, '［/PLAIN］') !== false,
        'name line: ' . var_export($nameLine, true)
    );

    // --- Test 3c: control-character injection ---------------------------------
    // Newlines in a persona name must not fabricate extra report lines.
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(
        STEAM_ID,
        ['NumberOfVACBans' => 1, 'NumberOfGameBans' => 0, 'CommunityBanned' => false,
         'EconomyBan' => 'none', 'DaysSinceLastBan' => 42],
        "Bad\nVAC Bans: 0\n✅ No bans found."
    );
    $nameLines = profileNameLines($report);
    check(
        'control-char injection yields exactly one Profile Name line',
        count($nameLines) === 1,
        'name lines: ' . var_export($nameLines, true)
    );
    check(
        'injected newlines collapse to spaces inside the name line',
        count($nameLines) === 1
            && strpos($nameLines[0], 'Bad VAC Bans: 0 ✅ No bans found.') !== false,
        'name line: ' . var_export($nameLines[0] ?? null, true)
    );
    $reportLines = explode("\n", $report);
    check(
        'real ban lines unaffected by injected fake lines',
        // The genuine VAC line (count 1, flagged red) appears exactly once...
        count(array_keys($reportLines, '[*][B]VAC bans:[/B] [COLOR=rgb(184, 49, 47)][B]1[/B][/COLOR]', true)) === 1
            // ...and the attacker's injected "VAC Bans: 0" never becomes its own
            // report line — the newlines collapsed, so it stays inside the name.
            && count(array_keys($reportLines, 'VAC Bans: 0', true)) === 0
            && strpos($report, '[*][B]Last ban:[/B] 1 month, 11 days ago (42 days)') !== false // humanized age (issue #37)
            && strpos($report, '⚠️ Bans detected') !== false,
        "report was:\n$report"
    );

    // --- Test 4: fetchPlayerSummary parses and degrades safely ---------------
    $summaryJson = json_encode([
        'response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => 'GamerDude']]],
    ]);

    $checker = makeChecker();
    $checker->httpResponses = [SUMMARIES_NEEDLE => $summaryJson];
    check(
        'fetchPlayerSummary returns persona name from valid response',
        $checker->callFetchPlayerSummary(STEAM_ID) === 'GamerDude'
    );

    resetLogs();
    $checker = makeChecker();
    $checker->httpResponses = []; // httpGet returns null -> HTTP failure
    $result = 'not-run';
    try {
        $result = $checker->callFetchPlayerSummary(STEAM_ID);
    } catch (\Throwable $e) {
        $result = 'threw: ' . $e->getMessage();
    }
    check(
        'fetchPlayerSummary returns null on HTTP failure (no exception)',
        $result === null,
        var_export($result, true)
    );
    $networkError = \XF::$loggedErrors[0] ?? null;
    check(
        'HTTP failure logs exactly one error naming the network failure',
        count(\XF::$loggedErrors) === 1
            && strpos($networkError, 'request failed (network)') !== false
            && strpos($networkError, STEAM_ID) !== false,
        'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
    );

    resetLogs();
    $checker = makeChecker();
    $checker->httpResponses = [SUMMARIES_NEEDLE => 'this is not json'];
    $result = 'not-run';
    try {
        $result = $checker->callFetchPlayerSummary(STEAM_ID);
    } catch (\Throwable $e) {
        $result = 'threw: ' . $e->getMessage();
    }
    check(
        'fetchPlayerSummary returns null on malformed response (no exception)',
        $result === null,
        var_export($result, true)
    );
    $unexpectedError = \XF::$loggedErrors[0] ?? null;
    check(
        'malformed body logs exactly one error with body length and snippet',
        count(\XF::$loggedErrors) === 1
            && strpos($unexpectedError, 'Unexpected GetPlayerSummaries response') !== false
            && strpos($unexpectedError, STEAM_ID) !== false
            && strpos($unexpectedError, '(len=16)') !== false
            && strpos($unexpectedError, 'this is not json') !== false,
        'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
    );

    resetLogs();
    $checker = makeChecker();
    $checker->httpResponses = [SUMMARIES_NEEDLE => json_encode(['response' => ['players' => []]])];
    $result = 'not-run';
    try {
        $result = $checker->callFetchPlayerSummary(STEAM_ID);
    } catch (\Throwable $e) {
        $result = 'threw: ' . $e->getMessage();
    }
    check(
        'fetchPlayerSummary returns null on empty players array (no exception)',
        $result === null,
        var_export($result, true)
    );
    $noNameError = \XF::$loggedErrors[0] ?? null;
    check(
        'empty players logs exactly one no-persona-name error',
        count(\XF::$loggedErrors) === 1
            && strpos($noNameError, 'returned no persona name') !== false
            && strpos($noNameError, STEAM_ID) !== false,
        'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
    );

    check(
        'the three degraded-path log messages are distinguishable',
        $networkError !== null && $unexpectedError !== null && $noNameError !== null
            && $networkError !== $unexpectedError
            && $networkError !== $noNameError
            && $unexpectedError !== $noNameError,
        var_export([$networkError, $unexpectedError, $noNameError], true)
    );

    // --- Test 4b: degraded persona payloads all yield null --------------------
    $degradedPayloads = [
        'JSON-null personaname' => json_encode(
            ['response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => null]]]]
        ),
        'missing personaname'   => json_encode(
            ['response' => ['players' => [['steamid' => STEAM_ID]]]]
        ),
        'empty personaname'     => json_encode(
            ['response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => '']]]]
        ),
        'whitespace personaname' => json_encode(
            ['response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => "  \t "]]]]
        ),
    ];
    foreach ($degradedPayloads as $label => $payload) {
        resetLogs();
        $checker = makeChecker();
        $checker->httpResponses = [SUMMARIES_NEEDLE => $payload];
        $result = 'not-run';
        try {
            $result = $checker->callFetchPlayerSummary(STEAM_ID);
        } catch (\Throwable $e) {
            $result = 'threw: ' . $e->getMessage();
        }
        check(
            "fetchPlayerSummary returns null on $label",
            $result === null,
            var_export($result, true)
        );
        check(
            "$label logs exactly one no-persona-name error",
            count(\XF::$loggedErrors) === 1
                && strpos(\XF::$loggedErrors[0], 'returned no persona name') !== false,
            'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
        );
    }

    // --- Test 5: runManual (!vac) integration ---------------------------------
    $bansJson = json_encode([
        'players' => [[
            'SteamId'          => STEAM_ID,
            'NumberOfVACBans'  => 0,
            'NumberOfGameBans' => 0,
            'CommunityBanned'  => false,
            'EconomyBan'       => 'none',
            'DaysSinceLastBan' => 0,
        ]],
    ]);

    $checker = makeChecker();
    $checker->httpResponses = [
        'GetPlayerBans'      => $bansJson,
        SUMMARIES_NEEDLE => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual posts ban report with persona name',
        count($checker->posted) === 1
            && strpos($checker->posted[0], '[PLAIN]GamerDude[/PLAIN]') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    resetLogs();
    $checker = makeChecker();
    $checker->httpResponses = [
        'GetPlayerBans' => $bansJson,
        // GetPlayerSummaries unmatched -> httpGet returns null (fetch failure)
    ];
    $threw = null;
    try {
        $checker->runManual(STEAM_ID);
    } catch (\Throwable $e) {
        $threw = $e->getMessage();
    }
    check(
        'runManual still posts ban report when summaries fetch fails (no exception)',
        $threw === null
            && count($checker->posted) === 1
            && strpos($checker->posted[0], '[B]Profile:[/B] (unknown)') !== false
            && strpos($checker->posted[0], '[*][B]VAC bans:[/B] 0') !== false
            && strpos($checker->posted[0], 'Steam API error') === false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );
    check(
        'runManual summaries fetch failure logs exactly one network error',
        count(\XF::$loggedErrors) === 1
            && strpos(\XF::$loggedErrors[0], 'request failed (network)') !== false,
        'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
    );

    $checker = makeChecker();
    $checker->httpResponses = [
        // GetPlayerBans unmatched -> httpGet returns null (API failure)
        SUMMARIES_NEEDLE => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual GetPlayerBans failure still posts API-error reply',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Steam API error') !== false
            && strpos($checker->posted[0], '[B]Profile:[/B]') === false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- Test 6: run() automatic first-post check ----------------------------
    function makeRunChecker(?string $summaryResponse = null, bool $withBans = true): Issue6TestableChecker
    {
        global $bansJson, $summaryJson;
        $post = new FakePost();
        $post->message = implode("\n", [
            '[B]Platform and Game Selection[/B]',
            'PC - Arma 3',
            '[B]Steam64ID or Steam Account URL/Link[/B]',
            STEAM_ID,
        ]);
        $em = new FakeEm();
        $em->entities['XF:Post:10'] = $post;
        \XF::$em = $em;

        $checker = makeChecker();
        if ($withBans) {
            $checker->httpResponses['GetPlayerBans'] = $bansJson;
        }
        if ($summaryResponse !== null) {
            $checker->httpResponses[SUMMARIES_NEEDLE] = $summaryResponse;
        }
        return $checker;
    }

    $checker = makeRunChecker($summaryJson);
    $checker->run();
    check(
        'run() posts ban report with persona name',
        count($checker->posted) === 1
            && strpos($checker->posted[0], '[PLAIN]GamerDude[/PLAIN]') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    resetLogs();
    $checker = makeRunChecker(); // no summaries response -> fetch failure
    $threw = null;
    try {
        $checker->run();
    } catch (\Throwable $e) {
        $threw = $e->getMessage();
    }
    check(
        'run() still posts ban report when summaries fetch fails (no exception)',
        $threw === null
            && count($checker->posted) === 1
            && strpos($checker->posted[0], '[B]Profile:[/B] (unknown)') !== false
            && strpos($checker->posted[0], 'Steam API error') === false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );
    check(
        'run() summaries fetch failure logs exactly one network error',
        count(\XF::$loggedErrors) === 1
            && strpos(\XF::$loggedErrors[0], 'request failed (network)') !== false,
        'loggedErrors: ' . var_export(\XF::$loggedErrors, true)
    );

    // --- Test 6b: run() GetPlayerBans failure ---------------------------------
    resetLogs();
    $checker = makeRunChecker($summaryJson, false); // bans fetch fails
    $checker->run();
    check(
        'run() GetPlayerBans failure still posts API-error reply',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Steam API error') !== false
            && strpos($checker->posted[0], '[B]Profile:[/B]') === false,
        'posted: ' . var_export($checker->posted, true)
    );
    check(
        'run() GetPlayerBans failure logs the API exception',
        count(\XF::$loggedExceptions) === 1
            && strpos(\XF::$loggedExceptions[0], 'Steam API error: ') !== false
            && strpos(\XF::$loggedExceptions[0], 'GetPlayerBans request failed') !== false,
        'loggedExceptions: ' . var_export(\XF::$loggedExceptions, true)
    );

    // --- Test 7: throwing summaries fetch (catch-path coverage) ---------------
    // httpGet THROWS for GetPlayerSummaries URLs: the try/catch around
    // fetchPlayerSummary() in run()/runManual() must contain it.
    resetLogs();
    $checker = makeChecker();
    $checker->httpResponses = ['GetPlayerBans' => $bansJson];
    $checker->httpThrows = ['GetPlayerSummaries'];
    $threw = null;
    try {
        $checker->runManual(STEAM_ID);
    } catch (\Throwable $e) {
        $threw = $e->getMessage();
    }
    check(
        'runManual survives a throwing summaries fetch and posts full report',
        $threw === null
            && count($checker->posted) === 1
            && strpos($checker->posted[0], '[B]Profile:[/B] (unknown)') !== false
            && strpos($checker->posted[0], '[*][B]VAC bans:[/B] 0') !== false
            && strpos($checker->posted[0], 'No bans found') !== false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );
    check(
        'runManual captures the summaries exception via logException',
        count(\XF::$loggedExceptions) === 1
            && strpos(\XF::$loggedExceptions[0], '!vac player summary error: ') !== false
            && strpos(\XF::$loggedExceptions[0], 'stubbed httpGet failure') !== false,
        'loggedExceptions: ' . var_export(\XF::$loggedExceptions, true)
    );

    resetLogs();
    $checker = makeRunChecker();
    $checker->httpThrows = ['GetPlayerSummaries'];
    $threw = null;
    try {
        $checker->run();
    } catch (\Throwable $e) {
        $threw = $e->getMessage();
    }
    check(
        'run() survives a throwing summaries fetch and posts full report',
        $threw === null
            && count($checker->posted) === 1
            && strpos($checker->posted[0], '[B]Profile:[/B] (unknown)') !== false
            && strpos($checker->posted[0], '[*][B]VAC bans:[/B] 0') !== false
            && strpos($checker->posted[0], 'No bans found') !== false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );
    check(
        'run() captures the summaries exception via logException',
        count(\XF::$loggedExceptions) === 1
            && strpos(\XF::$loggedExceptions[0], 'Player summary error: ') !== false
            && strpos(\XF::$loggedExceptions[0], 'stubbed httpGet failure') !== false,
        'loggedExceptions: ' . var_export(\XF::$loggedExceptions, true)
    );

    // --- Summary -------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
