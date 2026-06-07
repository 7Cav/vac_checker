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

    /**
     * Network spy: overrides both outbound-I/O paths reachable from
     * resolveSteamId() (httpGet for the vanity-URL API, resolveSteamShortLink
     * for s.team links — the latter uses raw curl, not httpGet). Any attempted
     * call is recorded and aborted, so tests can assert zero network I/O.
     */
    class NetworkSpySteamChecker extends \Cav7\SteamChecker\SteamChecker
    {
        /** @var string[] */
        public $networkCalls = [];

        protected function httpGet(string $url): ?string
        {
            $this->networkCalls[] = 'httpGet: ' . $url;
            throw new \RuntimeException('Unexpected network I/O: ' . $url);
        }

        protected function resolveSteamShortLink(string $url): ?string
        {
            $this->networkCalls[] = 'resolveSteamShortLink: ' . $url;
            throw new \RuntimeException('Unexpected network I/O: ' . $url);
        }
    }

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
        strpos($line, 'replying in this thread') !== false);
    $check('instruction names Steam64ID as an accepted argument',
        stripos($line, 'Steam64ID') !== false);
    $check('instruction names profile URL as an accepted argument',
        stripos($line, 'profile URL') !== false);

    // Edge-case guard: no literal valid-looking Steam64ID in the instruction.
    $check('instruction contains no literal valid Steam64ID',
        !preg_match('/7656119\d{10}/', $line));

    // Uniqueness + placement: instruction appears exactly once and is the
    // final line of each failure reply.
    foreach (['unresolvable' => $unresolvable, 'API-error' => $apiError] as $name => $reply) {
        $check($name . ' reply contains the instruction line exactly once',
            substr_count($reply, $line) === 1);
        $check($name . ' reply mentions !vac exactly once',
            substr_count(strtolower($reply), '!vac') === 1);
        $replyLines = explode("\n", $reply);
        $check($name . ' reply ends with the instruction line',
            end($replyLines) === $line);
    }

    // ------------------------------------------------------------------------
    // Placeholder non-resolution through the REAL resolver.
    //
    // The instruction's safety property is that no token a quote-reply can
    // extract from it resolves to a real Steam account. Pin that directly:
    // feed the tokens the Post.php pipeline can yield ('.', see the
    // characterization below) and the raw placeholder fragment ('<Steam64ID')
    // into the real resolveSteamId(), asserting null AND zero network I/O.
    // A future rewording whose placeholder accidentally matches the
    // vanity-URL pattern would attempt a network call and fail here.
    // ------------------------------------------------------------------------
    $resolveWithSpy = function (string $token): array {
        $spy = new NetworkSpySteamChecker(new \XF\Entity\Thread());
        $ref = new \ReflectionMethod($spy, 'resolveSteamId');
        $ref->setAccessible(true);
        try {
            $result = $ref->invoke($spy, $token);
            $threw  = false;
        } catch (\Throwable $e) {
            $result = '(threw: ' . $e->getMessage() . ')';
            $threw  = true;
        }
        return [$result, $spy->networkCalls, $threw];
    };

    foreach (['.', '<Steam64ID'] as $token) {
        // resolveSteamShortLink (raw curl, not spied by httpGet) is only
        // reachable when the token contains 's.team/' — guard against that.
        $check("token '$token' does not contain s.team/ (raw-curl shortlink path unreachable)",
            stripos($token, 's.team/') === false);

        [$resolved, $calls, $threw] = $resolveWithSpy($token);
        $check("resolveSteamId('$token') returns null without throwing",
            $resolved === null && !$threw);
        $check("resolveSteamId('$token') performs zero network I/O",
            $calls === []);
    }

    // ------------------------------------------------------------------------
    // Characterization: staff quote-reply of a failure post (ACCEPTED edge
    // case from issue #7 — this documents the behaviour, it does not defend
    // a requirement).
    //
    // When staff quote the bot's failure reply, Post.php's parsing pipeline
    // reduces the quoted instruction to "!vac  ." — strip_tags() eats
    // "<Steam64ID or profile URL>" as a pseudo-HTML tag — so the !vac handler
    // fires with the literal token '.', which must never resolve (pinned
    // above). The pipeline below is copied byte-for-byte from
    // src/addons/Cav7/SteamChecker/XF/Entity/Post.php lines 79-83; if Post.php
    // changes, update this copy and re-pin.
    // ------------------------------------------------------------------------
    $fixture = '[QUOTE="VAC Bot, post: 123, member: 99"]' . "\n"
        . $apiError . "\n"
        . '[/QUOTE]' . "\n"
        . 'Looks like the check failed — can someone take a look?';

    $plain = preg_replace('/\[URL[^\]]*\](.*?)\[\/URL\]/is', '$1', $fixture);
    $plain = preg_replace('/\[[^\]]*\]/', ' ', $plain);
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $quoteMatched = preg_match('/!vac\s+(\S+)/i', $plain, $qm);

    $check('quote-reply pipeline DOES match !vac in the quoted instruction (accepted edge case)',
        $quoteMatched === 1);

    $quotedToken = $qm[1] ?? '(no match)';
    $check("quote-reply pipeline captures the literal token '.'",
        $quotedToken === '.');

    [$quotedResolved, $quotedCalls, $quotedThrew] = $resolveWithSpy($quotedToken);
    $check('quoted token resolves to null with zero network I/O',
        $quotedResolved === null && !$quotedThrew && $quotedCalls === []);

    // Existing failure content must remain.
    $check('unresolvable reply keeps its header',
        strpos($unresolvable, '[B]Steam VAC Check[/B]') === 0);
    $check('unresolvable reply keeps the manual-check warning',
        strpos($unresolvable,
            'Could not determine a valid Steam ID from the application. Manual check required.') !== false);
    $check('unresolvable reply keeps the raw value line',
        strpos($unresolvable, 'Raw value: bogus-value') !== false);
    // SteamID line is a clickable profile link since issue #5.
    $check('API-error reply keeps the SteamID line',
        strpos($apiError,
            'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]') !== false);
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
        'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]', // linked since issue #5
        'Profile Name: (unknown)', // null persona name (issue #6) — builder called without a fetch
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
        'SteamID: [URL="https://steamcommunity.com/profiles/76561197960287930"]76561197960287930[/URL]', // linked since issue #5
        'Profile Name: (unknown)', // null persona name (issue #6) — builder called without a fetch
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
