# Changelog

All notable changes to **TarotGen.io** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** This project predates formal release tagging, so the entries below
> were reconstructed from the Git history. Dates reflect the day the work landed
> on the development branch. The application version is sourced from
> [`frontend/package.json`](frontend/package.json) and is shown on the admin
> dashboard, where it opens this file's notes (read in at build time; there is
> no other changelog to update).

**Releases & tags (going forward).** Each version now corresponds to a Git tag and
GitHub Release named `v<version>` (e.g. `v4.1.0`), created **automatically** by the
CI `release` job (`.github/workflows/ci.yml` → `.github/scripts/release.sh`): on a
push to `main`, after the full gate passes, if the current version
(`frontend/package.json`) has no release yet it is tagged and released, with the
release body taken from that version's section below. So bump the version and
update its section here in the same commit — no manual tagging.

## [Unreleased]

### Changed

- Node 26, now the LTS, is the minimum for building the frontend
  (`frontend/.nvmrc`, `engines`); CI builds and tests on it.

## [5.4.0] — 2026-10-30

Signed-out drawing now asks for a Turnstile check, and the FFXIV plugin gets a
draw key so it can keep drawing without one. The changelog moves to the admin
dashboard.

> **Deploy in this order.**
>
> 1. **Before the backend:** copy `migrate_plugin_draw_keys.php` into the
>    droplet's `db/` and run it as `www-data` after `sudo tarotgen-backup`. It
>    only adds a table, and 5.3.0 runs on it unchanged.
> 2. Deploy `-Target both`, so the key page and its API arrive together.
> 3. **Straight after,** release the plugin (`-Target plugin`, 0.8.0). Once step
>    2 is live, unlinked plugins older than 0.8.0 can't draw; they're told to
>    update. Plugins linked to an account keep drawing.
> 4. **After the deploy:** back up again and run `migrate_drop_changelog.php`,
>    which drops the old `changelog` table. It is the first migration that
>    removes something, so the code that stopped reading the table goes first.

### Added

- **A Turnstile check on New Draw and Recreate Draw for signed-out visitors,**
  against bots drawing in bulk. Signed-in readers never see it.
- **Draw keys for the FFXIV plugin.** An install that isn't linked to an
  account gets a key once, from a page with the same check (`/draw-key`), and
  draws with it from then on. A key only draws: it opens no account, its
  readings are a guest's, and it is rate-limited per key and per address.
- **FFXIV plugin 0.8.0:** New Reading offers "Get a draw key" when the plugin
  has neither a key nor a linked account. The key is stored encrypted, like
  the account link, and a key or link the site no longer accepts is forgotten,
  with the site's reason shown. An unlinked install upgraded from an earlier
  version gets a one-time pop-up explaining the change, with the steps to get
  a key (or link an account).
- **The changelog is in the admin dashboard:** the version tag opens these
  notes, read from `CHANGELOG.md` when the site is built.

### Changed

- Where Turnstile is configured, a reading made without a signed-in user needs
  a passed check or a plugin draw key. Unlinked plugins older than 0.8.0 are
  told to update.

- The nightly retention run also deletes draw-key codes that expired without
  being redeemed.
- The privacy page names Cloudflare Turnstile and where it runs, and says what
  is kept of a plugin draw key.

### Removed

- The hand-written changelog: the public `/changelog` page (it now goes to the
  home page), the admin editor, their API routes and the `changelog` table.

## [5.3.0] — 2026-10-30

### Changed

- **Admin Spreads is split into pages:** the list, the editor (`/admin/spreads/new`,
  `/admin/spreads/{id}/edit`), Categories and Submissions. Editing a spread is in
  the address now, so Back, Cancel and Save return to the list instead of the
  dashboard. The dashboard menu shows waiting spread submissions.
- **Admin Deck Systems likewise:** the list, the editor (it now asks before you
  leave with unsaved changes) and Submissions (with a card preview); the
  dashboard menu shows waiting deck-system submissions.

### Fixed

- Saving a deck system in the admin could undo another admin's approval made
  while the form was open.

## [5.2.0] — 2026-10-30

A journal you can search, readings you can keep private, and a library of
spreads to browse. No interpretations, as ever: position descriptions say what
a position asks, never what a card means.

> **Before deploying the backend, run both migrations** (as `www-data`, after
> `sudo tarotgen-backup`): `php db/migrate_reading_journal.php` and
> `php db/migrate_spread_library.php`. Every reading and spread read needs the
> new columns.

### Added

- **Journal fields.** A reading can record the question asked, who it was for,
  and tags. They're yours alone: never shown on a shared reading or its link
  preview. Set them when drawing or later, on the reading or in My Readings.
- **Find your readings.** My Readings has a filter bar: search (name, notes,
  question, who it was for), deck, spread, tag, dates and daily cards. Filters
  stay in the address, so Back works.
- **Who can open a reading:** anyone with the link (the default), anyone with
  the password, or **private** (only you; everyone else sees "not found", and
  its link preview stays generic).
- **Guests can have a reading deleted sooner:** after 1, 7 or 30 days, instead
  of the usual year. The privacy page says so.
- **Spreads library** (`/spreads`): browse the public spreads by category, search
  them, preview each layout and its positions, then **Draw with this spread** or
  **Copy to My Spreads**. "Spreads" replaces "Create a Spread" in the top menu;
  the library links to it.
- **Position descriptions:** each position in a spread can say what it asks
  ("What's blocking you"). They show while drawing, on the reading and in print.
- New Draw's spread picker groups public spreads by category.
- **FFXIV plugin 0.7.0:** its spread pickers group by category too, and the
  reading panel shows position descriptions. (Released separately; it also
  works with older servers, without the grouping.)
- **Admin:** a category manager (add, rename, reorder, remove; a category still
  holding spreads can't be removed), a category on every public spread, and a
  "Publish in" choice when approving a submitted spread.

### Changed

- The journal export includes question, querent, tags and who can open each
  reading; the CSV gains those columns after Reading Name.

## [5.1.0] — 2026-10-30

The first features on the new foundation: a daily card, a view of which cards
keep turning up, a printable reading and a journal export, signing out of other
devices, and a one-click fix for reported card images. No interpretations, as
ever: these are tools for keeping and reviewing your own practice.

> **Before deploying the backend, run `php db/migrate_daily_cards.php`** (as
> `www-data`, after `sudo tarotgen-backup`). Every reading read now checks for
> a daily card, so it needs the new table.

### Added

- **Daily card.** Signed-in readers draw one card a day from the **Daily Card**
  page, choosing the deck and reversals each time, and look back over a month
  calendar of past draws. Each is a real one-card reading, so notes and sharing
  work, and **My Readings** marks it "Daily card". The day is the reader's own
  local date, and it stays drawn even if its reading is deleted: one card a day,
  no redrawing.
- **Card frequency** on your Dashboard: which cards turn up most in your
  readings, upright and reversed, for one deck or all of them, over the last 30
  days, 90 days, year or all time.
- **Print a reading.** A Print button and a print layout: every card face up and
  numbered, a table of positions and cards, and your notes, in ink-friendly
  light colours. Print to PDF for a file.
- **Export your journal** from Account Settings, as JSON (every reading with its
  spread and cards) or CSV (one row per card, ready for a spreadsheet).
- **Sign out of all other devices** from Account Settings.
- **Admin:** the dashboard counts open card reports, and Errored Cards has
  **Regenerate & resolve**: once a new scan is uploaded, one click rebuilds that
  card's web images and closes the report, then lists the image URLs to purge
  from the CDN cache.

## [5.0.0] — 2026-10-28

A new look and a rebuilt foundation, and the baseline new features are
versioned from. The app moves from the old mystical-purple theme to an
**elevated celestial minimalism** look, with its interactive UI on **Reka UI**
headless primitives. Underneath, a full codebase audit: private data out of the
web root, nightly backups, an enforced Content-Security-Policy, faster images
and CSS, every dependency brought current, and new tooling and CI. The test
suite grew from about 370 tests to over 1,700, with coverage floors and an
end-to-end smoke run, and the tests found and fixed more than thirty bugs.
Card reports now carry a reason, so admins know what each reported card needs.

### Added

- **Reasons on card reports.** Reporting a card from the lightbox now opens a
  short form: pick what's wrong (*scan artefact*, *cropped or crooked*, *poor
  image quality*, *wrong card*, or *something else*) and optionally describe it;
  a description is required for *something else*. The admin **Errored Cards**
  page shows each card's reasons at a glance, plus a **View reports** dialog with
  every report's reason, details, and date. Reasons are grouped by round: when a
  card an admin resolved is reported again, the earlier reasons are shown as
  already resolved, so only the new problem reads as current. The FFXIV plugin
  (0.6.0) has the same form; older plugin builds can still report, without a
  reason. **Run `php db/migrate_card_report_submissions.php` before deploying the
  backend** (it adds `card_report_submissions` and `card_reports.round`).
- **Nightly database backups**, integrity-checked and kept for 14 days, and a
  **retention job** that removes guest readings after a year (readings made while
  signed in are never touched), as the privacy page states.

### Changed

- **Reskin — "elevated celestial minimalism."** Neutral charcoal surfaces with
  gold/silver line-art and a **single aqua accent** reserved for the primary
  action + active/selected states. A new design-token system in
  [`frontend/src/assets/tokens.css`](frontend/src/assets/tokens.css) (`--myst-*`)
  is mapped onto Bulma's own CSS variables so every Bulma component inherits the
  theme, with solid WCAG-AA text tiers (no more opacity-dimmed text).
- **Display face.** All heading/display type is now the self-hosted **Crucial**
  (a single weight declared across the 400-700 range), driven by the one
  `--myst-heading-font` token.
- **New brand mark.** A gold four-pointed **star-in-ring** celestial mark
  replaces the old icon everywhere: the favicon (now an SVG master +
  `favicon.png`), the PWA icons (`icon-192/512`, maskable, apple-touch —
  regenerated by [`backend/scripts/gen_icons.php`](backend/scripts/gen_icons.php)
  from a medallion + a bare-mark master), the boot-loader orb, and the FFXIV
  plugin icon. The boot spinner was recolored to the gold-on-charcoal scheme.
- **Reka UI.** Dialogs, dropdown/context menus, popovers, tooltips, comboboxes,
  selects, switches, segmented controls, number fields, disclosures, pagination,
  and toasts are now Reka UI primitives wrapped in shared components
  (`BaseModal`, `ConfirmDialog`, `DeckComboBox`/`SpreadComboBox`,
  `SegmentedControl`, `ToggleSwitch`, `NumberField`, `Tooltip`, …), replacing
  hand-rolled Bulma/native widgets for consistent behavior + accessibility.
- **Admin dashboard** redesigned into a console layout (a grouped side-menu with
  live counts, insights up top) instead of the old stat-card grid.
- The three spread-canvas editors (admin, Recreate Draw, Arrange) were
  consolidated onto one shared `SpreadCanvasEditor` component.
- **My Readings loads a page at a time** instead of every reading at once.
- Every API error is a JSON `{ "error": … }`, Slim's own 404/405 included, and the
  API reference at `/api/docs` now describes every endpoint's response as sent.
- The admin API is split per resource (nine controllers instead of one of 1,500
  lines), and all frontend API calls go through one client.

### Performance

- Authenticated page loads no longer serialize on SQLite's single writer. The
  session row's sliding expiry is now refreshed **at most once every 5 minutes**
  instead of on every request (`DbSessionHandler`), and the connection runs
  `PRAGMA synchronous = NORMAL` under WAL so each remaining write skips a full
  fsync. On the client, the spread-selector fetch waterfall was flattened
  (public list + personal spreads + favorites now load together) and the
  favorites / favorite-decks fetches are de-duplicated.
- **Card images are cached** at the edge and in the browser, and the lightbox and
  image export use generated full-size **WebP** instead of the master PNG scans.
- The CSS bundle shrank by 29% (a modular Bulma build), thumbnails load lazily,
  and readings are indexed by time.

### Removed

- Retired the unused heading-font switcher (`useHeadingFont`) left orphaned when
  the picker UI was removed — along with its three alternate self-hosted fonts
  and a **dead Cinzel Google-Fonts request** that loaded on every page. A single
  self-hosted display face now drives all heading type.
- Dead Bulma navbar-dropdown CSS (the nav is a Reka `DropdownMenu` now), two
  empty `<style scoped>` blocks, and a duplicated `.spread-canvas` rule.
- The retired plugin share relay's four tables and six indexes, the legacy deck
  columns, and an unused second type system on the frontend.

### Security

> **Before deploying the backend, run `php db/migrate_sessions_user_id.php`**
> (as `www-data`, after `sudo tarotgen-backup`). The new session code records
> whose session each is; without the column no session can be saved.

- **Changing your password signs out your other devices**, and a password reset
  signs out every session; deleting an account (yours, or by an admin) ends its
  sessions. Linked plugins keep working; revoke them under Connected Apps.
- **No more account enumeration.** Registering with an address that already has
  an account answers like any sign-up (the owner gets an email instead), a
  password login for an unknown address takes as long as a wrong password, and
  passkey sign-in options look alike for every address.
- **Admins can't remove their own admin rights or delete themselves**, and
  accounts listed in `PROTECTED_ADMIN_EMAILS` can't be demoted or deleted by
  anyone.
- **You can never lock yourself out.** Password, passkeys and Google: switching
  password login off, deleting your last passkey or unlinking Google is refused
  when it would leave no way to sign in (password login can now be switched off
  with only Google linked). Accounts made with Google and no password are shown
  how to set one, by an emailed link.
- `APP_ENV` is read case-insensitively, so `Production` can't run as development.
- **Private data lives outside the web root.** The database and `.env` moved to
  a separate data directory (`TAROT_DATA_DIR`), and the web server refuses the
  code and tooling directories outright.
- **An enforced Content-Security-Policy** on every page, with a fresh nonce per
  response, instead of a report-only one.
- The FFXIV plugin's account-link check accepts only real loopback addresses
  (a crafted `\@127.0.0.1` URL could pass the server's check before).
- A deleted account's leftover session can no longer submit anything.
- **Personal spreads are private to their owner.** A reading could be made from
  anyone's personal spread by its id; now only the owner (or their linked
  plugin) can use it, and anyone else gets the same answer as for an unknown id.
- **Unapproved submissions are visible only to admins.** Pending decks and deck
  systems could be read by id; now everyone else gets a 404, and readings can't
  be drawn from a pending deck.
- The contact form is protected by Turnstile (when configured), and the
  plugin's token exchange is rate-limited.
- Security advisories fixed in the dependencies: DOMPurify, ProseMirror,
  TipTap, `slim/slim` (route arguments decoded twice) and `symfony/yaml`.

### Fixed

- **FontAwesome bandwidth.** Switched from the monolithic `@awesome.me` Pro kit
  (~762 MB per install) to the per-style `@fortawesome/pro-*-svg-icons` packages
  — the same icons at a fraction of the download, so cold CI installs no longer
  blow the Font Awesome bandwidth quota.
- Old-theme colors that survived the reskin: the reading-image **PNG export**
  (old purple panels + old-gold borders), the Markdown editor's sub-AA
  placeholder, decorative aqua on the plugin feature icons, and a scatter of
  stale `var(--token, <old-value>)` fallbacks — all brought onto the new palette.
- **Deck and spread search never filtered:** typing in either picker showed
  every entry. It filters now, and Enter picks the first match.
- **Every deck list failed** once a deck system still in use was deleted, or a
  deck's artist or purchase link was cleared.
- **Deleting someone else's personal spread** was refused in effect but answered
  "deleted", and removed every user's favourites of it.
- **Reset on New Draw and Recreate Draw kept the owner options**, so a password
  typed earlier still locked the next reading.
- Forgot Password reported success when the request had failed; the changelog
  page said "no entries yet" while loading and when it couldn't load.
- Requests that should be refused with a reason are, instead of failing with a
  server error or a false success: duplicate deck and deck-system names (409),
  unknown ids (404: approving a missing submission, a special card for a missing
  deck, contact and card-report updates), required names and titles left out
  (400), favouriting an unknown deck, registering a passkey that's already on
  file, and a contact message or spread submitted from a deleted account's
  session.
- A personal spread saved with malformed or no positions stored a 0-card spread;
  its card count now always follows its positions.
- A plugin token showed as "last used" from the moment it was issued.
- Admin: the special-cards list lost the other decks after a save while
  filtered; table sorting now works from the keyboard.
- A PHP warning on every login and logout; timestamps mixed PHP's time zone with
  SQLite's UTC; a reading-id collision was a server error; the spread picker
  blanked after a reset; the first edit on a spread canvas couldn't be undone;
  the Turnstile script loaded once per widget; the cookie notice couldn't be
  dismissed with storage blocked; Space didn't log out from the mobile menu.

### Developer experience

- **Tests:** about 1,700 (from about 370): HTTP tests for every API controller,
  component and view tests for the whole frontend, and a Playwright smoke run
  on the built app (draw, report a card, find it as admin). CI fails below a
  coverage floor: currently 93.6% of backend lines and 97.5% of frontend lines.
- **Static analysis and linting:** PHPStan level 8 with strict rules over the app
  and its tests, Rector, PHP CS Fixer, composer-unused and require-checker; on
  the frontend Knip, Stylelint with a palette rule, and template accessibility
  linting (every form control is labelled); analyzers with warnings as errors for
  the plugin; CodeQL and Dependabot.
- **Dependencies current:** swagger-php 7, Vitest 5, happy-dom 21, PHPUnit 13.4,
  Vue 3.5 and Vite 8 (TypeScript stays on 6 until Vue's tooling supports 7).
- **Deploys** upload one checksummed tarball per target over two SSH
  connections instead of file by file.

## [4.1.0] — 2026-07-04

An in-game companion and a card-quality feedback loop. This release adds a
**Final Fantasy XIV Dalamud plugin** that talks to the existing API, the
server-side features that back it (optional account linking and a chatless
reading-share relay), and a way for anyone to flag a card scan that needs
re-scanning. Every change is **additive** — no existing endpoint or behavior
changed — so first-party session-cookie auth is untouched.

### Added

#### FFXIV Dalamud plugin (`plugin/`)

- A new **self-contained C# subproject** (Dalamud **API 15**, **.NET 10**, built
  and packaged independently by DalamudPackager — the site deploy never touches
  it) that draws and views TarotGen readings **in-game**: pick a public spread or
  a free draw, render the spread canvas with positioned/rotated cards, open any
  reading by its share code, and click a card for a full-res lightbox.
- **Optional account linking** — an OAuth-style **loopback + PKCE** flow opens the
  site's authorize page in the browser and hands a revocable token back to a local
  listener (no copy-paste). Linking unlocks locking/finalizing a reading,
  favorites-sorted pickers, and "My Readings". Tokens are **DPAPI-encrypted** at
  rest and revocable from the account's "Connected Apps" screen.
- **Chatless reading share** — push a reading to another plugin user, who gets a
  passive in-game *"‹A› wants to share a Tarot reading"* popup (no chat, no
  clipboard). Recipients pick a consent tier (party members / anyone / off) and
  can block a sender.
- **Published to a self-hosted "custom repo"** (`https://tarotgen.io/plugin/repo.json`)
  that testers add under Dalamud's settings; a new `deploy.ps1 -Target plugin`
  builds Release and uploads `latest.zip` + `icon.png` + `repo.json`.

#### Backend — plugin account linking

- **`plugin_tokens`** (long-lived, revocable, hashed) and **`plugin_auth_codes`**
  (short-lived, single-use PKCE codes) tables, a `PluginTokenService`, and the
  `POST /api/plugin/authorize` (session) → `POST /api/plugin/token` (public
  code→token swap) endpoints. An **`AccountAuth`** middleware accepts *either* a
  session *or* a plugin Bearer token on the read-only account routes a plugin is
  allowed to reach, while destructive/profile routes stay session-only. Linked
  tokens are listed and revoked via `GET`/`DELETE /api/account/tokens`.

#### Backend — chatless share relay

- **`plugin_clients` / `plugin_messages` / `plugin_blocks`** tables, a
  **`ClientAuth`** middleware, and `POST /api/plugin/guest-authorize` (mint a
  no-account client token), `POST /api/plugin/clients/register`,
  `GET /api/plugin/inbox`, `POST /api/plugin/share`, and
  `POST /api/plugin/clients/block`. Delivery is a short-TTL server inbox drained
  by a jittered background poll, so a plain short-poll behaves as a push.
  Harassment controls: a server-enforced `nobody` tier + block list,
  per-sender/per-IP throttles, and a distinct-recipient fan-out cap;
  party/friends filtering happens client-side (the server never sees game state).

#### Backend — card scan-error reports

- **`card_reports`** table and a public **`POST /api/card-reports`** endpoint.
  Reporting a card upserts one row per `(deck, card)` and bumps a counter;
  admins review them at **`GET`/`PATCH /api/admin/card-reports`**.

#### Frontend

- A browser **"Connect the plugin"** authorize page (`/authorize`) offering
  *log in to link your account* **or** *continue as a guest*, and a
  **"Report scan issue"** button in the card lightbox with success feedback.
- An admin **"Errored Cards"** page (nav + dashboard) listing reported scans
  (thumbnail, deck, card, count, dates) with resolve/reopen, plus the
  **"Connected Apps"** revoke UI in Account Settings.

### Security

- The share relay never stores a plaintext roster of players: a recipient's
  `Character@World` is kept only as a **keyed HMAC hash** (optional
  `PLUGIN_IDENTITY_SALT`). And `POST /api/plugin/share` returns a **uniform
  response** whether or not a recipient exists / accepts / has blocked the sender,
  so it can't be used to probe who runs the plugin.
- Card reports are rate-limited two ways — an overall per-IP cap plus a
  per-IP-per-card cooldown so a card's count can't be inflated.
- The plugin authenticates with **Bearer tokens** (a per-account token for account
  routes, a per-install client token for the relay) over HTTPS — additive to, and
  separate from, the first-party session cookie. A native `HttpClient` sends no
  `Origin`, which `OriginGuard` already permits.

### Developer experience

- New optional env var **`PLUGIN_IDENTITY_SALT`** (documented in
  `backend/.env.example`); new one-off migrations `db/migrate_plugin_tokens.php`,
  `migrate_plugin_clients.php`, and `migrate_card_reports.php`.
- Two new OpenAPI security schemes (`pluginToken`, `clientToken`) and tag groups
  (`Plugin`, `Cards`, `Admin · Card Reports`); `README.md` and `AGENTS.md` now
  document the `plugin/` subproject and its `deploy.ps1 -Target plugin` target.

## [4.0.0] — 2026-07-01

API normalization and machine-readable documentation. The HTTP API is reshaped
into a consistent hybrid-REST design and published as an interactive reference.

> **Breaking (API).** Nearly every endpoint path — and several methods — changed.
> This is a first-party API (session-cookie auth, no external consumers) and the
> SPA was migrated in lockstep, so no backward-compatible aliases are kept. This
> warrants a **major** version bump (→ 4.0.0) when released.

### Added

- **OpenAPI 3.1 specification** ([`backend/openapi.json`](backend/openapi.json))
  generated from `zircote/swagger-php` attributes on every controller operation
  and on each `Structure` schema. Root metadata — API info, the `/api` server, the
  `sessionCookie` security scheme, and the tag groupings — lives in
  `backend/api/Routes/OpenApiSpec.php`.
- **Interactive API reference served by the app itself:** [Scalar](https://scalar.com/)
  at **`GET /api/docs`**, backed by the raw spec at **`GET /api/openapi.json`**.
  No separate hosting or build step — the PHP app serves both at runtime wherever
  the API is deployed (Scalar's viewer script loads from a CDN).
- **`composer docs`** regenerates the committed spec, and a new `OpenApiSpecTest`
  (PHPUnit) fails if the committed `openapi.json` drifts from the attributes —
  keeping the docs honest the same way `composer stan`/`lint` keep the code honest.
- **Generated frontend types:** `frontend/src/types/api.generated.ts`, produced from
  the spec by `npm run gen:types` (openapi-typescript), plus a central
  `frontend/src/api/endpoints.ts` module that all SPA call sites now route through.
- CI (`.github/workflows/ci.yml`) now verifies both artifacts are current: it
  regenerates the spec (`composer docs`) and the types (`npm run gen:types`) and
  fails on any diff.

### Changed

- **Resources are now plural nouns:** `/deck`→`/decks`, `/deck-system`→`/deck-systems`,
  `/spread`→`/spreads`, `/reading/{id}`→`/readings/{id}`, `/contact`→`/contacts`, and
  the passkey routes move under `/auth/passkeys`.
- **Consistent method semantics:** creates return **`201`** and deletes return
  **`204`**; a random reading is `POST /readings/generate` and a custom one is
  `POST /readings`; `finalize` is now `POST /readings/{id}/finalize` (was `PUT`).
- **Flag toggles became `PATCH` on the resource** instead of bespoke `POST` actions —
  a deck's `usable`, a user's `is_admin`, a contact's `is_read`, and the account
  display name (`PATCH /account`). Genuine state transitions/commands stay as
  `POST /<resource>/{id}/<verb>` (`…/approve`, `…/draw`, `…/unlock`, …).
- **Credential/session endpoints consolidated under `/auth/*`** (`/auth/login`,
  `/auth/register`, `/auth/activate`, `/auth/me`, …); the password change moved to
  `POST /account/change-password`.
- **Retention cleanup is now a bulk delete:**
  `DELETE /admin/readings/all?older_than_days=` returning `{ "deleted": N }`.
- Special cards are nested under their deck
  (`/admin/decks/{deck_id}/special-cards/{card_id}`), and favorites are removed by
  path (`DELETE /account/favorites/{type}/{id}`) rather than by request body.
- The SPA API client tolerates empty `204` responses and gained a `patch` helper;
  the backend deploy payload now ships `openapi.json` so the runtime docs work in
  production.
- **`API.md` rewritten** as the hybrid-REST *conventions* guide that points at the
  generated reference; `AGENTS.md` and `README.md` document the `composer docs` /
  `npm run gen:types` workflow and the `/api/docs` URL.

## [3.4.0] — 2026-06-30

Backend type-safety, modernization, and API documentation. No user-facing or API
behavior changes — this release hardens the PHP backend and its tooling.

### Added

- **Full REST API reference** in [`API.md`](API.md), linked from the README.
  Documents every `/api/*` endpoint with its method, auth requirement, request
  body, responses, rate limits, and caching behavior.
- **`AbstractController::parsedBody()`** — a single, typed
  (`array<string,mixed>`) accessor for JSON request bodies, replacing the
  repeated `$request->getParsedBody() ?? []` idiom across every controller and
  giving the static analyzer a real offset-accessible type at each call site.
- **Typed query helpers** in `StatsService` (`scalarInt`/`row`/`rows`) and
  `AbstractData::query()`, collapsing the repeated
  `query(...)->fetch*()` + `?: []` boilerplate and making PDO's exception-mode
  contract explicit.

### Changed

- **PHPStan raised from level 6 to level 8** (`backend/phpstan.neon`), enforced
  in CI via the existing `composer stan` step. Closed ~110 newly-surfaced
  findings; the baseline now holds only 6 deliberately-defensive guards and an
  interface-mandated `string|false` signature (down from 7).
- **Strengthened data integrity at the boundaries.** `GoogleOAuthService` now
  normalizes Google's raw payloads to their documented array shapes;
  `UserData::findAuthByEmail()` returns a concrete `{user_id,password_hash,is_active}`
  shape; the Data layer's list-returning methods are now `list<…>`-precise; and
  `Env::get()` carries a conditional return type so callers with a non-null
  fallback get a guaranteed `string`.
- **PHP 8.x modernization to cut boilerplate:** constructor property promotion
  across the Repository layer (now `readonly`), `StatsService`, `AbstractData`,
  `ApiException`, and the remaining controllers; `#[\Override]` on the
  `Structure` JSON serializers and every `DbSessionHandler` interface method; and
  `#[\SensitiveParameter]` on all password / reset-token parameters in
  `AuthService` and `PasswordPolicy` so secrets are redacted from stack traces.
- **`Structure` entities are now immutable value objects.** Every property uses
  PHP 8.4 asymmetric visibility (`public private(set)`): reads are public
  (`$user->user_id` instead of `$user->getUserId()`), writes are private. PDO's
  `FETCH_CLASS` hydration and the array constructor still populate them, but
  external code can no longer mutate an entity after it's built. This removed
  ~250 hand-written getters and their call sites across the backend; the small
  number of genuinely computed accessors (e.g. `Deck::getEffectiveTotalCards()`,
  `Contact::isRead()`) and `Reading`'s builder setters are retained.

## [3.3.0] — 2026-06-28

Bot/abuse protection for the sign-in flow.

### Added

- **Cloudflare Turnstile on the login form.** A privacy-friendly CAPTCHA now
  guards password sign-in.
  - New `Tarot\Service\TurnstileService` performs server-side token
    verification against Cloudflare's `siteverify` endpoint, **failing closed**
    (a challenge that cannot be verified is treated as not passed).
  - New public `GET /api/config` endpoint (`ConfigController`) exposes the
    non-secret Turnstile **site key** to the SPA, keeping the backend `.env` the
    single source of truth. The secret is never sent to the client.
  - New self-configuring `TurnstileWidget.vue` component: it fetches the site
    key, lazy-loads the Cloudflare script once, renders the widget, and supports
    `v-model` (token) + `v-model:enabled` with a `reset()` method for retries.
  - The login form disables submission until the challenge is solved and resets
    the single-use token after a failed attempt.
- Configuration via two new environment variables, documented in
  [`backend/.env.example`](backend/.env.example):
  - `CLOUDFLARE_TURNSTILE_SITEKEY`
  - `CLOUDFLARE_TURNSTILE_SECRET`
- Unit coverage for `TurnstileService` (configuration detection plus
  pass/reject/transport-error verification paths).

- The application version (from `package.json`) is now shown on the admin
  dashboard header.

### Changed

- `POST /api/user/login` now requires a valid `turnstile_token` **before**
  credentials are checked — but only when Turnstile is configured. A failed
  challenge is counted against the existing per-IP login rate limiter.

### Fixed

- **Admin panel no longer crashes for signed-in admins.** The navbar had a
  "Cards" link pointing at a non-existent `admin-cards` route; the unresolvable
  `<RouterLink>` threw during render and blanked the whole SPA for any logged-in
  admin. Removed the orphaned link.
- Added a global Vue `errorHandler` so a single failing component degrades
  gracefully (logged) instead of taking down the entire app on mount.

### Security

- Adds a human-verification layer in front of credential stuffing and automated
  login attempts, complementing the existing IP rate limiting and
  `OriginGuard` CSRF defenses.
- Added a **report-only Content-Security-Policy** plus `X-Frame-Options`,
  `X-Content-Type-Options`, and `Referrer-Policy` headers on the app shell
  (`backend/.htaccess`). Report-only is the safe first rollout — it reports
  violations without blocking, ready to promote to enforcing after monitoring.

### Developer experience

- Upgraded the frontend toolchain to **TypeScript 6** (`~6.0`) with
  **`vue-tsc 3`**, and refreshed the documented tech-stack versions in
  `README.md` and `AGENTS.md` (also correcting stale Vite `^6` → `^8` and
  PHP `>=8.3` → `>=8.5` entries).
- Upgraded backend testing to **PHPUnit 13** (refreshed PHPStan and
  php-cs-fixer too) and bumped the JS dev tools (ESLint, Vitest,
  `@vitejs/plugin-vue`, happy-dom) to their latest releases.
- Upgraded the runtime frontend libraries to their latest releases:
  **vue-router 4 → 5** (major), plus Vue, Vite, `marked`, `dompurify`, TipTap,
  and the FontAwesome packages (`@fortawesome/*`). The DOMPurify
  XSS-sanitisation test now runs under `jsdom` (added dev-only), because
  DOMPurify ≥ 3.4.8 mishandles the happy-dom test DOM — sanitisation was
  verified correct in real browsers.
- Upgraded **PHPMailer to 7** (no code changes required).
- Added a committed `db/schema.sql` and `scripts/dump_schema.php` so the
  database structure can be rebuilt from scratch, and retired the AGENTS.md
  "known gaps" backlog by resolving its remaining items.
- Moved deploy connection settings (host, SSH key path, web root) out of
  `scripts/deploy.ps1` into a gitignored `scripts/deploy.config.ps1` (template:
  `deploy.config.example.ps1`) so no infrastructure details live in source
  control.
- Added project governance docs: this `CHANGELOG.md`, a `CONTRIBUTING.md`
  guide, and a proprietary `LICENSE`.
- Added a **GitHub Actions CI** pipeline (`.github/workflows/ci.yml`) that runs
  the full check suite on every push and pull request: frontend
  (type-check, lint, unit tests, build) and backend (PHPUnit, PHPStan, and
  PHPCS).
- Wired up **PHPCS to the PSR-12 standard** (`backend/phpcs.xml`) and applied
  the auto-fixes across the backend so `composer lint` passes.

### Notes

- **Graceful degradation:** with no keys set, `/api/config` returns a `null`
  site key, the widget stays hidden, and login proceeds unchallenged — so local
  development without keys is unaffected.

## [3.2.0] — 2026-06-24

Repository restructure and tooling hygiene. No user-facing behavior changes.

### Changed

- **Split the codebase into a `frontend/` + `backend/` monorepo.** Vue/Vite
  (npm) now lives under `frontend/`; the Slim/PHP (Composer) app lives under
  `backend/`. Every PHP path is `__DIR__`-relative within the backend subtree,
  so the move required no code edits. The production deploy
  (`scripts/deploy.ps1`) flattens both trees back into the single web root.
- Normalized all line endings to **LF** and added a `.gitattributes` to enforce
  it, ending cross-platform CRLF churn.

### Fixed

- A batch of accumulated refinements across the SPA views and admin screens
  (consistency, edge-case handling, and input-validation hardening), backed by
  new `Input` and `IndexNow` unit tests.

## [3.1.0] — 2026-06-11

Richer readings and a professional icon system.

### Added

- **Additional draws within a reading.** A finalized reading can be unlocked to
  draw more cards, extending an existing spread rather than starting over.
- **Font Awesome Pro v7 (self-hosted kit)** with duotone iconography across the
  app, plus a font-management/testing surface for previewing heading fonts.
- A registry-driven icon system (`frontend/src/fontawesome.ts`) so icons are
  tree-shaken and type-checked at build time.
- Expanded automated tests, including `OriginGuard` middleware coverage.

### Changed

- Migrated remaining iconography off the legacy set onto the Pro kit's
  duotone/solid conventions.

## [3.0.0] — 2026-06-04

**Complete re-architecture.** The legacy server-rendered PHP/jQuery app was
rebuilt as a Vue 3 single-page application backed by a modern Slim 4 API, and
grew from an anonymous reading generator into a full account-based platform.

### Added

#### Accounts & authentication

- **User accounts** with email + password registration, **email activation**
  (single-use tokens, 24-hour expiry), login, logout, and a `/me` session check.
- **Password reset** flow with short-lived (1-hour) single-use tokens and an
  enumeration-safe "if an account exists…" response.
- **Self-service account management:** change display name, change password,
  and delete account (readings cascade-deleted via foreign keys).
- **Sign in with Google** (server-side OAuth2). Auto-links by verified email,
  auto-registers new users, and refuses to bind unverified Google emails.
- **WebAuthn passkeys** (`lbuchs/webauthn`): register, rename, and delete
  passkeys; passwordless login; and a per-account toggle to disable password
  login entirely.
- **Argon2id password hashing** (with transparent rehashing) and a shared
  password-strength policy.

#### Tarot domain

- **Deck systems** — card definitions (the 78-card structure, naming,
  meanings) are decoupled from individual decks, so many decks can share one
  system.
- **Readings** in three modes: free draw, spread-based, and custom layouts,
  with card placement, reversals, and finalization.
- **Spreads** (admin-curated and user-submitted), **favorites** (readings and
  decks), a public **contact form**, and a public **changelog**.
- **Deck/spread/deck-system community submissions** with an admin approval
  queue.

#### Admin panel

- A dedicated admin SPA section gated behind an `is_admin` account: dashboard
  with **usage insights** (readings per day, top decks/spreads, reading-type
  breakdown), plus management for decks, deck systems, special cards, spreads,
  pending submissions, readings, changelog, users, and contact messages.
- Deck **thumbnail generation** service.

#### Frontend platform

- **Vue 3 + TypeScript SPA** on Vite, with Vue Router, a composable-based data
  layer, a toast system, and a Markdown editor (TipTap + DOMPurify).
- **Bulma 1.0** theme with a custom dark tarot aesthetic.
- **Progressive Web App**: manifest, service worker, and app icons, plus an
  `og.php` server shim that injects per-reading Open Graph / Twitter meta for
  social sharing and SEO.
- **IndexNow** integration to push changed URLs to search engines.

### Changed

- Replaced the single `index.php` entrypoint and jQuery front-end with a Slim 4
  + PHP-DI backend (autowired container, compiled to disk in production) and a
  built SPA bundle.
- Moved PHP sessions to a **database-backed handler** with self-managed garbage
  collection, session-fixation protection (ID regeneration on privilege
  change), and a 30-day "remember me" persistence option.

### Removed

- Retired the legacy PHP page rendering and jQuery/AJAX entrypoints.

### Security

- **`OriginGuard` CSRF defense:** state-changing requests are rejected unless
  the `Origin`/`Referer` host matches the site (defense-in-depth atop
  `SameSite=Lax` cookies).
- Hardened session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` in
  production), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
  opt-in credentialed CORS (never `*`), and **per-IP rate limiting** on
  registration, login, and password-reset requests.

### Developer experience

- Introduced the modern toolchain: **Vite, TypeScript, ESLint**, **Vitest**
  (frontend) and **PHPUnit + PHPStan** (backend), a local dev router mirroring
  production `.htaccess`, and a SQLite dev database (no DB server required).

## [2.0.0] — 2026-04-17

First step away from the legacy stack.

### Added

- **Vue-driven reading UI**, replacing the jQuery interactions.
- Card **reversal viewing and flipping** in the card viewer, hover effects on
  cards, and a loading screen for slower draws.

### Changed

- Refreshed the Bulma-based styling.
- Capped the maximum reversal probability at 50%.

### Removed

- Dropped the jQuery and lightbox dependencies.

## [1.0.0] — 2025-04-08

Initial release.

### Added

- Server-rendered PHP tarot reading generator with a jQuery front-end and a
  lightbox-based card viewer.

[Unreleased]: https://github.com/jwill89/tarot-site/compare/v5.4.0...HEAD
[5.4.0]: https://github.com/jwill89/tarot-site/compare/v5.3.0...v5.4.0
[5.3.0]: https://github.com/jwill89/tarot-site/compare/v5.2.0...v5.3.0
[5.2.0]: https://github.com/jwill89/tarot-site/compare/v5.1.0...v5.2.0
[5.1.0]: https://github.com/jwill89/tarot-site/compare/v5.0.0...v5.1.0
[5.0.0]: https://github.com/jwill89/tarot-site/compare/v4.1.0...v5.0.0
[4.1.0]: https://github.com/jwill89/tarot-site/compare/v4.0.0...v4.1.0
[4.0.0]: https://github.com/jwill89/tarot-site/compare/v3.4.0...v4.0.0
[3.4.0]: https://github.com/jwill89/tarot-site/compare/v3.3.0...v3.4.0
[3.3.0]: https://github.com/jwill89/tarot-site/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/jwill89/tarot-site/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/jwill89/tarot-site/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/jwill89/tarot-site/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/jwill89/tarot-site/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/jwill89/tarot-site/releases/tag/v1.0.0
