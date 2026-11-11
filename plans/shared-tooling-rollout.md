# shared-tooling rollout

The passages of shared-tooling's cross-project plan (`PLAN.md` at v2.6.0) that name this project, moved here word for word on 2026-11-10 when shared-tooling became project-agnostic. The portfolio-wide rest is in `PLAN.md` beside the project folders.

## Summary

The real gains are in making things consistent across projects. No backend changes language: TarotGen.io's move from Slim to Go was dropped on November 6 (section 3). This review covers all 20 folders in Web Projects.

## Decisions so far

The standardization work starts from these, so it doesn't reopen them. All were decided on November 5, 2026.

- TarotGen.io's backend moves from Slim to Go. (Reversed on November 6: it stays on PHP, see below.)

Decided 2026-11-06, after the Gallery's pilot:

- Stylelint moves to 18 now, in shared-tooling's `lint/web`, with its two configs (`stylelint-config-standard` 41, `stylelint-config-recommended` 19), not waiting on `stylelint-config-recommended-scss`, which covers only TarotGen.io's one `bulma.scss`. It ships in one major release, v2.0.0, with the site-snapshot function and the Gallery's and TarotGen.io's move onto it, so each project updates once. A major of a tool `lint/web` owns always moves here first; each project's Dependabot ignores those majors.
- TarotGen.io stays on PHP and Slim, reversing the port to Go: Go would make no page measurably faster, Slim 4 still ships security releases, and it's a PHP example worth keeping beside the Gallery's Laravel (section 3). If its plugin's share relay returns over WebSockets, that is a small Go service beside the PHP app.
- TarotGen.io's schema changes move from untracked one-off scripts to Phinx migrations its deploy runs, starting from a baseline of today's schema. It is the more public, heavily used site, where the Gallery stays on Laravel's own migrations.
- After the pilots, the rollout order is Senpan, Marxbot, TarotGen.io, then `standards/` and the rest. Senpan and Marxbot go first because they have no scheduled backup; TarotGen.io no longer waits for a port. The three get their CLAUDE.md pointer to the standards once `standards/` exists.

## Project by project

Every project stays on its current stack. The Gallery keeps Laravel and TarotGen.io keeps Slim, and both drop Bulma.

| Project | Stack now | Verdict |
| --- | --- | --- |
| TarotGen.io | Slim 4 + Vue / Bulma / Reka UI | Keep Slim, drop Bulma (see below) |
| Venue Tools, Kindred/Senpan/TarotGen plugins | C# / .NET | Dalamud requires it |

## Where the real gains are

### 3. Keep TarotGen.io on PHP

November 5's plan moved its backend from Slim to Go, because Slim's community is small and TarotGen.io is built like Kindred Nights. A closer look on November 6 reversed that. Being built like another project is no reason to rewrite it, and the benefits didn't hold up:

- **No speed to gain.** Page loads measured on the live site take 232–245 ms whether the page runs two queries or none, so the time is the trip through Cloudflare (its `AUDIT.md`). The data is small: 2,044 readings in 1.4 MB. The one heavy job, an admin making WebP card images at about 0.3 s each, runs in libwebp from PHP or Go alike.
- **No memory saved.** PHP-FPM stays as long as the Gallery and Grav need it.
- **Slim is maintained.** Slim 4 added PHP 8.5 in November 2025 and shipped security releases in May and August 2026 (4.15.2, 4.15.3); TarotGen.io is on 4.15.3. It uses only Slim's routing, middleware and PSR-7 objects (the data layer is plain PDO, the container PHP-DI), so even an abandoned Slim would mean replacing a router, not a rewrite.
- **It's the portfolio's PHP-without-a-framework example**, beside the Gallery's Laravel: hand-written repositories, PHP-DI, passkeys, PKCE sign-in for the plugin, CSP nonces, PHPStan and 95% line coverage. Go is already the backend in most of the other projects.
- **A port would cost** about 18,000 lines of PHP and 19,500 of tests across 116 routes, while players' installed plugins need the API unchanged, and every password hash, passkey and plugin token carried over.

The one place PHP is the wrong tool is the plugin's share relay, which sent a reading straight to another player's plugin. It was removed on 2026-08-19, to come back over WebSockets rather than polling. PHP-FPM can't hold a WebSocket, and an SSE stream ties up one of the 12 workers TarotGen.io shares with the Gallery and Grav. If the relay returns, it's a small Go service beside the PHP app:

- A systemd unit behind Apache, like Kindred's server, deployed with `Install-ServiceBinary` and checked by `go.yml`.
- The PHP app stays in charge of sign-in: it checks the plugin's token and hands the relay a short-lived signed ticket.
- It pings every 30–60 s, since Cloudflare drops a connection that's silent for about 100 s.

An async PHP server (Amp or ReactPHP) running as its own service could do it too, but that ecosystem is far smaller.

## Real-time: WebSockets, streams or REST

| Project | Today | Best fit | Why |
| --- | --- | --- | --- |
| TarotGen.io | REST | Keep; a returning plugin relay uses WebSockets from a small Go service | Draw, save and share are request and response; the plugin's share relay, removed in August, is to come back over WebSockets |

## Shared packages

The copies have already drifted:

- `@jwill89/eslint-config` is installed in 3 of the 9 Vue front ends (Gallery, TarotGen.io, Fantasy Dossier).

| Where | Purpose | Holds | Used by |
| --- | --- | --- | --- |
| shared-tooling `lint/php` | Lint PHP | phpstan base, php-cs-fixer ruleset, Rector set | Gallery, Fantasy Dossier, résumé site, TarotGen.io |

**C# and PowerShell both earn a folder.** The four plugins use three setups today: Kindred's `Directory.Build.props`, settings inside Senpan's and TarotGen.io's project files, and nothing in Venue Tools, though Senpan's file says it means to match Kindred. For PowerShell, a shared deploy module beats a shared lint file: the 14 deploy scripts total 4,321 lines, and helpers like `Resolve-PuttyTool`, `Write-Step` and `Fail` are copied into 11 of them.

**Shared CI fits mixed stacks.** shared-tooling's `.github/workflows` folder holds one reusable workflow per stack (`go.yml`, `web.yml`, `php.yml`, `dotnet.yml`, `pwsh.yml`), each taking inputs such as the folder to run in. A repo's own `ci.yml` shrinks to the list of the ones it needs, plus its project-only jobs (TarotGen.io's CodeQL and plugin workflows, for example):

```yaml
jobs:
  go:
    uses: jwill89/shared-tooling/.github/workflows/go.yml@v1.0.0
  web:
    uses: jwill89/shared-tooling/.github/workflows/web.yml@v1.0.0
    with:
      dir: web
```

## Testing standards

| Stack | Today | Gap | shared-tooling adds |
| --- | --- | --- | --- |
| Vue / TS | Vitest in 8 of 9 front ends, about 250 test files | A floor only in TarotGen.io; Fingerprint Generator has no tests; two DOM libraries (happy-dom, and jsdom in Senpan and TarotGen.io) | `test/web`: a Vitest preset on jsdom, with v8 coverage and a floor |
| PHP | PHPUnit in TarotGen.io, Pest in the Gallery; 154 test files | A floor only in TarotGen.io | `test/php`: a base `phpunit.xml` (Pest reads it too) and TarotGen.io's floor script, made general |
| End-to-end | Playwright in Home Library and TarotGen.io; Puppeteer in the résumé site | Two tools | `test/e2e`: a Playwright base config and conventions; move the résumé site to it |

**Settled: jsdom and Playwright.** Both lead their fields: about 152 million weekly npm downloads for jsdom against 33 million for happy-dom, and 108 million for `@playwright/test` against 15 million for Puppeteer and 8 million for Cypress. jsdom runs slower, but it behaves more like a real browser: TarotGen.io already runs two specs on jsdom because DOMPurify strips scripts there and not under happy-dom.

No test relies on a happy-dom-only API, so the switch is one config line per project plus a test run. The Gallery, Home Library, TarotGen.io and Kindred's markdown test move over; Senpan already uses jsdom. Tests that touch no DOM, like Fantasy Dossier's, stay on Vitest's faster `node` environment.

**The testing standards, in `standards/testing.md`,** set what every project tests and how:

- Test through real SQLite and real HTTP handlers rather than mocks. TarotGen.io's HTTP tests on an in-memory database are the model.

## Shared standards guide

The material exists but is scattered: about 8,600 lines across 24 files, including AGENTS.md in six repos, CLAUDE.md in three, CONTRIBUTING.md in four, plus plans, decision logs, ops notes and TarotGen.io's 2,037-line audit. Some rules already repeat word for word, such as Home Library's and Kindred's rule that an agent never commits without your permission.

## Project gaps

| Project | Missing | Note |
| --- | --- | --- |
| TarotGen.io | None since its 2026-11-06 rollout | Phinx migrations, run by the deploy, replaced the one-off scripts in `backend/db/` |

## Where to start

<!-- markdownlint-disable MD029 -->

3. **Gather the standards into `standards/` in this repo** (github.com/jwill89/shared-tooling, public). Claude Code sorts each rule as shared, project-only or conflicting and brings you the conflicts. It comes after the rollouts of Senpan, Marxbot and TarotGen.io (decided 2026-11-06), which take their CLAUDE.md pointer then.
   - Where a decision changes how an existing project works, the change is that project's own follow-up:
     - **TarotGen.io**: RFC 9457 errors, 422, camelCase JSON, no `'unsafe-inline'` in `style-src`, a password change revoking plugin tokens, and Font Awesome from its own kit package or the free packages.
4. **Seed shared-tooling from what you already have; no new rules yet.**
   - TarotGen.io's coverage floors become the model for `test/web` and `test/php`, and the deploy scripts' existing health checks become the shared smoke step.

<!-- -->

6. **Roll out one repo at a time**, on `main`, with no separate branches. Everything shared-tooling can do lands in one pass: swap in the shared configs, move its deploy script onto the shared module, turn on its scheduled backup and database pull, and add its basic end-to-end suite, together (decided 2026-11-05, after Kindred's pilot did them on two branches; backups added 2026-11-06). Point its CLAUDE.md at the standards, trim what moved there, and close that project's gaps (see Project gaps). Small fixes ride along: the SQLite switch in Senpan and Modem Monitor, jsdom, and Playwright for the résumé site. After the pilots, the order is Senpan, Marxbot, TarotGen.io, then the rest (decided 2026-11-06).
   - **TarotGen.io** (done and deployed 2026-11-06 as v5.5.0, on v1.3.0; on v2.0.0 since 2026-11-07):
     - The shared configs and CI for all four stacks, in place of the old `eslint-config` submodule; its own plugin workflow now only packages.
     - The deploy on SharedDeploy runs Phinx migrations after a compressed snapshot, with an HTTP smoke check and rollback, the daily backup (28 days) and the pull.
     - The old root cron backup is retired. Retention runs alone at 10:30 UTC, only when that day's backup exists.
     - Phinx owns the schema. A baseline compares a live database with the expected schema, and refuses, changing nothing, if they differ.
     - The end-to-end suite grew from 2 tests to 18, with axe on the main pages.
     - Axe and the owner's review fixed the gold buttons' text, the muted grey, and face-down cards that a screen reader named before they were turned.
     - The privacy page now says 28 days of backups.
   - **The compression rule's follow-ups** (decided 2026-11-06; done 2026-11-07, in v2.0.0):
     - All five rolled-out projects took v2.0.0 and deployed, which compressed their backups and snapshots.
     - Kindred's own `kindred backup` writes its daily copy compressed.
     - The Gallery's and TarotGen.io's deploy snapshots are the module's `Backup-RemoteDatabase`, and TarotGen.io's pull runs as www-data (`Copy-LiveDatabase -Owner`).
     - v2.0.0 also moved every project to Stylelint 18; TarotGen.io dropped `stylelint-config-recommended-scss` for `postcss-scss`.
   - **Font Awesome bandwidth** (2026-11-07). The account went over its November bandwidth: CI installed from the Pro registry 50 times in the first week (13 Kindred, 21 Senpan, 15 TarotGen.io, 1 Dossier), since every changed lockfile missed setup-node's exact-key npm cache, and a job that failed after installing saved nothing. Senpan's kit held all 17 styles, a 201 MB download each time, about 4.2 GB of the 4.6 GB.
     - v2.1.5's shared workflows restore npm's download cache from the newest earlier run and save both caches straight after the install, so a package is fetched again only when its own version changes. Home Library's own CI took the same scheme.
     - The owner cut every kit to the styles its sites import (Senpan's to four, 42 MB), and the guide now says a kit holds only those.
     - The Gallery stopped loading its kit from `kit.fontawesome.com` on every page view: it installs the shared kit and renders inline SVG through `<font-awesome-icon>`, with a test that fails on an unregistered icon. Home Library moved from the per-style packages to the same kit.
     - Kits are not cut to chosen icons (Font Awesome's custom subsetting), decided 2026-11-07: it would make each package a few hundred KB, but every new icon would first need adding to the kit and a new package version. A few MB per style, fetched only when a kit's version changes, is the price of adding an icon freely.
   - **Release tags from CI** (2026-11-07). The standard has CI tag each release, but Kindred, Marxbot and Home Library still tagged by hand or not at all; Kindred's standards pass had recorded it as a departure instead of adopting it, and its deploy then refused an untagged HEAD. All three now have the Dossier's `release` job and `release.sh`, reading `web/package.json` (Kindred 0.30.1, Home Library 1.1.0) or a `VERSION` file (Marxbot 1.0.0, which the bot embeds and logs), and each deploy fetches the tags and refuses a HEAD whose tag isn't that version. Kindred's hand tags had gone backwards (v0.29.5 and v0.29.6 after v0.30.0). The Gallery's, TarotGen.io's and the Dossier's deploys then took the same check (their CI already tagged releases; the Gallery hadn't recorded the gap as a departure), with `-Preview` for an untagged try. Senpan's waits for its move to one app version, since CI still tags its parts separately.

<!-- markdownlint-enable MD029 -->
