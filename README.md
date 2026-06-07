# Steam VAC Checker

XenForo addon for the [7th Cavalry](https://7cav.us) that automatically checks
Steam VAC/game bans when enlistment applications are submitted.

When a new thread is created in the configured enlistment forum node, the addon
reads the application's platform and Steam ID fields from the first post,
resolves the Steam identifier, queries the Steam Web API for ban data, and
posts the result as a reply from a configured bot account.

## Features

- **Automatic checks** — new threads in the enlistment node trigger a VAC/game
  ban lookup on the applicant's Steam account (PC applications only)
- **Manual re-runs** — members of allowed user groups can post
  `!vac <steam_url_or_id>` in an enlistment thread to run a check on demand
- **Flexible ID resolution** — accepts a bare SteamID64,
  `steamcommunity.com/profiles/...` URLs, vanity `steamcommunity.com/id/...`
  URLs, and `s.team` short links (including friend-invite links)
- **Clear results** — the bot reply lists VAC bans, game bans, community ban
  and economy ban status, with a prominent pass/fail line

## Requirements

- XenForo 2.3.0+
- A [Steam Web API key](https://steamcommunity.com/dev/apikey)
- A XenForo user account for the bot to post as

## Installation

1. Copy `src/addons/Cav7/SteamChecker` into your XenForo installation at
   `src/addons/Cav7/SteamChecker`
2. Install the addon: **Admin CP → Add-ons → Steam VAC Checker → Install**
   (or `php cmd.php xf-addon:install Cav7/SteamChecker`)
3. Configure the options below

## Configuration

All options live under **Admin CP → Options → Steam VAC Checker**:

| Option | Description |
| --- | --- |
| Steam API Key | Your Steam Web API key |
| Bot User ID | XenForo user ID the results are posted as (0 disables posting) |
| Enlistment Node ID | Forum node whose new threads trigger the automatic check |
| Enable Debug Logging | Logs each step to the server error log — turn off in production |
| `!vac` Command — Allowed User Group IDs | Group IDs allowed to use `!vac` (comma- or newline-separated); blank disables the command |

## License

[MIT](LICENSE) © 2026 7Cav
