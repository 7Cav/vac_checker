<?php

/**
 * Issue #6 — persona name in ban report.
 *
 * Self-contained test script. No framework, no network. Run with:
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

        /** @var string[] messages passed to postReply */
        public $posted = [];

        protected function httpGet(string $url): ?string
        {
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

    const STEAM_ID = '76561198000000001';
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
    $lines = explode("\n", $report);
    $steamIdIdx = array_search('SteamID: ' . STEAM_ID, $lines, true);
    $nameIdx = array_search('Profile Name: GamerDude', $lines, true);
    check(
        'builder includes Profile Name line',
        $nameIdx !== false,
        "report was:\n$report"
    );
    check(
        'Profile Name line adjacent to SteamID line',
        $steamIdIdx !== false && $nameIdx !== false && abs($nameIdx - $steamIdIdx) === 1,
        "steamIdIdx=" . var_export($steamIdIdx, true) . " nameIdx=" . var_export($nameIdx, true)
    );

    // --- Test 2: null persona name renders degraded "(unknown)" line --------
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, null);
    check(
        'null persona name renders (unknown)',
        strpos($report, 'Profile Name: (unknown)') !== false,
        "report was:\n$report"
    );
    check(
        'null persona name still produces full ban report',
        strpos($report, 'VAC Bans: 0') !== false && strpos($report, 'No bans found') !== false,
        "report was:\n$report"
    );

    // --- Test 3: BBCode in persona name is neutralized -----------------------
    function profileNameLine(string $report): ?string
    {
        foreach (explode("\n", $report) as $line) {
            if (strpos($line, 'Profile Name: ') === 0) {
                return $line;
            }
        }
        return null;
    }

    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, '[B]x[/B]');
    $nameLine = profileNameLine($report);
    check(
        'bold-injection name line has no ASCII brackets',
        $nameLine !== null && strpos($nameLine, '[') === false && strpos($nameLine, ']') === false,
        'name line: ' . var_export($nameLine, true)
    );
    check(
        'bold-injection name text still visible',
        $nameLine !== null && strpos($nameLine, 'x') !== false,
        'name line: ' . var_export($nameLine, true)
    );

    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(
        STEAM_ID,
        CLEAN_BAN_DATA,
        '[URL=https://evil.example]y[/URL]'
    );
    $nameLine = profileNameLine($report);
    check(
        'URL-injection name line has no ASCII brackets',
        $nameLine !== null && strpos($nameLine, '[') === false && strpos($nameLine, ']') === false,
        'name line: ' . var_export($nameLine, true)
    );

    // --- Test 4: fetchPlayerSummary parses and degrades safely ---------------
    $summaryJson = json_encode([
        'response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => 'GamerDude']]],
    ]);

    $checker = makeChecker();
    $checker->httpResponses = ['GetPlayerSummaries' => $summaryJson];
    check(
        'fetchPlayerSummary returns persona name from valid response',
        $checker->callFetchPlayerSummary(STEAM_ID) === 'GamerDude'
    );

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

    $checker = makeChecker();
    $checker->httpResponses = ['GetPlayerSummaries' => 'this is not json'];
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

    $checker = makeChecker();
    $checker->httpResponses = ['GetPlayerSummaries' => json_encode(['response' => ['players' => []]])];
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
        'GetPlayerSummaries' => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual posts ban report with persona name',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Profile Name: GamerDude') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

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
            && strpos($checker->posted[0], 'Profile Name: (unknown)') !== false
            && strpos($checker->posted[0], 'VAC Bans: 0') !== false
            && strpos($checker->posted[0], 'Steam API error') === false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );

    $checker = makeChecker();
    $checker->httpResponses = [
        // GetPlayerBans unmatched -> httpGet returns null (API failure)
        'GetPlayerSummaries' => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual GetPlayerBans failure still posts API-error reply',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Steam API error') !== false
            && strpos($checker->posted[0], 'Profile Name:') === false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- Test 6: run() automatic first-post check ----------------------------
    function makeRunChecker(?string $summaryResponse = null): Issue6TestableChecker
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
        $checker->httpResponses = ['GetPlayerBans' => $bansJson];
        if ($summaryResponse !== null) {
            $checker->httpResponses['GetPlayerSummaries'] = $summaryResponse;
        }
        return $checker;
    }

    $checker = makeRunChecker($summaryJson);
    $checker->run();
    check(
        'run() posts ban report with persona name',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Profile Name: GamerDude') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

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
            && strpos($checker->posted[0], 'Profile Name: (unknown)') !== false
            && strpos($checker->posted[0], 'Steam API error') === false,
        'threw: ' . var_export($threw, true) . ' posted: ' . var_export($checker->posted, true)
    );

    // --- Summary -------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
