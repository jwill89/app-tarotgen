# TarotGen.io — FFXIV Dalamud Plugin

A [Dalamud](https://github.com/goatcorp/Dalamud) plugin for **Final Fantasy XIV** that talks to the
[TarotGen.io](https://tarotgen.io) REST API: draw a tarot reading in-game (pick a public spread or a
free draw), view any reading by its share code, optionally link a TarotGen account to lock and track
readings, and report a problem with a card's image.

It is a **separate, self-contained C# subproject** in the TarotGen repo, built and packaged
independently by [DalamudPackager](https://github.com/goatcorp/DalamudPackager). The site's
`scripts/deploy.ps1` frontend/backend targets do **not** touch it; it has its own version and release
channel (`-Target plugin`).

## What it does (and deliberately does not)

**In scope:**

- **Generate a reading** — pick a public spread *or* a free draw (random card count), with reversals. No account needed: an unlinked install gets a **draw key** once (see "How it authenticates"). The spread pickers group the public spreads under the site's library categories, in the order the site lists them.
- **View a reading** by its share code / URL, including password-protected readings (guest enters the password). Each position lists its card, with what the position asks beneath it when the spread says (the New Reading preview shows the same).
- **Optional account** — link a TarotGen account to lock/finalize a reading, sort deck/spread pickers by your favorites, and track generated readings ("My Readings"). Guests never have to link.
- **Report a problem with a card's image** from the card lightbox — pick what's wrong (scan artefact, cropping, image quality, wrong card, or something else) and optionally describe it, so the site admins know what to fix.

**Out of scope** (lives on the website): admin, account/profile management, changelog viewing, PNG
export, echoing readings to game chat, the drag/rotate placement editor, custom spreads, drawing
additional cards into a reading, custom readings from hand-picked cards, and deck/spread submission.

## Build target

| | |
|---|---|
| Dalamud | **API 15** (Dalamud 15.x, FFXIV Patch 7.5) |
| Runtime | **.NET 10** — TFM `net10.0-windows`, `<Project Sdk="Dalamud.NET.Sdk/15.0.0">` |
| Language | C# |
| Platform | Windows (Dalamud is Windows-first; Wine/Linux via XIVLauncher is handled with fallbacks) |
| License | Open source (required for the official Dalamud repo; the site backend is otherwise proprietary) |

## Building

Requires the Dalamud dev SDK on disk (XIVLauncher installs it to `%AppData%\XIVLauncher\addon\Hooks\dev`)
plus the .NET 10 SDK, and the repo's `shared/` submodule (`git submodule update --init`):
`plugin/Directory.Build.props` imports the .NET analyzers and C# code style every build checks from
shared-tooling's `shared/lint/dotnet`. Any warning fails the build (the NuGet vulnerability advisories
excepted). Then:

```powershell
dotnet build plugin\TarotGen.Plugin\TarotGen.Plugin.csproj -c Debug     # compile
dotnet build plugin\TarotGen.Plugin\TarotGen.Plugin.csproj -c Release   # + DalamudPackager -> bin\Release\TarotGen.Plugin\latest.zip
```

`dotnet format plugin\TarotGen.Plugin\TarotGen.Plugin.csproj --verify-no-changes` checks the
formatting, as CI's plugin job does after its Release build.

Or open `TarotGen.Plugin.sln` in Visual Studio 2026 / Rider 2025.3. To try it in-game: `/xldev` →
Dev Plugin Locations → point at the built DLL, then `/tarot`. The plugin **version** comes from
`<Version>` in the `.csproj` — bump it to make Dalamud offer an update to installed testers.

## Installing (testers)

The plugin is published to a self-hosted **custom Dalamud repo**. In-game:

1. `/xlsettings` → **Experimental** → **Custom Plugin Repositories**.
2. Add `https://tarotgen.io/plugin/repo.json`, then **Save**.
3. `/xlplugins` → find **TarotGen** → **Install**. Run `/tarot`.

## Publishing

From the repo root (needs the deploy settings in `scripts/deploy.config.ps1`; copy
`scripts/deploy.config.example.ps1`):

```powershell
.\scripts\deploy.ps1 -Target plugin
```

This refuses a `<Version>` that isn't higher than the published one, builds Release from clean, and
uploads `latest.zip`, `icon.png`, and a generated `repo.json` (the pluginmaster, its download links
carrying `?v=<version>`), which replace the folder behind `https://tarotgen.io/plugin/` in one swap,
the previous kept as `plugin.old`. The deploy then checks the published `repo.json` names the new
version, and puts the previous one back if it doesn't.

## How it authenticates

The plugin is a native `HttpClient`, so it uses a **Bearer token** rather than the site's session
cookie. Both of its credentials come from the same browser round trip (OAuth-style **loopback +
PKCE**, no copy-paste: the site page hands a one-time code back to a local loopback listener, and the
plugin swaps it, with its PKCE verifier, for the credential).

- **Account token** (`tg_pat_…`) — linking an account opens the site's `/authorize` page. The plugin
  then uses the token for the account routes it needs (locking a reading, favorites, My Readings) and
  draws as that account. It can be revoked at any time from the site's *Connected Apps* screen.
- **Draw key** (`tg_dk_…`, since 0.8.0) — the site asks a signed-out draw to pass a Cloudflare
  Turnstile challenge, which a game client can't show. So when the plugin isn't linked and has no
  key, New Reading offers **Get a draw key**: it opens the site's `/draw-key` page, the player passes
  the challenge once, and the key comes back the same way (`POST /api/plugin/draw-keys/token`). The
  plugin sends it as `Authorization: Bearer tg_dk_…` on draws only; it opens nothing else (no account
  routes), names no account (its readings are a guest's), and is rate-limited per key. If the site
  ever turns it down (a 401: revoked), the plugin forgets it and offers to get a new one. A linked
  account doesn't need one, and keeps it in case it is unlinked later.

Viewing readings needs neither. Both credentials are **DPAPI-encrypted** at rest, separately
(plaintext fallback under Wine).

Plugins before 0.8.0 can't draw without a linked account once the site's Turnstile guard is live:
they have no way to get a draw key. An install upgraded from one of them, unlinked and without a key,
gets a one-time pop-up the next time its window opens, "Drawing now needs a draw key", with the steps
and buttons to get a key or link an account (`Configuration.DrawKeyNoticeSeen`). A new install learns
it in onboarding instead.

## Backend it talks to

PHP **8.5 / Slim 4** on one DigitalOcean droplet — **Apache (event MPM) + PHP-FPM**, SQLite (WAL),
behind **Cloudflare**. The endpoints it uses are the `Plugin`- and `Cards`-tagged routes in the API
(`/plugin/*`, `/account/tokens`, `/card-reports`); browse the live reference at
[`/api/docs`](https://tarotgen.io/api/docs).
