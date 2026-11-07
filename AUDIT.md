# TarotGen.io — Codebase Audit (2026-10-27)

> This is a historical record. The standards in [`shared/standards/`](shared/standards/README.md) supersede its rules where they differ.

An antagonistic pass over the whole repo (frontend, backend, plugin, DB, tooling,
tests, product gaps). Written as a work list for an agent or a person to burn
down. Every finding was verified against the tree on the date above unless
marked **unverified**; a few earlier suspicions that did *not* survive
verification are listed at the end so nobody re-chases them.

## How to use this file

- Work top to bottom within a section; sections are ordered by risk. §10 has a
  suggested sequencing across sections.
- Tags: **[critical] [high] [medium] [low]** = impact. **[S] [M] [L]** = effort
  (hours / a day / multiple days).
- Tick the box when the **Verify** step passes, not when the edit lands.
- Conventions, commands, and layering rules live in `AGENTS.md`; read it first.
  Three rules that bite here:
  1. *(Since 2026-11-06.)* `backend/db/schema.sql` is **generated** from the
     migrations: `php scripts/dump_schema.php` builds it on a throwaway database
     (never the dev DB, so drift can't leak in) and `SchemaTest` fails when it is
     stale. Never edit it by hand.
  2. *(Since 2026-11-06.)* Schema changes are **Phinx migrations** in
     `backend/db/migrations/`, committed; the backend deploy runs them on the
     droplet as www-data, after snapshotting the database, with
     `php vendor/bin/phinx migrate` (AGENTS.md §6, §7). The one-off
     `backend/db/migrate_*.php` scripts the items below mention are retired:
     the baseline migration is their end state.
  3. After route/schema changes: `composer docs` → commit `backend/openapi.json`
     → `npm run gen:types`. `OpenApiSpecTest` and CI both fail on drift.
- Product constraint: **this site does not interpret readings.** No card
  meanings, no AI readings, no "what does this spread say". It is a tool for
  people who do readings. Do not propose or build that.

## Status (2026-10-30)

**65 items done, 6 open — 4 of the open ones are §9 product features.**

| § | Area | Done | Open | What's left |
|---|---|---|---|---|
| 0 | State of the tree | 1 | 2 | Owner's git housekeeping: dead worktrees/branches, old branches, a stash |
| 1 | Bugs and risks | 9 | 0 | — (§1.7 retention confirmed on its first live runs) |
| 2 | Database | 7 | 0 | — (all migrations run on prod 2026-10-28) |
| 3 | Performance | 5 | 0 | — |
| 4 | Duplication | 7 | 0 | — |
| 5 | Standards | 9 | 0 | — (`eslint-plugin-vuejs-accessibility` is tracked in §6.1) |
| 6 | Tooling | 5 | 0 | Owner: Dependabot secrets (§6.4). Found and open: keyboard favouriting (§6.1) |
| 7 | Dependencies | 4 | 0 | — Held: TypeScript 7 (until vue-tsc supports 7.1), PHPStan 2.3.3 (until Rector catches up). Owner's call: `scrollBehavior` → experimental `ScrollRestoration` (§7.3) |
| 8 | Tests | 7 | 0 | — (and the owner's decisions the tests raised are implemented: "Open from §8") |
| 9 | Product | 11 | 4 | §9.6–9.7 and the two large ones; 5.1.0–5.4.0 shipped the rest |

**Deployed** (2026-10-31 00:01–00:03 UTC): 5.4.0, in its CHANGELOG order:
`migrate_plugin_draw_keys.php` (backup first), the site, FFXIV plugin 0.8.0
(published to `/plugin/repo.json`; it supersedes the unreleased 0.7.0), then
`migrate_drop_changelog.php` (backup first; 7 rows dropped). Prod's schema
matches `schema.sql`.

## Decisions and production facts (2026-10-28)

**Working agreement (owner's decisions):**

- **Agents edit and verify; the owner commits.** No commits, branches, pushes,
  or pruning by an agent. §0's git housekeeping is the owner's.
- **Droplet access** goes through the deploy SSH setup (`scripts/deploy.config.ps1`
  → plink via Pageant; since 2026-11-06 the deploy holds one shared connection and
  no longer uses pscp). Read-only checks run freely; **every prod-changing
  command (migration, cron, upload) is proposed and approved first.** Batch
  commands: the droplet runs `ufw limit ssh` (6 connections / 30 s).
- **Backups (§1.1): local rotation on the droplet, 14 copies.** No off-site copy.
  *(Since 2026-11-06: daily at 10:00 UTC, 28 days kept, by shared-tooling's
  backup timer, which the backend deploy installs; the deploy's pull of the live
  database is the one copy off the droplet. AGENTS.md §6 "Backups".)*
- **Guest-reading retention (§1.7): 1 year.**
- **Exposure notification (§1.0): none** — too few users to warrant one.
- **CSP (§1.5): keep Cloudflare Web Analytics and Bot Fight Mode**; allow them
  with a per-response nonce.

**Read-only reconnaissance of production** (2026-10-28; these supersede the
audit's assumptions where they differ):

| Fact | Value | Affects |
|---|---|---|
| PHP / SAPI | 8.5.12, mod_php; `date.timezone = UTC`; OS zone `Etc/UTC`. **Since 2026-11-03: PHP-FPM** (one shared `www` pool for every PHP site on the droplet, 12 workers, as `www-data`; unix socket via `proxy_fcgi`) and Apache on the **event** MPM with HTTP/2, which mod_php's prefork ruled out | §1.4 is latent in prod |
| Web `php.ini` | `error_reporting = E_ALL & ~E_DEPRECATED`, `display_errors = Off`, `log_errors = On` → `/var/log/apache2/error.log` (daily, 14 rotations). Under FPM the same file (identical to the old one); PHP errors reach `error.log` as `AH01071: Got error 'PHP message: …'` and `/var/log/php8.5-fpm.log` | §1.2, §1.3 |
| SQLite | 3.45.1 (PDO); WAL; DB **1.43 MB**; `sqlite3`, `gzip`, `rclone` installed; 21 GB free | §1.1, §2.3 |
| Cron | none for `root` or `www-data`; `/etc/cron.d`: certbot, e2scrub_all, php, sysstat | §1.1, §1.7 |
| `decks` columns | **no legacy columns** (`is_thoth`/`has_extras`/`non_standard`/`total_cards` already dropped) | §1.2 |
| Schema drift vs `schema.sql` | identical except (a) `decks` — **`schema.sql` is stale**, still lists the legacy columns; (b) `card_report_submissions` + `card_reports.round` — the pending migration | §1.2, §0 |
| Pending migration | `card_report_submissions` **absent** on prod | §0 |
| Readings | 2,044 total, 1,925 guest; **210 guest readings older than 1 year** (oldest 2025-03-16) | §1.7, §2.3 |
| Relay tables | `plugin_clients` 7 rows, `plugin_client_identities` 7, `plugin_messages` 0, `plugin_blocks` 0 | §2.1 |
| Error log, 14 days | 0 dynamic-property lines (deprecations aren't logged); 0 `session_set_cookie_params` lines | §1.2, §1.3 |
| Card image | `Card_0001.png` = **5.1 MB**, no `Cache-Control`, `cf-cache-status: MISS`; its WebP thumb is 62 KB | §3.1, §3.2 |
| Ad-hoc DB copies on server | four `tarotdb.pre-*.db` from July migrations + `db/old/` | §1.1 |

*That table is the morning-of snapshot that shaped the plan. Since changed by the
work below: a daily backup runs (§1.1; since 2026-11-06 a systemd timer, with cron
running only retention after it, §1.7); the
`.env` and DB moved out of the web root (§1.0); `schema.sql` matches prod and
the pending migration ran (§0, §1.2); the relay tables are gone (§2.1); card
images are cached for 30 days at the edge with a full-size WebP beside each PNG
(§3.1, §3.2).*

## 0. State of the tree (read before doing anything)

- [x] **Uncommitted feature work is sitting in the tree.** Card-report reasons
      (22 modified + 7 new files: `CardReportReason.php`, `CardReportSubmission.php`,
      `CardReportDialog.vue`, `useCardReports.ts`, `utils/cardReports.ts`, two specs).
      All gates were green when it was written. Commit it (or stash it) before
      starting audit work so diffs stay reviewable. It needs
      `php db/migrate_card_report_submissions.php` run on the droplet **before**
      the next backend deploy.
  - **Done 2026-10-28:** committed by the owner; the migration ran on the
    droplet (prod, dev and `schema.sql` matched object-for-object afterwards).
- [ ] **Three dead worktrees + branches.** `.claude/worktrees/{dazzling-lovelace-4f59b9,
      pensive-tereshkova-d135ec,priceless-jackson-d01aeb}` and branches
      `claude/dazzling-lovelace-4f59b9`, `claude/pensive-tereshkova-d135ec`,
      `claude/priceless-jackson-d01aeb` have **zero commits** ahead of `main`.
      They were spun off for the session-cookie warning, the stale relay docs,
      and the dark `color-scheme`; none of that work landed, so all three items
      are still open below (§1.3, §5.4, §5.8). `git worktree remove` + `git branch -D`.
  - **Still present (2026-10-28, end of day)**, but nothing of value is in them
    any more: §1.3 and §5.8 were done on `main`, and §5.4 mostly. Pure clutter —
    the owner's to remove.
- [ ] Review the other local branches (`api-rewrite`, `dalamud-plugin`) with
      `git branch --merged main` and the stash `stash@{0}` ("pre-reskin tweaks");
      drop what is dead.

### Known-good — leave alone

So the next pass doesn't churn things that are right: CSPRNG Fisher–Yates draw
and cut-and-turn reversals (`ReadingService`); hashed session ids and
write-throttled session rows (`DbSessionHandler`); hashed user/plugin tokens
with PKCE (`PluginTokenService`); Cloudflare-range-gated `ClientIp`;
`OriginGuard`; `password_hash` never hydrated onto `Reading`/`User`; lazy route
chunks and the three vendor groups in `vite.config.ts`; the spec-drift and
generated-types CI checks; forgot-password non-enumeration; PHPStan 8 with a
five-entry baseline; strict TS + typed ESLint. *(Correction 2026-10-28: strict
TS was configured but never actually checked — `npm run type-check` checked no
files, and a broken `vue` augmentation blinded typed ESLint too. Both fixed; see
§4.4. They are known-good from that fix on.)*

---

## 1. Bugs and risks

- [x] **1.0 The production database and `.env` were publicly downloadable.** [critical] [S + M]
  - **Found 2026-10-28** while placing the §1.1 backup script. The deploy flattens
    `backend/` into one web root and `.htaccess` serves every existing file, so
    `/.env`, `/db/tarotdb.db` (+ the `db/tarotdb.pre-*.db` copies),
    `/.deploy/backend-prev.tgz`, `/composer.*`, `/openapi.json` were served
    verbatim, and any `.php` under `db/`, `scripts/` (incl. `make_admin.php`),
    `includes/`, `vendor/`, `api/` executed by URL.
  - **Evidence:** the Apache access log (14 days, Oct 14-28) shows `GET /.env`
    answered with the real file (3,177 bytes logged = 2,345-byte body + headers)
    **11 times between Oct 17 and Oct 26**, by scanners. No download of the DB
    appears in those 14 days; earlier history is gone.
  - **Hotfix — DONE 2026-10-28 05:06 UTC:** deny rules at the top of
    `backend/.htaccess` and an index.php-only rule in `backend/api/.htaccess`;
    installed on the droplet with automatic rollback; 23 server-side checks plus
    external bypass attempts (encoded, doubled-slash, dot-segment paths) all 404.
    Previous files kept as `.htaccess.bak-20261028-050600` (Apache denies `.ht*`).
  - **Owner (in progress):** rotate `GOOGLE_CLIENT_SECRET` and
    `CLOUDFLARE_TURNSTILE_SECRET`; decide on notifying the 7 users and
    contact-form senders (DB held emails, Argon2id hashes, contact messages,
    reading contents; sessions/tokens are stored hashed and are not replayable).
    `PLUGIN_IDENTITY_SALT` is dead (§2.1) — delete, don't rotate.
    *2026-10-28:* both secrets rotated and the salt line deleted by the owner.
    **Decided (owner): no user notification** — the user base is too small to
    warrant one.
  - **Durable fix — DONE 2026-10-28 05:21 UTC** (commit `2a44c27` + droplet
    change): `Tarot\Config\Paths` resolves `.env` and the DB from
    `TAROT_DATA_DIR` (fallback `backend/` for dev); `Connection` refuses to
    create a missing DB. On the droplet: `/var/lib/tarotgen/{.env, db/tarotdb.db}`
    (root:www-data 640 / www-data 640, dirs 750); `SetEnv TAROT_DATA_DIR` in both
    tarotgen.io vhosts; `TAROT_DATA_DIR` in `/etc/environment`. Old files archived
    (root-only) in `/var/backups/tarotgen/web-root-data-20261028-052131/`; the web
    root's `db/` now holds only migration scripts. Verified: real reading served
    from the new copy after the old one was gone; Apache session writes land in
    it; a fresh SSH session's PHP CLI resolves and opens it.
  - **Follow-ups:** rotated secrets go in **`/var/lib/tarotgen/.env`** (read per
    request — no reload needed). Delete the archive
    `web-root-data-20261028-052027/` (a rolled-back first attempt) once §1.1
    backups exist. Long term, give the vhost a `public/` document root so code,
    vendor and scripts aren't in it either.

- [x] **1.1 No database backups exist anywhere.** [critical] [S]
  - **Where:** one SQLite file, one droplet. `scripts/deploy.ps1` deliberately
    never touches `db/`; nothing else does either. No cron, no script, no doc
    (confirmed: no crontab for `root`/`www-data`). The only copies are four
    ad-hoc `tarotdb.pre-*.db` files from July migrations.
  - **Fix (decided: local rotation, 14 copies):** a script (shell or
    `backend/scripts/backup_db.php`) that runs
    `sqlite3 db/tarotdb.db ".backup '<dir>/tarotdb-$(date +%F).db'"`
    (online-safe under WAL), `PRAGMA integrity_check` on the copy, gzips, and
    keeps the newest 14. Put the backup directory **outside the web root** (e.g.
    `/var/backups/tarotgen/`) so a backup can never be served over HTTP. Nightly
    entry in `/etc/cron.d/tarotgen`. Document in `AGENTS.md` §Deployment. Once
    it runs, the ad-hoc `pre-*.db` copies can go. Note this protects against bad
    migrations and corruption, **not** droplet loss — revisit off-site later.
  - **Verify:** restore a backup into a temp file, `PRAGMA integrity_check` = ok,
    `SELECT COUNT(*) FROM readings` matches prod within the day. Do the restore
    drill once, for real.
  - **DONE 2026-10-28 05:27 UTC.** `scripts/server/tarotgen-backup.sh` →
    `/usr/local/sbin/tarotgen-backup`; `scripts/server/tarotgen.cron` →
    `/etc/cron.d/tarotgen` (03:17 UTC nightly). Each run: `.backup` as www-data,
    switched to a self-contained rollback-journal file, `integrity_check`,
    gzipped root-only (600) into `/var/backups/tarotgen/nightly/`, newest 14 kept
    (~199 KB each); logs to `journalctl -t tarotgen-backup`. First run + restore
    drill passed: gunzipped copy integrity ok and **all 24 tables matched the live
    DB row-for-row** (2,044 readings). Still open: off-site copy (droplet loss),
    and alerting if a nightly run fails (today a failure is only visible in the
    journal).

- [x] **1.2 Dev deck loads emit dynamic-property deprecations; `schema.sql` is stale.** [low] [S]
  - **Correction (prod recon):** prod's `decks` table **does not** have the
    legacy columns, so prod is unaffected. The problem is local: the dev DB and
    the committed `schema.sql` (lines 54-58) still carry `is_thoth`,
    `has_extras`, `non_standard`, `total_cards`, which means anyone rebuilding a
    DB from `schema.sql` gets the bug.
  - **Where:** `backend/includes/Tarot/Data/DeckData.php:10` selects `d.*` into
    `Tarot\Structure\Deck` via `PDO::FETCH_CLASS` (`AbstractData.php:75`); on
    the dev DB `DeckData::retrieve()` prints 108 notices (27 decks × 4 columns).
    Fatal in PHP 9.
  - **Fix:** (a) explicit column list in `DeckData` (the real guard — §2.4);
    (b) run the gitignored `db/drop_legacy_deck_columns.php` on the **dev** DB
    only; (c) replace the `decks` block in `schema.sql` with prod's exact
    `CREATE TABLE "decks"` (no legacy columns).
  - **Verify:** the appendix one-liner prints only `hydrated N decks` on dev; a
    DB built from `schema.sql` has the same `decks` columns as prod.
  - **DONE 2026-10-28:** (a) `DeckData::SELECT_WITH_SYSTEM` lists its columns;
    new `DeckDataTest::testColumnsDeckDoesNotDeclareAreNotHydrated` adds an
    undeclared `is_thoth` column — it fails on the old `d.*` query (deprecation and
    assertion) and passes now. (b) `drop_legacy_deck_columns.php` run on the
    dev DB (backup `db/tarotdb.backup-20261028-011103-pre-1.2.db`): one-liner
    went from the four `Deck::$…` deprecations per deck to a clean
    `hydrated 27 decks`. (c) The four legacy lines removed from the `decks`
    block of `schema.sql`. Prod's `CREATE TABLE "decks"` (read-only query) is
    byte-identical to both the migrated dev DB's and a fresh DB built from
    `schema.sql`. The other `SELECT *` sites remain — §2.4.

- [x] **1.3 PHP warning on every login/logout.** [medium] [S]
  - **Where:** `backend/includes/Tarot/Utility/Session.php:80` —
    `Session::regenerate()` calls `session_set_cookie_params()` while the session
    started by `Session::start()` (line ~58) is active. PHP: *"Session cookie
    parameters cannot be changed when a session is active"*. In dev the warning
    HTML is prepended to the login JSON body. Prod has `display_errors = Off`, so
    responses are clean there; it would be one log line per auth, but the prod
    log shows none in 14 days (7 users, long-lived remember-me sessions — logins
    are rare). `api/index.php:35` already sets the params at bootstrap.
  - **Fix:** in `regenerate()`, drop the `session_set_cookie_params()` call and
    always call `self::sendCookie($lifetime)` after `session_regenerate_id(true)`
    (it already does so for the persistent case), so the cookie attributes are
    set by `setcookie()` which is legal mid-session.
  - **Verify:** `curl -i -X POST localhost/api/auth/login …` returns a body
    starting with `{`; PHP log clean; remember-me cookie still carries the 30-day
    `Expires`.
  - **Correction to the fix:** "always call `sendCookie($lifetime)`" would log
    every non-remembered login straight back out — `$lifetime` is 0 there, and
    `sendCookie()` sends `expires = time() + 0`, an already-expired cookie. The
    failing `session_set_cookie_params()` call was a no-op all along (PHP refuses
    it and keeps the bootstrap params), so the right fix is just to delete it.
  - **DONE 2026-10-28:** `Session::regenerate()` now only calls
    `session_regenerate_id(true)` (+ `sendCookie(30 days)` when persistent), with
    the reasoning in its docblock. Verified on the dev server with a throwaway
    account (DB restored from a pre-test `VACUUM INTO` copy afterwards): login
    with and without remember-me and logout return bodies starting with `{`;
    plain login → session cookie, remember-me → `Max-Age=2592000`, `/me` slides
    it, logout from a remembered session sends exactly one new-ID session cookie
    (PHP drops the old-ID sliding header queued by `start()`), `/me` afterwards
    401; no PHP warning after the change. Same `Set-Cookie` behaviour as before,
    minus the warning. Aside: a remembered password login still sends the
    30-day header twice (`regenerate(persistent)` then `persist()`) — identical
    values, harmless.

- [x] **1.4 Timezone coupling between PHP and SQLite timestamps.** [low] [S]
  - **Prod recon:** prod PHP and OS are both UTC, so this is latent there. It
    bites on dev (Windows PHP writes local time, the UI reads it as UTC) and on
    any future host whose `date.timezone` isn't UTC.
  - **Where:** 18 writes of `date('Y-m-d H:i:s')` (PHP's zone; nothing anywhere
    calls `date_default_timezone_set`) in `Data/PluginTokenData.php` (4),
    `Data/UserTokenData.php` (3), `Data/UserData.php` (3),
    `Data/PluginAuthCodeData.php` (3), `Data/PasskeyData.php` (2),
    `Data/CardReportData.php` (2), `api/Routes/Internal/PasskeyController.php` (1),
    while every `DEFAULT CURRENT_TIMESTAMP` column is UTC and
    `frontend/src/utils/datetime.ts:9` assumes *all* timestamps are UTC. Token
    expiry comparisons, "last reported", and sort orders are correct today only
    because the droplet's PHP zone happens to be UTC.
  - **Fix:** one helper (`Tarot\Utility\Clock::now(): string` returning
    `gmdate('Y-m-d H:i:s')`) and replace all 18; add
    `date_default_timezone_set('UTC')` at the top of `api/index.php`, `og.php`,
    and the CLI scripts as a belt.
  - **Verify:** `grep -rn "date('Y-m-d H:i:s')" backend/includes backend/api`
    returns nothing; run the suite under a non-UTC zone:
    `vendor\bin\phpunit -d date.timezone=America/Chicago`.
  - **DONE 2026-10-28:** the count missed the three **expiry** writes
    (`AuthService` activation/reset, `PluginTokenService::expiry`,
    `date(…, time() + TTL)`), which are compared as strings against `:now` and so
    had to move in step — 21 writes + `ChangelogData`'s date fallback, all now
    `Tarot\Utility\Clock::now()/in()/today()` (`gmdate`). Nothing parses
    timestamps in PHP (no `strtotime`/`DateTime`), so string comparisons stay
    consistent. `api/index.php` pins `date_default_timezone_set('UTC')` as the
    belt; not `og.php` (no date handling) and not the autoloader (that would also
    force UTC on the suite and defeat the non-UTC run). New `ClockTest` runs in
    UTC+14: a user created there had `registered_at` **50,400 s (14 h)** off
    SQLite's clock before the change, ≤2 s after. The non-UTC run also caught a
    test fixture writing an "expired" auth code with `date()` — fixed to
    `Clock::in(-60)`. The grep is empty; 257 tests pass under UTC−11, −6, 0 and
    +14; PHPStan/phpcs clean. Prod data needs nothing (it always ran UTC); rows a
    Windows dev PHP wrote in local time stay as they are on dev.

- [x] **1.5 CSP has been "report-only, promote later" since it shipped.** [medium] [S–M]
  - **Where:** `backend/.htaccess:12`. The policy still allows
    `fonts.googleapis.com`/`fonts.gstatic.com` (fonts are self-hosted now), and
    `/api/docs` (`api/index.php:127`) loads Scalar from `cdn.jsdelivr.net`, which
    the policy would block the day it is enforced (`Header always set` applies
    to PHP responses too).
  - **Fix:** remove the Google Fonts entries; either self-host the Scalar bundle
    or exempt `/api/docs` with a `SetEnvIf Request_URI "^/api/docs"` guard; then
    rename the header to `Content-Security-Policy`. Keep `report-uri`/`report-to`
    if you want ongoing signal.
  - **Verify:** every route + Turnstile on `/login` + `/api/docs` load with a
    clean console under the enforced header.
  - **Partly done 2026-10-28.** Google Fonts hosts removed (fonts self-hosted;
    nothing references them). `/api/docs` now pins Scalar to
    `@scalar/api-reference@1.74.0/dist/browser/standalone.js` with SRI
    (`sha384-VXnK…`, stable across fetches) and sends its **own enforced CSP**
    (one script, `fonts.scalar.com`, `connect-src 'self'` — so no
    `proxy.scalar.com` and no "Ask AI" egress); the site policy skips it via
    `<If "%{THE_REQUEST} !~ …">`. Verified on dev: renders, fonts load,
    "Test Request" GET /config → 200, console clean. The built SPA is clean
    under the site policy (no inline scripts — the one inline `<script>` is
    JSON-LD data — no `style=`, no foreign hosts but Turnstile; Google sign-in
    is a navigation, so `form-action` is moot).
  - **Blocked on an owner decision — what Cloudflare injects.** Browsing prod
    under report-only logged violations from two Cloudflare features, not our
    code: (1) **Bot Fight Mode's JS-detection inline script**, which embeds a
    per-request ray id (hash differs every load — measured), so only a
    per-request **nonce in the CSP header** can allow it (Cloudflare stamps the
    header's nonce onto scripts it injects; `unsafe-inline` defeats the point);
    on the free plan it can't be turned off without turning off Bot Fight Mode;
    (2) the **Web Analytics** beacon from `static.cloudflareinsights.com` — note
    the privacy policy says "we do not use third-party analytics". Options:
    serve the HTML shell through PHP with a per-request nonce (strict policy,
    keeps both features); or turn the features off and enforce the static
    policy as is.
  - **Decided (owner): keep Web Analytics and Bot Fight Mode → nonce.
    Implemented 2026-10-28, awaiting deploy + prod verification.** Every page
    route now goes through `og.php`, which sends an **enforced** CSP built by
    `Tarot\Utility\ContentSecurityPolicy` with a fresh 128-bit nonce per
    response: `script-src 'self' 'nonce-…' challenges.cloudflare.com
    static.cloudflareinsights.com` — no `'unsafe-inline'` (styles keep it).
    The static `.htaccess` header is gone; `/index.html` and `/dist/index.html`
    also route to `og.php` so no CSP-free copy of the app exists. Escape hatch:
    `CSP_MODE=report-only` in the live `.env` (no deploy). Verified locally via
    php-cgi: header present + nonce fresh per response, ordinary pages' body
    byte-identical to the build, `/reading/{id}` share meta intact, look-alike
    paths untouched, the escape hatch flips the header. Unit-tested policy.
    Privacy policy §5/§6 now name Cloudflare Web Analytics accurately — beacon
    reports to our own `/cdn-cgi/rum`; measured on prod: no cookies, no
    storage — and Cloudflare's bot script. **What can only be verified on
    prod:** that Cloudflare stamps the nonce onto its JS-detection script and
    the beacon. After deploy, browse home / New Draw / a reading + lightbox /
    login (Turnstile) / `/api/docs` with the console open: expect zero CSP
    violations and both `/cdn-cgi/challenge-platform/…` and `/cdn-cgi/rum`
    requests. If anything is blocked, set `CSP_MODE=report-only` first, then
    fix.
  - **Verified on prod 2026-10-28** (after the deploy): `/`, `/new`,
    `/reading/{id}`, `/privacy`, `/index.html`, `/dist/index.html` all send an
    **enforced** CSP with a different nonce each, no report-only header,
    `no-cache`; `/reading/{id}` keeps its share meta. Cloudflare **stamps the
    nonce**: in navigation HTML both its JS-detection inline script and the
    analytics beacon carry the response's nonce (`MATCH`), and the detection
    snippet passes it on — `d.nonce=…` on the inner iframe script and
    `a.nonce=…` on the `/cdn-cgi/challenge-platform/…/main.js` loader — so the
    whole chain is allowed. In a fresh browser tab: zero CSP violations on
    home, New Draw, a reading + lightbox (WebP), login (Turnstile renders its
    "Verify you are human" widget; it waits for a human click, as it should for
    an automated browser), beacon loaded and `/cdn-cgi/rum` sent.
  - **One gap found and fixed:** Cloudflare injects the beacon into `/api/docs`
    too, and that page's policy had no nonce, so the beacon was blocked there
    (console error only, no user impact). The docs route now issues its own
    nonce + the beacon host. **Verified on prod 2026-10-28** after the backend
    deploy: fresh nonce per request, both Cloudflare scripts `MATCH`; in a fresh
    tab the reference renders in full, Inter + JetBrains Mono load, the console
    holds only Scalar's version banner, and `/cdn-cgi/rum` and the JS-detection
    `oneshot` both complete.

- [x] **1.6 Reading-id collision is a 500.** [low] [S]
  - **Where:** `backend/includes/Tarot/Service/ReadingService.php:435` — 5 random
    bytes (40 bits), no retry; a UNIQUE violation surfaces as a PDOException.
  - **Fix:** in `persist()`, catch the constraint violation (SQLSTATE 23000),
    regenerate, retry up to 3 times.
  - **Verify:** unit test with a `ReadingRepository` double that throws once.
  - **DONE 2026-10-28:** `persist()` draws a fresh id and retries (3 attempts)
    only when the error is SQLite's `UNIQUE constraint failed:
    readings.reading_id`; any other error, or a third collision, propagates as
    before. Tests replay a *real* SQLite collision exception (captured from an
    in-memory table) — one collision → saved with a new id; three → gives up;
    a foreign-key error → not retried (one attempt).

- [x] **1.7 Guest readings never expire.** [medium] [S]
  - **Where:** `ReadingData::cleanGuestOlderThan()` (`ReadingData.php:233`) is
    reachable only through the admin "Clean" button (`DELETE /admin/readings/all`).
    Nothing runs it.
  - **Fix (decided: 1 year):** `backend/scripts/retention.php` (uses the same
    Data method with 365 days; also a good place for `PRAGMA optimize`), cron it
    nightly after the backup (§1.1); state the retention window on
    `PrivacyPolicyView.vue`. **The first run deletes 210 of prod's 1,925 guest
    readings** (oldest dates from 2025-03-16) and breaks their share links;
    publish the privacy-page text before the first run.
  - **Verify:** script output count; cron entry on the droplet; privacy page text.
  - **Implemented 2026-10-28 (awaiting deploy + droplet install):**
    `backend/scripts/retention.php` → `ReadingRepository::cleanGuest(365)`, with
    `--dry-run` (same DELETE in a rolled-back transaction) and
    `ANALYZE` after (closes §2.7's ANALYZE note; see the prod dry run below for
    why not `PRAGMA optimize`). Cron: chained
    onto the 03:17 backup with `&&`, so nothing is deleted without a fresh backup;
    output to `journalctl -t tarotgen-retention`. `scripts/` added to the
    backend deploy payload so cron never runs a hand-copied, drifting file (the
    repo's `scripts/` is a superset of what prod had). Privacy policy §8 now states
    the window, the signed-in exemption and the 14-day backup tail; "Last
    updated" bumped. **Account holders are exempt** (owner's decision): the DELETE
    is `user_id IS NULL` only, and every creation path (`ReadingService::persist`
    via `ReadingController::currentUserId`) sets `user_id` for a session *or* a
    linked plugin's bearer token. Readings an account holder makes while signed
    out can't be attributed and count as guest readings — the policy says so.
    New `ReadingDataTest::testCleanGuestOlderThanDeletesOnlyOldGuestReadings`
    (366-day guest goes; 364-day guest and 900-day owned stay). Locally: dry
    run on dev reports 210 and deletes nothing; a real run on a copy deletes
    those 210, leaves all 5 owned, analyses 17 tables; re-run deletes 0.
  - **Order on the droplet:** deploy frontend (policy live) + backend (ships
    the script) → dry run as www-data → install the cron line.
  - **Prod dry run 2026-10-28 06:33 UTC** (after both deploys): 2,044 readings
    (1,925 guest, 119 owned); **210 guest readings** would go (2025-03-16 …
    2025-10-23); no owned reading is older than a year yet. Same 210 under
    cron's bare environment (`env -i` + the cron file's variables), exit 0; the
    count was unchanged afterwards. **Found:** prod's PHP links SQLite 3.45.1,
    where `PRAGMA optimize=0x10002` analysed 1 table (the bit is 3.46+) vs 20 for
    plain `ANALYZE` — tested on a root-only temp copy of the nightly backup,
    deleted after. The script now runs `PRAGMA analysis_limit=400; ANALYZE`;
    that change needs a backend deploy before the cron line goes in.
  - **Cron installed 2026-10-28 06:46 UTC** (after the `ANALYZE` deploy, confirmed
    on the droplet): `/etc/cron.d/tarotgen` root:root 644, LF, trailing newline
    checked; previous file kept as
    `/var/backups/tarotgen/tarotgen.cron.prev-20261028-064618`; cron logged
    `RELOAD (/etc/cron.d/tarotgen)` at 06:47. Rehearsal of the exact installed
    command (`true` for the backup, `--dry-run` added) under cron's environment
    logged `dry run: 210 …` to `journalctl -t tarotgen-retention`; readings
    still 2,044. **First real run: 03:17 UTC 2026-10-29**, after that night's
    backup. Tick this item once `journalctl -t tarotgen-retention` shows
    `deleted 210 …` (± any that age past a year meanwhile) and the backup log
    shows the pre-deletion copy.
  - **CONFIRMED 2026-10-30:** 03:17 UTC 2026-10-29, after that night's backup
    (`tarotdb-20261029-031701`, 2,066 readings): `deleted 210 guest reading(s)
    older than 365 days`, the dry run's count. 2026-10-30: `deleted 0`. Readings
    2,066 → 1,856, then 1,883 with new draws.

- [x] **1.8 Small leaks / decisions to record.** [low] [S]
  - Public `GET /decks` and `/deck-systems` return `submitted_by` user ids
    (`Structure/Deck.php:47`, `Structure/DeckSystem.php:29`). Strip for non-admin
    responses.
  - Registration confirms whether an email / display name exists
    (`Service/AuthService.php:69,72`). Forgot-password correctly does not.
    Acceptable trade-off; write the decision down in `API.md` so it isn't
    "fixed" twice in opposite directions.
  - `frontend/src/composables/useDecks.ts:32` swallows a failed deck load
    (`// Silently fail`), leaving the New Reading page with an empty picker and
    no message. Surface it (toast) — see §4.2, which replaces that fetch anyway.
  - **Found 2026-10-28 (§2.1 verification):** every API 404 writes a full Slim
    stack trace (~2 KB, `[php:notice]`) to the shared `/var/log/apache2/error.log`
    — `addErrorMiddleware(…, true, true)` at `api/index.php:72` logs errors with
    details, and `HttpNotFoundException` counts. The traffic is scanners: in 14
    days ~1,300 API 404s led by `/api/.env` (236), `/api/v1/.env` (162) and
    `..%2f` traversal probes, all correctly 404. Harmless, but it buries real
    errors. Fix: a handler for `HttpNotFoundException` /
    `HttpMethodNotAllowedException` that renders without logging (or log one
    line, no trace); keep full detail for real exceptions.
  - **DONE 2026-10-28 (all four):** `submitted_by` is stripped from public
    `GET /decks`, `/decks/{id}`, `/deck-systems`, `/deck-systems/{id}` via a new
    `AbstractStructure::jsonSerializeWithout()` (admin endpoints keep it; the
    plugin's nullable `SubmittedBy` was never read). The enumeration trade-off
    is written down in `API.md` (forgot-password's uniform reply verified in
    code). Deck-load failures toast (§5.7). 404/405s use the default renderer
    with logging off — same JSON/HTML negotiation, no stack trace; real
    exceptions untouched. (A `static fn` handler broke 404s into 200 + warning:
    Slim binds handler closures to the container — caught on the dev server,
    fixed, documented in a comment.) Also removed the dead share-relay rows from
    `API.md`'s auth section.

---

## 2. Database

- [x] **2.1 Four dead tables and six dead indexes from the removed share relay.** [medium] [S]
  - **Where:** `plugin_clients`, `plugin_client_identities`, `plugin_messages`,
    `plugin_blocks` + `idx_plugin_clients_hash`, `idx_plugin_clients_identity`,
    `idx_client_identities_pair`, `idx_client_identities_hash`,
    `idx_plugin_clients_user`, `idx_plugin_messages_inbox`. Zero references in
    `backend/` or `plugin/` since commit f40037a. Prod holds dead data in two of
    them (`plugin_clients` 7 rows, `plugin_client_identities` 7). The env var
    `PLUGIN_IDENTITY_SALT` is likewise unreferenced but still documented in
    `backend/.env.example`.
  - **Fix:** `db/migrate_drop_plugin_relay.php` with `DROP TABLE IF EXISTS` ×4,
    run dev + prod, hand-edit `schema.sql`, delete the env block. (The relay may
    return over WebSockets; recreate the schema then, with whatever shape that
    design needs — don't keep this one "just in case".)
  - **Verify:** `sqlite3 db/tarotdb.db .tables` shows none of the four;
    `composer test` green; `grep -rn PLUGIN_IDENTITY_SALT backend` empty.
  - **Implemented 2026-10-28 (dev done; prod migration pending):** re-checked —
    no code in `backend/`, `frontend/src`, `plugin/` or `scripts/` references the
    tables, `ClientAuth`, `ShareRelay` or `SharePrompt`.
    `db/migrate_drop_plugin_relay.php` (gitignored like every migration) drops
    children first, all in one transaction, prints each table's row count, and is
    idempotent; `plugin_tokens`/`plugin_auth_codes` (live linking) untouched. Dev:
    dropped 3 tables (dev never had `plugin_client_identities`), integrity ok,
    0 FK violations; second run skips all four. `schema.sql`: a fresh build lost
    exactly the 4 tables + 6 indexes (+ `plugin_blocks`' PK autoindex), the other
    50 objects byte-identical — and the dev DB now equals `schema.sql`
    object-for-object. Also removed: the `PLUGIN_IDENTITY_SALT` block in
    `.env.example` (0 references left) and the unused `clientToken` security
    scheme in `OpenApiSpec.php` (`openapi.json` −5 lines; generated TS unchanged).
    253 tests / PHPStan / phpcs pass. **Prod:** `sudo tarotgen-backup`, upload the
    migration to `<WebRoot>/db/`, run as www-data, verify `.tables`; then delete
    the dead `PLUGIN_IDENTITY_SALT=` line from `/var/lib/tarotgen/.env`.
  - **DONE on prod 2026-10-28 06:56 UTC** (after the backend deploy): backup
    `tarotdb-20261028-065605.db.gz` first; dropped `plugin_messages` (0 rows),
    `plugin_blocks` (0), `plugin_client_identities` (7), `plugin_clients` (7) —
    exactly the dead rows counted above; `plugin_tokens`/`plugin_auth_codes` kept
    (5 rows each). Integrity ok, 0 FK violations, files still www-data. **Prod's
    schema now equals the committed `schema.sql` object-for-object (49)** —
    compared against a fresh build in a root-only temp dir. Live: `/api/config`,
    `/api/decks/1`, a reading → 200, `/api/auth/me` → 401. Owner to delete the
    `PLUGIN_IDENTITY_SALT=` line from the live `.env`.

- [x] **2.2 Legacy deck columns** — handled by §1.2 (drop them, stop selecting `*`).

- [x] **2.3 `readings` has no index on `reading_time`.** [low] [S]
  - **Prod recon:** 2,044 rows in a 1.43 MB DB — the scans cost nothing today.
    Cheap insurance, not a fix for a felt problem.
  - **Where:** used by `StatsService` (totals/daily, lines 68-74, 156-160),
    `ReadingData::listAll` (`ORDER BY r.reading_time DESC … LIMIT/OFFSET`, line 205),
    and `cleanGuestOlderThan` (line 233). The dashboard additionally runs
    `json_extract` over the whole table on every load (`StatsService.php:86-88,
    103, 133`).
  - **Fix:** migration adding `CREATE INDEX idx_readings_time ON readings(reading_time)`
    (consider `(user_id, reading_time)` and dropping `idx_readings_user`). Only
    if the table grows past tens of thousands: generated columns
    `deck_id`/`spread_id` extracted from the JSON, indexed.
  - **Verify:** `EXPLAIN QUERY PLAN` on the three queries shows the index.
  - **Implemented 2026-10-28 (dev done; prod migration pending):**
    `db/migrate_readings_time_indexes.php` (idempotent) adds
    `idx_readings_time (reading_time, reading_id)` and
    `idx_readings_user_time (user_id, reading_time, reading_id)`, drops
    `idx_readings_user` (now a prefix of the second), and runs `ANALYZE readings`.
    The trailing `reading_id` matches every `ORDER BY reading_time DESC,
    reading_id DESC`. Plans on dev: `listByUser`, `countByUser`, `listAll`,
    `cleanGuestOlderThan` and the stats daily series went from a full scan + temp
    B-tree sort to an index scan with no sort. `listAll` was rewritten in §2.5 and
    re-checked: it pages on `idx_readings_time`, then looks up users and decks by
    primary key for the page's rows only. `schema.sql` hand-edited; dev DB =
    `schema.sql` object-for-object. The dashboard's whole-table `json_extract`
    stays as is (generated columns only if the table grows past tens of thousands).
  - **DONE on prod 2026-10-28 21:23 UTC**, after a backup
    (`tarotdb-20261028-212309.db.gz`, 2,059 readings), run as www-data. Both
    indexes were created, `idx_readings_user` was dropped, and integrity is ok.
    Prod's schema then matched a fresh `schema.sql` build object for object (41). `SELECT *` + `FETCH_CLASS` couples schema to Structures.** [medium] [S]
  - **Where:** 18 occurrences across `ChangelogData`, `DeckSystemData` (×6),
    `SpecialCardData` (×3), `UserData` (×4), plus `DeckData`'s `d.*` (fixed in
    §1.2). Any added
    column that a Structure doesn't declare becomes a dynamic property →
    deprecation now, fatal in PHP 9. §1.2 is this bug happening.
  - **Fix:** explicit column lists (a `private const string COLS` per Data
    class, as `ReadingData::SELECT_COLS` already does).
  - **Verify:** `grep -rn "SELECT \*" backend/includes/Tarot/Data` is empty.
    Note the unit tests cannot catch prod schema drift because each test creates
    its own minimal table; the explicit list is the real guard.
  - **DONE 2026-10-28:** the count missed `SpreadData` (×1) and
    `UserSpreadData` (×2); all 18 sites now use a per-class `SELECT_COLS`
    (`SYSTEM_COLS`/`CARD_COLS` in `DeckSystemData`), matching
    `ReadingData::SELECT_COLS`. Every list equals its Structure's properties /
    `hydrate()`'s reads, checked against `schema.sql` (= prod) — so no data
    changes. Side benefit: `UserData`'s rows no longer carry `password_hash`;
    only `findAuthByEmail()`/`getPasswordHash()` select it. The grep is empty.
    New `ProductionSchemaReadsTest` closes the gap noted above: it loads the
    committed `db/schema.sql` into SQLite, seeds one row per table through each
    class's own writers, and runs every read — a mistyped column fails at
    prepare (`no such column`, checked by planting one in `ChangelogData`), a
    stray column into a `FETCH_CLASS` Structure fails as a deprecation. It also
    gives `ChangelogData`, `SpecialCardData` and `SpreadData` their first tests.
    252 tests, PHPStan, phpcs (no new warnings) pass; public deck-system,
    deck-card, spread and changelog endpoints smoke-tested clean on dev.

- [x] **2.5 Two hydration styles.** [low] [M]
  - **Where:** 12 Data classes do `FETCH_ASSOC` + a hand-written `hydrate()`
    (`ChangelogData`, `ContactData`, `FavoriteSpreadData`, `PasskeyData`,
    `PendingSpreadData`, `PluginAuthCodeData`, `PluginTokenData`, `ReadingData`,
    `SpreadData`, `UserData`, `UserSpreadData`, `UserTokenData`); the rest use
    `fetchAllAs`/`fetchOne`.
  - **Fix:** migrate to `fetchAllAs` where the Structure's typed props match
    the columns (the Data classes are in coercive mode, so SQLite's `0/1` → `bool`
    and `"5"` → `int` assignments are fine; keep manual hydration where JSON
    decoding or derived flags are needed, e.g. `ReadingData`).
  - **Verify:** existing Data tests; no new deprecations.
  - **DONE 2026-10-28 — one style, no exceptions (owner's call):** every row
    read now fetches straight into a class through `fetchOne`/`fetchAllAs`, and
    the Data layer has no `FETCH_ASSOC` left (`fetchColumn`/`FETCH_COLUMN` for
    scalars). The "keep manual hydration" cases above didn't hold up:
    - *Derived flags* moved into the SELECT: `User::$google_linked`
      (`COALESCE(google_id, '') <> ''`, so the Google id stays in SQL) and
      `has_passkeys` (`EXISTS …`). The second also ends an N+1: the admin users
      list ran one passkey query per user. `Reading::$password_protected` was
      already computed in SQL; its hydrate was pure casting.
    - *JSON columns:* spread `positions` arrive as text and the property's PHP 8.4
      set hook decodes them (`AbstractStructure::jsonList`), so `Spread`,
      `PendingSpread` and `UserSpread` fetch directly. Checked first that hooks
      fire under `FETCH_CLASS`.
    - *The admin readings list* is a `ReadingSummary` worked out in SQL
      (`json_extract` for deck and spread, a decks join for the name, `Guest` and
      `Deck #<id>` fallbacks), a page at a time. The controller's load-every-deck
      loop is gone.
    - *Arrays that were entities:* the passkey list (`Passkey`), favourite spreads
      (`FavoriteSpread`) and the admin list are now schemas in the spec. Until now
      their endpoints were documented as a bare `object`. The internal lookups
      fetch into small classes with no schema: `PasskeyCredential`,
      `PluginAuthCode`, `UserToken`, and `PluginToken` (which already existed)
      for `findActive`.
    - *The password hash* fetches into `UserCredentials`, where it is `private`
      behind `verify()`/`needsRehash()`, so nothing can read or `json_encode` it.
      This is safer than the array was. It deliberately isn't an
      `AbstractStructure`, whose `jsonSerialize()` reflects private properties too.

    `fetchOne`/`fetchAllAs` use `FETCH_PROPS_LATE` (construct, then fill), so a
    Structure constructor can never overwrite fetched values. The rule, and where
    each kind of conversion belongs, is in `AbstractData`'s docblock and AGENTS
    §4. Every non-nullable property was checked against a NOT NULL column, since
    `FETCH_CLASS` doesn't absorb a NULL the way the old casts did. Removed: seven
    `hydrate()`s, `UserData::userHasPasskeys`, and the unused
    `Reading::setIsFinal`/`setPasswordProtected`.

    Tests: new `ListPayloadsTest` (the three lists against their schemas; the admin
    list's owners, decks, spreads, fallbacks and paging). The spec checker moved
    from `ReadingPayloadsTest` into `HttpTestCase::assertDocumented`, which now
    handles arrays and inline objects; it was confirmed to reject a missing field,
    an object for a list, and an extra nested field. 305 tests, PHPStan and
    php-cs-fixer pass; frontend type-check, lint and 166 tests pass. Against the
    dev DB, read-only: every converted read hydrates every row, and the admin
    list equals the old PHP derivation over all 1,225 readings. Frontend:
    `Passkey`, `FavoriteSpread` and `ReadingSummary` come from the spec, replacing
    three hand-written interfaces. **No prod data step:** deploy backend and
    frontend.

- [x] **2.6 `myReadings` is unbounded.** [medium] [M]
  - **Where:** `AccountController.php:52` → `ReadingData::listByUser` returns
    every reading with its full `reading_info` JSON. `AccountReadingsView.vue`
    has no search, filter, or paging.
  - **Fix:** paginate like `listAll` (`?limit&offset`, total) and return a light
    summary (deck id, spread name, card count, name, date, flags) for the list.
    **Plugin compatibility:** `TarotApiClient.GetMyReadingsAsync` deserialises
    `List<Reading>` and reads `ReadingInfo`; keep `reading_info` in the response
    (or bump the plugin) before changing the shape. Pairs with feature §9.1.
  - **Verify:** payload size for a 200-reading account; plugin "My Readings"
    tab still renders.
  - **DONE 2026-10-28:** `GET /account/readings?limit=&offset=` returns one page
    (max 100) with the total in `X-Total-Count`; **without `limit` the response
    is unchanged** (a plain array), so the current plugin keeps working — page
    it in the plugin later, then the unbounded path can go. `apiRequest`
    success results now carry optional `headers`. My Readings shows 25 per page
    (Reka pagination, styles moved to `style.css` from the admin view's scoped
    copy), reloads after delete, steps back when a delete empties the last
    page, and shows "Couldn't load your readings" + Try again instead of the
    misleading empty state. A test caught `Number(null) === 0` hiding the list
    when the header is missing. Verified on dev with 30 seeded readings
    (pages 25 + 5, delete, step-back). The light-summary payload is left for §9.1.

- [x] **2.7 Cosmetic / when-you-touch-it.** [low]
  - `special_cards` FK has no `ON DELETE CASCADE` (worked around manually in
    `DeckData::delete`, line 125) and its constraint is named
    `FK__sepcial_cards__decks`. SQLite needs a table rebuild to fix; do it when
    the table is next migrated.
    **Implemented 2026-10-28 (dev done; prod migration pending):**
    `db/migrate_special_cards_cascade.php` rebuilds the table (foreign keys off
    outside the transaction → new table → copy → row-count check → drop → rename
    → `foreign_key_check`, which must come back empty or it rolls back and exits 1;
    idempotent). The constraint is now `fk_special_cards_decks … ON DELETE
    CASCADE`. Orphan rollback tested on a copy. With every table that references
    `decks` cascading, `DeckData::delete` is one `DELETE`. New `AdminDecksTest`:
    deleting a deck takes its special cards, reports and favourites and leaves the
    other deck's. It fails (500) against the old schema. **Order on prod: back
    up, run this migration, then deploy the backend** — the new `delete()` relies
    on the cascade.
    **DONE on prod 2026-10-28 21:23 UTC**, with the same backup as §2.3. 0 orphans
    beforehand; all 9 rows kept. `foreign_key_check` came back empty, integrity is
    ok, and the files are still www-data's. The backend had gone out a little
    earlier, so deleting a deck that had special cards would have been refused
    until then; nothing was lost.
  - Mixed timestamp sources — §1.4.
  - No `ANALYZE`/`PRAGMA optimize` ever runs; fold into the retention cron (§1.7).
    **Done in §1.7** (`scripts/retention.php` runs `ANALYZE` nightly; `PRAGMA
    optimize` was tried and is ineffective on prod's SQLite 3.45).

---

## 3. Performance and bandwidth

- [x] **3.1 Card images are served with no cache headers.** [high] [S]
  - **Where:** `backend/.htaccess:20-25` sets `Cache-Control` only for `/_app/`
    (hashed bundles) and `/fonts/`. `/assets/decks/**` — the heaviest assets on
    the site, a full-size PNG per lightbox open — fall to Cloudflare's 4-hour
    default and are re-downloaded all day. `share_banner.png` too.
  - **Prod recon:** `curl -sI` on `Card_0001.png` → 5,097,892 bytes, **no
    `Cache-Control` header, `cf-cache-status: MISS`**; `share_banner.png` 604 KB,
    MISS.
  - **Fix:** `SetEnvIf Request_URI "^/assets/decks/" TG_DECK_IMAGE` +
    `Header always set Cache-Control "public, max-age=2592000" env=TG_DECK_IMAGE`
    (30 days; images change only on a re-scan). On re-scan, purge the CF cache
    for that path or bump a `?v=` in `cardImageUrls` (§4.1).
  - **Verify:** `curl -sI https://tarotgen.io/assets/decks/1/Card_0001.png | grep -i cache`.
  - **DONE 2026-10-28** (deploy of `e2189f3` + `.htaccess` follow-up installed
    05:35 UTC with auto-rollback; 27 status/header checks passed). Follow-up
    found while verifying: the existing `/_app/` `immutable` and `/fonts/`
    rules had **never** been sent — those paths are rewritten into `dist/`, and
    `SetEnvIf Request_URI "^/_app/"` doesn't match the rewritten request; they
    now match the served file (`<If "%{REQUEST_FILENAME} =~ m#/dist/_app/#">`).
    The HTML shell (`index.html`, `og.php`) is now `no-cache`, so a returning
    browser can't boot a stale shell naming deleted bundles; missing files under
    `/_app/` and `/fonts/` 404 instead of returning cacheable 200-HTML.
  - **Implemented 2026-10-28:** `/assets/*` 2xx responses get
    `public, max-age=86400, s-maxage=2592000, stale-while-revalidate=604800`
    (Cloudflare 30 days, browsers 1 day; `Header set` without `always`, so
    errors are never edge-cached). Also fixed: a **missing** file under
    `/assets/` fell through to the SPA as 200-HTML, which Cloudflare caches by
    extension (`.png`) — it now 404s like `/plugin/` (matching `dev-router.php`).
    Prod census: all 28 decks complete (2,228 full PNGs, avg 2.2 MB, 5 GB), so
    no live page relied on that fallthrough. **After replacing a card image,
    purge its URL in Cloudflare.**

- [x] **3.2 Lightbox serves raw PNG; only thumbnails are WebP.** [high] [M]
  - **Where:** `ThumbnailService` writes `thumbs/*.webp` only; `imgUrl` in
    `ReadingView.vue:86` points at the PNG. **Prod recon:** a full card is ~5 MB
    of PNG (thumb: 62 KB WebP), so a 10-card spread viewed in the lightbox moves
    ~50 MB — on mobile data, the dominant cost of using the site. Also check
    the export path (`useReadingExport.ts` loads `imgUrl` for the PNG export)
    before changing what `imgUrl` points at.
  - **Fix:** generate a full-size `*.webp` (and optionally AVIF) alongside each
    PNG in `ThumbnailService`; serve it from the lightbox with the PNG as
    fallback (`<picture>` or `onerror` swap). Keep PNGs — the plugin's
    `CardTextureCache` loads them.
  - **Verify:** bytes transferred opening a lightbox before/after.
  - **Implemented 2026-10-28 (awaiting deploy + backfill):** `ThumbnailService`
    → `CardImageService`, which writes a full-size WebP q82 next to each PNG plus
    the 400 px q80 thumb from one decode, regenerates when either is missing or
    older than its PNG (a re-scan refreshes), writes via temp file + rename, and
    reports a `failed` count. Admin routes are now `POST /admin/decks/images` and
    `/admin/decks/{id}/images` (buttons: "Generate web images");
    `scripts/generate_card_images.php` does the backfill without a request time
    limit. Frontend: `imgUrl` is the WebP, `ReadingCard.pngUrl` the fallback —
    the lightbox swaps to the PNG on `@error`, the export retries with it, and
    the report dialog's 52 px preview now uses the thumb instead of the full scan.
    AVIF skipped: WebP already gets ~92% of the win. Measured locally: 5.9 MB PNG → 461 KB WebP (1629×2834, visually
    identical at 1:1) + 72 KB thumb; lightbox load and PNG fallback checked in
    the browser. **Deploy order:** backend → backfill (≈15 min at nice 19,
    ≈1 GB) → frontend. Between the backend and frontend deploys the old admin
    "Generate thumbnails" button 404s; nothing user-facing changes.
  - **DONE 2026-10-28** (commit `37782a9`, deployed): backfill ran 05:54–06:10
    UTC as www-data at nice 19 — 28 decks, 2,228 generated, 0 failed, 952 s;
    every PNG has its display WebP, no temp files left, all www-data-owned,
    dimensions match the masters. 5.25 GB of PNG → 0.35 GB of WebP (6.6%).
    Live: `Card_0001.webp` 377 KB vs the 5.1 MB PNG, `image/webp` with the
    §3.1 cache headers; the lightbox on a real reading loads the WebP at
    1629×2834 for 416 KB with no PNG requests.
  - **Follow-up (plugin):** `CardTextureCache` still downloads the master PNG.
    Switching it to the display WebP needs a check that Dalamud's texture
    provider decodes WebP; until then the PNGs must stay.

- [x] **3.3 The CSS bundle is 407 KB (48.6 KB gz) and roughly 60% unreferenced.** [medium] [M]
  - **Where:** census (appendix): 780 class selectors in `dist/_app/index-*.css`,
    310 referenced anywhere in `src/` (generous substring match), 367 of the
    unused are Bulma `is-*` modifiers, 66 are `fa-*`. `bulma.scss` is already
    modular (57 lines); the remaining weight is helper/modifier generation.
  - **Fix:** trim further in `bulma.scss` (disable the modifier/responsive
    variants you don't use; review the 41 kept helpers in `bulma-helpers.css`),
    rather than a blanket PurgeCSS, which would need a `/^is-/` safelist for the
    classes toggled at runtime and defeat itself. Re-run the census after.
  - **Verify:** census ratio; a visual pass over home, reading, new-draw,
    account settings, two admin pages.
  - **DONE 2026-10-28 — 418 → 256 KB, 48.7 → 34.5 KB gzip (−29%).** Measured
    per module first (each compiled on its own, classes matched against `src/`).
    The weight was not where the census pointed: Bulma's **themes** module was
    181 KB / 17.2 KB gz — the light theme written three times and the dark twice
    (`:root`, `prefers-color-scheme`, `[data-theme]`). The site is dark-only, so
    `bulma.scss` now writes what those rules settle on for
    `<html data-theme="dark">` once — light as the base, dark over it, the
    shared setup — 61 KB / 6.2 KB gz. Dropped `layout/hero` and
    `components/panel` (38 KB): their only "users" were HomeView's own scoped
    `.hero`/`.panel`, which matched Bulma's names by accident. That leak had
    given the home cards a 24 px bottom margin on the first (on a phone it sat
    *inside* the card, not between the two) and a faint white resting shadow on
    the second; compared side by side on the live page, the owner confirmed
    both were artefacts, so they are gone with the modules.
    One unused helper removed (`has-text-weight-bold`). Left alone, on purpose:
    `grid/columns` (28 KB, 334 of 357 classes unused, but 2.9 KB gz — hand-
    trimming the grid would make the next `is-offset-2` silently do nothing)
    and the button colour variants (≈1 KB gz, measured in August).
  - **Verified** with the computed-style walk from the August trim: 35 routes,
    signed in and out, about 7,700 elements × 61 properties, before vs after.
    Identical everywhere except the intended changes listed under §4.6 and the
    home hero's `display` (flex → block, no element changes size).

- [x] **3.4 `og.php` runs for every human visit to `/reading/{id}`.** [low] [M]
  - **Where:** `.htaccess` rewrite; `og.php` does PHP + 2 queries + regex over
    the shell per view and makes those pages edge-uncacheable. Fine at today's
    scale; it is the first thing to revisit if reading views climb. Options:
    rewrite only for crawler user agents, or a 60-second per-id micro-cache.
  - **Since §1.5 (2026-10-28) `og.php` serves every page**, for the per-response
    CSP nonce. Non-reading pages do no queries (file read + header), and the
    shell was already `no-cache`, so no edge caching was lost. The reading-page
    cost above is unchanged; a micro-cache would have to vary the nonce, so
    "crawler-only meta" is now the simpler option.
  - **Decided 2026-10-28: keep it.** Measured on prod (median of 9, as a browser
    navigation): `/privacy` (shell only) 238 ms, `/reading/{id}` 232 ms,
    `/api/config` 245 ms — the share-meta lookup (two primary-key queries)
    doesn't register against the network. A crawler-only rewrite would trade
    that for UA sniffing, where any chat app missing from the list loses its
    link preview without anyone noticing. Revisit only if reading views grow by
    orders of magnitude.

- [x] **3.5 Eager thumbnails.** [low] [S]
  - Only two `loading="lazy"` attributes exist (both `ReadingView.vue`). Add to
    card thumbnails in `AccountReadingsView`, the `CustomReadingView` picker,
    and admin tables.
  - **DONE 2026-10-28.** Every `<img>` reviewed: lazy where an image can start
    off-screen — the card images on the `CustomReadingView` and
    `FreeDrawPlacementView` canvases and the admin card-reports table (one
    thumbnail per row). `AccountReadingsView` no longer shows images. Left eager:
    the brand logo, the lightbox image, the report dialog's preview (visible the
    moment they render) and the reading's card backs (one cached URL).

---

## 4. Duplication

- [x] **4.1 Card image URL building is copied four times.** [medium] [S]
  - **Where:** `'Card_' + String(id).padStart(4, '0')` + `/assets/decks/{deck}/…`
    in `views/ReadingView.vue:83` (+ back-card literals at 66, 71, 81),
    `views/CustomReadingView.vue:200`, `views/FreeDrawPlacementView.vue:134`,
    `views/admin/ErroredCardsView.vue:76`.
  - **Fix:** `src/utils/cardImages.ts` exporting `cardImageUrls(deckId, cardId)`
    → `{ full, thumb }` and `cardBackUrls(deckId)`; the version token from §3.1
    lives here too. Unit-test it.
  - **Verify:** `grep -rn "padStart(4" src --include=*.vue` returns nothing.
  - **DONE 2026-10-28** with §3.2: `src/utils/cardImages.ts` exports
    `cardImageUrls()` → `{ display, png, thumb }` and `cardBackImageUrls()`,
    covered by `cardImages.spec.ts`; all four views use it and the grep is clean.
    No `?v=` token: §3.1 settled on purging Cloudflare after a re-scan instead.

- [x] **4.2 Forty raw `fetch('/api' + …)` call sites bypass the API client.** [medium] [M]
  - **Where:** composables `useAccount` (6), `usePasskeys` (9),
    `useFavoriteDecks` (2), `useFavoriteSpreads` (2), `useUserSpreads` (4),
    `useDecks` (1); views `AccountSettingsView`, `admin/DeckSystemsView`,
    `ContactView`, `CustomReadingView` (2), `FreeDrawPlacementView` (3),
    `NewReadingView`, `ReadingView` (4), `SubmitDeckSystemView`,
    `SubmitDeckView` (2), `SubmitSpreadView`. The 429 branch is pasted into
    `ContactView.vue:45` and `SubmitSpreadView.vue:52`; error-message parsing is
    re-implemented per site; several swallow errors. `AGENTS.md` §5 already says
    to use `apiRequest`/`apiFetch`.
  - **Fix:** migrate each to `apiRequest` (keep `apiFetch` for list reads); add
    `useReading`/`useContact`-style composables where a view owns the call;
    then lock it in with an ESLint `no-restricted-syntax` rule forbidding
    `fetch(` outside `composables/apiClient.ts`.
  - **Verify:** `grep -rn "fetch('/api" src` → only `apiClient.ts`; lint rule
    fails on a new raw fetch.
  - **DONE 2026-10-28.** 34 were left (the bug batch had moved some); all are
    on `apiRequest`/`apiFetch` now, through composables: new `useReadings`
    (generate, custom, get, unlock, draw more, finalize, placement) and
    `useSubmissions` (deck, deck system, spread — the spread submit was pasted
    into two views), `useDecks.fetchDeckCards`, `useAccount.unlinkGoogle`, and
    `useAccount`/`usePasskeys`/`useUserSpreads` rewritten (each had its own
    `parseJson` + `JSON_HEADERS` copy). `sendJson()`/`sendForm()` in
    `apiClient.ts` replace four copies of the JSON-body helper. The pasted 429
    branch is gone: the server's message *is* that text. Errors that were
    swallowed now surface (deck systems failing to load on Submit a Deck, a
    deck's cards on Recreate Draw, `useUserSpreads` no longer throws offline),
    and a rate-limited reading no longer says "we couldn't find that reading".
    `usePasskeys` now builds typed WebAuthn options instead of mutating `any`.
    Lint rule `tarot/api-client-only` (`no-restricted-syntax` on `fetch(` and
    `window|globalThis|self.fetch`, outside `apiClient.ts` and specs) — tested
    to fail on both forms. New specs: `useReadings`, `useSubmissions`,
    `usePasskeys` (byte conversion both ways, cancel, server reasons),
    `useUserSpreads`, more `useAccount`. Clicked through live on dev: new draw,
    draw more, mark final, free-draw placement + public spread submission,
    Recreate Draw's deck cards, account settings — all working.

- [x] **4.3 The admin list-page scaffold is repeated in nine views.** [low] [M]
  - **Where:** back link + `PageHeader` + search field + `.settings-panel` +
    table + empty-state in `views/admin/{Changelog,Contacts,Decks,DeckSystems,
    ErroredCards,Readings,SpecialCards,Spreads,Users}View.vue`.
  - **Fix:** `components/admin/AdminListPage.vue` with `title`/`subtitle`/
    `searchPlaceholder` props and `actions`/`default`/`empty` slots; views keep
    their table bodies.
  - **Verify:** each page renders identically (screenshots), line count drops.
  - **DONE 2026-10-28.** `AdminListPage` (column, back link, `PageHeader` with an
    `#actions` slot that only renders when used — `PageHeader` drops its actions
    box otherwise) and `AdminSearchField` (optional `label`, for Special Cards'
    filter row). Converted by script so the DOM stayed identical; the
    computed-style walk shows changelog, contacts, decks, deck systems, special
    cards and users **identical** to before. Views: −211 lines, +61 shared.
    **Manage Spreads changed on purpose:** it was the one admin page with no
    column (full container width) and a hand-rolled Bulma `level` heading; it
    now has the same width, `PageHeader` and gold rule as the other eight, and
    its add/edit mode uses the same frame.

- [x] **4.4 Two type systems, one of them dead.** [medium] [M]
  - **Where:** `src/types/index.ts` (251 lines, hand-maintained) and
    `src/types/api.generated.ts` (regenerated and diff-checked in CI on every
    run, **imported by nothing**).
  - **Fix:** pick one. Recommended: add `required: [...]` to the `#[OA\Schema]`
    attributes on the Structures (swagger-php emits optional props otherwise, so
    the generated types are currently all `?`), then derive
    `export type CardReport = components['schemas']['CardReport']` etc. in
    `types/index.ts` and delete the hand copies. Otherwise delete the generator,
    the npm script, and the CI step.
  - **Verify:** `npm run type-check`; one source of truth.
  - **DONE 2026-10-28.** Rather than 14 hand-kept `required: [...]` lists, a
    swagger-php processor (`Routes\RequireStructureProperties`, run by
    `composer docs` and OpenApiSpecTest) marks every property of a
    `Tarot\Structure` schema required — true by construction, since
    `jsonSerialize()` sends them all. Spread `positions` were "array of object";
    a `SpreadPosition` schema (in `OpenApiSpec.php`, the shape
    `NormalizesPositions` stores, with descriptions) now types them and the
    five request bodies that take positions. `types/index.ts` re-exports 15
    entity types from the generated file and deletes their hand copies; the
    reading-view payload, `AccountReading`, `UsageStats` and client shapes stay
    hand-written for now.
  - **Follow-up, done the same day:** the reading endpoints were documented
    with the stored-row `Reading` schema while get/unlock/generate/draw/finalize
    send `accessiblePayload()` and GET can send the locked stub. New schemas in
    `OpenApiSpec.php` — `ReadingView`, `LockedReading` (GET is a `oneOf` of the
    two), `ReadingInfo`, `DrawCard`, `SpreadSnapshot` — every property described;
    `Reading.reading_info` now references `ReadingInfo`. `card_name` is honestly
    optional (the resolver omits it for an unnamed extra card), so the frontend's
    `ReadingCard` gets `cardLabel()` ("Card #N") instead of a blank caption. The
    frontend now takes all six reading types from the spec — no hand-written API
    types are left except `UsageStats` and client shapes.
    `tests/Http/ReadingPayloadsTest` reads each endpoint's documented response
    from `openapi.json` and compares real responses with it, nested objects
    included; run against the old spec it fails on exactly the mismatches.
  - **Found while doing that — frontend type-checking had been off.**
    `npm run type-check` ran `vue-tsc --noEmit` on the solution-style root
    `tsconfig.json` (`"files": []`), which checks no files. Checking the app
    project for real gave 599 errors, nearly all "`vue` has no exported member
    `ref`": `vite-env.d.ts` had no top-level import/export, so its
    `declare module 'vue' { GlobalComponents }` *replaced* Vue's types instead of
    augmenting them. Not a Vue/TypeScript version problem — Vue 3.5.44 (latest
    stable) with TypeScript 6.0.3 compiles cleanly in isolation. The augmentation
    moved to `src/global-components.d.ts` (a module); the script now checks
    `tsconfig.app.json` and `tsconfig.node.json`, and a deliberate error in a
    `.ts` and a `.vue` file both fail it. That left 14 real errors, fixed: a
    TipTap 3 bug (`setContent(html, false)` — v3 takes an options object, so
    `emitUpdate: false` was ignored and syncing the prop echoed an update back),
    `SegmentedControl` assigning a plain string to its generic model (it now
    takes the matching option's value), three admin forms passing a possibly
    missing id into a URL, `lib`/`target` still ES2020 under code that ships
    `.at()`/`Object.hasOwn` (now ES2022, which Vite 8's browser targets have),
    the reading types above, and a spec's mock signature. With real types, typed
    ESLint surfaced 29 more: `Number()`/`String()` wrappers left from when
    everything was `any` (removed where the value is a typed number/string; the
    `perPage` select was checked to hand back numbers), a floating `nextTick`,
    `as` casts replaced by a typed form interface, Turnstile's after-`await`
    element re-check kept but made to read the ref fresh, and indexes that can
    miss typed honestly (`deckLookup` is `Partial`, `.at()` for maybe-empty).

- [x] **4.5 `AdminController` is 1,505 lines / 45 methods.** [medium] [M]
  - **Fix:** split per resource to mirror the admin views and the route groups
    already in `api/index.php:252-318`: `AdminDashboardController`,
    `AdminDeckController` (+ special cards, thumbnails), `AdminDeckSystemController`,
    `AdminSpreadController` (+ pending), `AdminReadingController`,
    `AdminUserController`, `AdminContactController`, `AdminChangelogController`,
    `AdminCardReportController`. Mechanical; keep the `OA\Tag`s so the spec
    doesn't move.
  - **Verify:** `composer docs` produces an identical `openapi.json`
    (operationIds are hash-based — expect them to change; paths/schemas must not).
  - **DONE 2026-10-28.** Split by script into the nine controllers named above
    (special cards and card images inside `AdminDeckController`, pending spreads
    in `AdminSpreadController`, the session check in `AdminDashboardController`),
    each with only the dependencies its methods use. Checked mechanically: all
    44 methods moved with byte-identical bodies and attributes; the only lines
    lost are the nine section headings each class now replaces. `openapi.json`:
    paths, components and tags identical; the 44 admin operationIds changed, as
    predicted. All 16 admin GET routes answered 200 on dev afterwards.

- [x] **4.6 The gold tint is typed out 39 times.** [medium] [S]
  - **Where:** `rgba(201, 162, 75, α)` at eight alphas (.06 .1 .12 .2 .25 .4 .55
    .6) across `style.css`, `HomeView.vue` (27 colour literals in its 666-line
    style block), `App.vue` (10), and six components; aqua `rgba(67, 179, 167, α)`
    likewise. This contradicts the project's own "tokens.css is the palette
    source of truth" rule.
  - **Fix:** add an alpha scale to `tokens.css` (`--myst-gold-a06` … `--myst-gold-a60`,
    or use `color-mix(in srgb, var(--myst-gold) 12%, transparent)` and keep one
    token); replace the literals; then enforce with Stylelint (§6.1).
  - **Verify:** `grep -rhoE "rgba\(201, ?162, ?75" src` count is 0 outside
    `tokens.css`.
  - **DONE 2026-10-28 — and the count is 0 inside `tokens.css` too.** 62 palette
    copies replaced by `color-mix(in srgb, var(--token) N%, transparent)`, alpha
    for alpha, by script: 39 gold, 12 aqua, 5 status tints (info/success/
    warning/danger), 2 surface-3, and `tokens.css`'s own derived tints
    (`--myst-hair-gold`, the aqua washes) — so each colour is defined once.
    `color-mix` over `transparent` computes to the same colour; the style walk
    confirms no element changed. Off-palette leftovers, which **do** change
    slightly: the lightbox caption/counter/hint greys (`#ddd/#999/#888` → text
    tokens) and its orange "(Reversed)" (`#f4a261` → `--myst-warning`, what the
    reading page already uses for it); the toggle's bluish border (`#4a4f63` →
    `--myst-border-strong`); pure-white headings (spread details, changelog
    titles → `--myst-text-strong`). White stays where it sits on a fill or on
    card art. Deleted as dead: the five `.notification.is-warning:not(.is-light)
    .toggle-*` rules (no such notification exists). Stylelint enforcement waits
    for §6.1.

- [x] **4.7 Card-report reason copy lives in three places by design** (frontend
  `utils/cardReports.ts`, backend `CardReportReason`, plugin `ApiModels.cs`).
  [low] [S] Add a Vitest that reads `backend/openapi.json` and asserts the
  frontend keys equal the `CardReportReason` enum so they cannot drift.
  - **DONE 2026-10-28, differently.** With §4.4 the frontend's
    `CardReportReason` *is* the spec's enum, and `REASON_COPY` is a
    `Record<CardReportReason, …>` — a new backend reason fails type-checking
    until it has copy, which is stronger than the suggested test. The unguarded
    copy was the plugin's: `tests/Unit/Structure/CardReportReasonTest.php`
    reads `ApiModels.cs` and asserts its keys equal the enum's cases.

---

## 5. Standards and consistency

- [x] **5.1 `composer.json` has `"*"` version constraints** on `slim/slim`,
  `slim/http`, `slim/psr7`, `php-di/slim-bridge`, `friendsofphp/php-cs-fixer`,
  `squizlabs/php_codesniffer`. [medium] [S] Pin to the installed majors
  (`composer show -D` for current versions) with caret ranges.
  - *Status 2026-10-28:* `squizlabs/php_codesniffer` is gone (§5.2); the other
    five still read `"*"`.
  - **DONE 2026-10-28:** caret ranges floored at the installed versions —
    `slim/slim ^4.15`, `slim/http ^1.4`, `slim/psr7 ^1.8`, `php-di/slim-bridge
    ^3.4`, `friendsofphp/php-cs-fixer ^3.95`. Also declared `php-di/php-di ^7.1`:
    `index.php` and `HttpTestCase` build the container with it directly, though
    it only arrived through `slim-bridge`. `composer update --lock` changed only
    the lock's content-hash (no package moved); `composer validate` passes. The
    next backend deploy runs `composer install` on the droplet once, because the
    hash changed; it installs the same versions.

- [x] **5.2 php-cs-fixer has no config file.** [medium] [S]
  `composer lint` runs phpcs (PSR-12, warnings ignored); `composer lint:fix`
  runs php-cs-fixer with **no** `.php-cs-fixer.dist.php`, so the fixer and the
  checker don't agree on a ruleset. Pick one: recommended php-cs-fixer with a
  dist config (`@PSR12` + `@PHP8x` risky set as tolerated), `php-cs-fixer check`
  in CI, and drop phpcs; or keep phpcs and remove the fixer.
  - **DONE 2026-10-28 — php-cs-fixer, phpcs removed.** Measured first: the two
    tools disagreed in 26 files (php-cs-fixer's own `@PSR12` flagged code phpcs
    passed). `backend/.php-cs-fixer.dist.php`: `@PER-CS3x0` (PSR-12's successor,
    pinned) + `@PHP8x5Migration` + `no_unused_imports`, with three house-style
    overrides so adopting it didn't restyle working code: casts stay `(int)$x`
    (344 of 347 casts), empty constructor bodies keep their own lines (44
    files), and multi-line *call arguments* take no trailing comma (61 files;
    arrays already did, everywhere). The risky set was left out: it would add
    `declare(strict_types=1)` to 139 files, a behaviour change, not a format.
    One pass fixed 26 files — mostly PHP 8.4's `new Foo()->bar()`, `fn(`
    spacing, `0o775` octals, a few trailing commas on parameter lists. Scope is
    every committed PHP file (`api`, `includes`, `scripts`, `tests`, `og.php`,
    `dev-router.php`); phpcs only looked at three directories. `composer lint` =
    `php-cs-fixer check`, `lint:fix` = `fix`; CI step renamed; `phpcs.xml` and
    `squizlabs/php_codesniffer` removed; cache gitignored. Lost: phpcs's 120-char
    line warning, which was already set not to fail anything.

- [x] **5.3 Missing project metadata.** [low] [S] `engines` in
  `frontend/package.json` + `.nvmrc` (CI pins Node 24); root `.editorconfig`;
  `plugin/.editorconfig`.
  - **DONE 2026-10-28:** `frontend/.nvmrc` (`24`) is now the one place the Node
    version lives: CI's `setup-node` reads it (`node-version-file`) instead of
    its own `'24'`, and `engines.node` is `>=24` (both raised to Node 26, the
    new LTS, on 2026-11-01). Root `.editorconfig` records
    what the files already do: UTF-8, LF, final newline, no trailing whitespace
    (no tracked file has any), 4 spaces, 2 for frontend sources / npm JSON /
    YAML / Markdown, 4 for `backend/**.json`, and `schema.sql` left alone (it
    keeps SQLite's own text, tabs included). Prettier reads `.editorconfig` for
    what its config leaves unset; it now resolves `tabWidth 2` / `useTabs false`
    / `endOfLine lf` (its defaults anyway) and `format:check` is unchanged.
    `plugin/.editorconfig`: only conventions all 13 C# files follow
    (file-scoped namespaces, Allman braces), 2-space project files, tabbed `.sln`.

- [x] **5.4 Documentation drift (the spun-off docs task landed nothing).** [low] [S]
  - `AGENTS.md`: §3 says `eslint.config.js` (it is `eslint.config.ts`, consuming
    the `frontend/eslint-config` submodule); the §3 tree comment says PHPStan
    "level 6" while §6 says level 8 (8 is right); §12 still describes
    `ShareRelay`, `SharePrompt`, `ClientAuth`, and a per-install client token.
  - `plugin/README.md`: lines ~6, ~21, ~78 still advertise the chatless share
    relay, consent tiers, and the client token.
  - `plugin/TarotGen.Plugin/TarotGen.Plugin.json` Description: "Optional account
    linking (later)" — linking shipped.
  - `plugin/TarotGen.Plugin/Configuration.cs` docblock references `docs/auth.md`,
    which does not exist.
  - `backend/.env.example`: the `PLUGIN_IDENTITY_SALT` block (§2.1).
  - **Partly done with §2.1:** AGENTS.md §12 no longer describes the relay
    (layout list matches the plugin's real files; auth paragraph rewritten) and
    the `.env.example` block is gone. The PHPStan-level line in AGENTS.md §3 was
    fixed with §5.2. **Still open (checked 2026-10-28):** AGENTS.md §3 tree says
    `eslint.config.js`; `plugin/README.md` still advertises the chatless share
    (line ~21) and the client token / relay (~78–82); `TarotGen.Plugin.json`
    still says "Optional account linking (later)"; `Configuration.cs` still
    points at the missing `docs/auth.md`.
  - **DONE 2026-10-28:**
    - AGENTS.md §3: the tree shows `eslint.config.ts` and the `eslint-config/`
      submodule.
    - `plugin/README.md`: the share-relay feature, its client token and
      consent-tier text are gone. The auth section describes the one account
      token, and no longer calls the account routes read-only (locking a reading
      writes). "Publishing" names `scripts/deploy.config.ps1`; it said
      `deploy.local.ps1`, a file the script never read.
    - The manifest describes linking as shipped.
    - Two missing `docs/auth.md` links (`Configuration.cs`, and one more in
      `LinkService.cs`) and Plugin.cs's "P0/P1" scope note now point at the
      README.

    Plugin builds with 0 warnings. A repo-wide search finds the relay only in
    AGENTS §12's note that it was removed, and in the dead `.claude/worktrees/`
    copies (§0 housekeeping). The new manifest text goes out with the next
    `-Target plugin` deploy (Dalamud reads it from `repo.json`; no version bump).

- [x] **5.5 `contactForm.spec.ts` tests a re-implementation, not the code.** [low] [S]
  The spec defines its own `submitContact()` with its own fetch logic and tests
  that. It cannot fail when `ContactView.vue` changes. Replace with a test of
  the composable §4.2 introduces, or a `@vue/test-utils` mount.
  - **DONE 2026-10-28** (with §8.5): the submit logic moved into
    `composables/useContact.ts` (on `apiRequest`; same user-facing messages),
    `ContactView` calls it, and `useContact.spec.ts` replaces the old spec —
    request shape incl. trimming, server message, 429, body-less 500, network
    error. Submitted for real on dev → success panel.

- [x] **5.6 Accessibility basics.** [low] [S] No `<main>` landmark anywhere in
  `src/`, no skip link. Add both in `App.vue`; add
  `eslint-plugin-vuejs-accessibility` (§6.1). Reka already covers the widgets.
  - **DONE 2026-10-28** (the lint plugin stays with §6.1): `App.vue` wraps the
    router view in `<main id="main-content" tabindex="-1">`. "Skip to content"
    is the first focusable element, hidden off-screen until it has keyboard
    focus. It focuses `<main>` itself instead of following its href, so no hash
    lands in the URL for the router to act on. The old `.app-wrapper > section
    { flex: 1 }` now sits on `<main>` (grows, flex column) and its section. All
    17 parameterless public pages measured identical before and after (page
    height, section box, footer position; short pages still fill the viewport).
    Keyboard-checked on dev: Tab shows the link, Enter puts focus on `<main>`
    with the URL unchanged, and the next Tab is the first link in the page
    content, past the nav. Pages that autofocus a field (login) skip the link,
    as they skip the nav.

- [x] **5.7 Error-swallowing composables.** [low] [S] `useDecks.ts:32` and
  friends (`useSpreads`, `useFavorite*`) catch and drop failures. Return an
  `ApiResult` or toast; §4.2 is the natural moment.
  - **DONE 2026-10-28:** `useDecks`, `useSpreads`, `useFavoriteDecks`,
    `useFavoriteSpreads` (and `useAccount.listReadings` — §2.6) now use
    `apiRequest` and toast what failed; favourites stay quiet on 401 (expired
    session, the router handles it). `useDecks` shares one in-flight request
    and retries on the next call. `toggleFavorite` (called fire-and-forget by
    the comboboxes) toasts on failure and can no longer reject. Specs:
    `useDecks.spec.ts` (rewritten), `useFavoriteDecks.spec.ts`. Seen on dev with
    the API stopped: toasts instead of empty pickers. Known rough edge: a
    *total* outage on New Draw stacks four toasts (decks, spreads, two
    favourites) — accurate but loud; group them if it ever matters.

- [x] **5.8 Native controls render light on the dark theme.** [low] [S] No
  `color-scheme` is declared anywhere (`getComputedStyle(html).colorScheme`
  is `normal`), so scrollbars inside tall modals and native form chrome are
  white-on-charcoal. Add `color-scheme: dark` at `:root` in `tokens.css` and a
  restrained `scrollbar-color` using existing tokens. (This was one of the three
  tasks that never landed.)
  - **DONE 2026-10-28:** `:root` in `tokens.css` declares `color-scheme: dark`
    and `scrollbar-color: var(--myst-border-strong) transparent` (inherited;
    colour only, so the modal scroll-lock widths are untouched). Computed on dev:
    `colorScheme` = `dark`, scrollbar `rgba(255,255,255,.16) transparent`.

- [x] **5.9 Slim's own errors don't use the `{ error }` shape.** [low] [S]
  Found by the §8.1 tests. A route or method that doesn't exist answers
  `{"message": "404 Not Found"}` / `"405 Method Not Allowed"` (plus an
  `exception` array outside production), or an HTML page when the request
  doesn't ask for JSON, while API.md promises `{ "error": "…" }` and every
  controller sends that. No visible harm today: the frontend's
  `messageFromBody()` reads only `error` and falls back to its own text. Fix: a
  JSON error renderer for the default handler that emits `{ error }` (detail
  only outside production), then tighten `RequestPipelineTest`'s 404/405 test.
  - **DONE 2026-10-28:** `Routes\JsonErrorRenderer` is registered on Slim's
    default error handler, and `forceContentType('application/json')` makes it
    the only renderer, whatever the request's `Accept`. A Slim `HttpException`
    keeps its client message ("Not found.", "Method not allowed. Must be one
    of: GET"; the `Allow` header still goes out). Any other exception says only
    "Something went wrong on our side. Please try again.", because its own
    message can carry paths or SQL. Outside production the exception chain
    follows under `exception`.

    `RequestPipelineTest` now checks the body and `Allow`, plus a 404 for an
    `Accept: text/html` request. Both of its tests fail with the wiring removed
    (no `error` key; an HTML page). `JsonErrorRendererTest` covers the
    production no-leak case. 309 tests, PHPStan, php-cs-fixer pass;
    curl-checked on dev.

---

## 6. Tooling and linting gaps

Nothing currently lints CSS, nothing finds unused code or dependencies, the
plugin is never compiled in CI, and no bot watches dependencies.

- [x] **6.1 Frontend.** [medium] [S–M]
  - **Knip** (unused files/exports/dependencies). Install as a devDependency so
    it runs offline and in CI (`npm run knip`). It would have flagged
    `api.generated.ts` (§4.4) immediately.
  - **Stylelint** with `stylelint-config-standard`, `stylelint-config-recommended-vue`,
    `postcss-html`; add `color-no-hex` and a
    `declaration-property-value-disallowed-list` entry for `rgba(`/`rgb(` with
    an override that allows them only in `src/assets/tokens.css`. That
    mechanically enforces the token rule (§4.6).
  - **Coverage**: `@vitest/coverage-v8`, `test:coverage` script, thresholds set
    at today's numbers and ratcheted.
  - `eslint-plugin-vuejs-accessibility`.
  - **Playwright** smoke test (§8.6).
  - **DONE 2026-10-28** (Playwright stays with §8.6):
    - **Knip** (`npm run knip`, `knip.ts`). First run: `public/sw.js` (registered
      by URL; now an entry), `bulma` (pulled in by Sass `@use`, which Knip
      doesn't read; a small SCSS compiler now hands it the specifiers), the
      submodule's own deps and the generated types (ignored). Real findings,
      fixed: eight exports used only in their own file un-exported; the unused
      `deckLabel()` deleted (the combobox renders its own markup); the unused
      `SpreadSnapshot` alias removed; and `CARD_HALF_H` is now *used*:
      `SpreadCanvasEditor` had hard-coded its value (9.46) instead of importing it.
    - **Stylelint** (`npm run lint:css`) extends the *recommended* configs, not
      `-standard`: standard flagged 667 lines of working CSS for blank lines,
      BEM class names and `rgba()` notation, all Prettier's business. Recommended
      found 34 real ones, fixed:
      - **The table hover was broken in all 11 striped, hoverable tables.** Bulma's
        three hover rules out-ranked ours, so the authored white-3% tint never
        applied and odd and even rows hovered in two different greys (measured:
        surface vs surface-3). Ours now use Bulma's selectors and win by order.
      - `word-break: break-word` (deprecated) became `overflow-wrap: anywhere`.
      - Duplicate `body` and `.box` rules were merged.
      - `no-descending-specificity` is off: its other 25 hits were unrelated
        selectors.

      The palette rule: no hex, and `rgb()`/`hsl()` outside `tokens.css` only for
      pure white or black. It caught the dialog scrims, `rgba(6, 6, 10, …)` twice,
      now `--myst-scrim` with `color-mix()` (pixel-identical), and three `#fff`,
      now `white`.
    - **Coverage** (`npm run test:coverage`, CI runs it in place of `npm test`):
      10.5% statements / 6.3% branches / 12.1% functions / 10.0% lines. The floor
      sits just under that in `vite.config.ts`; §8 raises it.
    - **`eslint-plugin-vuejs-accessibility`** found real bugs:
      - **66 of 91 form labels named nothing.** No `for`/`id`, so screen readers
        got placeholder text or nothing, and clicking a label didn't focus its
        field. All are associated now, with 26 controls given names:
        - plain fields got `for`/`id`;
        - `BaseSelect`, `NumberField`, `ToggleSwitch`, `DeckComboBox` and
          `SpreadComboBox` take an `id` and put it on their focusable element;
        - group captions (`SegmentedControl`, the aspect-ratio pair, the
          position-title inputs) and `MarkdownEditor` (now a named
          `role="textbox"`) use `aria-labelledby`;
        - captions that labelled nothing became `<span class="label">`.

        The browser check caught one cross-component bug the linter can't see:
        `/new` and `/custom` already had `<label for>` on the deck combobox, but
        the id never reached its input. Verified on dev with a throwaway admin:
        all 62 rendered controls on 28 routes named, no dangling `for`, the
        "Add Deck" modal fully named, and clicking a label focuses its field.
        Backup restored after.
      - **The dashboard's "Logout" card** was an `<a>` with no `href`, so the
        keyboard couldn't reach it. Now a `<button>`, measured identical in size
        and computed style.
      - **Reading cards** clear their highlight when focus leaves, as on mouse-out.
      - **Inline exceptions, each with its reason in the template:** autofocus on
        login, forgot-password, reset and unlock; the lightbox's gesture surface
        (the keyboard has +/- and buttons); the detail rows' mouse shortcut.
  - **Open, found here:** keyboard users can't favourite a deck or spread. The
    star is the only place to do it, and it sits inside a listbox option, which
    can't hold a focusable control. Needs a design decision (a key on the
    highlighted option, or a star button beside the field). Marked inline in
    both comboboxes.

- [x] **6.2 Backend.** [medium] [S–M]
  - PHPStan extensions via `phpstan/extension-installer`: `phpstan-strict-rules`,
    `phpstan-deprecation-rules` (would have caught §1.2 at analysis time if the
    Structures were analysed against real rows — at minimum it flags deprecated
    API use), `phpstan-phpunit`.
  - **Rector** (`rector/rector`) with the PHP 8.5 level set + dead-code set,
    `--dry-run` in CI.
  - `composer-unused` + `composer-require-checker` (implicit deps, e.g. `ext-gd`
    for `CardImageService`, `ext-mbstring`, `ext-json` are not declared).
  - `ergebnis/composer-normalize`.
  - **`composer audit` as a CI step** — green since §7.1, so it can gate now.
    *Done 2026-10-28 (§7.1).*
  - Coverage with `pcov` in CI.
  - **DONE 2026-10-28:**
    - **PHPStan extensions:** 168 new findings, all fixed, none baselined (the
      baseline went from 3 entries to 1):
      - **8 deprecations:** `curl_close()` (deprecated in 8.5, a no-op since 8.0)
        and swagger-php's `Generator::isDefault()` (now `Undefined::isDefault()`).
      - **22 `empty()`:** exact checks now. A request flag goes through
        `Input::bool()`; a session value is compared with what was stored.
      - **Short ternaries, non-boolean conditions, 24 useless casts.**
        `RateLimiter`'s casts were standing in for validation, so it now
        validates where it reads the file (`decode()`), which also retires two
        baseline guards; there's a new test for a malformed file.
      - **Strict base64 in passkey sign-in:** bad input now fails as missing data
        rather than decoding to bytes with junk dropped.
      - **`AbstractStructure::jsonSerialize()`** reads cached public
        `ReflectionProperty`s instead of `$this->$name`.

      Only PHPUnit's documented `$this->assert…()` style is exempt from the
      static-call rule, and only in `tests/`.
    - **Rector** (`composer rector`, `rector:check` in CI): 10 files, e.g.
      `array_any()`, typed constants, the no-op `imagedestroy()` gone. Skipped
      once, with its reason: a first-class-callable factory in `dependencies.php`,
      because production compiles the DI container.
    - **`composer deps:check`:**
      - **composer-unused:** `php-di/slim-bridge` was unused (nothing referenced
        it), so it's removed.
      - **composer-require-checker:** eight extensions now declared (`ctype`,
        `curl`, `filter`, `gd`, `mbstring`, `pdo_sqlite`, `session`,
        `simplexml`), checked against prod's `php -m` first so a deploy's
        `composer install` can't refuse. `psr/container`, `psr/http-message` and
        `psr/http-server-handler` were used directly, so they're declared too.
        swagger-php's symbols are whitelisted (dev-only by design; see AGENTS §6).
    - **composer-normalize:** `composer.json` reordered; CI runs `--dry-run`.
    - **Coverage:** CI runs PHPUnit with pcov and prints the summary: 54.9%
      lines / 46.9% methods. Two tests used `CoversClass` on a trait (a warning
      only under coverage, so a failure with `failOnWarning`); they use
      `CoversTrait` now. The threshold waits for §8.7.

    310 tests, PHPStan, php-cs-fixer pass. **Deploy note:** the lock changed
    (slim-bridge out, dev tools in), so the next backend deploy runs
    `composer install` on the droplet.

- [x] **6.3 Plugin.** [medium] [S]
  - `TarotGen.Plugin.csproj`: `<TreatWarningsAsErrors>true</TreatWarningsAsErrors>`,
    `<AnalysisLevel>latest-recommended</AnalysisLevel>`,
    `<EnforceCodeStyleInBuild>true</EnforceCodeStyleInBuild>`; a `.editorconfig`
    with the C# style rules.
  - **A CI job that builds it.** Today a plugin compile break ships silently.
    The standard goatcorp workflow downloads the Dalamud dev distribution
    (`https://goatcorp.github.io/dalamud-distrib/latest.zip`) into `DALAMUD_HOME`
    and runs `dotnet build -c Release`; upload `latest.zip` as an artifact.
  - **DONE 2026-10-28:**
    - **The three settings are in the `.csproj`**, plus `plugin/.editorconfig`
      (§5.3). NuGet vulnerability advisories stay warnings so a new one can't
      block a release; Dependabot raises the update.
    - **11 analyzer findings, fixed:**
      - **CA1001**, which led to a real bug: unloading the plugin mid-link left
        the loopback listener running for up to 3 minutes. `LinkService` is now
        `IDisposable`, and `Plugin.Dispose()` stops the flow.
      - **CA1305:** an invariant-culture number badge.
      - **CA1862:** ordinal case-insensitive search.
      - **CA1822/CA1859:** static helpers and list parameters; `SiteBase` (an
        alias for `Configuration.SiteUrl`) is gone.
    - **`.github/workflows/plugin.yml`** runs on changes under `plugin/`, on
      Linux as Kindred Nights' plugin job does: Dalamud into `DALAMUD_HOME`,
      `EnableWindowsTargeting`, then a Release build, `dotnet format
      --verify-no-changes` and the package uploaded as an artifact. Local
      Debug and Release builds have 0 warnings; the package builds.

- [x] **6.4 Repo.** [low] [S] `.github/dependabot.yml` covering `npm`
  (`/frontend`), `composer` (`/backend`), `nuget` (`/plugin/TarotGen.Plugin`),
  and `github-actions`. CodeQL if the plan allows it.
  - **DONE 2026-10-28:**
    - **Dependabot:** weekly on Mondays; minors and patches grouped per
      ecosystem, majors one by one (Kindred Nights' shape). TypeScript's major is
      held (owner's rule), and the Font Awesome registry is declared. **Owner:**
      add `FONTAWESOME_NPM_TOKEN` and `ESLINT_CONFIG_TOKEN` as *Dependabot*
      secrets, or its npm job and CI on its PRs fail. Untested risk: the
      `file:eslint-config` dependency is a git submodule Dependabot may not check
      out; if its npm job errors on that, the shared config needs publishing as
      a package.
    - **CodeQL** (`.github/workflows/codeql.yml`; the repo is public, so free):
      JavaScript/TypeScript, C# (build-mode none) and the workflows. CodeQL has
      no PHP.
    - **Also added:** a "Scripts" CI job running PSScriptAnalyzer
      (`PSScriptAnalyzerSettings.psd1`, as in Kindred Nights) over the deploy
      scripts. Its 12 findings are fixed:
      - the scripts are now plain ASCII, so Windows PowerShell 5.1 reads them
        without a BOM;
      - the switches are passed into the functions instead of read through
        script scope;
      - `Publish-Bundles` became `Publish-Bundle`.

      The deploy harness confirmed `-SkipBuild`/`-NoBackup` still work. The
      release job now waits for it too.

- [x] **6.5 "Kindred Nights"** was mentioned as a candidate linter; it is not a
  tool I recognise. If it meant **Knip**, see §6.1. Otherwise, name the tool and
  evaluate it against the gaps above rather than adding it on principle.
  - **Resolved 2026-10-28:** Kindred Nights is the owner's sibling repo
    (`C:\Web Projects\Kindred Nights`), and its tooling was the reference:
    - Its frontend already runs what §6.1 added (the accessibility lint plugin,
      Stylelint with recommended Vue configs, a token check, `.nvmrc`).
    - This repo took three things from it: Dependabot's shape, PSScriptAnalyzer
      for the deploy scripts, and its Linux Dalamud build with `dotnet format`.

---

## 7. Outdated dependencies (as of 2026-10-27)

- [x] **7.1 `composer audit` fails: HIGH CVE-2026-109413 in `symfony/yaml`**
  (transitive via `friendsofphp/php-cs-fixer`, dev-only, but red). [high] [S]
  `composer update friendsofphp/php-cs-fixer symfony/yaml` and re-run.
  - **Correction:** `symfony/yaml` comes in through `zircote/swagger-php`
    (`composer why`), not php-cs-fixer. Both are `require-dev`, and the deploy
    runs `composer install --no-dev`, so it never reached production; it only
    ran in `composer docs`.
  - **DONE 2026-10-28:** `symfony/yaml` 8.1.2 → 8.1.9 and
    `roave/security-advisories` refreshed. That refresh first failed: the
    advisories now flag PHP itself below 8.5.12, and `config.platform.php` was
    still pinned to 8.5.6. The pin is now **8.5.12**, matching production
    (`php -v` on the droplet), local and CI. `composer audit` is clean;
    `composer docs` produces an identical `openapi.json`; tests, PHPStan and
    phpcs pass. `composer audit` now runs in CI after the install step.
  - **Production check (read-only, 2026-10-28):** `composer audit --no-dev` on
    the installed packages is clean; `vendor/` matches the lock; no dev packages
    present; Apache's `libapache2-mod-php8.5` and the CLI are both 8.5.12 with no
    pending php/apache/openssl/sqlite upgrades. One runtime update was still
    warranted — see §7.2 (`slim/slim` 4.15.3).
- [x] **7.2 Composer, direct:** `zircote/swagger-php` 6.6.0 → 6.20.0 (14 minors
  behind — regenerate and diff `openapi.json` after), `phpstan/phpstan`
  2.2.8 → 2.3.3, `phpunit/phpunit` 13.3.1 → 13.4.5, `php-cs-fixer`
  3.95.20 → 3.95.27, `slim/slim` 4.15.2 → 4.15.3. [low] [S]
  - **`slim/slim` 4.15.3 — DONE 2026-10-28** (runtime; ships on the next backend
    deploy). Not in the advisory database, but the release fixes
    GHSA-h377-p8x2-prf9: route arguments were URL-decoded twice, bypassing route
    regex constraints. This API has no constraints, so the exposure was a
    double-decoded `{reading_id}` etc. reaching controllers (int casts / bound
    parameters); low, but worth the patch. Smoke-tested on the dev server:
    `/readings/a3921cc26%2566` now 404s instead of resolving to `…26f`; 404/405
    still negotiate JSON for `Accept: application/json`. Tests/PHPStan/phpcs pass,
    `openapi.json` unchanged. `laravel/serializable-closure` 2.0.15 → 2.1.0
    (php-di's, used to compile the prod container) left alone: bug fix + PHP 8.6
    support only.
  - *Still behind (`composer outdated --direct`, 2026-10-28):* php-cs-fixer
    3.95.20 → 3.95.27, phpstan 2.2.8 → 2.3.3, phpunit 13.3.1 → 13.4.5 (all
    dev-only); `zircote/swagger-php` 6.6.0 now has a **7.0** major out — plan it
    with a spec diff, since it generates `openapi.json`.
  - **DONE 2026-10-28 (the rest):** `composer update` in range — php-cs-fixer
    3.95.27, phpunit 13.4.5, phpstan-phpunit 2.1.1, strict-rules 2.1.0, rector
    2.8.2; runtime `laravel/serializable-closure` 2.1.0 and `symfony/polyfill-*`
    (ship on the next backend deploy, which runs `composer install` because the
    lock changed). **PHPStan held at 2.3.3** by a `conflict`: 2.3.4's phar bundles
    a phpdoc-parser Rector 2.8.2 crashes on (AGENTS.md §6; drop it when a Rector
    release passes with 2.3.4).
  - **swagger-php 7.0 — DONE 2026-10-28.** On 6.20 the spec came out identical;
    7.0 needed real work, each step diffed against the committed spec:
    - Every `OA\…` attribute moved to the 7.0 spec attributes (`OpenApi\Spec as
      OAS`; Rector's swagger-php set did the rewrite); `schema:` /
      `securityScheme:` keys became `component:`.
    - The CLI's `--add-processor` is gone: `composer docs` is now `bin/openapi.php`,
      and it and `OpenApiSpecTest` share `Routes\OpenApiSpec::builder()`.
      `RequireStructureProperties` is an augmenter now (v7 ignored the old
      processor, which silently dropped every `required` list).
    - The default (hybrid) pipeline turned all 95 property initializers into
      `default`s — placeholders for hydration, not values the API fills in — so
      the build removes the `Properties` augmenter. Result: no `default`s, every
      `required` list as before.
    - It also drops schemas nothing references, which exposed three endpoints
      documented as less than they send: `GET /deck-systems/{id}` and
      `GET /admin/deck-systems/{id}` said `DeckSystem` but send its `cards`
      (new shared `DeckSystemWithCards`; the frontend's hand-written copy now comes
      from the spec), and `GET /admin/pending-spreads` said `object[]` (now
      `PendingSpread[]`). Covered by the new `CatalogPayloadsTest`;
      `assertDocumented()` learned `allOf`.
    - That test then caught a standing spec error: the public deck and
      deck-system endpoints strip `submitted_by`, but the spec marked it required
      (so the frontend type promised it). It's now optional with "Admin views
      only" (`x: ['admin-only' => true]` tells the augmenter).
    - `composer-require-checker.json` lists the new symbols.
    - Full chain green: normalize, deps:check, audit, docs, lint, rector:check,
      312 tests, PHPStan (with `bin/` now analysed, linted and Rector-checked).
- [x] **7.3 npm:** 20 packages behind. Minors: `vue` 3.5.44, `vue-router` 5.4.0,
  `vite` 8.3.5, `reka-ui` 2.11.0, `@tiptap/*` 3.32.0, `dompurify` 3.4.17,
  `marked` 18.1.2, `eslint` 10.13.0, `vue-tsc` 3.3.13, `@vitejs/plugin-vue`
  6.0.10, `sass-embedded` 1.106.1, `jsdom` 30.1.3, `prettier` 3.9.10.
  **Majors (plan separately):** `typescript` 6.0.3 → 7.0.2, `vitest`
  4.1.11 → 5.0.5, `happy-dom` 20.11.2 → 21.0.4. [low–medium] [S–M]
  - **Vue/Vite done 2026-10-28** (lock only — all in range): `vue` 3.5.44,
    `vite` 8.3.5, `@vitejs/plugin-vue` 6.0.10, `vue-router` 5.4.0, `vue-tsc`
    3.3.13. All MIT on the registry; the only new transitive dep is `confbox`
    (MIT). TypeScript stays 6.0.3 — `package.json` pins `~6.0.0` (`vue-tsc`'s
    own peer range is just `>=5`, so the pin is the guard; owner: wait for TS 7.1).
    type-check, lint, 135 tests, build pass; home → New Draw (deck combobox,
    generate) → reading → lightbox clicked through on dev, no Vue warnings.
    *(Correction: "type-check" checked no files at the time — see §4.4. Once it
    was made real, Vue 3.5.44 + TypeScript 6.0.3 type-checked clean after fixing
    a broken `vue` augmentation in this repo; the versions were never the
    problem. Verified separately in an isolated project.)*
  - **`npm audit` (found while doing the above; pre-existing):** 36 advisories,
    1 critical / 5 high. Shipped code: `dompurify` (critical + high — but both
    need `SAFE_FOR_TEMPLATES`/`IN_PLACE`+hooks; `utils/markdown.ts` uses defaults),
    `prosemirror-view` paste XSS (reachable: TipTap is on the public contact
    form and reading notes — needs the victim to paste crafted content),
    `@tiptap/core` ReDoS. Dev/test only: `undici`, `brace-expansion`,
    `@vitest/mocker`, `postcss-selector-parser`, `happy-dom` (major). Every
    shipped fix is in range → `npm audit fix` (no `--force`), as its own commit.
  - **Audit fix done 2026-10-28:** `npm audit fix` took 36 → 8, but left the
    TipTap family split — `starter-kit` on 3.32.0 while `extension-link`,
    `extension-placeholder`, `pm` and `vue-3` stayed on 3.30.2 with a second,
    vulnerable `@tiptap/core`. Installed all five at `^3.32.0` (one core now) and
    `vitest` at `^4.1.12` (patched `@vitest/mocker`). **Shipped deps: 0
    vulnerabilities.** Left: `happy-dom` (dev/test, needs 21 — a major, planned
    with the other majors). type-check, lint, 135 tests, build pass; the TipTap
    editor (contact form) typed + toggled Bold → `<p>… <strong>…</strong></p>`.
    *(The real type-check later found one TipTap 3 API change this had missed —
    `setContent(html, false)` — fixed in §4.4.)*
  - *Still behind (`npm outdated`, 2026-10-28):* in range — `@tiptap/*` 3.32.1,
    `eslint` 10.13.0, `reka-ui` 2.11.0, `marked` 18.1.2, `prettier` 3.9.10,
    `sass-embedded` 1.106.1, `jsdom` 30.1.3. Majors — `typescript` 7 (owner:
    stay on 6 until Vue supports TS 7.1), `vitest` 5, `happy-dom` 21.
  - **DONE 2026-10-28 (the rest):** `npm update` in range (`@tiptap/*` 3.32.1,
    `reka-ui` 2.11.0, `eslint` 10.13.0, `typescript-eslint` 8.72.1, `marked`
    18.1.2, `prettier` 3.9.10, `sass-embedded` 1.107.0, `jsdom` 30.1.3), then the
    majors together: **`vitest` + `@vitest/coverage-v8` 5.0.5, `happy-dom` 21.0.4**
    (fixes the last advisory: **`npm audit` now 0, dev included**). Vitest 5's
    breaking changes were checked against the specs (nested `vi.mock`,
    `toThrow('')`, unawaited `resolves`, window assignments, coverage globs): none
    applied.
    - typescript-eslint 8.72 found two things: a meaningless `void` in a spec (now
      a real assertion), and `scrollBehavior` deprecated in vue-router 5.4. Its
      replacement, `ScrollRestoration`, is in `vue-router/experimental` ("might
      have breaking changes"), needs a layout (or every page) to call it, and would
      change Back from "jump to top" to "where you were". **Deferred, owner's
      call**: kept with a one-line disable in `router/index.ts` saying why.
    - Coverage under Vitest 5 counted lines at 9.99%, under the 10% floor. Rather
      than lower the floor, `utils/deck.ts` and `utils/seo.ts` got specs (now
      11.0 / 7.0 / 12.6 / 10.5%).
    - **Found in the browser check: deck and spread search never filtered.** Both
      comboboxes bound `v-model:search-term` on `ComboboxRoot`, an API Reka v2 no
      longer has (checked on 2.10.3 as well, so it predates this update), so
      typing showed every deck and Enter took the first. They now track the term
      from the input's own `input` events (the input's text is the selection's
      name until the user types). The spread list hides "No Spread (Free Draw)"
      during a search that doesn't name it, so Enter picks the first matching
      spread and "No spreads found." can show. Verified on dev: filtering,
      Enter, reopen shows all, Escape restores the name, empty state. A §8.3
      component test should pin this down.
    - TipTap 3.32.1 rechecked on the contact form (type, select, Bold →
      `<strong>`). type-check, lint, lint:css, knip, format:check, 178 tests,
      build all pass.
    - **TypeScript 7 still held** (`~6.0.0`): vue-tsc 3.3.13 checks through
      TypeScript's JS API, which TS 7.0 (the Go port) doesn't have; its TS 7
      changelog entries are about its own build. TS 7.1 (that API) is in beta.
      Dependabot already ignores TypeScript majors.
  - **SQLite on prod is 3.45.1 and stays there on 24.04:** PHP (packages.sury.org)
    links the distro `libsqlite3`, and noble only gets 3.45.1 security patches.
    26.04 LTS (offered to the droplet) ships 3.46.1 — enough for `PRAGMA
    optimize`'s check-every-table bit, little else. Not a reason to upgrade the
    OS; §1.7 uses plain `ANALYZE` instead. A self-built lib in `/usr/local/lib`
    would shadow the distro one for every program and lose apt security updates.
- [x] **7.4 Plugin:** `Dalamud.NET.Sdk` 15.0.0, `System.Security.Cryptography.ProtectedData`
  10.0.11 — **unverified** (NuGet was not reachable from the audit sandbox).
  `dotnet list package --outdated` from a networked shell.
  - **DONE 2026-10-28:** `ProtectedData` 10.0.11 → 10.0.14. `Dalamud.NET.Sdk`
    15.0.0 is the newest on NuGet. `DotNet.ReproducibleBuilds` (1.2.39; 2.0.5
    out) comes from the SDK, so it moves when the SDK does. No vulnerable
    packages (transitive included); Release build clean with warnings as errors.
    The plugin `<Version>` is unchanged: bump it with the next plugin release.

---

## 8. Tests

At audit time: backend 240 tests / 638 assertions (unit only); frontend 132
tests across 17 specs (utils + composables only). **After §8 (2026-10-28):
backend 752 tests / 7,187 assertions, 95.7% of lines and 89.1% of methods
covered (from 55.6% / 47.9%); frontend 1,063 tests across 95 specs, 97.5% of
lines (from 10.5%); plus a Playwright smoke run.** Both CI jobs fail below a
coverage floor. The tests found more than thirty bugs, all fixed (see "Found by
the §8 tests" below), and raised policy questions the owner then decided ("Open
from §8", implemented).

- [x] **8.1 No HTTP-level tests at all.** [high] [M]
  All 14 controllers and all three auth middleware (`UserAuth`, `AdminAuth`,
  `AccountAuth`) are untested — `AdminController`'s 1,505 lines included.
  **Fix:** extract app construction from `api/index.php` into `api/app.php`
  (returns `$app`; `index.php` becomes `require 'app.php'; $app->run();`), then
  a PHPUnit base case that builds the app against an in-memory SQLite seeded
  from `db/schema.sql` and dispatches PSR-7 requests. First targets: 401 shapes
  from each middleware, `OriginGuard` end-to-end, validation 400s, card-reports
  201/200/400/429, one admin CRUD round-trip.
  - **DONE 2026-10-28 (the harness + the first targets).** `api/app.php` returns
    a factory `(container, is_production) → App` holding the middleware, error
    handling and every route; `index.php` keeps the process setup (UTC, env,
    cookie params, container compilation) and runs it. The `/openapi.json` and
    `/docs` closures became `ApiDocsController` (top-level `const`s can't be
    re-declared each time a test builds the app). The one production change the
    harness needed: `Session` took its store from the `Connection` singleton, so
    `app.php` now hands it the container's PDO (`Session::useDatabase`) and
    sessions follow whatever database the app was built on.
  - `tests/Http/HttpTestCase` (new `http` suite): schema.sql in memory with
    foreign keys on, as on prod; each test is one browser whose session id
    carries from request to request, with sessions really stored by
    `DbSessionHandler` (in-process works because PHPUnit writes to the stdout
    stream, so PHP never marks headers as sent); env blanked per test; a
    per-test documentation-range IPv6 client address so the file-backed rate
    limiters don't collide; Slim/mailer logging sent to a temp file.
  - 30 tests, ~1.6 s: `AuthGuardsTest` (admin/user/account 401 bodies,
    inactive and deleted accounts, demotion taking effect next request, plugin
    tokens open only the three read routes, unknown/revoked tokens),
    `RequestPipelineTest` (OriginGuard by Origin and by Referer, before routing
    and before auth, same-origin/no-header/GET pass, `APP_ORIGIN` + CORS,
    security headers, 404/405 as JSON), `CardReportsTest` (201 with reason,
    reason-less bump, 200 cooldown, four 400s, 429 after 20),
    `AdminChangelogTest` (create → public read → update → delete; 404; reader
    401 writes nothing), `SignInFlowTest` (register → 403 inactive → activate →
    401 wrong password → login → me → logout; new session id on sign-in, the
    pre-login one carries no user; duplicate 422), `ApiDocsTest`. PHPStan level
    8 now covers `api/` whole and `tests/Http` (tests/Unit has ~170 findings and
    stays out for now).
  - **Found by the new tests — fixed:** a card report for a deck that no longer
    exists (a stale tab, an old plugin build) failed the `card_reports` foreign
    key as a **500** with a stack trace in the error log; it is now a 400 ("A
    valid deck and card are required."). Verified on the dev server too.
  - **Found — open, see §5.9:** Slim's own 404/405 bodies aren't `{ error }`.
  - **Noticed, left alone:** `POST /admin/changelog` accepts an entry with no
    title (the spec says `title` is required; admin-only, so low).
  - Next targets, by risk: `AccountController` (ownership: one user's
    readings/spreads vs another's), `ReadingController` (draw/finalize/unlock,
    password-protected readings), `PluginAuthController` (PKCE code → token),
    the other eight admin controllers (§4.5 split them; only changelog has
    HTTP tests).
  - *Since:* `ReadingPayloadsTest` (4 tests, §4.4 follow-up) checks every
    reading endpoint's response against its documented schema — 34 HTTP tests
    in all.
  - **The next targets — DONE 2026-10-28.** Every controller now has HTTP tests
    (about 350 more): account ownership (one user can't read, change, delete,
    favourite or submit another's readings, spreads or tokens), the reading
    lifecycle (password-protected readings, draw more, finalize, placement,
    custom readings, rate limits), the plugin's PKCE link (loopback-only
    redirects, verifier, single use, expiry, what the token opens), every admin
    controller's round trips and approvals, public submissions, registration,
    activation, password reset, login (enumeration, rate limits, Turnstile,
    remember-me), Google sign-in/link/unlink (with a stand-in for Google via the
    new `replaceService()`), and passkeys including a **full WebAuthn ceremony**
    (the test acts as the authenticator, through lbuchs' real verification).
    Controllers are at 90–100% of lines; what's left is unreachable defence.
    `assertDocumented()` now runs on every documented 2xx body and understands
    `allOf`; the endpoints whose bodies the spec didn't describe (17 of them, and
    `GET /account/favorite-decks`, documented as `Deck[]` but sending ids) are
    documented, so the frontend types for them come from the spec.
  - **`tests/Unit` is under PHPStan now** (184 findings fixed, no suppressions),
    so all of `tests/` is analysed at level 8 + strict rules. `phpunit.xml` fails
    on PHPUnit's own deprecations, notices and warnings too, so a move to
    PHPUnit 14 (13.4.5 is the newest stable) is a bump and a run.
- [x] **8.2 Untested Data classes:** `ChangelogData`, `SpecialCardData`,
  `FavoriteDeckData`. [low] [S] Same in-memory pattern as `CardReportDataTest`.
  - **DONE 2026-10-28:** unit tests for all three (100% each), plus `Env`,
    `Connection`'s pragmas and `Mailer` without SMTP.
- [x] **8.3 Zero component/view tests on the frontend.** [medium] [M]
  Add `@vue/test-utils` and start with the interactive components:
  `CardReportDialog`, `LightboxOverlay` (keyboard + report gating),
  `DeckComboBox`, `ConfirmDialog`, `ToggleSwitch`, `SegmentedControl`.
  - The combobox search went unnoticed from the Reka migration until §7.3's
    browser check; a test that types into `DeckComboBox` / `SpreadComboBox` and
    reads the options would have failed from day one.
  - **DONE 2026-10-28.** `@vue/test-utils` and a shared harness
    (`src/__tests__/helpers/`: `mountWithApp` on the real route table, typed
    props, `stubApi` that fails a test on any call it didn't declare, `page.ts`
    for whole pages). Every component and every view now has a spec (about 700
    tests): the shared components, the reading views (`ReadingView` alone ~50),
    the account and auth views, the admin views, the submission views and
    `App.vue`, plus the router guards. The `DeckComboBox` spec fails 3 of 5
    against the old search bug. `router/routes.ts` now holds the route table so
    specs build a router on it without the guards.
- [x] **8.4 Untested composables:** `useAccount`, `useChangelog`,
  `useFavoriteDecks`, `useFavoriteSpreads`, `usePanZoom`, `usePasskeys`
  (272 lines; mock `navigator.credentials`), `usePluginLink`,
  `useReadingExport` (393 lines; canvas — at least the layout math),
  `useSpreads`, `useUserSpreads`, `utils/seo.ts`. [medium] [M] Easier after §4.2.
  - *Status 2026-10-28:* now covered — `useAccount`, `useFavoriteDecks`,
    `usePasskeys`, `useUserSpreads` (plus the new `useContact`, `useReadings`,
    `useSubmissions`). **Still untested:** `useChangelog`, `useFavoriteSpreads`,
    `usePanZoom`, `usePluginLink`, `useReadingExport`, `useSpreads`.
    (`utils/seo.ts` and `utils/deck.ts` got specs in §7.3.)
  - **DONE 2026-10-28:** every composable is at 100% of lines, `useReadingExport`
    included (a recording fake canvas). `endpoints.spec` checks every path in
    `api/endpoints.ts` exists in `backend/openapi.json`; `PrivacyPolicyView.spec`
    checks the page's retention and backup periods against `retention.php` and
    `tarotgen-backup.sh`, which AGENTS.md says to change together.
- [x] **8.5 Replace `contactForm.spec.ts`** (§5.5). [low] [S]
- [x] **8.6 One Playwright smoke run in CI:** home → new draw → reading →
  lightbox → report a problem → (seeded admin) Errored Cards shows it. [medium] [M]
  This app is interaction-heavy; one end-to-end path catches whole classes of
  regressions the unit layer can't.
  - **DONE 2026-10-28** (`npm run test:e2e`, CI job "Smoke"), exactly that path,
    on the **built** app: PHP's built-in server routed as `backend/.htaccess`
    routes (`e2e/server/router.php`), so pages go through `og.php` and its CSP,
    on a seeded throwaway `TAROT_DATA_DIR` (`e2e/server/prepare.php`). It fails on
    any page error or CSP refusal. Locally it drives the installed Edge; CI
    installs Chromium. AGENTS.md §8 has the details.
- [x] **8.7 Coverage in both CI jobs with thresholds.** [low] [S]
  - **DONE 2026-10-28.** Frontend: the floor in `vite.config.ts` is now 95 / 91 /
    94 / 96% (statements / branches / functions / lines). Backend: `composer
    test:coverage` (CI) fails below 94% of lines or 87% of methods
    (`bin/coverage-check.php`; PHPUnit has no threshold of its own).

### Found by the §8 tests — fixed

Each has a test that fails without its fix.

- **Deleting a deck system still used by a deck, or setting a deck's artist or
  purchase URL to null, made every deck read answer 500** (a NULL into a
  non-null `Deck` property): `/decks`, `/admin/decks`, everything. The SELECT
  now COALESCEs them.
- **`DELETE /account/spreads/{someone else's id}` answered 204 and deleted every
  user's favourites of that spread** (`UserSpreadData::delete()` returned
  `execute()`, always true). Now 404, nothing removed.
- **Pressing Reset on New Draw / Recreate Draw kept the owner options**: a
  password typed earlier still locked the next reading.
- **The plugin's loopback check** read `http://evil.example\@127.0.0.1/` as
  127.0.0.1 (a browser goes to evil.example). Not exploitable on its own (the
  SPA checks too, and OriginGuard), but the server check is the real defence;
  it now matches the SPA's pattern.
- A deleted account's leftover session could still submit decks and deck
  systems, and its contact messages and spread submissions failed as a 500
  (they now go in as a guest's).
- 500s where the client should hear why: favouriting an unknown deck,
  registering a passkey already on file, a duplicate deck or deck-system name.
- Forgot Password showed the green "sent" notice when the request failed
  (rate limit, offline), and hid the form.
- The admin special-cards list, filtered to one deck, kept only that deck after
  a save or delete.
- The spread picker went blank instead of "No Spread (Free Draw)" after a reset;
  the first edit on a spread canvas couldn't be undone; every Turnstile widget
  loaded its own copy of Cloudflare's script; the cookie banner couldn't be
  dismissed with storage blocked; admin table sorting didn't work from the
  keyboard (now a button with `aria-sort`; checked visually); Space didn't log
  out from the mobile menu; the changelog said "no entries yet" while loading
  and when the request failed; the passkey list took in the registration reply,
  which has no credential id (it's reloaded now); a footer typo.
- Refusals that came out as 500s or false successes now say why: duplicate deck
  and deck-system names (409, renames included), unknown ids (404: approving a
  missing submission, special cards for a missing deck, contact and card-report
  updates), required names and titles missing or blanked (400); personal spreads
  with malformed or no positions (400; the card count follows the positions);
  saving a personal spread now shows the server's reason.
- Passkey management and Google unlink checked only for a session id, not the
  account behind it: now behind `UserAuth`. Google's `email_verified` read the
  string `"false"` as verified. A plugin token showed as used from issuance; a
  passkey sign-in returned a stale `last_login_at`.
- Dead code the coverage report turned up: `CardReport::isResolved()`,
  `PluginToken::isActive()`, `SpecialCardRepository::get()`.

### Open from §8 — owner's decisions: DONE 2026-10-28

The tests found these policy questions; the owner decided each, and all are
implemented with tests:

- **Sessions end on a password change** (the other devices; this browser gets a
  new id) **and on a reset** (all of them), **and when an account is deleted**
  (by its owner or an admin). Plugin tokens survive (Connected Apps revokes
  them). Needs `db/migrate_sessions_user_id.php` on prod **before** the backend
  deploy: `sessions.user_id`, back-filled from existing sessions.
- **Personal spreads are usable only by their owner** (or the owner's linked
  plugin); anyone else gets what an unknown id gets.
- **Unapproved decks and deck systems are admin-only**: 404 to everyone else by
  id, and no readings from a pending deck; admins' responses aren't cached.
- **Enumeration closed**: registration with a taken email answers like a new one
  and emails the owner; password login on an unknown email runs a dummy hash
  check; passkey login options list stand-in credential ids (keyed by a secret
  in `<data dir>/db/passkey-decoy.key`, created on first use) for an unknown
  email, so every address looks alike.
- **Admins can't remove their own admin flag or delete themselves**, and the
  accounts in `PROTECTED_ADMIN_EMAILS` (`.env`) can't be demoted or deleted by
  anyone, their own account page included (403).
- **Always one way to sign in** (`SignInMethods`): password login can be
  switched off with a passkey or Google linked; deleting the last passkey or
  unlinking Google is refused (409) when it would leave none. Google-only
  accounts see how to set a password by an emailed reset link.
- `/plugin/token` is rate-limited (10 a minute per IP); the contact form has
  Turnstile when it's configured; `APP_ENV` is read case-insensitively.

Still noticed, not decided: an approved deck that uses a pending deck system
shows that system's card names; a card report for a pending deck's card is
accepted (so reporting a broken image keeps working).

---

## 9. Product gaps (tools for readers — no interpretations)

**Ordered smallest change first** (re-ordered 2026-10-29): the order they're
likely to be built. Tags are [value] [size]. Each is a thin slice over existing
data, and each ships with its tests (AGENTS.md §8, §10): the coverage floors
only go up.

### Small

- [x] **9.1 "Sign out everywhere."** [medium] [S]
  A button in Account Settings over a small `POST /account/sessions/end-others`.
  `DbSessionHandler::destroyForUser()` (§8) already ends every session but this
  one; the change-password flow uses it. (Split out of the old privacy item.)
  - **DONE 2026-10-30 (5.1.0):** `POST /account/sessions/end-others` (session
    only, not a plugin token) ends every other session and rotates this one, as
    a password change does; Account Settings → Signed-in Devices says how many.
- [x] **9.2 Admin: close the card-report loop.** [low] [S]
  An open-reports count on the dashboard (the Errored Cards nav item shows none
  today: "no server-side count", `admin/DashboardView.vue`), and a "re-scanned →
  regenerate" action on Errored Cards rows wired to the existing
  `POST /admin/decks/{id}/images`, so a report is closed in one click.
  - **DONE 2026-10-30 (5.1.0):** the dashboard counts open reports, and
    `POST /admin/card-reports/{id}/regenerate` rebuilds **that card's** images
    (always: SFTP clients keep a scan's old timestamp, so a deck-wide incremental
    run could skip it) and resolves the report only if that worked; a missing
    scan or an already-resolved report is a 409. The page then lists the URLs to
    purge from Cloudflare, with a copy button.
- [x] **9.3 Card frequency for an account.** [high] [S]
  "Which cards keep turning up" over the user's own readings (optionally per
  deck / per time window). One `json_each` query and a panel; readers who
  journal ask for exactly this.
  - **DONE 2026-10-30 (5.1.0):** `GET /account/card-frequency?deck_id=&days=`
    (`json_each` over each reading's draw; by card name across decks, by card
    in one deck) and a panel on the Dashboard: top 10 with "show all", upright
    and reversed, deck filter, 30 days / 90 days / 1 year / all time.
- [x] **9.4 Export.** [low] [S–M]
  A print stylesheet for a reading (print-to-PDF covers PDF), and a JSON + CSV
  export of the whole journal from Account Settings.
  - **DONE 2026-10-30 (5.1.0):** a Print button and print layout on the
    reading page (cards face up and numbered, a table, the notes; light, no
    chrome), and `GET /account/readings/export?format=json|csv` from Account
    Settings (JSON in the `JournalExport` schema; CSV one row per card, UTF-8
    with a BOM for Excel, formula cells neutralised).
- [x] **9.5 Daily card** with a calendar view of past draws. [low] [S–M]
  Needs one draw stored per user per day (a reading flagged as the daily card,
  or a small table) and a calendar page.
  - **DONE 2026-10-30 (5.1.0)**, as the owner decided: signed-in only, deck and
    reversals chosen each day, and a real reading that **My Readings** labels
    "Daily card". `/daily` has today's draw and a month calendar;
    `GET|POST /account/daily-cards`; the reader's local date, accepted only
    within a day of the server's UTC date; a second draw for the day is a 409
    naming the first. A daily card is finalized (one card, no drawing more).
    Table `daily_cards` (`db/migrate_daily_cards.php`, run on prod before the
    deploy). Deleting the day's reading keeps the day taken (the owner's call:
    no redrawing until the card suits), so `reading_id` is `ON DELETE SET NULL`.

### Medium

- [ ] **9.6 Public deck pages** (`/decks/{id}`: name, artist, purchase link,
  card gallery). [medium] [M]
  `GET /decks/{id}/cards` already exists (approved decks only since §8). Preview
  before drawing, SEO landing pages (sitemap + `og.php` meta), and a natural
  home for "report a problem".
- [ ] **9.7 Table / presentation mode** for in-person readings: full screen,
  chrome hidden, large cards, keyboard flip and advance, plus a QR code for the
  share link (readers hand a phone to the client). [medium] [M]
  Frontend only.
- [x] **9.8 Journal fields and findability.** [high] [M]
  Add `question` (what was asked) and `querent` (who it was for, free text) to
  readings, plus tags; then search and filter (deck, spread, tag, date range,
  text in notes/question) in **My Readings**, which already pages (§2.6). A
  migration, query parameters on `GET /account/readings`, and the UI.
  - **DONE 2026-10-31 (5.2.0):** `question`, `querent` and tags (`reading_tags`,
    normalised lower-case, 10 × 30 chars) — owner-only everywhere (shared and
    locked payloads, og.php meta). My Readings filters: `q`, `deck_id`,
    `spread` (the recorded name; "Free Draw" for unplaced), `tag`, `from`/`to`
    (in the reader's zone via `tz`), `daily`; state in the URL. `GET
    /account/tags` and `GET /account/reading-spreads` feed the filters.
- [x] **9.9 Privacy levels.** [medium] [M]
  Unlisted / private (today: public-by-link or password), and an optional
  expiry on a guest reading. A migration and an access rule on every reading
  read (`ReadingController`, `og.php` share meta, the plugin routes).
  - **DONE 2026-10-31 (5.2.0):** link / password / private — a new
    `readings.is_private` beside the existing `password_hash`, `visibility`
    worked out in the SELECT (no backfill; old code runs on the new schema).
    Private and expired readings are a 404 to all but the owner (and their
    plugin token); og.php's share meta is generic for both
    (`Tarot\Service\ReadingShareMeta`). Guests may pick 1 / 7 / 30 days
    (`expires_at`); `retention.php` deletes them nightly with the year-old ones.
- [x] **9.10 Spread library polish:** categories/tags + search, fork-a-spread
  into My Spreads, per-position descriptions (today titles only). [low] [M]
  - **DONE 2026-10-31 (5.2.0):** `/spreads` library (category tabs, search,
    layout preview via the shared `SpreadLayoutPreview`, Draw with / Copy to My
    Spreads — `POST /spreads/{id}/copy`); admin-managed `spread_categories`
    (seeded in `schema.sql`; one still holding spreads can't be removed — 409);
    optional position descriptions (280 chars) on every spread kind, carried
    into the reading snapshot; New Draw groups public spreads by category.
    `GET /spreads` carries each spread's category inline (`category_name`,
    `category_sort_order`), so the public site needs no categories call; only
    the admin side lists categories on their own. Plugin 0.7.0 groups its
    spread pickers by category and shows position descriptions.
    "Spreads" took "Create a Spread"'s place in the top nav (six links wrapped).

### Large

- [ ] **9.11 Pick-your-own-cards draw.** [high] [L]
  Fan the shuffled deck face-down and let the user tap cards to fill positions
  in order, instead of the server dealing the top N. Server stays authoritative
  (shuffle once per reading, store the permutation, reveal on pick). The
  single biggest ritual gap for people who actually do readings.
- [ ] **9.12 PWA offline for saved readings.** [low] [L] `sw.js` is a
  pass-through today (correctly, to avoid stale deploys); a reading-scoped cache
  is a later project.

### Planned next (the owner, 2026-10-30)

- [x] **9.13 Retire the manual changelog.** Remove the hand-written changelog
  and show the changelog **only in the admin panel**, as the owner's Kindred
  Nights and Senpan App Suite projects do. Check how they source it first
  (`CHANGELOG.md`, which CI already turns into GitHub Releases, is the likely
  source); then remove the public `/changelog` page, the admin Changelog CRUD
  and their API/table if nothing else needs them (a migration that drops a
  table is the first non-additive one: back up, and deploy the code first).
  - **DONE 2026-10-30 (5.4.0):** `CHANGELOG.md` is the only changelog. Vite's
    `virtual:changelog` (`frontend/config/changelog-plugin.ts`, parsed by
    `utils/changelog.ts`) reads it at build time, and the admin dashboard's
    version tag opens it in `ChangelogDialog` (lazy chunk; nothing public links
    to it). The page, the admin CRUD, their routes and the table are gone; old
    entries dropped (owner). `migrate_drop_changelog.php` runs **after** the
    deploy.
- [x] **9.14 Turnstile on New Draw for signed-out visitors** (bot use), while
  the FFXIV plugin can still draw without a signed-in user. Design question to
  settle first: how the plugin is let through without opening a bypass any bot
  can copy (a plugin-only route behind its own rate limit, a per-install token,
  or Turnstile skipped only for linked-plugin bearer tokens) — the plugin
  shouldn't need a browser challenge.
  - **DONE 2026-10-30 (5.4.0), the owner's design: a per-install draw key got
    behind Turnstile.** With Turnstile configured, `POST /readings/generate` and
    `POST /readings` (`ReadingController::refuseDraw()`) let through a signed-in
    user (session, or a linked plugin's `tg_pat_` token), an unlinked plugin's
    draw key (`Authorization: Bearer tg_dk_…`), or a passed challenge
    (`turnstile_token`, New Draw and Recreate Draw). The plugin gets its key on
    `/draw-key` (Turnstile, then PKCE to its loopback listener:
    `POST /plugin/draw-keys/authorize` → `/token`). Keys are stored hashed in
    `plugin_draw_keys`, open nothing but draws (their readings are guests'),
    and are limited to 10/key/minute, 300/key/day and 30/IP/minute; revoking
    one is a hand-run `UPDATE … SET revoked_at`. Plugin 0.8.0. **No bypass for
    older unlinked plugins:** they get an "update the plugin" message.
  - **Follow-ups, done the same day (owner):** `retention.php` deletes key codes
    that expired unredeemed (`DrawKeyRepository::purgeUnredeemedCodes()`); the
    privacy page names Turnstile and every page using it (a spec fails when a
    new page uses the widget unnamed) and what is kept of a draw key; plugin
    0.8.0 shows an unlinked install upgraded from an earlier version a one-time
    "Drawing now needs a draw key" pop-up with the steps. Still open, not
    asked: expired `plugin_auth_codes` (the account link's) aren't cleaned up
    either.

- [x] **9.15 Admin Deck Systems: the same split as Spreads.** Its add/edit
  form (the 78-card editor) is a panel atop the list page, not in the URL, so
  the browser's Back leaves the page and loses unsaved work. Route it like
  admin Spreads (`/admin/deck-systems/:id/edit` + a submissions page,
  `useReturnTo`). (Admin Decks edits in a modal and doesn't have this bug.)
  - **DONE 2026-10-30:** `/admin/deck-systems` (list), `/new` and `/{id}/edit`
    (the editor, with an unsaved-changes guard, `useUnsavedChanges`),
    `/submissions` (with a card preview); dashboard "Deck System Submissions"
    (`pendingDeckSystems`). Saving no longer sends `approved` back, which could
    undo another admin's approval.

### Declined

- ~~**Reversal rate setting**~~ (none / light / standard / heavy) — **declined
  2026-10-29, stays an on/off toggle.** Readers consulted said choosing a
  percentage takes the spirit out of the draw. Reversals are already modelled
  on a reader's physical "cut and turn" (`ReadingService::cutAndTurn()`: random
  packets, each turned with probability `REVERSAL_TURN_PROBABILITY`, so
  reversals come in realistic runs). Don't re-propose a rate control.

---

## 10. Suggested order

The original order (prod reconnaissance → §0, §1, §2, §3, §4, §8.1) is done
except §1.7's first live run. **Remaining, revised 2026-10-28 (end of day):**

1. ~~§1.7's first run~~ confirmed 2026-10-30; the Dependabot secrets are in
   (2026-10-29). Owner's housekeeping left: §0 worktrees, branches, stash.
2. ~~**Dependencies:**~~ done 2026-10-28 (§7). TypeScript 7 waits for vue-tsc
   (owner); `scrollBehavior` → `ScrollRestoration` waits for it to leave
   `vue-router/experimental` (owner's call, §7.3).
3. ~~**Tests**~~ done 2026-10-28 (§8). PHPUnit 13.4.5 is the newest stable.
   The owner's decisions it found are listed under "Open from §8".
4. **Open from §6.1:** keyboard favouriting (a design call).
5. **Features:** in §9's order, smallest first.

---

## Appendix A — Claims that did not survive verification

Listed so nobody re-chases them.

- "Favorites orphan when a spread is deleted" — **false.** `AdminSpreadController`
  (`deleteSpread`; was `AdminController.php:635` before §4.5) and
  `AccountController::deleteSpread` call `FavoriteSpreadData::removeBySpread()`;
  deck favorites cascade via FK.
- "Admin user list leaks password hashes" — **false.** `Structure/User.php`
  never declares `password_hash`; `UserData::hydrate` drops it.
- "`jsdom` is an unused dependency" — **false.** `markdown.spec.ts` pins
  `@vitest-environment jsdom` for the DOMPurify XSS contract test.

## Appendix B — How the numbers were measured

Re-run these after changes.

```bash
# §1.2 — deprecation repro (from backend/)
php -d error_reporting=E_ALL -d display_errors=1 -d log_errors=0 -r \
  'require "vendor/autoload.php"; $d = new Tarot\Data\DeckData(Tarot\Database\Connection::getInstance()); echo "hydrated ", count($d->retrieve()), " decks\n";' \
  2>&1 | sort | uniq -c | sort -rn | head
```

```bash
# §1.4 — PHP-zone timestamp writes
grep -rn "date('Y-m-d H:i:s')" backend/includes backend/api
# §2.4 — SELECT * in the Data layer
grep -rn "SELECT \*" backend/includes/Tarot/Data
# §4.1 / §4.2 / §4.6 — duplication counts (from frontend/)
grep -rn "padStart(4" src --include=*.vue
grep -rn "fetch('/api" src --include=*.vue --include=*.ts | grep -v __tests__ | wc -l
grep -rhoE "rgba\(201, ?162, ?75" src --include=*.css --include=*.vue | wc -l
```

```bash
# §7 — freshness and advisories
cd frontend && npm outdated
cd backend && composer outdated --direct && composer audit
```

```js
// §3.3 — CSS class census (save as css-usage.mjs, run from frontend/ after `npm run build`)
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
const dist = readdirSync('dist/_app').filter((f) => /^index-.*\.css$/.test(f))[0]
const css = readFileSync(join('dist/_app', dist), 'utf8')
const classes = new Set([...css.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]))
function walk(d, out = []) {
  for (const f of readdirSync(d)) {
    const p = join(d, f)
    statSync(p).isDirectory() ? walk(p, out) : /\.(vue|ts|html)$/.test(f) && out.push(p)
  }
  return out
}
const src = walk('src').map((p) => readFileSync(p, 'utf8')).join('\n') + readFileSync('index.html', 'utf8')
let used = 0
const unused = {}
for (const c of classes) {
  if (src.includes(c)) used++
  else unused[c.split('-')[0]] = (unused[c.split('-')[0]] || 0) + 1
}
console.log(`${(css.length / 1024).toFixed(0)} KB, ${classes.size} selectors, ${used} referenced (${((100 * used) / classes.size).toFixed(0)}%)`)
console.log(Object.entries(unused).sort((a, b) => b[1] - a[1]).slice(0, 10))
```

Baseline on 2026-10-27: `407 KB, 780 selectors, 310 referenced (40%)`;
top unused prefixes `is(367) fa(66) has(6) hero(5) panel(5) toast(4)`.
