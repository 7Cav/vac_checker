<?php

/**
 * Issue #7 — failure replies must include a one-line !vac re-run instruction.
 *
 * Self-contained: stubs the \XF static facade and \XF\Entity\Thread inline,
 * then drives the protected message builders via reflection. No network.
 *
 * Run:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
 *     php tests/Issue7RerunInstructionTest.php
 */

namespace XF\Entity {
    class Thread
    {
        public $thread_id = 1;
        public $first_post_id = 1;
        public $reply_count = 0;
    }
}

namespace {
    class XF
    {
        public static function options(): object
        {
            return (object) [
                'steamCheckerApiKey'    => 'test-key',
                'steamCheckerBotUserId' => 99,
                'steamCheckerDebugLog'  => false,
            ];
        }

        public static function logError(string $msg): void
        {
        }
    }

    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';

    $checker = new \Cav7\SteamChecker\SteamChecker(new \XF\Entity\Thread());

    $call = function (string $method, ...$args) use ($checker) {
        $ref = new \ReflectionMethod($checker, $method);
        $ref->setAccessible(true);
        return $ref->invoke($checker, ...$args);
    };

    $failures = 0;
    $check = function (string $label, bool $ok) use (&$failures) {
        echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
        if (!$ok) {
            $failures++;
        }
    };

    $unresolvable = $call('buildUnresolvableMessage', 'bogus-value');
    $apiError     = $call('buildApiErrorMessage', '76561197960287930');

    // Pull the instruction line (the line mentioning !vac) from each message.
    $instructionLine = function (string $message): ?string {
        foreach (explode("\n", $message) as $line) {
            if (stripos($line, '!vac') !== false) {
                return $line;
            }
        }
        return null;
    };

    $unresolvableLine = $instructionLine($unresolvable);
    $apiErrorLine     = $instructionLine($apiError);

    // AC1: unresolvable-ID reply includes a one-line !vac re-run instruction.
    $check('unresolvable reply contains a !vac instruction line',
        $unresolvableLine !== null);

    // AC2: Steam-API-error reply includes the same instruction.
    $check('API-error reply contains a !vac instruction line',
        $apiErrorLine !== null);
    $check('both replies use the identical instruction line',
        $unresolvableLine !== null && $unresolvableLine === $apiErrorLine);

    // AC3: instruction names the command, where to post it, and argument forms.
    $line = (string) $unresolvableLine;
    $check('instruction names the !vac command',
        stripos($line, '!vac') !== false);
    $check('instruction says to post a reply in this thread',
        stripos($line, 'repl') !== false && stripos($line, 'thread') !== false);
    $check('instruction names Steam64ID as an accepted argument',
        stripos($line, 'Steam64ID') !== false);
    $check('instruction names profile URL as an accepted argument',
        stripos($line, 'profile URL') !== false);

    // Edge-case guard: no literal valid-looking Steam64ID in the instruction.
    $check('instruction contains no literal valid Steam64ID',
        !preg_match('/7656119\d{10}/', $line));

    // Existing failure content must remain.
    $check('unresolvable reply keeps its header',
        strpos($unresolvable, '[B]Steam VAC Check[/B]') === 0);
    $check('unresolvable reply keeps the manual-check warning',
        strpos($unresolvable,
            'Could not determine a valid Steam ID from the application. Manual check required.') !== false);
    $check('unresolvable reply keeps the raw value line',
        strpos($unresolvable, 'Raw value: bogus-value') !== false);
    $check('API-error reply keeps the SteamID line',
        strpos($apiError, 'SteamID: 76561197960287930') !== false);
    $check('API-error reply keeps the manual-check warning',
        strpos($apiError,
            'Steam API error — could not complete the ban check. Manual check required.') !== false);

    // AC4: successful ban report is unchanged (banned and clean variants).
    $bannedReport = $call('buildBanReportMessage', '76561197960287930', [
        'NumberOfVACBans'  => 2,
        'NumberOfGameBans' => 1,
        'CommunityBanned'  => true,
        'EconomyBan'       => 'banned',
        'DaysSinceLastBan' => 30,
    ]);
    $expectedBanned = implode("\n", [
        '[B]Steam VAC Check[/B]',
        'SteamID: 76561197960287930',
        'VAC Bans: 2',
        'Game Bans: 1',
        'Days Since Last Ban: 30',
        'Community Banned: Yes',
        'Economy Ban: banned',
        '[COLOR=rgb(184, 49, 47)][B]⚠️ Ban(s) detected — review required.[/B][/COLOR]',
    ]);
    $check('ban report (bans detected) is byte-for-byte unchanged',
        $bannedReport === $expectedBanned);

    $cleanReport = $call('buildBanReportMessage', '76561197960287930', [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => false,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 0,
    ]);
    $expectedClean = implode("\n", [
        '[B]Steam VAC Check[/B]',
        'SteamID: 76561197960287930',
        'VAC Bans: 0',
        'Game Bans: 0',
        'Community Banned: No',
        'Economy Ban: none',
        '[COLOR=rgb(39, 179, 11)][B]✅ No bans found.[/B][/COLOR]',
    ]);
    $check('ban report (no bans) is byte-for-byte unchanged',
        $cleanReport === $expectedClean);

    echo "\n" . ($failures === 0
        ? "All checks passed.\n"
        : $failures . " check(s) FAILED.\n");
    exit($failures === 0 ? 0 : 1);
}
