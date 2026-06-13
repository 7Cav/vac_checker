<?php

/**
 * Issue #37 — human-readable last-ban age.
 *
 * Self-contained test script. No framework, no network. Drives the protected
 * helper formatBanAge() and the buildBanReportMessage() builder via reflection.
 *
 * The conversion is calendar-accurate (leap years respected) by anchoring to a
 * "now" timestamp and subtracting N days, then breaking the span down with the
 * real Gregorian calendar. To keep assertions stable across run dates, the
 * tests pin \XF::$time to fixed timestamps chosen so the day-count of each span
 * is deterministic regardless of when the suite runs.
 *
 *   docker run --rm -v /path/to/repo:/app -w /app php:8.3-cli php tests/Issue37HumanReadableBanAgeTest.php
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

// ---------------------------------------------------------------------------
// Testable subclass — exposes protected methods, no HTTP / no posting
// ---------------------------------------------------------------------------

namespace Cav7\SteamChecker {
    require __DIR__ . '/../src/addons/Cav7/SteamChecker/SteamChecker.php';

    class Issue37TestableChecker extends SteamChecker
    {
        protected function httpGet(string $url): ?string
        {
            return null;
        }

        protected function postReply(string $message): void
        {
        }

        public function callFormatBanAge(int $days): string
        {
            return $this->formatBanAge($days);
        }

        public function callBuildBanReportMessage(...$args): string
        {
            return $this->buildBanReportMessage(...$args);
        }
    }
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

namespace Issue37Tests {

    use Cav7\SteamChecker\Issue37TestableChecker;

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

    function makeChecker(): Issue37TestableChecker
    {
        return new Issue37TestableChecker(new \XF\Entity\Thread());
    }

    const STEAM_ID = '76561198000000001';

    // Build ban data with a given DaysSinceLastBan and at least one ban so the
    // age line is shown.
    function banData(int $days): array
    {
        return [
            'NumberOfVACBans'  => 1,
            'NumberOfGameBans' => 0,
            'CommunityBanned'  => false,
            'EconomyBan'       => 'none',
            'DaysSinceLastBan' => $days,
        ];
    }

    // Pin "now" to a fixed instant so spans are deterministic regardless of the
    // real run date. Midday UTC avoids DST/midnight edge ambiguity.
    function pinNow(string $iso): void
    {
        \XF::$time = (new \DateTimeImmutable($iso, new \DateTimeZone('UTC')))->getTimestamp();
    }

    // -----------------------------------------------------------------------
    // Helper-level tests (formatBanAge)
    // -----------------------------------------------------------------------

    // --- Zero / boundary: 0 days reads naturally ----------------------------
    pinNow('2023-06-15T12:00:00Z');
    $checker = makeChecker();
    $zero = $checker->callFormatBanAge(0);
    check(
        '0 days does not read as "0 years, 0 months, 0 days"',
        strpos($zero, '0 years') === false
            && strpos($zero, '0 months') === false,
        "got: $zero"
    );
    check(
        '0 days renders a natural, non-empty string',
        trim($zero) !== '' && (strpos($zero, 'today') !== false || $zero === '0 days'),
        "got: $zero"
    );

    // --- Single unit: exactly 1 day -> "1 day" (singular) -------------------
    // Anchor so that now-1day stays in the same month: pick mid-month.
    pinNow('2023-06-15T12:00:00Z');
    $checker = makeChecker();
    $oneDay = $checker->callFormatBanAge(1);
    check(
        '1 day renders singular ("1 day", not "1 days")',
        preg_match('/(^|\D)1 day(\b|$)/', $oneDay) === 1
            && strpos($oneDay, '1 days') === false,
        "got: $oneDay"
    );
    check(
        '1 day has no leading zero year/month units',
        strpos($oneDay, '0 ') === false,
        "got: $oneDay"
    );

    // --- Two days -> plural "2 days" ----------------------------------------
    pinNow('2023-06-15T12:00:00Z');
    $checker = makeChecker();
    $twoDays = $checker->callFormatBanAge(2);
    check(
        '2 days renders plural',
        strpos($twoDays, '2 days') !== false,
        "got: $twoDays"
    );

    // --- Exactly one calendar year (leap-aware) -> "1 year" -----------------
    // 2024 is a leap year. From 2025-03-01, exactly one calendar year back is
    // 2024-03-01, which is 365 days earlier (Mar 2024 -> Mar 2025 span does not
    // include Feb 29 2024... it does: 2024-03-01 minus is fine). Use a span we
    // can reason about: from 2023-03-01, 365 days earlier is 2022-03-01 (no leap
    // day between), i.e. exactly 1 year, 0 months, 0 days.
    pinNow('2023-03-01T12:00:00Z');
    $checker = makeChecker();
    $oneYear = $checker->callFormatBanAge(365);
    check(
        '365 days from 2023-03-01 is exactly "1 year" (no leap day in span)',
        $oneYear === '1 year',
        "got: $oneYear"
    );

    // --- Leap-year correctness: 366-day span that crosses Feb 29 ------------
    // From 2024-06-15, 366 days earlier is 2023-06-15 (the span 2023-06-15 ->
    // 2024-06-15 includes Feb 29 2024). Calendar breakdown is exactly 1 year.
    // A naive fixed-365 calc would instead yield "1 year, 1 day". This asserts
    // leap awareness.
    pinNow('2024-06-15T12:00:00Z');
    $checker = makeChecker();
    $leapSpan = $checker->callFormatBanAge(366);
    check(
        '366-day span crossing Feb 29 2024 reads "1 year" (leap-aware, not "1 year, 1 day")',
        $leapSpan === '1 year',
        "got: $leapSpan"
    );

    // And the non-leap counterpart: 365-day span ending 2024-06-15 starts
    // 2024-06-16 (2023) ... use a year with no leap day in the trailing span.
    // From 2023-06-15, 365 days earlier is 2022-06-15 (no Feb 29 in
    // 2022-06-15..2023-06-15) -> exactly 1 year.
    pinNow('2023-06-15T12:00:00Z');
    $checker = makeChecker();
    $nonLeap = $checker->callFormatBanAge(365);
    check(
        '365-day span with no leap day reads "1 year"',
        $nonLeap === '1 year',
        "got: $nonLeap"
    );

    // --- Structural: years/months/days, omit leading zero units -------------
    // Pick a multi-year span and assert structure rather than a frozen string,
    // so the test cannot rot. Whatever the breakdown, it must:
    //   * never contain a "0 <unit>" leading term,
    //   * be comma-space separated,
    //   * pluralize each present unit correctly,
    //   * round-trip back to the original day count when re-added to the anchor.
    pinNow('2025-09-20T12:00:00Z');
    foreach ([3, 5, 47, 400, 847, 1000, 2000] as $days) {
        $checker = makeChecker();
        $s = $checker->callFormatBanAge($days);

        // No leading zero-valued unit anywhere.
        check(
            "formatBanAge($days): no zero-valued unit term",
            !preg_match('/\b0 (year|month|day)s?\b/', $s),
            "got: $s"
        );

        // Each term is "<n> <unit>[s]" with correct pluralization.
        $terms = explode(', ', $s);
        $okTerms = true;
        $sumOk = true;
        foreach ($terms as $term) {
            if (!preg_match('/^(\d+) (year|month|day)(s?)$/', $term, $m)) {
                $okTerms = false;
                break;
            }
            $n = (int) $m[1];
            $plural = $m[3] === 's';
            // Singular iff n === 1.
            if (($n === 1) === $plural) {
                $okTerms = false;
                break;
            }
        }
        check(
            "formatBanAge($days): every term is '<n> <unit>' with correct plural",
            $okTerms,
            "got: $s"
        );

        // Round-trip: reconstruct the date from the breakdown and confirm it is
        // exactly $days before the anchor. This proves the breakdown is a
        // faithful calendar decomposition (the core leap-year guarantee).
        if ($okTerms) {
            $y = 0; $mo = 0; $d = 0;
            foreach ($terms as $term) {
                preg_match('/^(\d+) (year|month|day)/', $term, $m);
                $n = (int) $m[1];
                if ($m[2] === 'year') { $y = $n; }
                elseif ($m[2] === 'month') { $mo = $n; }
                else { $d = $n; }
            }
            $now = (new \DateTimeImmutable('@' . \XF::$time))->setTime(0, 0, 0);
            $reconstructed = $now->sub(new \DateInterval("P{$y}Y{$mo}M{$d}D"));
            $diff = $now->diff($reconstructed)->days;
            check(
                "formatBanAge($days): breakdown round-trips to $days calendar days",
                $diff === $days,
                "got: $s (reconstructed diff=$diff)"
            );
        }
    }

    // --- Ordering: when multiple units present, years before months before days
    pinNow('2025-09-20T12:00:00Z');
    $checker = makeChecker();
    $multi = $checker->callFormatBanAge(800); // ~2y 2m
    $terms = explode(', ', $multi);
    $order = [];
    foreach ($terms as $term) {
        if (strpos($term, 'year') !== false) { $order[] = 'y'; }
        elseif (strpos($term, 'month') !== false) { $order[] = 'm'; }
        elseif (strpos($term, 'day') !== false) { $order[] = 'd'; }
    }
    $sorted = $order;
    usort($sorted, fn($a, $b) => array_search($a, ['y','m','d']) <=> array_search($b, ['y','m','d']));
    check(
        '800-day breakdown lists years, then months, then days',
        $order === $sorted && count($order) >= 1,
        "got: $multi"
    );

    // -----------------------------------------------------------------------
    // Builder-level tests (buildBanReportMessage)
    // -----------------------------------------------------------------------

    // --- Age line shows humanized duration, not a raw count -----------------
    pinNow('2025-09-20T12:00:00Z');
    $checker = makeChecker();
    $report = $checker->callBuildBanReportMessage(STEAM_ID, banData(847), 'GamerDude');
    $ageLine = null;
    foreach (explode("\n", $report) as $line) {
        // The age line is the list item carrying the "Last ban:" label
        // (legacy "Last Ban:" / "Days Since Last Ban:" kept for back-compat).
        if (strpos($line, 'Last ban:') !== false
            || strpos($line, 'Last Ban:') !== false
            || strpos($line, 'Days Since Last Ban:') !== false) {
            $ageLine = $line;
            break;
        }
    }
    check(
        'ban report includes a last-ban age line',
        $ageLine !== null,
        "report was:\n$report"
    );
    $expectedHuman = $checker->callFormatBanAge(847);
    check(
        'age line renders the humanized duration',
        $ageLine !== null && strpos($ageLine, $expectedHuman) !== false,
        "ageLine: " . var_export($ageLine, true) . " expected to contain: $expectedHuman"
    );
    check(
        'age line keeps the raw day count available (parenthetical)',
        $ageLine !== null && strpos($ageLine, '847') !== false,
        "ageLine: " . var_export($ageLine, true)
    );
    check(
        'age line is no longer a bare "Days Since Last Ban: 847"',
        $report !== null && strpos($report, "\nDays Since Last Ban: 847\n") === false
            && !preg_match('/Days Since Last Ban: 847$/m', $report),
        "report was:\n$report"
    );

    // --- Age line only appears when there are bans --------------------------
    pinNow('2025-09-20T12:00:00Z');
    $checker = makeChecker();
    $clean = [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => false,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 0,
    ];
    $cleanReport = $checker->callBuildBanReportMessage(STEAM_ID, $clean, 'GamerDude');
    check(
        'clean report (no bans) shows no last-ban age line',
        stripos($cleanReport, 'Last ban') === false
            && stripos($cleanReport, 'Days Since') === false,
        "report was:\n$cleanReport"
    );
    check(
        'clean report still shows the no-bans verdict',
        strpos($cleanReport, 'No bans found') !== false,
        "report was:\n$cleanReport"
    );

    // --- Age line present when only a community ban exists -------------------
    pinNow('2025-09-20T12:00:00Z');
    $checker = makeChecker();
    $communityOnly = [
        'NumberOfVACBans'  => 0,
        'NumberOfGameBans' => 0,
        'CommunityBanned'  => true,
        'EconomyBan'       => 'none',
        'DaysSinceLastBan' => 400,
    ];
    $commReport = $checker->callBuildBanReportMessage(STEAM_ID, $communityOnly, 'GamerDude');
    check(
        'community-only ban report shows the age line',
        strpos($commReport, $checker->callFormatBanAge(400)) !== false,
        "report was:\n$commReport"
    );

    // --- Summary -------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
