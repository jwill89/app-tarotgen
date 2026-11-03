# AGENTS.md

Operational guide for AI agents and developers working in this repository. Read
this first to understand how the project is laid out, how the pieces fit
together, and the conventions to follow when making changes.

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
| Frontend    | Vue `^3.5`, vue-router `^5.1`, TypeScript `~6.0` (`vue-tsc ^3.3`), Vite `^8`, Bulma `^1.0`, Reka UI `^2.10` |
| Editor/MD   | TipTap `^3.25` (`@tiptap/*`), `marked`, `dompurify`, `turndown` |
| Icons       | FontAwesome **Pro v7**, self-hosted via the per-style packages (`@fortawesome/pro-{solid,regular,duotone}-svg-icons` + free `@fortawesome/free-brands-svg-icons`) + `@fortawesome/fontawesome-svg-core`. Needs `.npmrc` to install — see §6. |
| FE testing  | Vitest `^5` with `happy-dom` + `@vue/test-utils` (components/views), coverage via `@vitest/coverage-v8` (floor in `vite.config.ts`); Playwright `^1` smoke run (`e2e/`) |
| FE linting  | ESLint `^10` (flat config: `eslint-plugin-vue`, `@vue/eslint-config-typescript`, `eslint-plugin-vuejs-accessibility`), Stylelint `^17` (recommended + Vue/SCSS, palette rule), Knip (unused code/deps), Prettier |
| Backend     | PHP `>=8.5`, Slim 4 (`slim/slim`, `slim/http`, `slim/psr7`), `php-di/slim-bridge` |
| Auth/email  | `lbuchs/webauthn` (passkeys), `phpmailer/phpmailer` (SMTP) |
| BE testing  | PHPUnit `^13` |
| BE linting  | `friendsofphp/php-cs-fixer` (PER-CS 3.0, `.php-cs-fixer.dist.php`), PHPStan `^2` (level 8 + strict, deprecation and PHPUnit rules, baselined), Rector (PHP 8.5 + dead-code sets), composer-unused + composer-require-checker, composer-normalize |
| Plugin      | **FFXIV Dalamud plugin** (`plugin/`) — C# / **.NET 10** (`net10.0-windows`), Dalamud **API 15** (`Dalamud.NET.Sdk/15.0.0`), ImGui via `Dalamud.Bindings.ImGui`. Windows-only, built independently — see §12. |
| Misc tooling| Python scripts for deck image cleanup (`scripts/`) |

---

## 3. Repository layout

The repo is split into **`frontend/`** (Vue/Vite, the npm project root) and
**`backend/`** (PHP, the composer project root). All frontend paths below are under
`frontend/`, all backend paths under `backend/`. The deploy script (`scripts/deploy.ps1`)
flattens both into the live server's single flat web root.

```
/
├── frontend/               # Vue 3 SPA — run npm from here
│   ├── index.html          # SPA entry HTML (Vite).
│   ├── package.json        # Frontend scripts & deps.
│   ├── vite.config.ts      # Vite + Vitest config (alias @ -> src, /api + /assets proxy, manualChunks).
│   ├── config/             # Vite plugins: changelog-plugin.ts serves the root CHANGELOG.md as `virtual:changelog`.
│   ├── eslint.config.ts    # ESLint flat config: the shared @jwill89/eslint-config + project rules.
│   ├── eslint-config/      # Git submodule holding that shared config (`git submodule update --init`).
│   ├── stylelint.config.js # Stylelint: recommended configs + the palette rule (§5).
│   ├── knip.ts             # Knip: unused files/exports/deps; entry points it can't infer.
│   ├── playwright.config.ts # The smoke run (§8); e2e/ holds its spec and PHP server scripts.
│   ├── .nvmrc              # Node version (CI reads it too).
│   ├── tsconfig*.json       # TS project config.
│   ├── .npmrc              # FontAwesome Pro registry token (gitignored).
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
│   ├── composer.json       # Backend deps, PSR-4 autoload, `composer test`/`stan`/`dev`.
│   ├── phpunit.xml         # PHPUnit config (unit + http suites).
│   ├── phpstan.neon        # PHPStan config (level 8 + strict rules); phpstan-baseline.neon holds pre-existing findings.
│   ├── .php-cs-fixer.dist.php # Code style (PER-CS 3.0 + house tweaks): `composer lint` / `lint:fix`.
│   ├── rector.php          # Rector: PHP 8.5 + dead-code sets (`composer rector`, `rector:check`).
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
│   ├── bin/                # Dev-only, not deployed: openapi.php (`composer docs`), coverage-check.php.
│   ├── scripts/            # PHP/Python CLI utils (make_admin.php, gen_icons.php, indexnow.php, *.py).
│   ├── db/                 # SQLite DB + one-off migration scripts (gitignored).
│   ├── assets/decks/{id}/  # Per-deck card images (Card_XXXX.png, Card_Back.png) (gitignored).
│   └── vendor/             # Composer deps (generated).
│
├── plugin/                 # FFXIV Dalamud plugin — self-contained C# subproject (§12).
│   ├── TarotGen.Plugin.sln
│   └── TarotGen.Plugin/    #   C# project (net10.0-windows, Dalamud.NET.Sdk); packaged by DalamudPackager.
│
├── scripts/deploy.ps1      # Build & deploy orchestration (frontend + backend + plugin).
├── PSScriptAnalyzerSettings.psd1 # Lint settings for scripts/*.ps1 (CI "Scripts" job).
├── .editorconfig           # Editor defaults for the repo (plugin/.editorconfig adds the C# rules).
├── .github/                # CI, plugin, CodeQL workflows; dependabot.yml.
├── README.md               # Human-facing quick orientation.
├── .gitignore / .gitattributes
└── (frontend/dist, */node_modules — generated)
```

---

## 4. Backend architecture (the layering rule)

Data flows through clearly separated layers. **Respect these boundaries** — do
not skip layers (e.g. don't run SQL from a controller).

```
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
  use the shape `['error' => '...']`.
- **Data classes** extend `AbstractData`, which provides `fetchOne()`,
  `fetchAllAs()`, and `applyUpdate()` (dynamic, allow-listed UPDATE). The `PDO`
  handle is **injected**, never fetched from a global, so tests can pass an
  in-memory SQLite PDO. Always use **prepared statements / bound params**.
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

The API is **hybrid REST** (see [`API.md`](API.md) for the full rulebook):
collections are plural nouns (`/decks`, `/readings`); `GET` reads, `POST` creates
(→`201`) or runs a command, `PUT` replaces, `PATCH` does a partial update / flag
toggle, `DELETE` removes (→`204`). Non-CRUD state transitions are
`POST /<resource>/{id}/<verb>` (`…/finalize`, `…/approve`, …); credential/session
endpoints stay action-oriented under `/auth/*`.

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
- Sessions use PHP sessions hardened in `index.php` (HttpOnly, SameSite=Lax,
  Secure in production). Admin access requires a **user account with the
  `is_admin` flag** — there is no shared admin password. Grant it with
  `php scripts/make_admin.php you@example.com`. No admin can remove their own
  flag or delete themselves, and the accounts in `PROTECTED_ADMIN_EMAILS` can't
  be demoted or deleted by anyone (`AccountProtection`).
- Each `sessions` row records its `user_id` (`DbSessionHandler`), so a password
  change ends the user's other sessions, a reset ends all of them, and deleting
  an account ends its sessions (`DbSessionHandler::destroyForUser`).
- **Always one way to sign in** (`SignInMethods`): password (set and not
  switched off), passkeys, Google. Switching password login off, deleting the
  last passkey or unlinking Google is refused (409) when it would leave none.
- No account enumeration: registration answers the same for a taken email
  (the owner is emailed), login runs a dummy hash check for an unknown email,
  and passkey login options give an unknown email stand-in credential ids
  (`PasskeyDecoys`, keyed by `<TAROT_DATA_DIR>/db/passkey-decoy.key`, created on
  first use). The sign-up and password-reset emails go out **after the
  response** under PHP-FPM (`Tarot\Utility\AfterResponse`, run by
  `api/index.php`), so neither answer waits on SMTP only when an address has an
  account; where a response can't be finished early (CLI, the dev server) they
  are sent inline, as before. An admin's resend stays inline (it reports
  whether the email went).
- **Who may make a reading** (`POST /readings/generate`, `POST /readings`;
  `ReadingController::refuseDraw()`): with Turnstile configured, a request with
  no signed-in user (session, or a linked plugin's `tg_pat_` token) needs a
  passed challenge (`turnstile_token`, from New Draw and Recreate Draw) or an
  unlinked plugin's **draw key** (`Authorization: Bearer tg_dk_…`). Keys come
  from the `/draw-key` page (Turnstile, then PKCE to the plugin's loopback
  listener: `POST /plugin/draw-keys/authorize` → `/token`), are stored hashed in
  `plugin_draw_keys`, open nothing but draws (their readings are guests') and
  are rate-limited per key and per IP (`DrawKeyService`). Without Turnstile (dev,
  the tests, the smoke run) nothing more is needed.
- `OriginGuard` middleware is CSRF defense-in-depth (rejects cross-origin
  state-changing requests). Added last so it runs outermost.
- CORS is **opt-in** via `APP_ORIGIN` (never `*` with credentialed cookies).
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

- **Components/views** use Vue 3 `<script setup lang="ts">`. Views are **lazy-
  loaded** in the router so each page (and heavy deps like TipTap) is a separate
  chunk.
- **Composables** (`src/composables/use*.ts`) hold state and API logic. They are
  the primary unit of reusable logic — prefer extending an existing composable
  over scattering `fetch` calls in components.
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
  when there is none — prefer showing it as is to re-wording statuses. Note
  `apiClient.ts` imports nothing local (so `useUser` can use it without an import
  cycle); the Vue-aware `useAdminApi` stays in `useApi.ts`.

  **A raw `fetch(` anywhere else is a lint error** (`tarot/api-client-only` in
  `eslint.config.ts`). Calls belong in a composable: `useReadings` (generate,
  custom, get, unlock, draw more, finalize, placement), `useSubmissions` (public
  deck / deck-system / spread submissions), `useAccount` (incl. sign out of
  other devices and the journal export links), `usePasskeys`, `useUserSpreads`,
  `useDecks`, `useContact`, `useUser`, `useCardFrequency`, `useDailyCards`.
- **Types:** `src/types/api.generated.ts` is **generated** from `backend/openapi.json`
  via `npm run gen:types` (openapi-typescript) — never edit it by hand; regenerate
  it after the backend spec changes (CI fails if it's stale). `src/types/index.ts`
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
  namespace — don't hardcode storage keys.
- **Icons:** FontAwesome is self-hosted and registered in `src/fontawesome.ts`.
  Only the icons actually used are imported (tree-shakeable) and collected into a
  `byPrefixAndName` lookup; templates render them with the `<FontAwesomeIcon>`
  component, e.g. `:icon="byPrefixAndName.fas['house']"`. **Footgun:** if you use a
  new icon in a template without adding its import + `index(...)` entry here, the
  lookup is `undefined` and it won't render — always register it. Icons come from
  the per-style Pro packages, so there are no CDN requests.
- **Styling:** Bulma utility classes + CSS custom properties (`--myst-*`) in
  `src/assets/tokens.css` (the palette source of truth, mapped onto Bulma's own
  variables). Theme is dark (`data-theme="dark"` on `<html>`). A translucent
  palette colour is `color-mix(in srgb, var(--myst-gold) 12%, transparent)` —
  never an `rgba()` copy of the token's channels; Stylelint enforces it (no hex,
  and outside `tokens.css` only pure white/black may be written as `rgb()`).
  Bulma is a modular build
  (`src/assets/bulma.scss`): only the modules in use, and only the dark theme's
  variables; a Bulma class that does nothing is probably a module that isn't
  imported (see that file's header).
- **Admin list pages** sit in `components/admin/AdminListPage.vue` (column width,
  back-to-dashboard link, `PageHeader` with an `#actions` slot) and use
  `AdminSearchField` for the search box; the view keeps its own tables.
- **Form labels:** every control has a name. Pair `<label for="x">` with the
  control's `id` (views: a `view-field` prefix, as `login-email`; components:
  ids from `useId()`, plus the row index inside a `v-for`). The custom controls
  take an `id` and put it on their focusable element (`BaseSelect`, `NumberField`,
  `ToggleSwitch`, `DeckComboBox`, `SpreadComboBox`); a caption over a group
  (`SegmentedControl`, several inputs) or over `MarkdownEditor` is a
  `<span class="label" :id>` the group or editor names with `aria-labelledby`.
  `eslint-plugin-vuejs-accessibility` checks the templates; a label that points
  into a component can only be checked in the browser.
- **Interactive UI (Reka UI):** dialogs, menus, popovers, tooltips, comboboxes,
  selects, switches, segmented controls, number fields, disclosures, pagination,
  and toasts are Reka UI headless primitives wrapped in shared components
  (`BaseModal`, `ConfirmDialog`, `DeckComboBox`/`SpreadComboBox`,
  `SegmentedControl`, `ToggleSwitch`, `Tooltip`, …) — prefer these over
  hand-rolling a Bulma/native widget so behavior + accessibility stay consistent.
- **Heading font:** headings read the `--myst-heading-font` CSS var, set once in
  `tokens.css` to the self-hosted **Crucial** (`src/assets/fonts.css`,
  `public/fonts/`). (The old multi-font audition switcher was retired.)

---

## 6. Build, run, and test commands

Run from the project root. Shell is **PowerShell** on Windows; chain with `;`.

### Frontend (npm) — run from `frontend/`
```powershell
cd frontend
npm install            # install deps (requires frontend/.npmrc with the FA Pro registry token — see below)
npm run dev            # Vite dev server :5173 (proxies /api and /assets to localhost:80)
npm run build          # production build to frontend/dist/
npm run preview        # preview the production build
npm run type-check     # vue-tsc on tsconfig.app.json + tsconfig.node.json (the root tsconfig only lists references)
npm run lint           # eslint . (flat config; TS + Vue SFCs, incl. template accessibility)
npm run lint:fix       # eslint . --fix
npm run lint:css       # stylelint (styles in .css/.scss/.vue; incl. the palette rule, §5)
npm run knip           # unused files, exports and dependencies (knip.ts)
npm test               # vitest run (single pass)
npm run test:watch     # vitest watch mode
npm run test:coverage  # vitest + coverage; fails below the floor in vite.config.ts
npm run test:e2e       # Playwright smoke run on the built app (npm run build first; see §8)
npm run gen:types      # regenerate src/types/api.generated.ts from backend/openapi.json
```

> **`frontend/.npmrc` is required to `npm install`.** The icon packages come from a
> private FontAwesome registry, so install needs a local `frontend/.npmrc` (gitignored)
> holding the registry auth token. Without it, the `@fortawesome/*` packages
> won't resolve.

> **After moving the `eslint-config` submodule, run `npm install` in `frontend/`
> and commit `package-lock.json` with it.** The lockfile records the config's
> dependency and peer ranges, so a config that raises them (as `e3787b1` raised
> ESLint to `^10.14.0`) leaves the lock out of sync, and CI's `npm ci` refuses to
> install. Keep `frontend/package.json`'s own `eslint`/`prettier`/`typescript`
> ranges at the config's peers.

### Backend (composer / php) — run from `backend/`
```powershell
cd backend
composer install       # install PHP deps
composer dev           # PHP built-in server on :80 via dev-router.php (the API backend)
composer test          # run PHPUnit (auto-discovers backend/phpunit.xml)
composer test:coverage # PHPUnit + coverage; fails below the floor in bin/coverage-check.php
composer stan          # PHPStan static analysis (backend/phpstan.neon, level 8, --memory-limit=512M baked in)
composer lint          # php-cs-fixer check (the code style CI enforces)
composer lint:fix      # php-cs-fixer fix (apply it)
composer rector        # Rector: current PHP idioms, dead code out (rector.php); rector:check lists without changing
composer deps:check    # composer-unused + composer-require-checker (every package used, every use declared)
composer normalize     # composer.json key order/format (CI runs it with --dry-run)
composer docs          # regenerate backend/openapi.json from the swagger-php attributes
vendor\bin\phpunit     # run PHPUnit directly
```

> PHPStan runs at **level 8** plus `phpstan-strict-rules` (no `empty()`, no short
> ternary, boolean-only conditions, no useless casts), `phpstan-deprecation-rules`
> and `phpstan-phpunit`, loaded by `phpstan/extension-installer`. A baseline
> (`phpstan-baseline.neon`, one entry) holds a pre-existing finding, so
> `composer stan` fails only on *new* issues.
>
> **`composer.json`'s `conflict` holds `phpstan/phpstan` at 2.3.3** (2026-10-28):
> PHPStan 2.3.4's phar bundles a newer phpdoc-parser (with `FullyQualifiedNameNode`)
> than Rector 2.8.2 bundles (2.3.11), and Rector then crashes on every file with
> a `callable` docblock type. Drop the conflict once a Rector release supports
> PHPStan 2.3.4 (`composer rector:check` passes with it).
> The Data/Repository/Structure layers and controller route `$args` are fully
> annotated (`array<string,mixed>`, `list<Structure>`, `array<string,string>`,
> etc.), so keep new array params/returns typed. Regenerate the baseline with
> `composer stan -- --generate-baseline`; raise toward `max` incrementally.

To serve the full app locally, run **two processes in two terminals**: from
`backend/` run `composer dev` (the PHP backend on port 80) and from `frontend/` run
`npm run dev` (the Vite SPA on :5173). The Vite proxy forwards `/api` and `/assets`
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
- After editing **`scripts/*.ps1`**: `Invoke-ScriptAnalyzer -Path scripts/deploy.ps1
  -Settings PSScriptAnalyzerSettings.psd1` (CI's "Scripts" job). Keep the scripts
  ASCII, so Windows PowerShell 5.1 reads them without a BOM.

### Deployment
The site runs on a DigitalOcean droplet (Apache on the event MPM + **PHP 8.5
FPM**, since 2026-11-03; one pool shared with the droplet's other PHP sites) at
`/var/www/tarotgen.io`, a single **flat** web root. OPcache re-checks files every
2 s, so a deploy needs no FPM reload; `SetEnv TAROT_DATA_DIR` in the vhost reaches
FPM; `.htaccess` `php_value` lines would be ignored under FPM (there are none).
PHP errors land in `/var/log/apache2/error.log` (`AH01071 … PHP message:`) and
`/var/log/php8.5-fpm.log`, beside the other sites': filter by `/var/www/tarotgen.io`. `scripts/deploy.ps1` flattens
`frontend/dist` and the `backend/` runtime files into it (PowerShell; uses PuTTY
`pscp`/`plink` with the DigitalOcean `.ppk` key):

```powershell
.\scripts\deploy.ps1                    # frontend (default): npm build + atomic dist swap
.\scripts\deploy.ps1 -Target backend    # PHP code; runs composer install on the droplet if composer.lock changed
.\scripts\deploy.ps1 -Target both       # frontend and backend, in one upload and one install
.\scripts\deploy.ps1 -Target plugin     # build the plugin (Release) + publish to its custom Dalamud repo (§12)
.\scripts\deploy.ps1 -SkipBuild         # deploy existing dist/ without rebuilding
```

The script flattens the repo into the server layout and **never** uploads the
server-side data: `.env` and the database (which live **outside the web root**, in
`TAROT_DATA_DIR` = `/var/lib/tarotgen` — see §9) or `assets/decks/`. Everything the
script does upload lands in the document root, so `backend/.htaccess` must keep
refusing `db/`, `includes/`, `vendor/`, `scripts/`, `.deploy/`, `.env*` and
`composer.*` (they were once served verbatim). Frontend keeps a `dist.old` rollback;
backend archives the previous code to `.deploy/backend-prev.tgz`. The `plugin`
target is independent — it uploads `latest.zip` / `icon.png` / `repo.json` to
`$WebRoot/plugin`.

Each target is packed into one tarball (Windows `tar.exe`, ustar format). A run
then makes exactly **two SSH connections**, which keeps it under `ufw limit ssh`
(6 connections in 30s) with no pauses. One `pscp` uploads every tarball to the SSH
user's home directory, outside the web root. One `plink` verifies their SHA-256
sums, then unpacks and swaps each target in. A bad checksum stops the run before
anything is touched. Unpacked files get root ownership, 644/755 modes and the
extraction time, as the old per-file upload left them. Per-file uploading cost
~0.15 s a file (240 files ≈ 35 s); the tarballs total ~0.8 MB.

**Automation.** `.github/workflows/ci.yml` (frontend, backend, the Playwright
smoke run, scripts, then the release tag, which waits for all four),
`plugin.yml` (when `plugin/` changes) and `codeql.yml` (frontend, plugin and
workflows; CodeQL has no PHP). `.github/dependabot.yml` opens weekly grouped
updates for npm, Composer, NuGet and Actions; TypeScript's major is held (see
§2). Dependabot can't read Actions secrets: `FONTAWESOME_NPM_TOKEN` and
`ESLINT_CONFIG_TOKEN` also exist as **Dependabot** secrets (both checked out by
CI on its pull requests). Every update job clones the repo with the private
`eslint-config` submodule, so each ecosystem lists the `github` git registry;
the Font Awesome registry needs its `scope` (`@fortawesome`), because the
repo's `.npmrc` is gitignored.

**A release with a migration** runs it on production first (backup, then
`runuser -u www-data -- php db/migrate_x.php`), then deploys (§10 "Schema
changes").

**Backups.** `/etc/cron.d/tarotgen` runs `/usr/local/sbin/tarotgen-backup` at 03:17
UTC: an online `.backup` of the DB, integrity-checked, gzipped into
`/var/backups/tarotgen/nightly/` (root-only, newest 14 kept). Sources live in
`scripts/server/` (not deployed by `deploy.ps1`; install them by hand, LF line
endings). Check runs with `journalctl -t tarotgen-backup`. Restore: stop writes,
`gunzip -c <backup> > /var/lib/tarotgen/db/tarotdb.db` as www-data, delete any
stale `tarotdb.db-wal`/`-shm` beside it. Take a manual run before migrations:
`sudo tarotgen-backup`.

**Retention.** Only if that backup succeeds, the same cron line runs
`backend/scripts/retention.php` as www-data (`scripts/` ships with `-Target
backend`): it deletes **guest** readings (`user_id IS NULL`) older than 365 days —
readings made while signed in, on the site or via a linked plugin, are never
touched — and those past the earlier expiry a guest chose; deletes plugin draw-key
codes that expired unredeemed (`plugin_draw_keys` rows with no key; issued keys
stay); then runs `ANALYZE` (not `PRAGMA optimize`: prod's SQLite 3.45 ignores its
check-every-table bit). The window is stated on the privacy page
(`PrivacyPolicyView.vue`, "Data Retention"); change both together. Check runs with
`journalctl -t tarotgen-retention`; preview with `runuser -u www-data -- php
scripts/retention.php --dry-run`.

---

## 7. Database

- Single SQLite file at `Tarot\Config\Paths::database()`: `<TAROT_DATA_DIR>/db/tarotdb.db`
  in production, `backend/db/tarotdb.db` locally. Accessed via `Tarot\Database\Connection`
  (PDO singleton). Foreign keys are enforced (`PRAGMA foreign_keys = ON`), WAL
  journaling is enabled, and `busy_timeout` is set. `Connection` opens the file
  read-write but **never creates it**: a missing DB (e.g. a mis-set
  `TAROT_DATA_DIR`) is an error, not a new empty database.
- **Schema changes** are applied via the one-off scripts in `db/`
  (`migrate_*.php`, `add_*`, `drop_*`). They are plain PHP run from the CLI:
  `php db/migrate_xxx.php`. There is no automated migration framework — add a new
  `migrate_*.php` script following the existing pattern when you change schema.
- `php db/check_state.php` prints current tables/schema for inspection.
- The DB path is absolute (`Paths::database()`), so scripts work regardless of the
  working directory. On the droplet the migration scripts still sit in
  `<WebRoot>/db/` (they need `../vendor/autoload.php`; `.htaccess` blocks the
  folder) and reach the real DB through `TAROT_DATA_DIR`, which `/etc/environment`
  sets for SSH sessions. **Run anything that writes in the live DB's directory as
  `www-data`** (`runuser -u www-data -- php db/migrate_x.php`, same for
  `sqlite3`), so every file created there (a `.backup` copy, a stray new DB)
  stays owned by the web-server user that has to read and write it.
- **Rebuilding the schema:** the committed `db/schema.sql` recreates the full
  structure — `sqlite3 db/tarotdb.db < db/schema.sql`. Regenerate it after a
  migration with `php scripts/dump_schema.php` so the committed schema stays in
  sync. The live `tarotdb.db` is gitignored and remains the source of truth for
  *data*; the `migrate_*.php` scripts are unordered one-offs that assume earlier
  state, so still treat the DB file as precious and back it up before migrating.

---

## 8. Testing conventions

- **Frontend (Vitest):** specs live flat in `src/__tests__/*.spec.ts`, named after
  the unit. Config in `vite.config.ts` (`environment: happy-dom`, `restoreMocks`,
  `unstubGlobals`, setup file `vitest.setup.ts`). Composables and utils are tested
  as units; components and views with `@vue/test-utils` through
  `src/__tests__/helpers/app.ts`: `mountWithApp` (as `main.ts` mounts: the global
  FontAwesome components and a router on the real route table,
  `src/router/routes.ts`, without the guards; attached to `document.body`, where
  Reka portals dialogs, tooltips and combobox lists, so query `document.body` for
  those), `afterEach(cleanup)`, `stubApi({ 'GET /decks': { body: [...] } })` (an
  unexpected call fails the test, so a spec states every call its view makes),
  `testUser` and `signIn`. Module-level state (the signed-in user, toasts,
  favourites) lasts a whole spec file: reset it in `beforeEach`.
  `npm run test:coverage` fails below the floor in `vite.config.ts`.
- **Smoke run (Playwright, `npm run test:e2e`, CI job "Smoke"):** `e2e/smoke.spec.ts`
  draws a reading, reports a card and finds the report as admin, on the **built**
  app: `npm run build` first. `playwright.config.ts` starts PHP's built-in server
  with `e2e/server/router.php` (`backend/.htaccess`'s routing: the SPA through
  `og.php` and its CSP, `/api` through the Slim app), after `e2e/server/prepare.php`
  seeds a throwaway `TAROT_DATA_DIR` (temp dir) and copies `dist/` to
  `backend/dist/` (gitignored), where `og.php` reads the shell. The server runs
  with `sys_temp_dir` inside that data dir, so the file-backed rate limiters start
  empty each run (earlier local runs can't trip them). Locally on Windows it
  drives the installed Edge; CI installs Chromium. `--repeat-each=5` is a good
  flakiness check; a step must tolerate a retry's extra data.
- **Backend (PHPUnit):** namespace `Tarot\Tests\`, two suites. Config
  (`phpunit.xml`) treats warnings/deprecations/notices as failures
  (`failOnWarning/Deprecation/Notice`) — keep tests clean.
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
    client IP so the file-backed rate limiters don't collide. Use these for
    status codes, error bodies, auth and anything that crosses layers.
    `replaceService()` swaps in a stand-in for something that talks to the outside
    world (Google, SMTP) before the first request.
  - PHPStan analyses `tests/` too, at the same level as the app, and
    `phpunit.xml` fails the run on PHPUnit's own deprecations, notices and
    warnings, so a deprecated PHPUnit API is caught as soon as it's used and a
    PHPUnit major is a bump and a run.
    `composer test:coverage` (CI) fails when line or method coverage drops below
    the floor in `bin/coverage-check.php`; locally it needs Xdebug or pcov.
- **Every change ships with its tests** — routes, services, composables,
  components and views alike, and a bug fix with the test that would have caught
  it. What each needs is in §10; the coverage floors never go down.

---

## 9. Configuration / environment

- Copy `.env.example` to `.env` and fill in values. Loaded by `Tarot\Config\Env`
  in `api/index.php` from `Tarot\Config\Paths::envFile()`.
- **`TAROT_DATA_DIR`** (process/Apache environment, not `.env`) names the private
  directory holding `.env` and `db/tarotdb.db`. Production: `/var/lib/tarotgen`,
  set by `SetEnv` in the Apache vhost (web), `/etc/environment` (SSH sessions) and
  `/etc/cron.d/tarotgen` (cron). Unset locally, where it falls back to `backend/`.
  Nothing private may live in the web root (AUDIT.md §1.0).
- Separately, a gitignored **`.npmrc`** at `frontend/` must hold the FontAwesome
  Pro registry auth token for `npm install` to resolve the icon packages (see §6).
- Key vars: `APP_ENV` (`development`/`production`, case-insensitive),
  `APP_ORIGIN` (CORS), `APP_URL` (links in emails), `SMTP_*` / `MAIL_*`
  (transactional email), `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` (optional
  OAuth), `CLOUDFLARE_TURNSTILE_*` (login and contact form, optional),
  `PROTECTED_ADMIN_EMAILS` (comma-separated accounts no one can demote or
  delete; set it on production).
- When `APP_ENV=production`: detailed errors are hidden, session cookies get the
  Secure flag, and the DI container is compiled. Never leak stack traces in prod.
- When SMTP is unconfigured outside production, the activation link is returned
  in the API response so sign-up still works in dev.

---

## 10. Conventions & expectations for changes

- **Every change ships with its tests — no exceptions for small features.**
  Coverage is held high on purpose (AUDIT §8: ~96% of backend lines, ~97% of
  frontend lines), and the floors in `vite.config.ts` and
  `backend/bin/coverage-check.php` **never go down**; raise them when coverage
  rises. For each change:
  - **Backend:** an HTTP test (`tests/Http`) for every new or changed route —
    the success path with `assertDocumented()`, its 400s, 401/403, 404, and
    what lands in the database — plus unit tests for new service or Data logic.
    List in `#[CoversClass]` every class a test really checks: PHPUnit credits
    coverage only to the classes listed.
  - **Frontend:** a spec for every new composable, component and view
    (`src/__tests__/helpers/`), covering the states and error paths a user can
    reach, not just the happy path.
  - A change on the core path (draw → reading → lightbox → report → admin)
    updates `e2e/smoke.spec.ts`.
  - **A bug fix comes with the test that would have caught it.** A finding that
    is really a product decision gets asked, not suppressed or skipped.
- **Versioning starts again from 5.0.0** (2026-10-28). The version lives in
  `frontend/package.json`; bump it together with its `CHANGELOG.md` section in
  the same commit (minor for a feature, patch for fixes, major for a breaking
  API or plugin change), and CI tags and releases it. Changes between releases
  collect under `## [Unreleased]`. `CHANGELOG.md` is the **only** changelog:
  the admin dashboard's version tag shows it, read in at build time
  (`virtual:changelog`, `utils/changelog.ts`); there is no changelog table, page
  or API (retired in 5.4.0).
- **Refusals** follow API.md: 400 validation, 401 not signed in, 403 not
  allowed (e.g. a protected admin), **404 for anything the requester may not
  see** as well as unknown ids (another user's reading or personal spread, a
  pending deck), so ids can't be probed; 409 conflicts (a taken name, a change
  that would leave no way to sign in); 429 rate limits. Always `{ error }` with a
  message a user can read, and the frontend shows that message (`ApiResult.error`
  from action composables) rather than a fixed one.
- **Decided security rules** (2026-10-28; AUDIT "Open from §8"), to keep when
  adding features: personal data (readings, personal spreads, tokens, passkeys)
  is reachable only by its owner; unapproved submissions only by admins
  (`AdminAuth::sessionIsAdmin()`), and only responses that are the same for
  everyone get public cache headers; nothing answers differently for an
  existing and an unknown account; a new public form or auth endpoint gets a
  `RateLimiter` and, when configured, Turnstile (as login and contact do; the
  privacy page names every page using it, `PrivacyPolicyView.spec.ts` checks), and
  making a reading without a signed-in user needs Turnstile or a plugin draw
  key (§4, "Who may make a reading"; no bypass for anything else); a
  password change or reset ends sessions; admins can't demote or delete
  themselves, and `PROTECTED_ADMIN_EMAILS` accounts can't be demoted or deleted;
  an account always keeps one way to sign in (`SignInMethods`). A reading's
  journal fields (`question`, `querent`, tags) reach only its owner — never a
  shared or locked payload, an export of anyone else, or og.php's share meta
  (`ReadingShareMeta`); a **private** reading (`readings.is_private`; "password"
  is still just `password_hash`, and `visibility` is worked out in the SELECT)
  and an **expired** guest reading (`expires_at`) are a 404 to everyone but the
  owner, with generic share meta.
- **Schema changes are additive and migrate before deploy:** a new
  `db/migrate_*.php` (idempotent; **migrations are never committed** — `db/` is
  gitignored apart from `schema.sql`, so don't un-ignore one, and don't add a
  committed test that needs one: verify it against a throwaway copy of the old
  schema instead; it's copied to the droplet by hand), `db/schema.sql` edited by hand (never
  regenerated wholesale from a local DB — that drops a table and reorders; it
  also seeds the starting `spread_categories`, the one reference data a fresh
  database needs), and
  run on production **before** the backend deploy that needs it. Design it so
  the old code keeps working on the new schema.
- **Product boundaries (the owner's, after talking with practitioners):** the
  site is a tool for readers — no card interpretations and no AI readings.
  Reversals are an **on/off toggle**, never a rate or percentage setting:
  choosing a rate takes the spirit out of the draw, and the draw already models
  a physical "cut and turn" (`ReadingService::cutAndTurn()`), so reversals come
  in realistic runs (AUDIT §9 "Declined").
- **Held or deferred on purpose — leave them unless the reason is gone:**
  TypeScript stays on 6 until `vue-tsc` supports TypeScript 7.1; PHPStan is
  held at 2.3.3 (`conflict` in `composer.json`) until Rector works with 2.3.4;
  `scrollBehavior` stays until vue-router's `ScrollRestoration` leaves
  `vue-router/experimental` (and Back should then restore position — the
  owner's call); favouriting from the keyboard inside the deck/spread combobox
  lists is an open design question (AUDIT §6.1).
- **Match existing patterns.** New backend resource? Add a `Structure`, `Data`,
  `Repository`, optional `Service`, a `Controller` extending
  `AbstractController`, a route group in `api/app.php`, and a `tests/Http` test
  for its status codes and auth. New frontend feature?
  Add a `use*` composable + view/component and a route with appropriate `meta`.
- **Layer discipline:** controllers don't touch SQL; SQL lives only in `Data`.
- **Security first:** always use prepared statements; never trust client input;
  keep error responses as `{ error }`; preserve auth guards on protected routes.
- **Keep types in sync** between PHP responses and `src/types/index.ts`.
- **Timestamps are UTC.** Write them with `Tarot\Utility\Clock` (`now()`, `in($seconds)`
  for expiries, `today()`), never `date()` — it follows PHP's zone, while SQLite's
  `CURRENT_TIMESTAMP` and the frontend (`utils/datetime.ts`) are UTC. Check with
  `vendor\bin\phpunit -d date.timezone=Pacific/Kiritimati` (UTC+14).
- **Comments** in this codebase explain *why*, not *what* — follow that style.
- **PSR-4 / PER-CS 3.0** for PHP (`composer lint:fix`). **Strict TypeScript**
  (`strict: true`) — keep `npm run type-check` clean. Augment a module's types
  (`declare module 'vue' { … }`) only in a file that is itself a module (has a
  top-level import/export, like `src/global-components.d.ts`): in a script file
  such as `vite-env.d.ts` the block *replaces* the module, which once silently
  emptied Vue's types.
- Don't commit generated artifacts (`dist/`, `vendor/`, `node_modules/`,
  `.phpunit.cache`) or the local `.env`.
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
| Change shared API types             | `src/types/index.ts` |
| Use a new icon                      | register it in `src/fontawesome.ts` (import + `library.add`) |
| Change/add a heading font           | `public/fonts/` + `src/assets/fonts.css` + `--myst-heading-font` in `src/assets/tokens.css` |
| Change a DB schema                  | new `db/migrate_*.php` script |
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
- **Analysis:** every build runs the recommended .NET analyzers and the
  `.editorconfig` style rules (`plugin/.editorconfig`), with **warnings as
  errors** (NuGet vulnerability advisories excepted, so a new advisory can't block
  a release). `dotnet format plugin/TarotGen.Plugin/TarotGen.Plugin.csproj
  --verify-no-changes` checks the formatting. CI (`.github/workflows/plugin.yml`)
  builds on Linux whenever `plugin/` changes: the Dalamud distribution goes into
  `DALAMUD_HOME`, and `EnableWindowsTargeting` lets the Windows target build there.
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

