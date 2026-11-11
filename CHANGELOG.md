# Changelog

All notable changes to **TarotGen.io** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 5.0.0 were reconstructed from the Git history; their dates are the day the work landed.

## [Unreleased]

### Changed

- `task deploy` deploys the newest release tagged on main, built from a checkout of its own, so later commits and uncommitted changes never reach the site. A release the server already runs is skipped unless `-Force` is given, and `-SkipBuild` now goes with `-Preview` alone.

## [5.5.1] - 2026-11-07

### Changed

- The database pull after a backend deploy makes its copy on the server as the database's own user, beside the database.

## [5.5.0] - 2026-11-06

### Added

- A daily database backup: a checked, compressed copy is kept on the server for 28 days. The backend deploy installs it and keeps it current.
- Schema changes are migrations, run by the backend deploy after it snapshots the database.
- `task` commands for the day-to-day work (`task dev`, `task check`, `task deploy`).

### Changed

- Building the frontend needs Node 26 and the Font Awesome token in `~/.npmrc`.
- Deploys upload one archive over a single SSH connection and run once on the server. They need PowerShell 7.4 and PuTTY's plink 0.85 or later, and `-Target both` deploys the backend first.
- A backend deploy builds the new code on the server, snapshots the database (the newest three are kept), swaps the code in and runs the migrations. If they fail, the previous code goes back and the command that restores the snapshot is printed.
- Every deploy is checked through Cloudflare afterwards: the API and database after a backend deploy, the home page and its script after a frontend one, `repo.json` after a plugin one. If a check fails, the deploy's changes go back.
- After a backend deploy passes, it pulls a copy of the live database into `backend/db/tarotdb.db` (`-NoDbPull` skips it).
- New deploy options `-SiteUrl` and `-ConfigPath`; `-VpsUser` has no default.
- The nightly retention run moves to 10:30 UTC and waits until that day's backup exists.
- The privacy page says deleted data can stay in the backups for up to 28 days (it said 14).
- Signing up and asking for a password reset no longer wait for the email to send.

### Removed

- The old nightly backup, replaced by the daily one.

### Fixed

- Forgot Password answered more slowly for an address with an account, so timing it could tell whether an address had one. It now answers before the email is sent.
- Text on the gold buttons was too faint to read comfortably; it is dark now, as on the aqua buttons.
- Grey hint text was too faint on the dark surfaces; it is lighter now, and darker when printed.
- A screen reader could announce a face-down card's name before it was turned. Face-down cards are announced by their position only, and the lightbox shows a face-down card's back with no Report button.

## [5.4.0] — 2026-10-30

### Added

- A Turnstile check on New Draw and Recreate Draw for signed-out visitors, against bots drawing in bulk. Signed-in readers never see it.
- Draw keys for the FFXIV plugin: an install not linked to an account gets a key once, from the `/draw-key` page behind the same check, and draws with it from then on. A key only draws, and is rate-limited per key and per address.
- FFXIV plugin 0.8.0: New Reading offers "Get a draw key" when the plugin has neither a key nor a linked account, and an install upgraded from an earlier version gets a one-time pop-up explaining the change. A key or link the site no longer accepts is forgotten, with the site's reason shown.
- The changelog is in the admin dashboard: the version tag opens these notes.

### Changed

- Where Turnstile is configured, a reading made without a signed-in user needs a passed check or a plugin draw key. Unlinked plugins older than 0.8.0 are told to update.
- The nightly retention run also deletes draw-key codes that expired without being redeemed.
- The privacy page names Cloudflare Turnstile and where it runs, and says what is kept of a plugin draw key.

### Removed

- The hand-written changelog: the public `/changelog` page (it now goes to the home page), the admin editor and their API routes.

## [5.3.0] — 2026-10-30

### Changed

- Admin Spreads is split into pages (the list, the editor, Categories and Submissions), and editing a spread is in the address, so Back, Cancel and Save return to the list. The dashboard menu shows waiting spread submissions.
- Admin Deck Systems likewise: the list, the editor (it asks before you leave with unsaved changes) and Submissions (with a card preview). The dashboard menu shows waiting deck-system submissions.

### Fixed

- Saving a deck system in the admin could undo another admin's approval made while the form was open.

## [5.2.0] — 2026-10-30

### Added

- Journal fields: a reading can record the question asked, who it was for, and tags. They are yours alone, never shown on a shared reading or its link preview, and can be set when drawing or later.
- Find your readings: My Readings has a filter bar with search (name, notes, question, who it was for), deck, spread, tag, dates and daily cards. Filters stay in the address, so Back works.
- Who can open a reading: anyone with the link (the default), anyone with the password, or private (only you; everyone else sees "not found" and the link preview stays generic).
- Guests can have a reading deleted sooner, after 1, 7 or 30 days instead of the usual year.
- A spreads library (`/spreads`): browse public spreads by category, search them, preview each layout and its positions, then draw with a spread or copy it to My Spreads. "Spreads" replaces "Create a Spread" in the top menu.
- Position descriptions: each position in a spread can say what it asks ("What's blocking you"). They show while drawing, on the reading and in print.
- New Draw's spread picker groups public spreads by category.
- FFXIV plugin 0.7.0: its spread pickers group by category and the reading panel shows position descriptions. It also works with older servers, without the grouping.
- Admin: a category manager (add, rename, reorder, remove; a category still holding spreads can't be removed), a category on every public spread, and a "Publish in" choice when approving a submitted spread.

### Changed

- The journal export includes question, querent, tags and who can open each reading; the CSV gains those columns after Reading Name.

## [5.1.0] — 2026-10-30

### Added

- Daily card: signed-in readers draw one card a day from the Daily Card page, choosing the deck and reversals each time, and look back over a month calendar of past draws. Each is a real one-card reading, marked "Daily card" in My Readings, and the day stays drawn even if its reading is deleted.
- Card frequency on your Dashboard: which cards turn up most in your readings, upright and reversed, for one deck or all of them, over the last 30 days, 90 days, year or all time.
- Print a reading: a Print button and a print layout with every card face up and numbered, a table of positions and cards, and your notes, in ink-friendly colours.
- Export your journal from Account Settings, as JSON (every reading with its spread and cards) or CSV (one row per card).
- Sign out of all other devices from Account Settings.
- Admin: the dashboard counts open card reports, and Errored Cards has Regenerate & resolve: once a new scan is uploaded, one click rebuilds that card's web images, closes the report, and lists the image URLs to purge from the CDN cache.

## [5.0.0] — 2026-10-28

### Added

- Reasons on card reports: reporting a card from the lightbox asks what's wrong (scan artefact, cropped or crooked, poor image quality, wrong card, or something else) and optionally for details. The admin Errored Cards page shows each card's reasons, and a View reports dialog lists every report.
- Nightly database backups, kept for 14 days, and a retention job that removes guest readings after a year. Readings made while signed in are never touched, as the privacy page states.

### Changed

- A new look, elevated celestial minimalism: neutral charcoal surfaces with gold and silver line art, and a single aqua accent reserved for the primary action and active or selected states. Text uses solid colours that meet WCAG AA contrast, with no opacity-dimmed text.
- All headings use the self-hosted Crucial typeface.
- A new brand mark, a gold four-pointed star in a ring, replaces the old icon everywhere: favicon, app icons, boot loader and the FFXIV plugin icon.
- Dialogs, menus, popovers, tooltips, comboboxes, selects, switches, segmented controls, number fields, pagination and toasts were rebuilt for consistent behaviour and keyboard accessibility.
- The admin dashboard is a console layout, with a grouped side menu showing live counts and insights up top, instead of the old stat-card grid.
- My Readings loads a page at a time instead of every reading at once.
- Every API error is a JSON `{ "error": … }` body, Slim's own 404 and 405 included, and the API reference at `/api/docs` describes every endpoint's response as sent.
- Signed-in pages load faster: a session's expiry is refreshed at most once every 5 minutes, and the spread picker loads its lists together.
- Card images are cached at the edge and in the browser, and the lightbox and image export use full-size WebP instead of the master PNG scans.
- The stylesheet is 29% smaller, thumbnails load lazily, and readings are faster to list.
- Deploys upload one checksummed archive per target instead of file by file.

### Removed

- The unused heading-font switcher, its three alternate fonts and a Google Fonts request that loaded on every page.

### Fixed

- Leftover old-theme colours in the reading image (PNG) export, the Markdown editor's placeholder and the plugin feature icons now use the new palette.
- Deck and spread search never filtered: typing in either picker showed every entry. It filters now, and Enter picks the first match.
- Every deck list failed once a deck system still in use was deleted, or a deck's artist or purchase link was cleared.
- Deleting someone else's personal spread answered "deleted" without removing it, but did remove every user's favourites of it.
- Reset on New Draw and Recreate Draw kept the owner options, so a password typed earlier still locked the next reading.
- Forgot Password reported success when the request had failed.
- Requests that should be refused now get a reason instead of a server error or a false success: duplicate names (409), unknown ids (404), missing required names (400), and a few cases around passkeys, favourites and deleted accounts.
- A personal spread saved with malformed or no positions stored a 0-card spread; its card count now always follows its positions.
- A plugin token showed as "last used" from the moment it was issued.
- Admin: the special-cards list lost the other decks after a save while filtered, and table sorting didn't work from the keyboard.
- A PHP warning on every login and logout, timestamps that mixed time zones, and a server error on a reading-id collision.
- The spread picker went blank after a reset, and the first edit on a spread canvas couldn't be undone.
- The Turnstile script loaded once per widget, the cookie notice couldn't be dismissed with storage blocked, and Space didn't log out from the mobile menu.

### Security

- Changing your password signs out your other devices, a password reset signs out every session, and deleting an account ends its sessions. Linked plugins keep working, and are revoked under Connected Apps.
- No more account enumeration. Registering with an address that already has an account answers like any sign-up (the owner gets an email instead), a password login for an unknown address takes as long as a wrong password, and passkey sign-in options look alike for every address.
- Admins can't remove their own admin rights or delete themselves, and accounts listed in `PROTECTED_ADMIN_EMAILS` can't be demoted or deleted by anyone.
- You can't lock yourself out: switching password login off, deleting your last passkey or unlinking Google is refused when it would leave no way to sign in. Password login can now be switched off with only Google linked, and Google-only accounts are shown how to set a password by an emailed link.
- `APP_ENV` is read case-insensitively, so `Production` can't run as development.
- Private data lives outside the web root: the database and `.env` are in a separate data directory (`TAROT_DATA_DIR`), and the web server refuses the code and tooling directories outright.
- An enforced Content-Security-Policy on every page, with a fresh nonce per response, instead of a report-only one.
- The FFXIV plugin's account-link check accepts only real loopback addresses.
- A deleted account's leftover session can no longer submit anything.
- Personal spreads are private to their owner. A reading could be made from anyone's personal spread by its id; now only the owner (or their linked plugin) can use it, and anyone else gets the same answer as for an unknown id.
- Unapproved submissions are visible only to admins. Pending decks and deck systems could be read by id; now everyone else gets a 404, and readings can't be drawn from a pending deck.
- The contact form is protected by Turnstile (when configured), and the plugin's token exchange is rate-limited.
- Dependencies with published security advisories were updated.

## [4.1.0] — 2026-07-04

### Added

- A Final Fantasy XIV Dalamud plugin that draws and views readings in-game: pick a public spread or a free draw, see the spread with positioned and rotated cards, open any reading by its share code, and click a card for a full-size lightbox.
- Optional account linking for the plugin through a browser sign-in, with no copy-paste, which unlocks locking and finalizing a reading, favourites-sorted pickers and My Readings. The link can be revoked under Connected Apps in Account Settings.
- A chatless reading share: push a reading to another plugin user, who gets a passive in-game popup. Recipients choose who may send to them (party members, anyone, or nobody) and can block a sender.
- The plugin is published through a custom Dalamud repository (`https://tarotgen.io/plugin/repo.json`) that testers add in Dalamud's settings, with `deploy.ps1 -Target plugin` to build and publish it.
- Plugin account linking API: `POST /api/plugin/authorize` and `POST /api/plugin/token`, with linked tokens listed and revoked at `GET` and `DELETE /api/account/tokens`. Read-only account routes accept a session or a plugin token; destructive and profile routes stay session-only.
- Chatless share API: guest authorization, client registration, inbox, share and block endpoints under `/api/plugin`.
- Card scan-error reports: a "Report scan issue" button in the card lightbox (`POST /api/card-reports`), and an admin Errored Cards page listing reported scans with thumbnail, deck, card, count and dates, with resolve and reopen.
- A "Connect the plugin" page (`/authorize`) offering to sign in and link your account, or continue as a guest.
- A new optional setting, `PLUGIN_IDENTITY_SALT`.

### Security

- The share relay never stores a plaintext roster of players: a recipient's character is kept only as a keyed hash. Sharing answers the same whether or not a recipient exists, accepts or has blocked the sender, so it can't be used to probe who runs the plugin.
- Card reports are rate-limited by an overall cap per address plus a per-address, per-card cooldown, so a card's count can't be inflated.
- The plugin authenticates with bearer tokens over HTTPS, separate from the site's session cookie.

## [4.0.0] — 2026-07-01

### Added

- An OpenAPI 3.1 specification of the API, served at `GET /api/openapi.json`, with an interactive reference (Scalar) at `GET /api/docs`.

### Changed

- Breaking: nearly every endpoint path, and several methods, changed. The site was updated in step, and no aliases for the old paths are kept.
- Resources are plural nouns: `/deck` is `/decks`, `/deck-system` is `/deck-systems`, `/spread` is `/spreads`, `/reading/{id}` is `/readings/{id}`, `/contact` is `/contacts`, and the passkey routes move under `/auth/passkeys`.
- Creates return 201 and deletes return 204. A random reading is `POST /readings/generate`, a custom one is `POST /readings`, and `finalize` is `POST /readings/{id}/finalize` (it was `PUT`).
- Flag toggles are `PATCH` on the resource instead of bespoke `POST` actions: a deck's `usable`, a user's `is_admin`, a contact's `is_read` and the account display name (`PATCH /account`). State transitions stay as `POST /<resource>/{id}/<verb>` (`approve`, `draw`, `unlock`, …).
- Sign-in and session endpoints are under `/auth/*` (`/auth/login`, `/auth/register`, `/auth/activate`, `/auth/me`, …), and the password change is `POST /account/change-password`.
- The retention cleanup is a bulk delete: `DELETE /admin/readings/all?older_than_days=` returning `{ "deleted": N }`.
- Special cards are nested under their deck (`/admin/decks/{deck_id}/special-cards/{card_id}`), and favorites are removed by path (`DELETE /account/favorites/{type}/{id}`) rather than by request body.

## [3.4.0] — 2026-06-30

### Added

- A written REST API reference (`API.md`) documenting every endpoint with its method, auth requirement, request body, responses, rate limits and caching.

## [3.3.0] — 2026-06-28

### Added

- Cloudflare Turnstile on the login form, a privacy-friendly CAPTCHA that guards password sign-in; submission stays disabled until the challenge is solved. With no keys set (`CLOUDFLARE_TURNSTILE_SITEKEY`, `CLOUDFLARE_TURNSTILE_SECRET`), the widget stays hidden and login proceeds unchallenged.
- `GET /api/config` exposes the non-secret Turnstile site key to the site.
- The application version is shown on the admin dashboard header.

### Changed

- `POST /api/user/login` requires a valid `turnstile_token` before credentials are checked, when Turnstile is configured. A failed challenge counts against the per-address login rate limit.
- Deploy connection settings (host, SSH key path, web root) moved out of `scripts/deploy.ps1` into a gitignored `scripts/deploy.config.ps1`.

### Fixed

- The admin panel blanked the whole app for signed-in admins, because of a "Cards" link to a page that doesn't exist. The link is gone, and a single failing component no longer takes down the app.

### Security

- A report-only Content-Security-Policy plus `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` headers on the app shell.

## [3.2.0] — 2026-06-24

### Changed

- The codebase is split into `frontend/` and `backend/`; the deploy flattens both into the web root.

### Fixed

- A batch of refinements across the site's views and admin screens: consistency, edge-case handling and input validation.

## [3.1.0] — 2026-06-11

### Added

- Additional draws within a reading: a finalized reading can be unlocked to draw more cards, extending the spread rather than starting over.
- Font Awesome Pro v7 duotone icons across the app, and a page for previewing heading fonts.

## [3.0.0] — 2026-06-04

### Added

- User accounts with email and password registration, email activation (single-use links, 24-hour expiry), login, logout and a session check.
- Password reset with short-lived (1-hour) single-use links and a response that doesn't reveal whether an account exists.
- Self-service account management: change display name, change password and delete the account along with its readings.
- Sign in with Google. It links by verified email, registers new users, and refuses unverified Google emails.
- Passkeys: register, rename and delete them, sign in without a password, and switch password login off per account.
- Argon2id password hashing and a shared password-strength policy.
- Deck systems: card definitions (the 78-card structure, naming, meanings) are separate from individual decks, so many decks can share one system.
- Readings in three modes (free draw, spread-based and custom layouts), with card placement, reversals and finalization.
- Spreads (admin-curated and user-submitted), favorites (readings and decks), a public contact form and a public changelog.
- Community submissions of decks, spreads and deck systems, with an admin approval queue.
- An admin section for admin accounts: a dashboard with usage insights (readings per day, top decks and spreads) and management of decks, deck systems, special cards, spreads, submissions, readings, the changelog, users and contact messages. Deck thumbnails are generated.
- A Vue single-page app with a dark tarot look, a toast system and a Markdown editor.
- An installable web app (manifest, service worker, icons) and per-reading Open Graph and Twitter previews for sharing.
- IndexNow notifications to push changed URLs to search engines.

### Changed

- The single-entrypoint PHP and jQuery site was rebuilt as a single-page app on a new API.
- Sessions are stored in the database, with session-fixation protection and a 30-day "remember me" option.

### Removed

- The old server-rendered pages and jQuery interactions.

### Security

- CSRF defence: state-changing requests are rejected unless their origin matches the site.
- Hardened session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` in production), `X-Frame-Options` and `X-Content-Type-Options` headers, opt-in credentialed CORS (never `*`), and per-address rate limiting on registration, login and password-reset requests.

## [2.0.0] — 2026-04-17

### Added

- A Vue-driven reading interface in place of the jQuery interactions.
- Card reversal viewing and flipping in the card viewer, hover effects on cards, and a loading screen for slower draws.

### Changed

- Refreshed the Bulma-based styling.
- The maximum reversal probability is capped at 50%.

### Removed

- The jQuery and lightbox dependencies.

## [1.0.0] — 2025-04-08

Initial release.

### Added

- A server-rendered PHP tarot reading generator with a jQuery front end and a lightbox card viewer.

[Unreleased]: https://github.com/jwill89/tarot-site/compare/v5.5.1...HEAD
[5.5.1]: https://github.com/jwill89/tarot-site/compare/v5.5.0...v5.5.1
[5.5.0]: https://github.com/jwill89/tarot-site/compare/v5.4.0...v5.5.0
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
