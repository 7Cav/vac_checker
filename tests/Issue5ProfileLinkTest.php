<?php

/**
 * Issue #5 — resolved SteamID64 rendered as clickable Steam profile link.
 *
 * Self-contained test script. No framework. No network for the code paths
 * under test: httpGet is stubbed in the testable subclass. Caveat: do not
 * feed s.team URLs into these tests — resolveSteamShortLink() uses raw curl
 * directly (not httpGet) and would hit the real network. Run with:
 *   docker run --rm -v /path/to/repo:/app -w /app php:8.3-cli php tests/Issue5ProfileLinkTest.php
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

namespace Issue5Tests {
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

    class Issue5TestableChecker extends SteamChecker
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

        public function callBuildApiErrorMessage(string $steamId64): string
        {
            return $this->buildApiErrorMessage($steamId64);
        }

        public function callBuildUnresolvableMessage(string $rawValue): string
        {
            return $this->buildUnresolvableMessage($rawValue);
        }
    }
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

namespace Issue5Tests {

    use Cav7\SteamChecker\Issue5TestableChecker;

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

    function makeChecker(): Issue5TestableChecker
    {
        return new Issue5TestableChecker(new \XF\Entity\Thread());
    }

    const STEAM_ID = '76561198000000001';

    const LINKED_ID_LINE = 'SteamID: [URL="https://steamcommunity.com/profiles/'
        . STEAM_ID . '"]' . STEAM_ID . '[/URL]';

    const CLEAN_BAN_DATA = [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => false,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 0,
    ];

    // --- AC1: ban report SteamID line is a clickable profile link -----------
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, CLEAN_BAN_DATA, 'GamerDude');
    $lines = explode("\n", $report);
    check(
        'ban report SteamID line is exactly the linked format',
        in_array(LINKED_ID_LINE, $lines, true),
        "report was:\n$report"
    );

    // --- AC2: API-error reply SteamID line uses the identical format --------
    $checker = makeChecker();
    $apiError = $checker->callBuildApiErrorMessage(STEAM_ID);
    check(
        'API-error reply SteamID line is exactly the linked format',
        in_array(LINKED_ID_LINE, explode("\n", $apiError), true),
        "reply was:\n$apiError"
    );

    // --- AC3: visible link text is exactly the bare SteamID64 ---------------
    // Parse the [URL] tag out of each reply's SteamID line and check both the
    // href and the inner (visible) text byte-for-byte.
    foreach (['ban report' => $report, 'API-error' => $apiError] as $name => $reply) {
        $idLine = null;
        foreach (explode("\n", $reply) as $line) {
            if (strpos($line, 'SteamID: ') === 0) {
                $idLine = $line;
                break;
            }
        }
        $parsed = $idLine !== null
            && preg_match('/^SteamID: \[URL="([^"]*)"\](.*)\[\/URL\]$/s', $idLine, $m);
        check(
            $name . ' SteamID line parses as a single [URL] tag',
            (bool) $parsed,
            'line: ' . var_export($idLine, true)
        );
        check(
            $name . ' link href is the profile URL derived from the ID',
            $parsed && $m[1] === 'https://steamcommunity.com/profiles/' . STEAM_ID,
            'href: ' . var_export($m[1] ?? null, true)
        );
        check(
            $name . ' visible link text is exactly the bare SteamID64',
            $parsed && $m[2] === STEAM_ID,
            'text: ' . var_export($m[2] ?? null, true)
        );
    }

    // --- AC4: unresolvable reply unchanged, no [URL markup ------------------
    $checker = makeChecker();
    $unresolvable = $checker->callBuildUnresolvableMessage('bogus-value');
    check(
        'unresolvable reply contains no [URL markup',
        stripos($unresolvable, '[URL') === false,
        "reply was:\n$unresolvable"
    );
    $expectedUnresolvable = implode("\n", [
        '[B]Steam VAC Check[/B]',
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Could not determine a valid Steam ID from the application. Manual check required.[/B][/COLOR]',
        'Raw value: bogus-value',
        '[I]Staff can re-run this check by replying in this thread with [ICODE]!vac your Steam64ID or profile URL[/ICODE].[/I]',
    ]);
    check(
        'unresolvable reply is byte-for-byte unchanged from the pre-issue-5 output',
        $unresolvable === $expectedUnresolvable,
        "reply was:\n$unresolvable"
    );

    // --- AC5: all other reply content unchanged -----------------------------
    // Ban report (bans detected) byte-for-byte: only the SteamID line gained
    // link markup; header, profile-name line, ban counts, status lines and
    // colors are untouched.
    $checker = makeChecker();
    $bannedReport = $checker->callBuildBanReportMessage(STEAM_ID, [
        'NumberOfVACBans'  => 2,
        'NumberOfGameBans' => 1,
        'CommunityBanned'  => true,
        'EconomyBan'       => 'banned',
        'DaysSinceLastBan' => 30,
    ], 'GamerDude');
    $expectedBanned = implode("\n", [
        '[B]Steam VAC Check[/B]',
        LINKED_ID_LINE,
        'Profile Name: [PLAIN]GamerDude[/PLAIN]',
        'VAC Bans: 2',
        'Game Bans: 1',
        // Issue #37: humanized age line (anchored to \XF::$time = 1700000000,
        // i.e. 2023-11-14 UTC; 30 days back is 2023-10-15 = exactly 30 days).
        'Last Ban: 30 days ago (30 days)',
        'Community Banned: Yes',
        'Economy Ban: banned',
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Ban(s) detected — review required.[/B][/COLOR]',
    ]);
    check(
        'ban report (bans detected) matches byte-for-byte with linked SteamID line',
        $bannedReport === $expectedBanned,
        "report was:\n$bannedReport"
    );

    $expectedClean = implode("\n", [
        '[B]Steam VAC Check[/B]',
        LINKED_ID_LINE,
        'Profile Name: [PLAIN]GamerDude[/PLAIN]',
        'VAC Bans: 0',
        'Game Bans: 0',
        'Community Banned: No',
        'Economy Ban: none',
        '[COLOR=rgb(39, 179, 11)][B]✅ No bans found.[/B][/COLOR]',
    ]);
    check(
        'ban report (no bans) matches byte-for-byte with linked SteamID line',
        $report === $expectedClean,
        "report was:\n$report"
    );

    $expectedApiError = implode("\n", [
        '[B]Steam VAC Check[/B]',
        LINKED_ID_LINE,
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Steam API error — could not complete the ban check. Manual check required.[/B][/COLOR]',
        '[I]Staff can re-run this check by replying in this thread with [ICODE]!vac your Steam64ID or profile URL[/ICODE].[/I]',
    ]);
    check(
        'API-error reply matches byte-for-byte with linked SteamID line',
        $apiError === $expectedApiError,
        "reply was:\n$apiError"
    );

    // --- E2E: run() and runManual() post replies with the linked SteamID ----
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
    $summaryJson = json_encode([
        'response' => ['players' => [['steamid' => STEAM_ID, 'personaname' => 'GamerDude']]],
    ]);
    // Needle keyed on the endpoint plus 'steamids=' . STEAM_ID, mirroring the
    // issue-6 suite: the GetPlayerBans URL also contains 'steamids=' . STEAM_ID.
    $summariesNeedle = 'GetPlayerSummaries/v2/?key=TESTKEY&steamids=' . STEAM_ID;

    $checker = makeChecker();
    $checker->httpResponses = [
        'GetPlayerBans'  => $bansJson,
        $summariesNeedle => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual posts ban report containing the linked SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false
            && strpos($checker->posted[0], 'Profile Name: [PLAIN]GamerDude[/PLAIN]') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    $checker = makeChecker();
    $checker->httpResponses = [
        // GetPlayerBans unmatched -> httpGet returns null (API failure)
        $summariesNeedle => $summaryJson,
    ];
    $checker->runManual(STEAM_ID);
    check(
        'runManual GetPlayerBans failure posts API-error reply with the linked SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false
            && strpos($checker->posted[0], 'Steam API error') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

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
    $checker->httpResponses = [
        'GetPlayerBans'  => $bansJson,
        $summariesNeedle => $summaryJson,
    ];
    $checker->run();
    check(
        'run() posts ban report containing the linked SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- E2E mutant-kill: profile-URL input, raw != resolved ----------------
    // The raw input is the URL form of the ID. The SteamID line must link the
    // bare resolved ID — never the raw input, which would produce a broken
    // double-URL href ([URL="https://steamcommunity.com/profiles/https://..."]).
    $profileUrlInput = 'https://steamcommunity.com/profiles/' . STEAM_ID;
    $doubleUrlHref   = '[URL="https://steamcommunity.com/profiles/https://';

    $checker = makeChecker();
    $checker->httpResponses = [
        'GetPlayerBans'  => $bansJson,
        $summariesNeedle => $summaryJson,
    ];
    $checker->runManual($profileUrlInput);
    check(
        'runManual(profile URL) posts ban report with the linked bare-ID SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false
            && strpos($checker->posted[0], $doubleUrlHref) === false,
        'posted: ' . var_export($checker->posted, true)
    );

    $checker = makeChecker();
    $checker->httpResponses = [
        // GetPlayerBans unmatched -> httpGet returns null (API failure)
        $summariesNeedle => $summaryJson,
    ];
    $checker->runManual($profileUrlInput);
    check(
        'runManual(profile URL) API failure posts API-error reply with the linked bare-ID SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false
            && strpos($checker->posted[0], 'Steam API error') !== false
            && strpos($checker->posted[0], $doubleUrlHref) === false,
        'posted: ' . var_export($checker->posted, true)
    );

    $post = new FakePost();
    $post->message = implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Arma 3',
        '[B]Steam64ID or Steam Account URL/Link[/B]',
        $profileUrlInput,
    ]);
    $em = new FakeEm();
    $em->entities['XF:Post:10'] = $post;
    \XF::$em = $em;

    $checker = makeChecker();
    $checker->httpResponses = [
        'GetPlayerBans'  => $bansJson,
        $summariesNeedle => $summaryJson,
    ];
    $checker->run();
    check(
        'run() with profile-URL field posts ban report with the linked bare-ID SteamID line',
        count($checker->posted) === 1
            && strpos($checker->posted[0], LINKED_ID_LINE) !== false
            && strpos($checker->posted[0], $doubleUrlHref) === false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- Vanity-resolver defense-in-depth ------------------------------------
    // ResolveVanityURL returns success but a malformed/malicious steamid. The
    // resolver must treat it as not-found: unresolvable reply, and the injected
    // markup never appears anywhere in the posted message.
    $checker = makeChecker();
    $checker->httpResponses = [
        'ResolveVanityURL' => json_encode([
            'response' => ['success' => 1, 'steamid' => 'x"][B]fake[/B]'],
        ]),
    ];
    $checker->runManual('https://steamcommunity.com/id/someuser');
    check(
        'malicious vanity steamid is treated as not-found (unresolvable reply, no injected markup)',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Could not determine a valid Steam ID') !== false
            && strpos($checker->posted[0], '[B]fake') === false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- Summary -------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
