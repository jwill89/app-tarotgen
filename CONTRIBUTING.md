# Contributing to TarotGen.io

Thanks for your interest in contributing! TarotGen.io is a Vue 3 SPA + PHP
(Slim 4) REST API for generating and sharing tarot readings. This guide covers
**how to propose and submit changes**. For project orientation and architecture,
start with these two documents and keep them close while you work:

- [`README.md`](README.md) — quick orientation, where things live, dev quickstart.
- [`AGENTS.md`](AGENTS.md) — the deep guide: architecture, the backend layering
  rule, conventions, and gotchas.

## Code of conduct

Be respectful and constructive. Assume good faith, keep discussion focused on
the work, and help newcomers. Harassment or hostility isn't welcome.

## Ways to contribute

- **Report a bug** — open an issue with steps to reproduce, what you expected,
  what happened, and your environment (OS, PHP/Node versions, browser).
- **Suggest a feature** — open an issue describing the problem you're solving
  before writing code, so we can agree on the approach.
- **Improve docs** — fixes to the README, AGENTS guide, code comments, or this
  file are always welcome.
- **Send a fix or feature** — see the workflow below.

> For anything beyond a small, obvious fix, **open an issue first**. It saves you
> from building something that doesn't fit, and gives us a place to agree on
> scope before review.

## Development setup

Full instructions live in the [README Quickstart](README.md#quickstart-local-dev).
In short — you need **Node**, **PHP 8.5+**, and **Composer**, the Font Awesome
Pro kit token in your user `~/.npmrc` (the tracked `frontend/.npmrc` holds no
token; see `AGENTS.md` §6), the `shared/` submodule (shared-tooling: the lint,
test, CI and deploy pieces), and a `backend/.env` copied from
[`backend/.env.example`](backend/.env.example). The app runs as **two
processes, one per folder**:

```powershell
git submodule update --init                     # shared/, once per clone
cd backend  ; composer install ; composer dev   # PHP API on :80
cd frontend ; npm install       ; npm run dev    # Vite SPA on :5173 (proxies /api → :80)
```

The backend uses a local **SQLite** database, so no database server is needed.
To start one, create an empty file and run the migrations (Phinx never creates
the file itself, so a wrong path can't quietly become a new database):

```powershell
cd backend ; New-Item db/tarotdb.db ; composer migrate
```

## Branching & workflow

1. **Fork** the repository (or create a branch if you have push access).
2. Branch off the default branch (`main`) with a descriptive name, e.g.
   `feature/turnstile-login`, `fix/reading-reversal-count`, `docs/contributing`.
3. Make your change in focused commits.
4. Run the full check suite (below) and make sure everything passes.
5. Update [`CHANGELOG.md`](CHANGELOG.md) and bump the version if appropriate
   (see [Versioning](#versioning--changelog)).
6. Open a pull request against `main`.

Keep pull requests **focused** — one logical change per PR. Unrelated cleanups
belong in their own PR.

## Coding conventions

Match the surrounding code: its naming, comment density, and idioms. A few
project-wide rules:

### Frontend (`frontend/`)

- **Vue 3 `<script setup lang="ts">`** with TypeScript. No `any` — the
  `type-check` step must stay clean.
- **2-space indentation**, as Prettier writes it (`npm run format`; shared
  settings from `shared/lint/web`), and ESLint's fixes with `npm run lint:fix`.
- Put shared logic in **composables** (`src/composables/`) and shared values in
  `src/constants.ts` — don't duplicate.
- **Icons** go through the Font Awesome registry in
  [`src/fontawesome.ts`](frontend/src/fontawesome.ts): add the icon to the
  registry, then use `<FontAwesomeIcon :icon="byPrefixAndName.fad['name']" />`.
  Don't reach for `dom.watch` or raw `<i>` tags.
- Reference SPA-owned `/assets` images as **runtime string binds**
  (`:src="brandLogo"`), never a static `src="/assets/…"` — see the gotcha in
  `AGENTS.md`.

### Backend (`backend/`)

- **PSR-4** under the `Tarot\` namespace, PHP 8.5, strict typing.
- Respect the **layering rule**: `Data → Repository → Service → Structure`.
  Controllers (`api/Routes/`) stay thin HTTP adapters — business logic lives in
  services, data access in repositories. (Full rule in `AGENTS.md`.)
- Dependencies are **autowired** by PHP-DI from constructor type-hints; you
  rarely need to touch `api/dependencies.php`.
- Read configuration through `Tarot\Config\Env`, never `getenv()` directly.
- **Secrets never get committed.** New config goes in `.env` with a documented,
  empty placeholder in [`backend/.env.example`](backend/.env.example).

### Database changes (`backend/db/`)

Schema changes are [Phinx](https://book.cakephp.org/phinx/0/en/index.html)
migrations in `backend/db/migrations/`. Details and SQLite's pitfalls are in
`AGENTS.md` §7.

1. **Add a migration** with `vendor\bin\phinx create AddThingToReadings` and
   write it: `up()` in SQL, and `down()` where the change can be undone. Keep
   it additive, so the code before yours still runs on the new schema.
   **Never edit a migration that has run in production**; fix it with another.
2. **Run it** locally: `composer migrate` (`composer migrate:status` shows what
   has run).
3. **Regenerate `db/schema.sql`** with `php scripts/dump_schema.php`, which builds
   it from the migrations on a throwaway database, and commit it with the
   migration. Don't edit it by hand: the tests load it, and `SchemaTest` fails
   when it doesn't match the migrations.
4. **Test it** against a database built from the previous `schema.sql`
   (`tests/Unit/Database/BaselineMigrationTest.php` shows how).

On the host, the backend deploy runs the migrations itself, as the web-server
user, from the web root, after snapshotting the database: `runuser -u www-data
-- env TAROT_DATA_DIR=/var/lib/tarotgen php vendor/bin/phinx migrate`. If one
fails, the previous code goes back and the deploy prints the command that
restores the snapshot. The old one-off `db/migrate_*.php` scripts are retired;
don't add new ones.

## Tests & checks

Every PR must pass the full suite. Add or update tests for the behavior you
change — backend logic gets PHPUnit coverage; frontend logic gets Vitest specs.

```powershell
# Frontend
cd frontend ; npm run type-check ; npm run lint ; npm test

# Backend
cd backend  ; composer test ; composer stan ; composer lint

# Everything, from the repo root: every lint, audit and test
task check
```

- `composer test` → PHPUnit, `composer stan` → PHPStan, `composer lint` → PHP CS
  Fixer's check (`composer lint:fix` to auto-format).
- `task check` adds most of the rest CI runs: Stylelint, Knip, Prettier, Rector,
  the dependency checks and audits, the plugin's `dotnet format`,
  PSScriptAnalyzer, markdownlint, the shared repo files and workflow refs, and
  the Playwright suite. CI also builds the plugin and checks the coverage floors
  and that the generated API types are current.
- If your change is observable in the browser, verify it manually too (run both
  servers and exercise the flow) before opening the PR.

## Commit messages

- Write in the **imperative mood** with a clear, specific subject line
  (e.g. `Add Cloudflare Turnstile to the login form`).
- Explain the *why* in the body when it isn't obvious from the diff.
- Group related work into coherent commits rather than one giant commit.

## Versioning & changelog

This project follows [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/). The version of record is the
`version` field in [`frontend/package.json`](frontend/package.json), and it is
surfaced on the admin dashboard.

When your change is user-visible:

1. Add an entry under the **`[Unreleased]`** heading in
   [`CHANGELOG.md`](CHANGELOG.md), in the right category (Added / Changed /
   Deprecated / Removed / Fixed / Security).
2. When cutting a release, move `[Unreleased]` entries under a new version
   heading with the date, bump `frontend/package.json`, and update the compare
   links at the bottom of the changelog.

Rough guide to the bump:

- **PATCH** (`x.y.Z`) — bug fixes, no behavior change.
- **MINOR** (`x.Y.0`) — backward-compatible features.
- **MAJOR** (`X.0.0`) — breaking changes to the API or data model.

## Reporting security issues

**Please do not open public issues for security vulnerabilities.** Report them
privately to the maintainer (see the repository owner's contact on
[GitHub](https://github.com/jwill89/tarot-site)) so a fix can ship before
disclosure. Authentication, session handling, CSRF (`OriginGuard`), rate
limiting, and the Turnstile/secret configuration are especially sensitive areas
— flag anything you find in those.

## License

This project is **proprietary** software — see [`LICENSE`](LICENSE). It is
source-available for reference and contribution, but it is not open source: you
may not reuse, redistribute, or deploy the code without the Owner's written
permission.

By submitting a contribution you assign all right, title, and interest in that
contribution to the Owner, so it can be incorporated into the project under the
proprietary license.

---

Thanks again for contributing! 🔮
