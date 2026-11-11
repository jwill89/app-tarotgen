# AGENTS.md

Operational guide for AI agents and developers working in this repository. Read
this first to understand how the project is laid out, how the pieces fit
together, and the conventions to follow when making changes.

This file holds only what is specific to TarotGen.io. The rules every project follows are in [`shared/standards/README.md`](shared/standards/README.md) and the topic files it lists; where this file says nothing, or says something older, those rules win. Where the code still departs from them, [§13](#13-where-this-project-departs-from-the-standards) says so.

---

## 1. What this project is

**TarotGen.io** (`tarotgen.io`) is a tarot reading generator and reading-sharing
platform. Visitors pick a deck and a spread (or freely choose a card count),
draw cards, then can save and share a permalink to the resulting reading.
Registered users get accounts (readings, custom spreads, favorites), and admins
manage decks, deck systems, spreads, users, and more.

It is a **single-page app (SPA) frontend + REST API backend**:

- **Frontend:** Vue 3 (`<script setup>` + TypeScript), Vue Router, Bulma CSS
  (layout/utilities), Reka UI (headless interactive primitives), TipTap
  (markdown editor), built with Vite.
- **Backend:** PHP 8.5+ (Composer platform pinned to `8.5.12`, production's version),
  Slim 4 framework with PHP-DI (autowiring) container, a layered architecture
  over a SQLite database.
- **Database:** SQLite (`db/tarotdb.db`) accessed through PDO, WAL mode.

---

## 2. Tech stack & versions

| Area        | Tooling |
|-------------|---------|
| Frontend    | Vue `^3.5`, vue-router `^5.1`, TypeScript `~6.0` (`vue-tsc 3.3.13`, held: §10), Vite `^8`, Bulma `^1.0`, Reka UI `^2.10` |
| Editor/MD   | TipTap `^3.25` (`@tiptap/*`), `marked`, `dompurify`, `turndown` |
| Shared      | **shared-tooling v2.1.0**, the git submodule at `shared/` (`shared/README.md`): the lint configs, test settings, CI workflows, repo files, the deploy module (`SharedDeploy`) and the standards (`shared/standards/`) every jwill89 project shares. |
| Icons       | FontAwesome **Pro v7**, self-hosted via the per-style packages (`@fortawesome/pro-{solid,regular,duotone}-svg-icons` + free `@fortawesome/free-brands-svg-icons`) + `@fortawesome/fontawesome-svg-core` (the kit package is a follow-up, §13). Needs the Font Awesome token in `~/.npmrc` to install — see §6. |
| FE testing  | Vitest `^5` on `jsdom` with shared-tooling's settings (`vitestDefaults`) + `@vue/test-utils` (components/views), coverage via `@vitest/coverage-v8` (floor in `vite.config.ts`); Playwright `^1` end-to-end suite (`e2e/`) on shared-tooling's settings, with axe (`@jwill89/test-a11y`) |
| FE linting  | ESLint `^10`, Prettier, Stylelint `^18` and the tsconfigs all extend shared-tooling's `@jwill89/lint-web` (`shared/lint/web`: ESLint with `eslint-plugin-vue`, typescript-eslint's strict type-checked set, `eslint-plugin-vuejs-accessibility` and `curly`; Stylelint's standard + Vue configs; tsconfigs with `noUncheckedIndexedAccess`), plus this app's own rules (SCSS, the palette rule); Knip (unused code/deps) |
| Backend     | PHP `>=8.5`, Slim 4 (`slim/slim`, `slim/http`, `slim/psr7`), `php-di/slim-bridge` |
| Auth/email  | `lbuchs/webauthn` (passkeys), `phpmailer/phpmailer` (SMTP) |
| BE testing  | PHPUnit `^13` |
| BE linting  | `friendsofphp/php-cs-fixer` (PER-CS 3.0, `.php-cs-fixer.dist.php`), PHPStan `^2` (level 8 + strict, deprecation and PHPUnit rules, baselined), Rector (PHP 8.5 + dead-code sets), each starting from shared-tooling's `shared/lint/php`; composer-unused + composer-require-checker, composer-normalize |
| Plugin      | **FFXIV Dalamud plugin** (`plugin/`) — C# / **.NET 10** (`net10.0-windows`), Dalamud **API 15** (`Dalamud.NET.Sdk/15.0.0`), ImGui via `Dalamud.Bindings.ImGui`; analyzers and code style from `shared/lint/dotnet`. Windows-only, built independently — see §12. |
| Misc tooling| Python scripts for deck image cleanup (`scripts/`); `Taskfile.yml` ([Task](https://taskfile.dev)) for the day-to-day commands |

---

## 3. Repository layout

The repo is split into **`frontend/`** (Vue/Vite, the npm project root) and
**`backend/`** (PHP, the composer project root). All frontend paths below are under
`frontend/`, all backend paths under `backend/`. The deploy script (`scripts/deploy.ps1`)
flattens both into the live server's single flat web root.

```text
/
├── frontend/               # Vue 3 SPA — run npm from here
│   ├── index.html          # SPA entry HTML (Vite).
│   ├── package.json        # Frontend scripts & deps.
│   ├── vite.config.ts      # Vite + Vitest config (alias @ -> src, /api + /assets proxy, manualChunks).
│   ├── config/             # Vite plugins: changelog-plugin.ts serves the root CHANGELOG.md as `virtual:changelog`.
│   ├── eslint.config.ts    # ESLint flat config: the shared @jwill89/lint-web (shared/lint/web) + project rules.
│   ├── prettier.config.js  # Prettier: the shared settings, as they are.
│   ├── stylelint.config.js # Stylelint: the shared config + SCSS and the palette rule (§5).
│   ├── knip.ts             # Knip: unused files/exports/deps; entry points it can't infer.
│   ├── playwright.config.ts # The end-to-end suite (§8), on shared/test/e2e; e2e/ holds its specs and PHP server scripts.
│   ├── .nvmrc              # Node version (CI reads it too).
│   ├── tsconfig*.json       # TS project config, extending the shared @jwill89/lint-web ones.
│   ├── .npmrc              # Font Awesome registry scopes + install-links (tracked; the token is in ~/.npmrc, §6).
│   ├── public/             # PWA assets (manifest, sw, icons), robots.txt, sitemap.xml, fonts/.
│   └── src/                # Frontend source (alias `@` -> src).
│       ├── main.ts         #   App bootstrap (mounts after session revalidation).
│       ├── App.vue         #   Root component (nav/layout).
│       ├── fontawesome.ts  #   FontAwesome icon registry — add every icon you use here (§5).
│       ├── constants.ts    #   Shared constants (storage keys, etc.).
│       ├── router/index.ts #   All routes + auth guards.
│       ├── views/          #   Route-level pages (lazy-loaded); views/admin/ for admin.
│       ├── components/     #   Reusable components; components/admin/ for admin.
│       ├── composables/    #   use* composables (state + API access logic).
│       ├── utils/          #   Pure helpers (datetime, markdown, deck, storage...).
│       ├── types/index.ts  #   Shared TypeScript interfaces (mirror API payloads).
│       ├── assets/         #   tokens.css (--myst-* vars), style.css, fonts.css (@font-face).
│       └── __tests__/      #   Vitest specs (*.spec.ts).
│
├── backend/                # PHP API — run composer from here
│   ├── og.php              # Serves the SPA shell for every page: per-response CSP nonce + /reading/{id} share meta.
│   ├── dev-router.php      # Local PHP built-in-server router (composer dev); mimics .htaccess.
│   ├── .htaccess           # Web-root rewrites (api/ → static files → every page via og.php).
│   ├── composer.json       # Backend deps, PSR-4 autoload, `composer test`/`stan`/`dev`/`migrate`.
│   ├── phinx.php           # Phinx config: db/migrations/ against Paths::database() (§7).
│   ├── phpunit.xml         # PHPUnit config (unit + http suites).
│   ├── phpstan.neon        # PHPStan: the shared level 8 (shared/lint/php) + strict rules; phpstan-baseline.neon holds pre-existing findings.
│   ├── .php-cs-fixer.dist.php # Code style (the shared PER-CS 3.0 rules + PHP 8.5 migration set): `composer lint` / `lint:fix`.
│   ├── rector.php          # Rector: the shared dead-code set + PHP 8.5 (`composer rector`, `rector:check`).
│   ├── composer-require-checker.json # Symbols used but deliberately not required (swagger-php, dev-only).
│   ├── .env / .env.example # Environment (gitignored / template).
│   ├── api/                # HTTP API entry point (Slim).
│   │   ├── index.php       #   Bootstrap: env, session cookie params, container; runs app.php's app.
│   │   ├── app.php         #   Builds the app: middleware, error handling, ALL route definitions.
│   │   ├── dependencies.php #  PHP-DI definitions (only PDO; rest autowired).
│   │   ├── .htaccess       #   Rewrites non-file requests to index.php.
│   │   └── Routes/{Internal,Middleware}/  # Controllers + AbstractController; UserAuth/AdminAuth/OriginGuard.
│   ├── includes/Tarot/     # Domain code (PSR-4 `Tarot\`): Config, Database, Data,
│   │                       #   Repository, Service, Structure, Exception, Utility.
│   ├── tests/{Unit,Http}/  # PHPUnit tests (namespace `Tarot\Tests\`) — see §8.
│   ├── bin/                # Dev-only, not deployed: openapi.php (`composer docs`).
│   ├── scripts/            # PHP/Python CLI utils (make_admin.php, gen_icons.php, indexnow.php, *.py).
│   ├── db/                 # migrations/ (Phinx) + schema.sql (generated from them); the SQLite DB
│   │                       #   and the retired one-off scripts beside them are gitignored (§7).
│   ├── assets/decks/{id}/  # Per-deck card images (Card_XXXX.png, Card_Back.png) (gitignored).
│   └── vendor/             # Composer deps (generated).
│
├── plugin/                 # FFXIV Dalamud plugin — self-contained C# subproject (§12).
│   ├── TarotGen.Plugin.sln
│   ├── Directory.Build.props #  Imports the shared analyzers and C# code style (shared/lint/dotnet).
│   └── TarotGen.Plugin/    #   C# project (net10.0-windows, Dalamud.NET.Sdk); packaged by DalamudPackager.
│
├── shared/                 # Git submodule: shared-tooling v2.1.0 (`git submodule update --init`).
├── deploy/apache-vhost.conf # The site's one vhost (port 80 and 443), a template every deploy of the site fills and ships (§6).
├── scripts/deploy.ps1      # Build & deploy orchestration (frontend + backend + plugin), on shared/deploy/pwsh.
├── scripts/server/tarotgen.cron # /etc/cron.d/tarotgen (retention); the backend deploy installs it.
├── Taskfile.yml            # `task` commands; includes shared/repo/Taskfile.yml as shared:*.
├── .editorconfig / .gitattributes # From shared/repo; the repo's own lines go below the project marker.
├── .markdownlint-cli2.jsonc # markdownlint: the shared config (shared/lint/markdown) + this repo's ignores.
├── .github/                # CI, plugin, CodeQL workflows; dependabot.yml.
├── README.md               # Human-facing quick orientation.
├── CLAUDE.md               # Imports this file and shared/standards/README.md for Claude Code.
├── .gitignore
└── (frontend/dist, */node_modules — generated)
```

---

## 4. Backend architecture (the layering rule)

Data flows through clearly separated layers. **Respect these boundaries** — do
not skip layers (e.g. don't run SQL from a controller).

```text
HTTP Request
   → Middleware (OriginGuard, UserAuth/AdminAuth)
   → Controller (api/Routes/Internal/*)   — request parsing, HTTP responses
   → Service (includes/Tarot/Service/*)    — business logic (optional layer)
   → Repository (includes/Tarot/Repository)— domain API over the Data layer
   → Data (includes/Tarot/Data/*)          — raw PDO/SQL, hydrates Structures
   → Structure (includes/Tarot/Structure/*)— entity objects returned upward
```

- **Controllers** extend `AbstractController`. Dependencies are **constructor-
  injected** (PHP-DI autowiring resolves concrete classes from type-hints).
  Return Slim responses via `$response->withJson([...], $status)`. Error bodies
  use the shape `['error' => '...']` (RFC 9457 is a follow-up, §13).
- **Data classes** extend `AbstractData`, which provides `fetchOne()`,
  `fetchAllAs()`, and `applyUpdate()` (dynamic, allow-listed UPDATE). The `PDO`
  handle is **injected**, never fetched from a global, so tests can pass an
  in-memory SQLite PDO.
- **Every row read fetches straight into a class** with `fetchOne()`/`fetchAllAs()`
  — there is no `FETCH_ASSOC` + hand-written `hydrate()`. The SELECT lists exactly
  the class's properties (never `*`). A derived value is computed in the SELECT
  (`… IS NOT NULL AS password_protected`, `EXISTS (…) AS has_passkeys`). A JSON
  column is decoded by the property's set hook (`Spread::$positions`). A secret
  goes into a class that keeps it `private` (`UserCredentials`). A row for
  internal use only gets a plain Structure with no `#[OA\Schema]`. A NULL into a
  non-nullable property is a TypeError, so match nullability to the column.
  `AbstractData`'s docblock has the details.
- **`PDO::class`** is the only manual container definition (see
  `api/dependencies.php`); it comes from `Connection::getInstance()`. Everything
  else (repositories, services, controllers) is autowired. Sessions are stored
  through the same PDO: `app.php` hands it to `Session::useDatabase()`.

### Routing

All routes are declared in `api/app.php` (base path `/api`), grouped by
resource. Admin routes are under `/admin` and guarded by `AdminAuth`; account
self-service routes under `/account` are guarded by `UserAuth`. Trailing-slash
variants are allowed via `[/]`.

The API's paths and methods follow the guide's [api-and-realtime.md](shared/standards/api-and-realtime.md); [`API.md`](API.md) has this API's own conventions, such as its commands (`…/finalize`, `…/unlock`, `…/draw`) and `DELETE /<resource>/all`.

Public catalog reads (decks, deck systems, spreads) send `Cache-Control: public, max-age=300`, since the catalog changes slowly; viewer-specific reads, such as a single reading, send `private, no-store`.

### API docs (OpenAPI + Scalar)

Endpoints are documented with **swagger-php 7** spec attributes
(`use OpenApi\Spec as OAS`; `#[OAS\Operation\Get(...)]`, `new OAS\MediaType\Json(...)`)
on the controllers, plus `#[OAS\Schema]` on the `Structure` classes. `composer docs`
(`bin/openapi.php`) regenerates the committed `backend/openapi.json` (OpenAPI 3.1); it's
served raw at `GET /api/openapi.json` and rendered by **Scalar** at `GET /api/docs` (both
public, served by `ApiDocsController`). `OpenApiSpecTest` fails if the committed spec
drifts from the attributes — so **after changing a route or a response shape, run
`composer docs` and commit `openapi.json`** (and `npm run gen:types` for the frontend
types). `Routes\OpenApiSpec` holds the global metadata (info, server, security schemes,
tags), the shared schemas no Structure owns (`SpreadPosition`, the reading payloads,
`DeckSystemWithCards`), and `builder()`, the one build both `composer docs` and the test
run. That build drops swagger-php's `Properties` augmenter (it turned every property
initializer into a `default`) and adds `Routes\RequireStructureProperties`, which marks
every property of a `Tarot\Structure` schema required (their `jsonSerialize()` always
sends all of them) — except one marked `x: ['admin-only' => true]`, a field the public
endpoints strip with `jsonSerializeWithout()` (`submitted_by`).
**A schema nothing references is dropped** (swagger-php's `Cleanup` augmenter), from
`openapi.json` and from the generated types: point the endpoint that sends a Structure
at it (`ref: '#/components/schemas/…'`), never at a bare `type: 'object'`.
`HttpTestCase::assertDocumented()` checks a real response against the committed spec
(`$ref`, `oneOf`, `allOf`, arrays, nested refs).
The Scalar bundle is pinned to one version with Subresource Integrity, and `/api/docs`
sends its own enforced CSP (`SCALAR_BUNDLE` / `SCALAR_SRI` on `ApiDocsController`); to
upgrade, change the version and recompute the sha384 of that exact file.

### Auth & security

- Sessions are PHP sessions, with the cookie's flags set in `index.php`. Admin is the `is_admin` flag on a user account, granted with `php scripts/make_admin.php you@example.com`. `AccountProtection` stops an admin demoting or deleting themselves, and guards the owner accounts named in `PROTECTED_ADMIN_EMAILS`.
- Each `sessions` row records its `user_id` (`DbSessionHandler`), so a password
  change ends the user's other sessions, a reset ends all of them, and deleting
  an account ends its sessions (`DbSessionHandler::destroyForUser`).
- **Always one way to sign in** (`SignInMethods`): password (set and not
  switched off), passkeys, Google. Switching password login off, deleting the
  last passkey or unlinking Google is refused (409) when it would leave none.
- No account enumeration: the stand-in passkey credential ids come from `PasskeyDecoys`, keyed by `<TAROT_DATA_DIR>/db/passkey-decoy.key` (created on first use). The sign-up and password-reset emails go out **after the response** under PHP-FPM (`Tarot\Utility\AfterResponse`, run by `api/index.php`), so neither answer waits on SMTP only when an address has an account; where a response can't be finished early (CLI, the dev server) they are sent inline. An admin's resend stays inline (it reports whether the email went).
- **Who may make a reading** (`POST /readings/generate`, `POST /readings`;
  `ReadingController::refuseDraw()`): with Turnstile configured, a request with
  no signed-in user (session, or a linked plugin's `tg_pat_` token) needs a
  passed challenge (`turnstile_token`, from New Draw and Recreate Draw) or an
  unlinked plugin's **draw key** (`Authorization: Bearer tg_dk_…`). Keys come
  from the `/draw-key` page (Turnstile, then PKCE to the plugin's loopback
  listener: `POST /plugin/draw-keys/authorize` → `/token`), are stored hashed in
  `plugin_draw_keys`, open nothing but draws (their readings are guests') and
  are rate-limited per key and per IP (`DrawKeyService`). Without Turnstile (dev,
  the tests, the end-to-end suite) nothing more is needed.
- `OriginGuard` middleware is CSRF defense-in-depth (rejects cross-origin
  state-changing requests). Added last so it runs outermost.
- CORS is **opt-in** via `APP_ORIGIN`.
- Passkeys/WebAuthn via `lbuchs/webauthn`; optional Google OAuth.
- In production the DI container is compiled to disk and error detail is hidden.

### og.php (every page) and the Content-Security-Policy

Every page route is rewritten to `og.php`, which serves the built `dist/index.html`
with two things only a server can add (the shell is never cached, so this costs a
little PHP per navigation):

- **An enforced CSP with a fresh nonce per response**
  (`Tarot\Utility\ContentSecurityPolicy`). Cloudflare injects scripts into our pages —
  Bot Fight Mode's JS detection (an inline script that differs on every request) and
  the Web Analytics beacon — and stamps the header's nonce onto them, so `script-src`
  needs no `'unsafe-inline'`. Our own code needs no nonce (one external module
  script). A new third-party script host must be added to that class, or it is blocked.
  `CSP_MODE=report-only` in the live `.env` downgrades to report-only without a deploy.
  `/index.html` and `/dist/index.html` also go through `og.php` (a static copy would
  be a CSP-free bypass). `/api/docs` sends its own policy.
- **Share meta for `/reading/{id}`:** crawlers can't run the SPA, so the generic title
  and og:/twitter: tags are swapped for the reading's (never leaking a
  password-protected reading). Other pages get the shell exactly as built.

---

## 5. Frontend architecture

- **API access:** the framework-free client lives in `composables/apiClient.ts`
  (re-exported from `useApi.ts`, so import either). Pick by how much you need to
  know about failures:
  - `apiFetch<T>()` — returns the data or `null`; for "just give me the list".
  - `apiRequest<T>()` — returns a discriminated `ApiResult<T>` with `ok`,
    `status`, the server's `error` message, the parsed `data` (read structured
    fields like a validation `errors` array), and `networkError`. Use it when a
    caller must tell failures apart or show *why* something failed (`useUser` does).
  - `useAdminApi()` — Vue-aware admin wrapper (auto 401→login, toasts errors).

  All paths are relative to `/api`; build bodies with `sendJson(method, body)` (or
  `sendForm(params)` for the two form-encoded reading calls). The backend returns
  `{ error: '...' }` on failure and `ApiResult.error` carries it, with your fallback
  when there is none. Note
  `apiClient.ts` imports nothing local (so `useUser` can use it without an import
  cycle); the Vue-aware `useAdminApi` stays in `useApi.ts`.

  **A raw `fetch(` anywhere else is a lint error** (`tarot/api-client-only` in
  `eslint.config.ts`). Calls belong in a composable: `useReadings` (generate,
  custom, get, unlock, draw more, finalize, placement), `useSubmissions` (public
  deck / deck-system / spread submissions), `useAccount` (incl. sign out of
  other devices and the journal export links), `usePasskeys`, `useUserSpreads`,
  `useDecks`, `useContact`, `useUser`, `useCardFrequency`, `useDailyCards`.
- **Types:** `src/types/api.generated.ts` is **generated** from `backend/openapi.json`
  via `npm run gen:types` (openapi-typescript). `src/types/index.ts`
  re-exports every entity the API sends as a backend `Structure` from it (`User`,
  `Deck`, `Spread`, `CardReport`, …), so a PHP property change reaches the frontend
  types with no hand copy. So do the payloads a controller assembles itself once
  the spec names them as a schema (`ReadingView`, `UsageStats`, `DeckSystemWithCards`
  in `OpenApiSpec.php`). Only client-side shapes are written by hand there. The HTTP
  tests' `assertDocumented()` keeps the spec honest about what each endpoint sends.
- **Routing/guards:** `src/router/index.ts`. Route `meta` flags drive guards:
  `admin` (requires `is_admin`), `userOnly` (requires login),
  `userGuest` (redirect away if logged in). Page titles come from `meta.title`.
- **Session bootstrap:** `main.ts` calls `fetchMe()` before mounting so guards
  have user state immediately; the router periodically revalidates.
- **Constants/storage:** keys live in `src/constants.ts` under the `tarot.`
  namespace.
- **Icons:** FontAwesome is self-hosted and registered in `src/fontawesome.ts`.
  Only the icons actually used are imported (tree-shakeable) and collected into a
  `byPrefixAndName` lookup; templates render them with the `<FontAwesomeIcon>`
  component, e.g. `:icon="byPrefixAndName.fas['house']"`. **Footgun:** if you use a
  new icon in a template without adding its import + `index(...)` entry here, the
  lookup is `undefined` and it won't render — always register it. Icons come from
  the per-style Pro packages, so there are no CDN requests.
- **Styling:** Bulma utility classes + CSS custom properties (`--myst-*`) in `src/assets/tokens.css` (the palette source of truth, mapped onto Bulma's own variables). Layout and spacing come from Bulma's helpers (§13). The theme is dark only (`data-theme="dark"` on `<html>`). Stylelint's palette rule takes `tokens.css` as the token file, and this app's own rule also refuses any `rgb()` but pure white or black, so a tint is always `color-mix()` over a token. Text on a gold fill (an `is-link` button) is dark ink, as on the aqua primary (`--bulma-link-invert-l` in `tokens.css`): Bulma's white read at 2.4:1. The quietest text, Bulma's `.has-text-grey` (hints, empty states), reads `--myst-text-grey` (`tokens.css` overrides the class): Bulma's fixed `#69748c` read 2.7–4.2:1 here, and the token reaches 4.5:1 on every surface, a hovered `--myst-surface-3` included; print has its own, darker value. The end-to-end suite holds the main pages to WCAG 2.2 AA (§8; the AAA target is a follow-up, §13). Bulma is a modular build (`src/assets/bulma.scss`): only the modules in use, and only the dark theme's variables; a Bulma class that does nothing is probably a module that isn't imported (see that file's header).
- **Admin list pages** sit in `components/admin/AdminListPage.vue` (column width,
  back-to-dashboard link, `PageHeader` with an `#actions` slot) and use
  `AdminSearchField` for the search box; the view keeps its own tables.
- **Form labels:** the custom controls that take an `id` and put it on their focusable element are `BaseSelect`, `NumberField`, `ToggleSwitch`, `DeckComboBox` and `SpreadComboBox`. A caption over a group (`SegmentedControl`, several inputs) or over `MarkdownEditor` is a `<span class="label" :id>` that the group or editor names with `aria-labelledby`.
- **Interactive UI (Reka UI):** dialogs, menus, popovers, tooltips, comboboxes,
  selects, switches, segmented controls, number fields, disclosures, pagination,
  and toasts are Reka UI headless primitives wrapped in shared components
  (`BaseModal`, `ConfirmDialog`, `DeckComboBox`/`SpreadComboBox`,
  `SegmentedControl`, `ToggleSwitch`, `Tooltip`, …); use these.
- **Heading font:** headings read the `--myst-heading-font` CSS var, set once in
  `tokens.css` to the self-hosted **Crucial** (`src/assets/fonts.css`,
  `public/fonts/`). (The old multi-font audition switcher was retired.)

---

## 6. Build, run, and test commands

Run from the project root. Shell is **PowerShell** on Windows; chain with `;`.

`Taskfile.yml` runs the same commands from the repo root: `task setup`, `task dev` (both servers), `task gen` (the spec, then the frontend's types), `task lint`, `task test`, `task check`, `task vuln`, and `task deploy` (everything: the backend and frontend in one upload, then the plugin when its version is new; `deploy:frontend`, `deploy:backend` and `deploy:plugin` deploy one each). `task lint:scripts` runs the shared `shared:lint:scripts`; the other shared tasks are `shared:lint:md`, `shared:repo:check`, `shared:version:check` and `shared:update`.

### Frontend (npm) — run from `frontend/`

```powershell
cd frontend
npm install            # install deps (needs the Font Awesome token in ~/.npmrc and the shared/ submodule — see below)
npm run dev            # Vite dev server :5173 (proxies /api and /assets to localhost:80)
npm run build          # production build to frontend/dist/
npm run preview        # preview the production build
npm run type-check     # vue-tsc on tsconfig.app.json + tsconfig.node.json (the root tsconfig only lists references)
npm run lint           # eslint . (flat config; TS + Vue SFCs, incl. template accessibility)
npm run lint:fix       # eslint . --fix
npm run lint:css       # stylelint (styles in .css/.scss/.vue; incl. the palette rule, §5)
npm run knip           # unused files, exports and dependencies (knip.ts)
npm run format         # prettier --write . (format:check is CI's check)
npm test               # vitest run (single pass)
npm run test:watch     # vitest watch mode
npm run test:coverage  # vitest + coverage; fails below the floor in vite.config.ts
npm run test:e2e       # Playwright: builds the SPA, then the end-to-end suite on it and the real API (§8)
npm run gen:types      # regenerate src/types/api.generated.ts from backend/openapi.json
npm run gen:check      # gen:types, then fail if the committed api.generated.ts differs (CI)
```

> **The Font Awesome token goes in `~/.npmrc`** (`//npm.fontawesome.com/:_authToken=…`). `frontend/.npmrc` maps the `@fortawesome` and `@awesome.me` scopes to that registry and sets `install-links=true`. CI writes the token from the `FONTAWESOME_NPM_TOKEN` secret.
>
> **`@jwill89/lint-web` and `@jwill89/test-a11y` install from the submodule** (`file:../shared/lint/web`, `file:../shared/test/a11y`).

### Backend (composer / php) — run from `backend/`

```powershell
cd backend
composer install       # install PHP deps
composer dev           # PHP built-in server on :80 via dev-router.php (the API backend)
composer test          # run PHPUnit (auto-discovers backend/phpunit.xml)
composer test:coverage # PHPUnit + coverage; fails below 95% of lines or 88% of methods (shared/test/php/coverage-check.php)
composer stan          # PHPStan static analysis (backend/phpstan.neon, level 8, --memory-limit=512M baked in)
composer lint          # php-cs-fixer check (the code style CI enforces)
composer lint:fix      # php-cs-fixer fix (apply it)
composer rector        # Rector: current PHP idioms, dead code out (rector.php); rector:check lists without changing
composer deps:check    # composer-unused + composer-require-checker (every package used, every use declared)
composer normalize     # composer.json key order/format
composer normalize:check # the same with --dry-run: fails if composer.json isn't normalized (CI)
composer docs          # regenerate backend/openapi.json from the swagger-php attributes
composer docs:check    # docs, then fail if the committed openapi.json differs (CI)
composer migrate       # run the pending migrations (db/migrations/) on Paths::database() (§7)
composer migrate:status # which migrations have run there
php scripts/dump_schema.php # regenerate db/schema.sql from the migrations (§7)
vendor\bin\phpunit     # run PHPUnit directly
```

> PHPStan runs the shared level 8 (`shared/lint/php/phpstan.neon`, which `phpstan.neon` includes) with `phpstan-phpunit` beside the shared rule sets, loaded by `phpstan/extension-installer`. The baseline (`phpstan-baseline.neon`) holds one pre-existing finding; regenerate it with `composer stan -- --generate-baseline`.
>
> **`composer.json`'s `conflict` holds `phpstan/phpstan` at 2.3.3** (2026-10-28):
> PHPStan 2.3.4's phar bundles a newer phpdoc-parser (with `FullyQualifiedNameNode`)
> than Rector 2.8.2 bundles (2.3.11), and Rector then crashes on every file with
> a `callable` docblock type. Drop the conflict once a Rector release supports
> PHPStan 2.3.4 (`composer rector:check` passes with it).

To serve the full app locally, run **two processes in two terminals**: from
`backend/` run `composer dev` (the PHP backend on port 80) and from `frontend/` run
`npm run dev` (the Vite SPA on :5173); `task dev` starts both. The Vite proxy forwards `/api` and `/assets`
to `http://localhost:80`, where `dev-router.php` reproduces the production
`.htaccess` routing (`/api/*` → `api/index.php`, files under `/assets/*` served from
disk) for PHP's built-in server, which has no `.htaccess`. Any PHP host serving
`/api` on port 80 (e.g. Apache) works too.

> **swagger-php is dev-only on purpose.** PHP never loads an attribute's class
> unless something reads the attribute, and only `composer docs` (and
> OpenApiSpecTest) do, so production runs without the package; nothing at runtime
> calls `OpenApiSpec::builder()` or loads `RequireStructureProperties`, and
> deploy.ps1 doesn't ship `bin/`. `composer-require-checker.json` whitelists its
> symbols by name (the tool matches exactly); a new `OAS\*` attribute type fails
> `composer deps:check` until it is added there. So is `fastcgi_finish_request`,
> which PHP-FPM itself provides (no package or extension declares it).
>
> **Gotcha — don't `import` files under `/assets` in Vue.** `/assets` is proxied
> to the PHP host, so referencing e.g. the brand logo as `<img src="/assets/...">`
> would make `@vitejs/plugin-vue` compile it into an asset-module import that
> returns raw image bytes (not JS) and blanks the app. Bind it as a runtime
> string instead (`const brandLogo = '/assets/favicon.png'`; `:src="brandLogo"`).

### Always do after changes

- After editing **frontend** TS/Vue: run `npm run type-check`, `npm run lint`,
  `npm run lint:css` (styles), `npm run knip`, and the relevant `npm test` specs.
- After editing **backend** PHP: run `composer test`, `composer stan`,
  `composer lint`, and `composer rector:check`; after touching `composer.json` or
  adding a dependency, `composer deps:check` and `composer normalize`.
- After editing **`scripts/*.ps1`**: `task lint:scripts`, which runs `shared:lint:scripts` (`shared/lint/pwsh/Invoke-ScriptLint.ps1`: PSScriptAnalyzer with the shared settings on every tracked PowerShell file; CI's `scripts` job runs the same script). Install PSScriptAnalyzer once with `Install-PSResource PSScriptAnalyzer -Version 1.25.0 -Scope CurrentUser -TrustRepository`. The deploy script needs PowerShell 7.4 (`pwsh`).
- After editing **Markdown**: `task shared:lint:md` (`npx --yes
  markdownlint-cli2@0.24.0` from the repo root, CI's `docs` job).
- `task lint` also runs the plugin's `dotnet format`.

### Deployment

The site runs on a DigitalOcean droplet (Apache on the event MPM + **PHP 8.5
FPM**, since 2026-11-03; one pool shared with the droplet's other PHP sites) at
`/var/www/tarotgen.io`, a single **flat** web root. OPcache re-checks files every
2 s, so a deploy needs no FPM reload; `SetEnv TAROT_DATA_DIR` in the vhost
(`deploy/apache-vhost.conf`, shipped by the deploy) reaches FPM; `.htaccess`
`php_value` lines would be ignored under FPM (there are none).
PHP errors land in `/var/log/apache2/error.log` (`AH01071 … PHP message:`) and
`/var/log/php8.5-fpm.log`, beside the other sites': filter by `/var/www/tarotgen.io`. `scripts/deploy.ps1` flattens
`frontend/dist` and the `backend/` runtime files into it. It is built on
shared-tooling's **SharedDeploy** module (`shared/deploy/pwsh`, whose README
describes each function) and needs PowerShell 7.4 and PuTTY's `plink` 0.85 or
later (`pscp` is no longer used), with the DigitalOcean `.ppk` key, loaded into
Pageant first if it has a passphrase. The deploy config names its SSH user (`VpsUser`, with no default); the backend steps run `runuser` and install cron and systemd files, so that user needs root's rights.

```powershell
.\scripts\deploy.ps1                    # both (default): backend, then frontend, in one upload and one remote run
.\scripts\deploy.ps1 -Target frontend   # npm build + dist swapped in by renames
.\scripts\deploy.ps1 -Target backend    # PHP code built whole on the droplet, DB snapshot, swap, then phinx migrate
.\scripts\deploy.ps1 -Target plugin     # build the plugin (Release) + publish to its custom Dalamud repo (§12)
.\scripts\deploy.ps1 -Force             # the release again, though the droplet runs it
.\scripts\deploy.ps1 -Preview           # try the working tree, untagged, stamped preview-<date-time>
.\scripts\deploy.ps1 -Target backend -NoDbPull # don't copy the live DB into backend/db/tarotdb.db afterwards
```

Settings resolve from the parameter, then `$env:DEPLOY_*`, then the gitignored
`scripts/deploy.config.ps1` (copy `scripts/deploy.config.example.ps1`; `-ConfigPath`
names another file): `VpsHost`, `VpsUser` (no default: it must be set somewhere),
`KeyPath`, `WebRoot`, and `SiteUrl`, the public address the smoke check fetches and
the plugin's download links are built from (default `https://tarotgen.io`). The
vhost's settings, which a plugin-only run doesn't need: `ServerAdmin` and `CertName`
(the folder under `/etc/letsencrypt/live` whose certificate covers every name), with
no defaults, and the optional `ServerName` (the host of `SiteUrl` unless set; it also
names the vhost file) and `ServerAlias` (more names, space-separated).
`-NoBackup` removes the rollback copies once the run succeeds on the host, so a
failed smoke check then only stops the run; the database snapshot is still taken.
`Get-Help .\scripts\deploy.ps1 -Full` walks through the whole run.

The script flattens the repo into the server layout and **never** uploads the
server-side data: `.env` and the database (which live **outside the web root**, in
`TAROT_DATA_DIR` = `/var/lib/tarotgen` — see §9), `assets/decks/` or the retired
one-off scripts in `db/`. Everything the script does upload lands in the document
root, so `backend/.htaccess` must keep refusing `db/`, `includes/`, `vendor/`,
`scripts/`, `.deploy/`, `.env*`, `composer.*` and `phinx.php` (they were once
served verbatim); the deploy swaps the `.htaccess` in first, so its rules are in
place before the files they guard.

**One archive, one connection, one remote run**, as the shared module does it (Windows `tar.exe`, ustar; `plink -share`). The one held connection, plus one more only if it drops and reconnects, keeps the run under the droplet's `ufw limit ssh` (6 connections in 30 s). The archive streams to the SSH user's home, and the remote run takes each target's step, API first:

- **the vhost** (`deploy/apache-vhost.conf`, filled from the settings when the archive
  is packed) goes in with the first of the backend and frontend steps, before that step
  changes anything. SharedDeploy installs it as
  `/etc/apache2/sites-available/<ServerName>.conf` where it differs (the previous kept
  as `.conf.old`), enables it, and disables every other enabled site serving its names,
  such as certbot's `-le-ssl` file; those stay in `sites-available`. It tries Apache
  with the change on a copy of its config first and refuses, changing nothing, if
  Apache would; Apache reloads once the rest of the run has succeeded, and a rollback
  puts the file and those sites back. Nobody edits the vhost on the host: a change
  goes in the template.
- **backend:** the database and the host's tools (`composer`, `php`, `sqlite3`,
  `gzip`, `runuser`) are checked first. The new code is built whole in
  `.deploy/next`: the upload, a copy of the live `vendor/` with `composer install
  --no-dev --optimize-autoloader` run on it (every deploy, so new code never runs
  on an old vendor; starting from the copy, it fetches only what `composer.lock`
  changed), and the retired scripts in `db/` carried over (§7). Then the database
  is snapshotted (below) and every entry swapped in by renames, the previous ones
  kept in `.deploy/backend-prev`. Then, from the web root:
  `runuser -u www-data -- env TAROT_DATA_DIR=/var/lib/tarotgen php vendor/bin/phinx migrate`.
  If it fails, the previous code goes back, and Phinx's message, the snapshot and
  the exact `gunzip -c` command that restores it are printed. The database itself
  is never put back automatically.
- **frontend:** `dist` swapped in by renames, the previous kept as `dist.old`.
- **plugin:** refuses a version not higher than the published `repo.json`'s
  (checked here before the build, too), then swaps `$WebRoot/plugin` in, the
  previous kept as `plugin.old`.

**The snapshot** before the migrations is
`/var/lib/tarotgen/db/tarotdb.db.pre-<yyyyMMdd-HHmmss, UTC>.gz`, written as
www-data: `sqlite3 .backup` to a partial file, `PRAGMA integrity_check`, gzip,
`gzip -t`, then the rename; the newest three are kept. It is taken before the
swap, so a snapshot that fails changes nothing. The restore command the deploy
prints, with the site's writes stopped:
`runuser -u www-data -- sh -c 'rm -f <db>-wal <db>-shm && gunzip -c <snapshot> > <db>'`,
where `<db>` is `/var/lib/tarotgen/db/tarotdb.db`.

**The smoke check** then fetches the site from this machine, through Cloudflare
(`SiteUrl`), each URL with a fresh `?deploy=` token so no edge-cached answer
stands in for the origin's. With the backend, `/api/decks` must list a deck (the
API and the database answer). `/` must answer; with the frontend it must name this
build's entry script, which must load. With the plugin, `repo.json` must carry
this release's `?v=`. If a check fails, what the run changed goes back, the plugin
and the frontend first, then the code. The database stays as the migrations left
it, and the snapshot's restore line is printed.

**After it passes**, a backend deploy takes three more steps on the same
connection:

1. **The daily backup** is installed, or found unchanged (see "Backups").
2. **The old nightly backup is retired:** `/etc/cron.d/tarotgen` becomes
   `scripts/server/tarotgen.cron` where it differs, and
   `/usr/local/sbin/tarotgen-backup` is removed.
3. **The live database is pulled** into `backend/db/tarotdb.db`, the one the app
   opens locally, keeping the three previous local copies (`-NoDbPull` skips it).

**Automation.** `.github/workflows/ci.yml` calls shared-tooling's reusable
workflows at `@v2.1.0`, the release the submodule holds:

- `frontend` (`web.yml`): an `npm audit` of what ships, then `gen:check`,
  `type-check`, `lint`, `lint:css`, `knip`, `format:check`, `test:coverage` and
  `build`;
- `backend` (`php.yml`): `audit`, `validate`, `docs:check`, `normalize:check`,
  `deps:check`, `lint`, `rector:check`, `test:coverage` (pcov) and `stan`;
- `e2e` (`e2e.yml`, after those two): the Playwright suite (§8);
- `plugin` (`dotnet.yml`): the Release build with warnings as errors, and
  `dotnet format` (§12);
- `scripts` (`pwsh.yml`): PSScriptAnalyzer on every tracked script;
- `docs` (`docs.yml`): markdownlint, `.editorconfig` and `.gitattributes` against
  `shared/repo`, and every workflow ref against the submodule's release.

TarotGen's own `release` job waits for all six. `plugin.yml` only packages the plugin (`latest.zip` + manifest, kept a week) when `plugin/`, `shared`, `.editorconfig` or the workflow itself changes, and `codeql.yml` covers the frontend, the plugin and the workflows (CodeQL has no PHP). `.github/dependabot.yml` opens weekly grouped updates for npm, Composer, NuGet and Actions; it skips TypeScript's major, vue-tsc 3.3.14 and the majors of the tools `lint/web` owns, and Composer's `conflict` holds PHPStan (§10). `FONTAWESOME_NPM_TOKEN` is also a **Dependabot** secret, for its npm job and for CI on its pull requests. The Font Awesome registry is declared there with no `scope:`, because with one Dependabot writes its own `.npmrc`, which loses `install-links=true`.

**A release with a migration** needs nothing by hand: the backend deploy
snapshots the database and runs `phinx migrate` as above (§7, §10 "Schema
changes"), once the release's `phinx.php`, `db/migrations/` and a `vendor/` with
Phinx are in place.

**Backups.** `tarotgen-backup.timer`, installed and kept by the backend deploy
(SharedDeploy's `Install-BackupTimer`), runs `tarotgen-backup.service` as
www-data at 10:00 UTC daily (up to 15 minutes' spread; a missed run happens at the
next boot). Its job (`/usr/local/libexec/shared-deploy/tarotgen-backup`) copies
the live DB with `VACUUM INTO`, checks it with `PRAGMA integrity_check`, gzips it
(`gzip -t`) and keeps it as
`/var/lib/tarotgen/db/backups/tarotgen-YYYY-MM-DD.db.gz` (UTC date) for **28
days**. Check runs with `journalctl -u tarotgen-backup`. Take one by hand (before
a migration run by hand, say) with `sudo systemctl start tarotgen-backup`, which
replaces that day's copy. Restore: stop writes, then as www-data delete
`tarotdb.db-wal`/`-shm` beside the DB and `gunzip -c <copy> >
/var/lib/tarotgen/db/tarotdb.db` (the job's header on the host has the line with
its paths). The copies stay on the droplet; the copy off it is each deploy's
database pull. The old nightly copies in `/var/backups/tarotgen/nightly` stay
until the owner deletes them: check `journalctl -u tarotgen-backup` first, then
`sudo rm -r /var/backups/tarotgen/nightly`.

**Retention.** `/etc/cron.d/tarotgen` (`scripts/server/tarotgen.cron`, root:root
644, LF; the backend deploy installs it whenever it differs) runs
`backend/scripts/retention.php` as www-data at 10:30 UTC, after the backup's
window, and only if today's backup exists, so there is always a copy from before
it; otherwise it logs that it skipped. (`scripts/` ships with `-Target backend`;
the deploy stops before changing anything if the cron file doesn't name the web
root's `scripts/retention.php` and the backups' folder.) It deletes **guest** readings (`user_id IS NULL`) older than 365 days —
readings made while signed in, on the site or via a linked plugin, are never
touched — and those past the earlier expiry a guest chose; deletes plugin draw-key
codes that expired unredeemed (`plugin_draw_keys` rows with no key; issued keys
stay); then runs `ANALYZE` (not `PRAGMA optimize`: prod's SQLite 3.45 ignores its
check-every-table bit). The window and the backups' period are stated on the
privacy page (`PrivacyPolicyView.vue`, "Data Retention"); `PrivacyPolicyView.spec.ts`
checks them against `retention.php` and the deploy's `Install-BackupTimer` (its
`-Keep`, else the module's 28 days), so change them together. Check runs with
`journalctl -t tarotgen-retention`; preview with `runuser -u www-data -- php
scripts/retention.php --dry-run`.

---

## 7. Database

- Single SQLite file at `Tarot\Config\Paths::database()`: `<TAROT_DATA_DIR>/db/tarotdb.db` in production, `backend/db/tarotdb.db` locally. `Tarot\Database\Connection` (a PDO singleton) opens it with the shared pragmas and never creates it, so a mis-set `TAROT_DATA_DIR` is an error.
- **Schema changes are Phinx migrations** (`robmorgan/phinx`, since 2026-11-06), in
  `db/migrations/` (committed), run in order and recorded in the DB's `phinxlog`
  table. `phinx.php` points Phinx at `Paths::database()`, so `TAROT_DATA_DIR`
  picks the same file for the site, the scripts and the migrations; it opens the
  file `mode=rw`, so like `Connection` it **never creates a database** (a wrong
  path is an error). `composer migrate` runs what is pending, `composer
  migrate:status` lists what has run. A new local DB: create an empty file
  (`New-Item db/tarotdb.db`), then `composer migrate`.
- **The baseline** (`20261106000000_baseline.php` + its frozen `.sql`) is the schema
  the old one-off scripts had built, which production has. On an empty DB it
  builds it, seeds included. On any other DB it checks that every table and index
  it defines is there as defined (layout and identifier quotes aside), changes
  nothing and is recorded as run; anything else (a partly migrated or drifted DB,
  another app's DB) is **refused** with a list of what is missing or different,
  and nothing is recorded. A local dev DB that missed one of the old scripts gets
  that refusal: bring it up with the old script, or start from an empty file.
  `20261106000001_drop_retired_tables.php` then drops the share-relay and
  changelog tables `IF EXISTS` (AUDIT §2.1, §9.13; the docs say production
  already lost them, so there it does nothing).
- **Adding a migration:** `vendor\bin\phinx create AddThingToReadings` writes
  `db/migrations/<UTC timestamp>_add_thing_to_readings.php` with an empty
  `change()`. For SQL, replace it with `up()` (`$this->execute(...)`) and a
  `down()` where the change can be undone, else have `down()` throw
  `IrreversibleMigrationException`. Each runs in one transaction, so one that throws leaves nothing
  behind. Phinx's connection has foreign keys **off**, and `PRAGMA foreign_keys`
  can't change inside the transaction: a table rebuild (create, copy, drop,
  rename) must end with `PRAGMA foreign_key_check` and throw if it returns rows.
  Then run it (`composer migrate`), regenerate `db/schema.sql` (below) and commit
  both. Test it against a DB built from the old `schema.sql`
  (`tests/Unit/Database/BaselineMigrationTest.php` shows how).
- **`db/schema.sql` is generated, never edited:** `php scripts/dump_schema.php` runs
  every migration on an empty throwaway DB and writes what they built (objects in
  creation order, then each table's seed rows). It never reads your local DB, so
  local drift can't leak into it. The tests load it; `SchemaTest` fails when it
  isn't exactly what the migrations build.
- **On the droplet** the backend deploy runs them, after its snapshot (§6
  "Deployment"): from the web root,
  `runuser -u www-data -- env TAROT_DATA_DIR=/var/lib/tarotgen php vendor/bin/phinx migrate`.
  By hand, run the same line after a backup (`sudo systemctl start
  tarotgen-backup`); `TAROT_DATA_DIR` also comes from `/etc/environment` in SSH
  sessions, but pass it explicitly anywhere else.
  The database's own user, which anything that writes in its folder runs as, is `www-data`.
- The old one-off scripts (`db/migrate_*.php`, `add_*`, `drop_*`, plus
  `check_state.php` and friends) stay where they are, gitignored, locally and in
  `<WebRoot>/db/` on the droplet. They are retired: the baseline is their end
  state, and nothing new goes there. The live `tarotdb.db` remains the source of
  truth for *data*, so treat it as precious and back it up before migrating.

---

## 8. Testing conventions

- **Frontend (Vitest):** specs are named after the unit. The setup file, `vitest.setup.ts`, stands in for `scrollIntoView`, pointer capture, `ResizeObserver`, `scrollTo`, and the Clipboard and Credential Management APIs. Composables and utils are tested as units; components and views with `@vue/test-utils` through `src/__tests__/helpers/app.ts`: `mountWithApp` (as `main.ts` mounts: the global FontAwesome components and a router on the real route table, `src/router/routes.ts`, without the guards; attached to `document.body`, where Reka portals dialogs, tooltips and combobox lists, so query `document.body` for those), `afterEach(cleanup)`, `stubApi({ 'GET /decks': { body: [...] } })` (an unexpected call fails the test, so a spec states every call its view makes), `testUser` and `signIn`. The module-level state to reset in `beforeEach` is the signed-in user, toasts and favourites. `npm run test:coverage` fails below the floor in `vite.config.ts`.
- **End-to-end suite (Playwright, `npm run test:e2e`, CI job `e2e`):** 18 tests on
  the built app and the real API. `e2e/pages.spec.ts` opens each of the main
  pages by its address, checks its heading and what the server sent for it, and
  requires it to pass axe at WCAG 2.2 AA (`@jwill89/test-a11y`) with no page error
  or CSP refusal (`e2e/breakage.ts`). `e2e/smoke.spec.ts` draws a reading as a
  guest, reports a card and finds the report as admin. `playwright.config.ts`
  spreads shared-tooling's `playwrightDefaults`. Its web server builds the SPA (`vite
  build`, so no `npm run build` first), runs `e2e/server/prepare.php`, then
  starts PHP's built-in server on `127.0.0.1:8123` with `e2e/server/router.php`
  (`backend/.htaccess`'s routing: the SPA through `og.php` and its CSP, `/api`
  through the Slim app). `prepare.php` makes a throwaway `TAROT_DATA_DIR` in the
  OS temp dir (`tarotgen-e2e`; it refuses a path inside `backend/db/`) with a
  `.env` and a database the migrations build, seeded with a deck system, a deck,
  a spread, a guest's reading and an admin, and copies `dist/` to
  `backend/dist/` (gitignored), where `og.php` reads the shell. The server runs
  with `sys_temp_dir` inside that data dir, so the file-backed rate limiters
  start empty each run (earlier local runs can't trip them).
- **Backend (PHPUnit):** namespace `Tarot\Tests\`, two suites (`phpunit.xml`).
  - `tests/Unit/` — one class at a time. Service-layer tests pass an **in-memory
    SQLite PDO** to Data classes for isolation.
  - `tests/Http/` — whole requests through the real app: extend `HttpTestCase`,
    which builds `api/app.php` on an in-memory database loaded from
    `db/schema.sql` (foreign keys on) and acts as one browser, carrying the
    session id from request to request (sessions really are stored, by
    `DbSessionHandler`). Helpers: `get`/`post`/`request`, `assertJsonResponse`,
    `createUser` + `signInAs` (no password hashing), `service()` for the
    container, `rows()` for what landed in the DB, `setEnv()`, and
    `assertDocumented()` — the payload matches the response schema in the
    committed `openapi.json` (no missing or undocumented fields, nested schemas
    included). Env is blanked per
    test (no SMTP, Turnstile or OAuth; not production) and each test gets its own
    client IP for the file-backed rate limiters.
    `replaceService()` swaps in a stand-in for something that talks to the outside
    world (Google, SMTP) before the first request.
  - `tests/Unit/Database/` runs the real migrations (`Tarot\Database\Schema::migrate()`,
    phinx.php's settings) on scratch files: `SchemaTest` holds `db/schema.sql` to
    what they build, `BaselineMigrationTest` holds the baseline to leaving a DB
    built from `schema.sql` exactly as it was, and to refusing a partial one.
  - `composer test:coverage` (CI) fails when line or method coverage drops below
    the floors its last command passes to shared-tooling's
    `shared/test/php/coverage-check.php` (`--lines=95 --methods=88`, in
    `composer.json`); locally it needs Xdebug or pcov.

---

## 9. Configuration / environment

- Copy `.env.example` to `.env` and fill in values. Loaded by `Tarot\Config\Env`
  in `api/index.php` from `Tarot\Config\Paths::envFile()`.
- **`TAROT_DATA_DIR`** (process/Apache environment, not `.env`) names the private
  directory holding `.env` and `db/tarotdb.db`. Production: `/var/lib/tarotgen`,
  set by `SetEnv` in the Apache vhost (web), `/etc/environment` (SSH sessions) and
  `/etc/cron.d/tarotgen` (cron). Unset locally, where it falls back to `backend/`.
- Read a setting through `Tarot\Config\Env`, never `getenv()` directly; only `Paths` reads `TAROT_DATA_DIR` from the process environment.
- Key vars: `APP_ENV` (`development`/`production`, case-insensitive),
  `APP_ORIGIN` (CORS), `APP_URL` (links in emails), `SMTP_*` / `MAIL_*`
  (transactional email), `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` (optional
  OAuth), `CLOUDFLARE_TURNSTILE_*` (login and contact form, optional),
  `PROTECTED_ADMIN_EMAILS` (comma-separated accounts no one can demote or
  delete; set it on production).
- When `APP_ENV=production`: detailed errors are hidden, session cookies get the
  Secure flag, and the DI container is compiled.
- When SMTP is unconfigured outside production, the activation link is returned
  in the API response so sign-up still works in dev.

---

## 10. Conventions & expectations for changes

- **Tests:** coverage is held high on purpose (AUDIT §8: ~96% of backend lines, ~97% of frontend lines), with the floors in `vite.config.ts` and `backend/composer.json` (`test:coverage`). A route's HTTP test (`tests/Http`) checks its success path with `assertDocumented()`; a frontend spec mounts through the helpers in `src/__tests__/helpers/`. A change on the core path (draw → reading → lightbox → report → admin) updates `e2e/smoke.spec.ts`.
- **Versioning starts again from 5.0.0** (2026-10-28). The version lives in `frontend/package.json`, and the bump follows the guide's [docs-and-agents.md](shared/standards/docs-and-agents.md) ("Choosing the bump"): here the Dalamud plugin is the client outside the site that relies on the API, so a change installed plugins must follow is a major. The admin dashboard's version tag shows `CHANGELOG.md`, read in at build time (`virtual:changelog`, `utils/changelog.ts`); the changelog table, page and API were retired in 5.4.0.
- **Refusals** use the guide's statuses. The cases here: a 404 for another user's reading or personal spread and for a pending deck, a 403 for a protected admin, and a 409 for a taken name or a change that would leave no way to sign in. Today a failed validation answers 400 and every error is `{ error }` (§13); the frontend shows that message (`ApiResult.error` from action composables).
- **Decided security rules** (2026-10-28; AUDIT "Open from §8"), to keep when adding features: personal data (readings, personal spreads, tokens, passkeys) is reachable only by its owner, and unapproved submissions only by admins (`AdminAuth::sessionIsAdmin()`). A new public form or auth endpoint gets a `RateLimiter` and, when configured, Turnstile, as login and contact do; the privacy page names every page using Turnstile (`PrivacyPolicyView.spec.ts` checks). Making a reading without a signed-in user needs Turnstile or a plugin draw key (§4, "Who may make a reading"; no bypass for anything else). A reading's journal fields (`question`, `querent`, tags) reach only its owner — never a shared or locked payload, an export of anyone else, or og.php's share meta (`ReadingShareMeta`). A **private** reading (`readings.is_private`; "password" is still just `password_hash`, and `visibility` is worked out in the SELECT) and an **expired** guest reading (`expires_at`) are a 404 to everyone but the owner, with generic share meta.
- **Schema changes** are Phinx migrations, made as §7 says and run by the backend deploy (§6 "Deployment"). Seed rows a fresh database needs, such as the starting `spread_categories`, go in a migration too.
- **Product boundaries (the owner's, after talking with practitioners):** the
  site is a tool for readers — no card interpretations and no AI readings.
  Reversals are an **on/off toggle**, never a rate or percentage setting:
  choosing a rate takes the spirit out of the draw, and the draw already models
  a physical "cut and turn" (`ReadingService::cutAndTurn()`), so reversals come
  in realistic runs (AUDIT §9 "Declined").
- **Held or deferred on purpose — leave them unless the reason is gone:** TypeScript stays on 6 until `vue-tsc` supports TypeScript 7.1; vue-tsc stays at 3.3.13, since 3.3.14 misreads a `//` in a static attribute and stops checking that template (Dependabot skips only 3.3.14, 2026-11-07); PHPStan is held at 2.3.3 (`conflict` in `composer.json`) until Rector works with 2.3.4; `scrollBehavior` stays until vue-router's `ScrollRestoration` leaves `vue-router/experimental` (and Back should then restore position — the owner's call); favouriting from the keyboard inside the deck/spread combobox lists is an open design question (AUDIT §6.1).
- **Match existing patterns.** New backend resource? Add a `Structure`, `Data`,
  `Repository`, optional `Service`, a `Controller` extending
  `AbstractController`, a route group in `api/app.php`, and a `tests/Http` test
  for its status codes and auth. New frontend feature?
  Add a `use*` composable + view/component and a route with appropriate `meta`.
- **Layer discipline:** controllers don't touch SQL; SQL lives only in `Data`.
- **Time** comes from `Tarot\Utility\Clock` (`now()`, `in($seconds)` for expiries, `today()`), which writes UTC, never from `date()`; the frontend reads timestamps as UTC (`utils/datetime.ts`). The form they are stored in is a departure (§13). Check the zone with `vendor\bin\phpunit -d date.timezone=Pacific/Kiritimati` (UTC+14).
- Deck card images live at `assets/decks/{deck_id}/Card_XXXX.png` with a
  `Card_Back.png` — the master scans, never modified (the plugin loads them).
  `CardImageService` derives a full-size display `Card_XXXX.webp` (lightbox,
  export) and a 400 px `thumbs/Card_XXXX.webp`, regenerating any older than its
  PNG. For one reported card, Errored Cards' **Regenerate & resolve**
  (`POST /admin/card-reports/{id}/regenerate`) rebuilds that card's images
  whatever their timestamps (SFTP clients keep a file's old mtime) and resolves
  the report, then lists the URLs to purge. Run it from the admin Decks page, or for a backfill / after an SFTP upload:
  `runuser -u www-data -- nice -n 19 php scripts/generate_card_images.php [deck_id …]`.
  The frontend builds these URLs only through `cardImageUrls()` /
  `cardBackImageUrls()` (`src/utils/cardImages.ts`). After replacing a scan,
  regenerate, then purge its URLs in Cloudflare (`/assets` is edge-cached 30 days).

---

## 11. Quick reference: where things live

| I want to…                          | Go to |
|-------------------------------------|-------|
| Add/modify an API endpoint          | `api/app.php` (route) + `api/Routes/Internal/*Controller.php` + `tests/Http/` |
| Change DB queries                   | `includes/Tarot/Data/*Data.php` |
| Add business logic                  | `includes/Tarot/Service/*Service.php` |
| Add an entity shape (PHP)           | `includes/Tarot/Structure/*.php` |
| Add a frontend page                 | `src/views/*.vue` + route in `src/router/index.ts` |
| Add reusable frontend logic         | `src/composables/use*.ts` |
| Change shared API types             | the PHP attributes, then `task gen` (§4); client-only shapes in `src/types/index.ts` |
| Use a new icon                      | register it in `src/fontawesome.ts` (import + its `index(...)` entry) |
| Change/add a heading font           | `public/fonts/` + `src/assets/fonts.css` + `--myst-heading-font` in `src/assets/tokens.css` |
| Change a DB schema                  | new migration in `db/migrations/` (`vendor\bin\phinx create`), then `php scripts/dump_schema.php` (§7) |
| Grant admin                         | `php scripts/make_admin.php <email>` |
| Adjust auth/session/CSRF            | `api/app.php` (middleware) + `api/index.php` (cookie params) + `api/Routes/Middleware/*` |

---

## 12. The FFXIV Dalamud plugin (`plugin/`)

A self-contained **C# / .NET 10** Dalamud plugin (Dalamud **API 15**,
`Dalamud.NET.Sdk/15.0.0`, Windows-only) that talks to the same public API to
draw, view, and share readings in-game. It is **built and distributed
independently** of the site — the frontend/backend deploy targets don't touch it,
and it has its own version, package, and release channel.

- **Build:** `dotnet build plugin/TarotGen.Plugin/TarotGen.Plugin.csproj -c Release`
  (needs the Dalamud dev SDK on disk — XIVLauncher installs it to
  `%AppData%\XIVLauncher\addon\Hooks\dev`). A Release build runs **DalamudPackager**,
  which emits `bin/Release/TarotGen.Plugin/latest.zip` + manifest. The plugin
  **version** comes from `<Version>` in the `.csproj` — bump it to make Dalamud
  offer an update to installed testers.
- **Analysis:** every build runs shared-tooling's .NET analyzers (recommended
  level) and C# code style (`shared/lint/dotnet`: `analyzers.props` and
  `.globalconfig`), which `plugin/Directory.Build.props` imports, so the build
  needs the `shared/` submodule. The `.csproj` keeps **warnings as errors**
  (`TreatWarningsAsErrors`, for every build, local and deploy too), with the
  NuGet vulnerability advisories NU1901–NU1904 left as warnings, so a new
  advisory can't block a release (AUDIT §6.3). `dotnet format
  plugin/TarotGen.Plugin/TarotGen.Plugin.csproj --verify-no-changes` checks the
  formatting. CI's `plugin` job (shared-tooling's `dotnet.yml`) runs the Release
  build with warnings as errors and `dotnet format` on every push and pull
  request; `.github/workflows/plugin.yml` only packages it (`latest.zip` +
  manifest, as a week-long artifact) when `plugin/`, `shared` or `.editorconfig`
  changes. Both build on Linux: the Dalamud distribution goes into
  `DALAMUD_HOME`, and `EnableWindowsTargeting` lets the Windows target build
  there.
- **Distribute:** `scripts/deploy.ps1 -Target plugin` builds Release and uploads
  `latest.zip` / `icon.png` / a generated `repo.json` (the pluginmaster) to
  `$WebRoot/plugin`. Testers add `https://tarotgen.io/plugin/repo.json` as a
  custom repo under Dalamud's `/xlsettings`.
- **Layout:** `Plugin.cs` (composition root + lifecycle), `Services/`
  (`TarotApiClient`, `CardTextureCache`, `LinkService` account link, `TokenStore`
  DPAPI, `ReadingFormat`, `SpreadDraw`, `SpreadLayout`), `Windows/` (`MainWindow`,
  `ReadingPanel`, `Ui`), `Models/ApiModels.cs` (DTOs), `Configuration.cs`.
- **Talks to the API over HTTPS** (not the session cookie). Viewing readings
  needs no auth. Drawing needs, where the site has Turnstile on, a linked
  account or a **draw key** (0.8.0+): the plugin gets one once on the site's
  `/draw-key` page and sends it only on draws (§4, "Who may make a reading").
  A linked account adds a per-account **Bearer token** for the account routes
  (`AccountAuth`). Both come through a browser loopback + PKCE flow
  (`LoopbackCodeFlow`; `/authorize` for the account, `/draw-key` for the key)
  and are stored DPAPI-encrypted (`TokenStore`); a 401 on a draw forgets the
  credential that was sent. The endpoints it uses are the `Plugin`/`Cards`-tagged
  routes in `api/app.php` (`/plugin/*`, `/account/tokens`, `/card-reports`). The chatless share relay (per-install
  client token, `plugin_clients` & co.) was removed; its tables were dropped in
  AUDIT §2.1 — a future relay gets a fresh design.
- **Conventions/gotchas:** ImGui is `Dalamud.Bindings.ImGui` (not `ImGuiNET`);
  **never block the framework thread** (all HTTP runs async off-thread; results are
  read in `Draw`); read local-player identity from `IPlayerState` (v15 removed
  `IClientState.LocalPlayer`). See [`plugin/README.md`](plugin/README.md) for the
  build/install walkthrough and the in/out-of-scope feature list.

---

## 13. Where this project departs from the standards

Each item is a place where the code still does what [`shared/standards/`](shared/standards/README.md) now rules out. Each says what the code does today, and each moves to the standard in a follow-up of its own. The first eight come from [`plans/shared-tooling-rollout.md`](plans/shared-tooling-rollout.md) (step 3's follow-ups for TarotGen.io, and its accessibility and timestamp decisions); the rest were found in this repo's docs and code on 2026-11-07.

- **Error bodies (RFC 9457):** every error is a JSON `{ "error": "…" }` body (registration's validation sends `{ "errors": [...] }`), never an `application/problem+json` problem details body.
- **Validation status (422):** most failed validations answer 400 (`withJson(['error' => …], 400)` in the controllers); a few already answer 422 (registration, the display name, a weak password). A wrong current password on a password change answers 422, where the guide says 403.
- **camelCase JSON:** JSON fields and query parameters are snake_case, like the columns (`password_protected`, `turnstile_token`, `?older_than_days=`). The plugin reads them with `JsonNamingPolicy.SnakeCaseLower`, so it must handle the new names before the API stops sending the old ones.
- **`style-src`:** the shell's CSP (`ContentSecurityPolicy::forShell()`) allows `'unsafe-inline'` in `style-src`, for the styles Vue, Reka UI, Font Awesome and Turnstile set; the policy `/api/docs` sends allows it too, for Scalar.
- **A password change and plugin tokens:** a password change or reset ends the account's sessions but leaves its plugin tokens (`plugin_tokens`), which are revoked one by one under Connected Apps (`AuthService::changePassword()`, `resetPassword()`).
- **Font Awesome:** the icons come from the per-style Pro packages (`@fortawesome/pro-solid-svg-icons`, `pro-regular-svg-icons`, `pro-duotone-svg-icons`) and `@fortawesome/free-brands-svg-icons`, not from the owner's `@awesome.me/kit-…` package.
- **Contrast (AAA):** the target is WCAG 2.2 AAA, with AA as the floor only where AAA can't be met and each such place recorded: body text at 7:1 and large text at 4.5:1. axe enforces the AA floor, and a contrast test holds AAA. (The copy of `accessibility.md` in `shared/` v2.1.0 still names AA.) Today the docs and tests set AA: `tokens.css` holds text to 4.5:1 (§5), `e2e/pages.spec.ts` runs axe at AA, and no test computes the contrast of the token pairs (Home Library's `contrast.test.ts` is the model).
- **Timestamps:** every timestamp column is `TEXT` in SQLite's space form (`2026-11-07 09:18:09`, in UTC), written by `Clock::now()` (`Y-m-d H:i:s`) or `DEFAULT CURRENT_TIMESTAMP`, with no CHECK, and the API sends that form. The guide's form is a `DATETIME` column holding `2026-11-07T09:18:09Z`, with a `strftime` default and a CHECK; each table moves in one migration (`database.md`, "Timestamps").
- **The session cookie** keeps PHP's default name, `PHPSESSID`, not a `__Host-` name.
- **The document root** is the flat web root, which holds the code, `vendor/` and `scripts/` beside `dist/`, with `backend/.htaccess` refusing them (§6). The guide puts a PHP site's document root at its `public/` folder. `.env` and the database already sit outside it, in `TAROT_DATA_DIR`.
- **Layout** comes from Bulma's helpers (columns, spacing), which the guide counts as neither of its two ways (Tailwind or object classes). They go when the app adopts the component kit.
- **State class:** the navigation marks the current page with a bare `active` class (`exact-active-class="active"` in `App.vue`), not `is-active`.
- **`Referrer-Policy`:** `backend/.htaccess` sends `strict-origin-when-cross-origin`, not `same-origin`.
- **Plugin tests:** the plugin has no test project, no game-free `Core/`, and no picture preview of its windows.
- **The PHP suite's time zone:** the suite runs in the machine's zone; the far-off-zone run (`-d date.timezone=Pacific/Kiritimati`) is done by hand (§10).
- **Markdown:** this file, API.md, CONTRIBUTING.md, README.md, plugin/README.md and AUDIT.md are hard-wrapped near 80 columns. MD013 is off, so markdownlint passes. A paragraph rewritten from now on is one line.
