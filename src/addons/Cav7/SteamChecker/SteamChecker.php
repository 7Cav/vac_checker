<?php

namespace Cav7\SteamChecker;

class SteamChecker
{
    /** @var \XF\Entity\Thread */
    protected $thread;

    /** @var string */
    protected $apiKey;

    /** @var int */
    protected $botUserId;

    public function __construct(\XF\Entity\Thread $thread)
    {
        $this->thread = $thread;
        $this->apiKey = (string) \XF::options()->steamCheckerApiKey;
        $this->botUserId = (int) \XF::options()->steamCheckerBotUserId;
    }

    protected function debug(string $msg): void
    {
        if (\XF::options()->steamCheckerDebugLog) {
            \XF::logError('[VAC-DEBUG] ' . $msg);
        }
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $this->debug('SteamChecker::run() started for thread ' . $this->thread->thread_id);

        if (!$this->apiKey) {
            \XF::logError('[Cav7/SteamChecker] Steam API key is not configured.');
            return;
        }

        if (!$this->botUserId) {
            \XF::logError('[Cav7/SteamChecker] Bot user ID is not configured.');
            return;
        }

        $this->debug('Config OK. apiKey=set botUserId=' . $this->botUserId . ' first_post_id=' . $this->thread->first_post_id);

        /** @var \XF\Entity\Post|null $post */
        $post = \XF::em()->find('XF:Post', $this->thread->first_post_id);
        if (!$post) {
            \XF::logError('[Cav7/SteamChecker] Could not load first post for thread ' . $this->thread->thread_id);
            return;
        }

        $message = $post->message;
        $this->debug('Post loaded. Message length=' . strlen($message));

        // --- Platform check -------------------------------------------------
        // Try the full label (standard enlistment) first; re-enlistment forms use
        // the shorter label, sometimes with the value inline on the same line.
        $platform = $this->extractNextLineField($message, 'Platform and Game Selection')
                 ?? $this->extractNextLineField($message, 'Platform and Game');
        $this->debug('Platform extracted: ' . var_export($platform, true));

        if ($platform === null || stripos(trim($platform), 'PC') !== 0) {
            $this->debug('Non-PC platform or field not found — skipping.');
            return;
        }

        // --- Steam ID extraction --------------------------------------------
        $rawSteamField = $this->extractNextLineField($message, 'Steam64ID or Steam Account URL/Link');
        $this->debug('Raw Steam field extracted: ' . var_export($rawSteamField, true));

        if ($rawSteamField === null) {
            $this->postReply($this->buildUnresolvableMessage('(field not found in post)'));
            return;
        }

        $rawSteamId = trim($rawSteamField);

        // --- Steam ID resolution --------------------------------------------
        try {
            $steamId64 = $this->resolveSteamId($rawSteamId);
            $this->debug('Resolved SteamID64: ' . var_export($steamId64, true));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] Steam ID resolution error: ');
            $this->postReply($this->buildUnresolvableMessage($rawSteamId));
            return;
        }

        if ($steamId64 === null) {
            $this->postReply($this->buildUnresolvableMessage($rawSteamId));
            return;
        }

        // --- Ban data fetch --------------------------------------------------
        try {
            $banData = $this->fetchBanData($steamId64);
            $this->debug('Ban data fetched: ' . json_encode($banData));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] Steam API error: ');
            $this->postReply($this->buildApiErrorMessage($steamId64));
            return;
        }

        // --- Persona name fetch (best effort) --------------------------------
        try {
            $personaName = $this->fetchPlayerSummary($steamId64);
            $this->debug('Persona name fetched: ' . var_export($personaName, true));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] Player summary error: ');
            $personaName = null;
        }

        // --- Post result ----------------------------------------------------
        $this->debug('Calling postReply.');
        $this->postReply($this->buildBanReportMessage($steamId64, $banData, $personaName));
    }

    /**
     * Manual invocation via the !vac command. Skips first-post extraction and
     * platform detection; resolves the supplied Steam identifier directly and
     * posts the standard ban-report reply.
     */
    public function runManual(string $rawSteamId): void
    {
        $this->debug('SteamChecker::runManual() rawSteamId=' . $rawSteamId);

        if (!$this->apiKey) {
            \XF::logError('[Cav7/SteamChecker] Steam API key is not configured.');
            return;
        }

        if (!$this->botUserId) {
            \XF::logError('[Cav7/SteamChecker] Bot user ID is not configured.');
            return;
        }

        // --- Steam ID resolution --------------------------------------------
        try {
            $steamId64 = $this->resolveSteamId($rawSteamId);
            $this->debug('runManual resolved SteamID64: ' . var_export($steamId64, true));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] !vac Steam ID resolution error: ');
            $this->postReply($this->buildUnresolvableMessage($rawSteamId));
            return;
        }

        if ($steamId64 === null) {
            $this->postReply($this->buildUnresolvableMessage($rawSteamId));
            return;
        }

        // --- Ban data fetch --------------------------------------------------
        try {
            $banData = $this->fetchBanData($steamId64);
            $this->debug('runManual ban data: ' . json_encode($banData));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] !vac Steam API error: ');
            $this->postReply($this->buildApiErrorMessage($steamId64));
            return;
        }

        // --- Persona name fetch (best effort) --------------------------------
        try {
            $personaName = $this->fetchPlayerSummary($steamId64);
            $this->debug('runManual persona name: ' . var_export($personaName, true));
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/SteamChecker] !vac player summary error: ');
            $personaName = null;
        }

        // --- Post result ----------------------------------------------------
        $this->postReply($this->buildBanReportMessage($steamId64, $banData, $personaName));
    }

    /**
     * Reply for a degenerate invocation (issue #25): the staffer attempted
     * !vac but no argument survived normalization — the trailing-token rule
     * in Post.php fired. Posts the two-line usage reply (lead-in + the
     * single-sourced re-run instruction). No Steam API interaction happens
     * on this path, so only the bot user is required, not the API key.
     */
    public function replyDegenerateInvocation(): void
    {
        $this->debug('SteamChecker::replyDegenerateInvocation() for thread '
            . $this->thread->thread_id);

        if (!$this->botUserId) {
            \XF::logError('[Cav7/SteamChecker] Bot user ID is not configured.');
            return;
        }

        $this->postReply($this->buildDegenerateInvocationMessage());
    }

    // -------------------------------------------------------------------------
    // Post-body parsing
    // -------------------------------------------------------------------------

    /**
     * Finds a BBCode label line and returns the associated value.
     *
     * Handles two formats:
     *   Inline:    "Label  Value on same line"  (re-enlistment forms)
     *   Next-line: "[B]Label[/B]\nValue"        (standard enlistment forms)
     *
     * Strips BBCode tags from each line before comparing. Returns null if the
     * label is not found.
     */
    protected function extractNextLineField(string $message, string $fieldLabel): ?string
    {
        $lines = explode("\n", $message);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $plain = $this->stripBbCode($lines[$i]);
            $pos   = stripos($plain, $fieldLabel);
            if ($pos === false) {
                continue;
            }

            // Inline value: text remaining on the same line after the label.
            // Ignore if it starts with "or " — that means the form appended
            // extra options to the label (e.g. "...URL/Link or EA Nick Name")
            // and the actual value is on the next line.
            $inline = trim(substr($plain, $pos + strlen($fieldLabel)));
            if ($inline !== '' && stripos($inline, 'or ') !== 0) {
                return $inline;
            }

            // Next-line value: first non-empty line that follows.
            for ($j = $i + 1; $j < $count; $j++) {
                $value = trim($lines[$j]);
                if ($value !== '') {
                    return $this->stripBbCode($value);
                }
            }
        }

        return null;
    }

    /**
     * Removes all BBCode tags (e.g. [B], [/B], [COLOR=...], [/COLOR]) from a
     * string, leaving only the plain text content.
     */
    protected function stripBbCode(string $text): string
    {
        return trim(preg_replace('/\[[^\]]*\]/', '', $text));
    }

    /**
     * Neutralizes bracket-delimited BBCode tags in untrusted text that will be
     * embedded in a bot post (e.g. a Steam persona name), and strips ASCII
     * control characters. Runs of control characters (including newlines, which
     * could otherwise fabricate extra report lines) collapse to a single space.
     * ASCII square brackets are replaced with their fullwidth lookalikes, so
     * injected tags like [B] or [URL=...] stay visible as text but are never
     * parsed as markup. The substitution is visible: fullwidth brackets render
     * differently from ASCII ones, and copy-pasted text carries the lookalikes,
     * not the original ASCII brackets.
     */
    protected function neutralizeBbCode(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        return str_replace(['[', ']'], ['［', '］'], $text);
    }

    // -------------------------------------------------------------------------
    // Steam ID resolution
    // -------------------------------------------------------------------------

    /**
     * Accepts any of the known Steam identifier formats and returns a
     * SteamID64 string, or null if the value cannot be resolved.
     *
     * @throws \RuntimeException on API failure (caller should catch)
     */
    protected function resolveSteamId(string $raw): ?string
    {
        // 1. Bare SteamID64 — 17-digit number starting with 7656119
        if (preg_match('/^(7656119\d{10})$/', $raw, $m)) {
            return $m[1];
        }

        // 2. Full profile URL containing a SteamID64 in the path
        if (preg_match('|steamcommunity\.com/profiles/(\d{17})|i', $raw, $m)) {
            return $m[1];
        }

        // 3. Vanity URL (e.g. https://steamcommunity.com/id/SomeUser/)
        if (preg_match('|steamcommunity\.com/id/([^/?\s]+)|i', $raw, $m)) {
            return $this->resolveVanityUrl($m[1]);
        }

        // 4. s.team short link — fetch the page and scan for a Steam profile URL
        //    in both the final redirect destination and the response body.
        //    s.team/p/ friend-invite links serve an HTML page rather than doing
        //    a plain HTTP redirect, so a HEAD request is not sufficient.
        if (stripos($raw, 's.team/') !== false) {
            return $this->resolveSteamShortLink($raw);
        }

        // Unrecognised format
        return null;
    }

    /**
     * Calls the Steam ResolveVanityURL API and returns the SteamID64, or null
     * if the vanity name is not found — or if the API returns anything other
     * than a well-formed 17-digit SteamID64.
     *
     * @throws \RuntimeException on HTTP or API failure
     */
    protected function resolveVanityUrl(string $vanity): ?string
    {
        $url = 'https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/'
            . '?key=' . urlencode($this->apiKey)
            . '&vanityurl=' . urlencode($vanity);

        $body = $this->httpGet($url);
        if ($body === null) {
            throw new \RuntimeException('ResolveVanityURL request failed for vanity: ' . $vanity);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['response']['success'])) {
            throw new \RuntimeException('Unexpected ResolveVanityURL response for vanity: ' . $vanity);
        }

        if ((int) $data['response']['success'] !== 1) {
            // Valid response but the vanity name was not found
            return null;
        }

        $steamId = $data['response']['steamid'] ?? null;

        // Defense-in-depth: only ever return a well-formed SteamID64 — this
        // value flows into BBCode replies and must never carry arbitrary API
        // content. Anything else is treated as not-found.
        return (is_string($steamId) && preg_match('/^\d{17}$/', $steamId))
            ? $steamId
            : null;
    }

    /**
     * Resolves an s.team short link to a SteamID64 by following redirects
     * or fetching the page body if it's a friend invite link.
     */
    protected function resolveSteamShortLink(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        // Friend invite links have the form s.team/p/{invite_code}/{confirm_token}.
        // The confirmation token is only needed for the friend-accept UI; the invite
        // code alone is a public profile shortlink that resolves without a Steam
        // session. Strip the token so we land on the profile instead of the login page.
        if (preg_match('#s\.team/p/([^/?]+)/[^/?]+#i', $url, $sm)) {
            $url = 'https://s.team/p/' . $sm[1];
            $this->debug('Stripped s.team invite token — fetching: ' . $url);
        }

        // One GET request: follow all redirects and capture both the final
        // URL and the response body. A browser User-Agent is required —
        // s.team returns a stub page for bots.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);

        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $this->debug('s.team fetch: errno=' . $errno . ' finalUrl=' . $finalUrl . ' bodyLen=' . strlen($body ?: ''));
        if ($body) {
            $this->debug('s.team body snippet: ' . substr(preg_replace('/\s+/', ' ', $body), 0, 500));
        }

        if ($errno !== 0 || $body === false) {
            return null;
        }

        // If we still end up on a login page (e.g. the invite code itself requires
        // auth), there is nothing more we can do without a Steam session.
        if ($finalUrl && preg_match('#steamcommunity\.com/login/#i', $finalUrl)) {
            $this->debug('Steam login redirect — cannot resolve without session.');
            return null;
        }

        // If the redirect chain landed on a recognisable Steam profile URL, done.
        if ($finalUrl && preg_match('#steamcommunity\.com/(id|profiles)/#i', $finalUrl)) {
            return $this->resolveSteamId($finalUrl);
        }

        // Scan the response body for a SteamID64.
        // Steam embeds the 17-digit ID in page JSON, meta tags, and JS variables.
        if ($body && preg_match('/\b(7656119\d{10})\b/', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Calls the Steam GetPlayerBans API and returns the player record array.
     *
     * @throws \RuntimeException on HTTP or API failure
     */
    protected function fetchBanData(string $steamId64): array
    {
        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerBans/v1/'
            . '?key=' . urlencode($this->apiKey)
            . '&steamids=' . urlencode($steamId64);

        $body = $this->httpGet($url);
        if ($body === null) {
            throw new \RuntimeException('GetPlayerBans request failed for SteamID: ' . $steamId64);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['players'][0])) {
            throw new \RuntimeException('Unexpected GetPlayerBans response for SteamID: ' . $steamId64);
        }

        return $data['players'][0];
    }

    /**
     * Calls the Steam GetPlayerSummaries API and returns the player's current
     * persona (profile) name, or null if it cannot be fetched. All failure
     * modes (HTTP failure, malformed JSON, missing fields) return null rather
     * than throwing and are logged via \XF::logError — the persona name is
     * decorative and must not block the ban report. Callers still wrap this
     * in try/catch as defence-in-depth (overrides or logging failures could
     * still throw).
     */
    protected function fetchPlayerSummary(string $steamId64): ?string
    {
        $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/'
            . '?key=' . urlencode($this->apiKey)
            . '&steamids=' . urlencode($steamId64);

        $body = $this->httpGet($url);
        if ($body === null) {
            \XF::logError('[Cav7/SteamChecker] GetPlayerSummaries request failed (network) for SteamID: ' . $steamId64);
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['response'])) {
            \XF::logError('[Cav7/SteamChecker] Unexpected GetPlayerSummaries response for SteamID ' . $steamId64 . ' (len=' . strlen($body) . '): ' . substr($body, 0, 200));
            return null;
        }

        $name = $data['response']['players'][0]['personaname'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            \XF::logError('[Cav7/SteamChecker] GetPlayerSummaries returned no persona name for SteamID: ' . $steamId64);
            return null;
        }

        return $name;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Performs a GET request and returns the response body, or null on failure.
     */
    protected function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Cav7/SteamChecker XenForo Addon/1.0',
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return null;
        }

        return (string) $response;
    }

    /**
     * Follows HTTP redirects for a URL and returns the final effective URL.
     * Used to expand s.team short links into full steamcommunity.com URLs.
     */
    protected function followRedirect(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,   // HEAD — we only need the final URL
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Cav7/SteamChecker XenForo Addon/1.0',
        ]);

        curl_exec($ch);
        $errno    = curl_errno($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ($errno === 0 && $finalUrl) ? (string) $finalUrl : null;
    }

    // -------------------------------------------------------------------------
    // Message builders
    // -------------------------------------------------------------------------

    protected function buildBanReportMessage(string $steamId64, array $banData, ?string $personaName = null): string
    {
        $vacBans       = (int) ($banData['NumberOfVACBans'] ?? 0);
        $gameBans      = (int) ($banData['NumberOfGameBans'] ?? 0);
        $communityBan  = !empty($banData['CommunityBanned']);
        $economyBan    = $banData['EconomyBan'] ?? 'none';
        $daysSince     = (int) ($banData['DaysSinceLastBan'] ?? 0);

        $hasBans = $vacBans > 0 || $gameBans > 0 || $communityBan;

        // [PLAIN] is a stock XF 2 BBCode that suppresses smilie conversion and
        // URL auto-linking at render time. Safe to wrap untrusted text:
        // neutralizeBbCode() guarantees no ASCII [/PLAIN] can survive inside
        // the name to close the wrapper. Also: postReply() bypasses XF's
        // message Preparer (which normally auto-links bare URLs at input
        // time) — PLAIN removes that implicit dependency.
        $nameLine = ($personaName !== null && trim($personaName) !== '')
            ? '[PLAIN]' . $this->neutralizeBbCode(trim($personaName)) . '[/PLAIN]'
            : '(unknown)';

        $lines = [
            '[B]Steam VAC Check[/B]',
            'SteamID: ' . $this->buildSteamIdLink($steamId64),
            'Profile Name: ' . $nameLine,
            'VAC Bans: ' . $vacBans,
            'Game Bans: ' . $gameBans,
        ];

        if ($hasBans) {
            // Render the raw Steam "days since" figure as a calendar-accurate
            // duration (issue #37), keeping the raw count as a parenthetical.
            // "today" reads naturally on its own; a real span gets an "ago".
            $age  = $this->formatBanAge($daysSince);
            $line = ($daysSince <= 0) ? $age : $age . ' ago';
            $lines[] = 'Last Ban: ' . $line . ' (' . $daysSince . ' days)';
        }

        $lines[] = 'Community Banned: ' . ($communityBan ? 'Yes' : 'No');
        $lines[] = 'Economy Ban: ' . $economyBan;

        if ($hasBans) {
            $lines[] = '[COLOR=rgb(184, 49, 47)][B]⚠️ Ban(s) detected — review required.[/B][/COLOR]';
        } else {
            $lines[] = '[COLOR=rgb(39, 179, 11)][B]✅ No bans found.[/B][/COLOR]';
        }

        return implode("\n", $lines);
    }

    /**
     * Turns a raw Steam "days since last ban" count into a human-readable
     * calendar duration like "2 years, 3 months, 24 days" (issue #37).
     *
     * The breakdown is calendar-accurate, not arithmetic on fixed 365-day
     * years or 30-day months: it anchors to now (the XF facade clock) and
     * subtracts N days, then lets DateTime::diff resolve the span against the
     * real Gregorian calendar — so leap days fall where they actually land.
     *
     * Pluralization is per-unit ("1 day", "2 days"). Leading zero-valued units
     * are omitted ("3 months, 24 days", never "0 years, 3 months, 24 days"),
     * while interior zero units are kept so the remaining terms stay adjacent
     * in calendar order (e.g. "1 year, 5 days"). Zero (and any negative, which
     * Steam never sends) reads as "today".
     */
    protected function formatBanAge(int $days): string
    {
        if ($days <= 0) {
            return 'today';
        }

        // Midnight UTC keeps the day arithmetic free of DST/clock-time edges;
        // we only care about the calendar breakdown, not wall-clock precision.
        $now = (new \DateTimeImmutable('@' . \XF::$time))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->setTime(0, 0, 0);
        $then = $now->sub(new \DateInterval('P' . $days . 'D'));
        $diff = $now->diff($then);

        $units = [
            'year'  => $diff->y,
            'month' => $diff->m,
            'day'   => $diff->d,
        ];

        // Emit one term per non-zero unit, in calendar order. Zero-valued units
        // are skipped entirely — leading ("3 months, 24 days") and interior
        // ("1 year, 5 days") alike — since a zero adds nothing to the reading.
        $parts = [];
        foreach ($units as $label => $value) {
            if ($value === 0) {
                continue;
            }
            $parts[] = $value . ' ' . $label . ($value === 1 ? '' : 's');
        }

        // Guard: a positive span always has at least one non-zero unit, but if
        // the calendar ever collapsed everything to zero, fall back to days.
        if ($parts === []) {
            return $days . ($days === 1 ? ' day' : ' days');
        }

        return implode(', ', $parts);
    }

    /**
     * Renders a resolved SteamID64 as a clickable BBCode link to its Steam
     * community profile. Visible link text is the bare ID — staff still read
     * and copy it; only the markup around it changes. The URL is derived
     * directly from the ID, no extra Steam API call.
     */
    protected function buildSteamIdLink(string $steamId64): string
    {
        return '[URL="https://steamcommunity.com/profiles/' . $steamId64 . '"]'
            . $steamId64 . '[/URL]';
    }

    protected function buildUnresolvableMessage(string $rawValue): string
    {
        return implode("\n", [
            '[B]Steam VAC Check[/B]',
            '[COLOR=rgb(184, 49, 47)][B]⚠️ Could not determine a valid Steam ID from the application. Manual check required.[/B][/COLOR]',
            'Raw value: ' . $rawValue,
            $this->buildRerunInstructionLine(),
        ]);
    }

    protected function buildApiErrorMessage(string $steamId64): string
    {
        return implode("\n", [
            '[B]Steam VAC Check[/B]',
            'SteamID: ' . $this->buildSteamIdLink($steamId64),
            '[COLOR=rgb(184, 49, 47)][B]⚠️ Steam API error — could not complete the ban check. Manual check required.[/B][/COLOR]',
            $this->buildRerunInstructionLine(),
        ]);
    }

    /**
     * Two-line usage reply for a degenerate invocation (issue #25): a
     * hardcoded lead-in plus the re-run instruction taken verbatim from its
     * single source below — never fork a near-duplicate of that string.
     */
    protected function buildDegenerateInvocationMessage(): string
    {
        return 'No Steam ID was found in that [ICODE]!vac[/ICODE] command.'
            . "\n" . $this->buildRerunInstructionLine();
    }

    /**
     * One-line staff instruction appended to failure replies, telling staff
     * how to re-run the check via the !vac command. Deliberately contains no
     * valid-looking SteamID64 so a quoted copy can't resolve to a real account,
     * and no literal '<'/'>' around the placeholder so a staffer copying the
     * line verbatim never trips the angle-bracket handling (issue #17).
     */
    protected function buildRerunInstructionLine(): string
    {
        return '[I]Staff can re-run this check by replying in this thread with [ICODE]!vac your Steam64ID or profile URL[/ICODE].[/I]';
    }

    // -------------------------------------------------------------------------
    // Reply posting
    // -------------------------------------------------------------------------

    protected function postReply(string $message): void
    {
        $this->debug('postReply() called. botUserId=' . $this->botUserId);

        /** @var \XF\Entity\User|null $botUser */
        $botUser = \XF::em()->find('XF:User', $this->botUserId);
        if (!$botUser) {
            \XF::logError('[Cav7/SteamChecker] Bot user ID ' . $this->botUserId . ' not found.');
            return;
        }

        $this->debug('Bot user found: ' . $botUser->username . '. Saving post entity directly.');

        // XF 2.3 removed XF\Service\Post\Creator entirely. Create the Post
        // entity directly. XF's own Post._postSave() hooks handle updating
        // the thread's reply_count, last_post_id, and last_post_date.
        /** @var \XF\Entity\Post $post */
        $post = \XF::em()->create('XF:Post');
        $post->thread_id     = $this->thread->thread_id;
        $post->user_id       = $botUser->user_id;
        $post->username      = $botUser->username;
        $post->post_date     = \XF::$time;
        $post->message       = $message;
        $post->message_state = 'visible';
        $post->ip_id         = 0;
        $post->position      = $this->thread->reply_count + 1;

        $post->save();
        $this->debug('Post saved. post_id=' . $post->post_id);

        // XF's entity manager may hold a stale Thread with first_post_id = 0
        // (captured before the OP was persisted). When XF's post-save hooks
        // flush that cached entity back to the DB, the bot post ends up as
        // first_post_id, breaking the thread-list hover card. Fix it explicitly.
        $opPostId = (int) \XF::db()->fetchOne(
            'SELECT post_id FROM xf_post WHERE thread_id = ? AND position = 0 LIMIT 1',
            [$this->thread->thread_id]
        );
        if ($opPostId) {
            \XF::db()->query(
                'UPDATE xf_thread SET first_post_id = ? WHERE thread_id = ?',
                [$opPostId, $this->thread->thread_id]
            );
            $this->debug('first_post_id corrected to op post_id=' . $opPostId);
        }
    }
}
