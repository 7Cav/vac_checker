<?php

/**
 * Issue #36 — Star Citizen (and any non-Steam PC) enlistments must skip silently.
 *
 * A Star Citizen application selects platform `PC - Star Citizen`, so it passes
 * the PC platform gate, but its form omits the Steam-identifier field entirely
 * (it carries an `RSI Profile:` link instead). With no Steam account to check,
 * run() must post nothing — the same quiet skip as a non-PC platform.
 *
 * The discriminator is structural, NOT a game name: skip only when the
 * Steam-identifier field LABEL is absent from the post. When the label IS
 * present but its value can't be resolved (typo, vanity, private, EA nickname),
 * the loud buildUnresolvableMessage reply must still fire — staff rely on it.
 *
 * Self-contained: stubs the \XF facade, \XF\Entity\Thread, a FakeEm and
 * FakePost inline, and drives run() with no network (httpGet stubbed). Models
 * Issue6PersonaNameTest's run() harness. Exits non-zero on any failure.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/Issue36StarCitizenSkipTest.php
 */

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

namespace Issue36Tests {
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

namespace Cav7\SteamChecker {
    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';

    class Issue36TestableChecker extends SteamChecker
    {
        /** @var array<string, ?string> URL-substring => response body */
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
    }
}

namespace Issue36Tests {

    use Cav7\SteamChecker\Issue36TestableChecker;

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

    function resetLogs(): void
    {
        \XF::$loggedErrors = [];
        \XF::$loggedExceptions = [];
    }

    const STEAM_ID = '76561198000000001';

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

    /**
     * Wires \XF::$em to a single first post carrying $message, returns a checker
     * with a clean GetPlayerBans response stubbed in (so a resolvable Steam ID
     * produces a normal report).
     */
    function makeRunChecker(string $message): Issue36TestableChecker
    {
        global $bansJson;
        $post = new FakePost();
        $post->message = $message;
        $em = new FakeEm();
        $em->entities['XF:Post:10'] = $post;
        \XF::$em = $em;

        $checker = new Issue36TestableChecker(new \XF\Entity\Thread());
        $checker->httpResponses['GetPlayerBans'] = $bansJson;
        return $checker;
    }

    // --- AC1: PC - Star Citizen with no Steam field → NO reply (silent) ------
    // Confirmed real-application shape: PC - Star Citizen platform, an
    // RSI Profile: link, and no Steam-identifier field anywhere.
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Star Citizen',
        '[B]RSI Profile:[/B]',
        'https://robertsspaceindustries.com/citizens/SomePilot',
    ]));
    $checker->run();
    check(
        'PC - Star Citizen enlistment with no Steam field posts no reply',
        $checker->posted === [],
        'posted: ' . var_export($checker->posted, true)
    );

    // --- AC5 reinforcement: skip is driven by absence of the field label,
    // not by the game name. A made-up non-Steam PC title with no Steam field
    // must also skip — proving no hardcoded game list.
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Some Future Non-Steam Title',
        '[B]Account Profile:[/B]',
        'https://example.com/profile/abc',
    ]));
    $checker->run();
    check(
        'any PC enlistment lacking the Steam field label skips silently (no game hardcoded)',
        $checker->posted === [],
        'posted: ' . var_export($checker->posted, true)
    );

    // --- AC2: standard PC enlistment with a resolvable Steam ID → normal report
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Arma 3',
        '[B]Steam64ID or Steam Account URL/Link[/B]',
        STEAM_ID,
    ]));
    $checker->run();
    check(
        'standard PC enlistment with resolvable Steam ID posts a ban report',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Steam VAC Check') !== false
            && strpos($checker->posted[0], STEAM_ID) !== false
            && strpos($checker->posted[0], 'No bans found') !== false
            && strpos($checker->posted[0], 'Manual check required') === false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- AC3: PC enlistment WITH a Steam field whose value can't be resolved
    // → still gets the loud "manual check required" reply. The field label is
    // present; only the value is garbage.
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Arma 3',
        '[B]Steam64ID or Steam Account URL/Link[/B]',
        'not-a-real-steam-id-just-a-nickname',
    ]));
    $checker->run();
    check(
        'PC enlistment with an unresolvable Steam value still gets the manual-check reply',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Manual check required') !== false
            && strpos($checker->posted[0], 'Raw value: not-a-real-steam-id-just-a-nickname') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- AC3 variant: the Steam field label is present but its value line is
    // EMPTY/whitespace (applicant left it blank). This is still "field present,
    // value won't resolve" — the loud reply must fire, NOT the silent skip.
    // This is the discriminating case the brief calls out.
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'PC - Arma 3',
        '[B]Steam64ID or Steam Account URL/Link[/B]',
        '',
        '[B]Some Next Section[/B]',
    ]));
    $checker->run();
    check(
        'PC enlistment with a present-but-blank Steam field still gets the manual-check reply',
        count($checker->posted) === 1
            && strpos($checker->posted[0], 'Manual check required') !== false,
        'posted: ' . var_export($checker->posted, true)
    );

    // --- AC4: non-PC enlistments continue to be skipped silently --------------
    resetLogs();
    $checker = makeRunChecker(implode("\n", [
        '[B]Platform and Game Selection[/B]',
        'Xbox',
        '[B]Gamertag[/B]',
        'SomeGamer',
    ]));
    $checker->run();
    check(
        'non-PC (Xbox) enlistment is skipped silently',
        $checker->posted === [],
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
