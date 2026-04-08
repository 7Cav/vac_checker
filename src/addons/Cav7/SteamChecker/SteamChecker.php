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
        $platform = $this->extractNextLineField($message, 'Platform and Game Selection');
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

        // --- Post result ----------------------------------------------------
        $this->debug('Calling postReply.');
        $this->postReply($this->buildBanReportMessage($steamId64, $banData));
    }

    // -------------------------------------------------------------------------
    // Post-body parsing
    // -------------------------------------------------------------------------

    /**
     * Finds a BBCode label line and returns the text on the first non-empty
     * line that follows it. Returns null if the label is not found.
     *
     * Handles both inline-value fields ("Label:[/B] Value on same line") and
     * next-line fields ("[B]Label[/B]\nValue on next line"). For the labels we
     * care about (Platform, SteamID) the value is always on the next line, so
     * we strip BBCode from each line and look for a line whose plain text
     * contains the label string, then return the next non-empty line.
     */
    protected function extractNextLineField(string $message, string $fieldLabel): ?string
    {
        $lines = explode("\n", $message);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $plain = $this->stripBbCode($lines[$i]);
            if (stripos($plain, $fieldLabel) !== false) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $value = trim($lines[$j]);
                    if ($value !== '') {
                        return $this->stripBbCode($value);
                    }
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
     * if the vanity name is not found.
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

        return $data['response']['steamid'] ?? null;
    }

    /**
     * Resolves an s.team short link to a SteamID64 by following redirects 
     * or fetching the page body if it's a friend invite link.
     */
    protected function resolveSteamShortLink(string $url): ?string
    {
        // Ensure the URL has a scheme for cURL
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        // 1. Follow the HTTP redirect to get the final destination
        $finalUrl = $this->followRedirect($url);
        if (!$finalUrl) {
            $finalUrl = $url; // Fallback just in case
        }
        
        // 2. If the redirect took us directly to a standard profile page, resolve it
        if (preg_match('#steamcommunity\.com/(id|profiles)/#i', $finalUrl)) {
            return $this->resolveSteamId($finalUrl);
        }

        // 3. For friend invites (/user/...), fetch the destination's HTML body
        // (httpGet has follow location disabled, so we MUST pass the finalUrl here)
        $body = $this->httpGet($finalUrl);
        if ($body) {
            // The most foolproof way to find the ID on a Steam User page is 
            // extracting the 17-digit SteamID64 directly from the source code.
            // All modern Steam accounts start with 7656119.
            if (preg_match('/(7656119\d{10})/', $body, $m)) {
                return $m[1]; 
            }
            
            // Fallback: look for a vanity URL in the JSON data or meta tags
            if (preg_match('#steamcommunity\.com\\\\?/(id|profiles)\\\\?/([^"\'\\/?\s]+)#i', $body, $m)) {
                return $this->resolveVanityUrl(stripslashes($m[2]));
            }
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

    protected function buildBanReportMessage(string $steamId64, array $banData): string
    {
        $vacBans       = (int) ($banData['NumberOfVACBans'] ?? 0);
        $gameBans      = (int) ($banData['NumberOfGameBans'] ?? 0);
        $communityBan  = !empty($banData['CommunityBanned']);
        $economyBan    = $banData['EconomyBan'] ?? 'none';
        $daysSince     = (int) ($banData['DaysSinceLastBan'] ?? 0);

        $hasBans = $vacBans > 0 || $gameBans > 0 || $communityBan;

        $lines = [
            '[B]Steam VAC Check[/B]',
            'SteamID: ' . $steamId64,
            'VAC Bans: ' . $vacBans,
            'Game Bans: ' . $gameBans,
        ];

        if ($hasBans) {
            $lines[] = 'Days Since Last Ban: ' . $daysSince;
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

    protected function buildUnresolvableMessage(string $rawValue): string
    {
        return implode("\n", [
            '[B]Steam VAC Check[/B]',
            '[COLOR=rgb(184, 49, 47)][B]⚠️ Could not determine a valid Steam ID from the application. Manual check required.[/B][/COLOR]',
            'Raw value: ' . $rawValue,
        ]);
    }

    protected function buildApiErrorMessage(string $steamId64): string
    {
        return implode("\n", [
            '[B]Steam VAC Check[/B]',
            'SteamID: ' . $steamId64,
            '[COLOR=rgb(184, 49, 47)][B]⚠️ Steam API error — could not complete the ban check. Manual check required.[/B][/COLOR]',
        ]);
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
        // entity directly — the entity's own _postSave() handles updating
        // the thread's reply count and last-post metadata.
        /** @var \XF\Entity\Post $post */
        $post = \XF::em()->create('XF:Post');
        $post->thread_id  = $this->thread->thread_id;
        $post->user_id    = $botUser->user_id;
        $post->username   = $botUser->username;
        $post->post_date  = \XF::$time;
        $post->message    = $message;
        $post->message_state = 'visible';
        $post->ip_id      = 0;
        $post->position   = $this->thread->reply_count + 1;

        $post->save();

        $this->debug('Post saved. post_id=' . $post->post_id);
    }
}
