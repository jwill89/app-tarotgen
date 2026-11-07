# TarotGen.io

A tarot reading generator and reading-sharing platform. Pick a deck and a spread
(or freely choose a card count), draw cards, then save and share a permalink to the
result. Registered users get accounts (saved readings, custom spreads); admins
manage decks, spreads, users, and more.

It's a **Vue 3 SPA frontend + PHP REST API backend**, split into `frontend/` and
`backend/`. In production both are served from a single flat web root behind Apache
(the SPA is the static `dist/` build; `/api/*` hits the PHP app; every page's HTML
shell goes through `og.php`, which adds a per-response CSP nonce and, for
`/reading/{id}`, social link-preview meta). The deploy script flattens
the two folders into that web root.

A companion **FFXIV Dalamud plugin** lives in `plugin/` — a self-contained C# /
.NET subproject that talks to the same API to draw, view, and share readings
in-game. It is built and distributed independently (its own `latest.zip` served
from a custom Dalamud repo), so the site deploy never touches it. See
[`plugin/README.md`](plugin/README.md).

> **For contributors and AI agents:** [`AGENTS.md`](AGENTS.md) is the deep guide —
> architecture, the backend layering rule, conventions, and gotchas. This README is
> the quick orientation; [`API.md`](API.md) covers the hybrid-REST conventions, and
> the live endpoint reference is generated from the code — browse it at **`/api/docs`**
> (Scalar) or read the raw spec at [`backend/openapi.json`](backend/openapi.json).

## Where things live

```text
frontend/   Vue 3 SPA — npm project root
backend/    PHP API — composer project root
plugin/     FFXIV Dalamud plugin — C# subproject (built & distributed independently)
scripts/    deploy.ps1 (repo-level orchestration)
shared/     shared-tooling — git submodule: the lint, test, CI and deploy pieces shared across projects
```

| Path | What |
|------|------|
| `frontend/src/` | Vue 3 (`<script setup>` + TS), Vue Router, Bulma (layout/utilities), Reka UI (headless interactive primitives), TipTap. Views, components, composables, utils, types. Specs in `src/__tests__/`. |
| `frontend/index.html` | SPA entry HTML (Vite). |
| `frontend/public/` | PWA assets (manifest, service worker, icons), `robots.txt`, `sitemap.xml`, self-hosted fonts. |
| `backend/api/` | PHP API entry (Slim 4). `app.php` builds the app (middleware + all routes), `index.php` boots and runs it; `Routes/` holds controllers + middleware. Endpoints are documented via swagger-php attributes → `backend/openapi.json` (`composer docs`), served at `/api/docs`. |
| `backend/includes/Tarot/` | Domain code (PSR-4 `Tarot\`): Data → Repository → Service → Structure, plus Config/Database/Utility. |
| `backend/og.php` | Serves every page's HTML shell: per-response CSP nonce, plus Open Graph meta for `/reading/{id}` shares. |
| `backend/tests/` | PHPUnit: `Unit/` (classes in isolation), `Http/` (requests through the whole app on an in-memory DB). |
| `backend/scripts/` | PHP/Python CLI utilities (`make_admin.php`, `indexnow.php`, …). |
| `backend/{phpunit.xml,phpstan.neon}` | QA configs (auto-discovered when running `composer` from `backend/`). |
| `backend/db/` | Phinx migrations (`migrations/`) and the `schema.sql` generated from them, committed; the local SQLite DB beside them is gitignored. |
| `backend/assets/decks/` | Per-deck card images — server-side data, gitignored, **never** in a deploy payload. |
| `plugin/TarotGen.Plugin/` | FFXIV Dalamud plugin (C#, .NET 10, Dalamud API 15). Built by DalamudPackager; published to a custom Dalamud repo via `deploy.ps1 -Target plugin`. See [`plugin/README.md`](plugin/README.md). |
| `scripts/deploy.ps1` | Build & deploy orchestration (frontend + backend + plugin), on shared-tooling's SharedDeploy module. |
| `shared/` | [shared-tooling](https://github.com/jwill89/shared-tooling) v2.1.0, a git submodule: ESLint/Prettier/Stylelint/tsconfig, the PHP and C# lint bases, the Vitest and Playwright settings, the CI workflows, `.editorconfig`/`.gitattributes`, the deploy module and the standards every project follows (`shared/standards/`). |
| `Taskfile.yml` | [Task](https://taskfile.dev) commands (`task` lists them), including the shared ones. |
| `*/{node_modules,vendor,dist}/` | Generated dependency / build output. |

## Quickstart (local dev)

Requires Node 26+ (the LTS; `frontend/.nvmrc`), PHP 8.5+, Composer, and the
FontAwesome Pro registry token in your user `~/.npmrc` (the tracked
`frontend/.npmrc` names the registry; see `AGENTS.md` §6). Fetch the submodule
once (`git submodule update --init`, or clone with `--recurse-submodules`) and
copy `backend/.env.example` → `backend/.env`. Run the app as **two processes,
one per folder** (or `task dev` for both):

```powershell
git submodule update --init          # shared/ (once per clone)
cd frontend ; npm install            # frontend deps
cd ..\backend ; composer install     # backend deps

# Terminal 1 — PHP backend on :80
cd backend ; composer dev            # = php -S localhost:80 dev-router.php

# Terminal 2 — Vite SPA on :5173 (proxies /api + /assets to :80)
cd frontend ; npm run dev
```

## Checks

```powershell
cd frontend ; npm run type-check ; npm run lint ; npm test
cd backend  ; composer test ; composer stan ; composer lint
task check                           # every lint, audit and test (CI also checks the generated files and coverage floors)
```

## API docs

The REST API is described by an OpenAPI 3.1 spec generated from swagger-php attributes
on the controllers/Structures. Browse it interactively at **`/api/docs`** (Scalar) when
the backend is running; the raw spec is at [`backend/openapi.json`](backend/openapi.json).

```powershell
cd backend  ; composer docs         # regenerate backend/openapi.json (CI fails if it's stale)
cd frontend ; npm run gen:types     # regenerate src/types/api.generated.ts from the spec
```

Run both after changing a route or a response shape, and commit the results. See
[`API.md`](API.md) for the hybrid-REST conventions.

## Build & deploy

```powershell
cd frontend ; npm run build              # production SPA build → frontend/dist/

.\scripts\deploy.ps1                     # build + deploy frontend (dist swapped in by renames)
.\scripts\deploy.ps1 -Target backend     # PHP code: composer install --no-dev on the droplet, DB snapshot, swap, migrations
.\scripts\deploy.ps1 -Target both        # backend, then frontend
.\scripts\deploy.ps1 -Target plugin      # build the plugin (Release) + publish latest.zip/icon/repo.json
.\scripts\deploy.ps1 -SkipBuild          # deploy the existing frontend/dist without rebuilding
.\scripts\deploy.ps1 -Target backend -NoDbPull  # skip pulling the live DB into backend/db/ afterwards
```

The deploy script flattens `frontend/` + `backend/` into the live server layout at
`/var/www/tarotgen.io` as one archive over one SSH connection, then checks the site
through Cloudflare (`/api/decks` and `/`) and rolls back what it changed if that
fails. It **never uploads** the server's `.env`, database or `assets/`; a backend
deploy snapshots the database and runs the Phinx migrations, and once the site
checks out it keeps the daily backup timer in place and pulls a copy of the live
database for local use. Each target keeps a rollback copy (`dist.old`,
`.deploy/backend-prev`, `plugin.old`). The settings (host, SSH user, key, web
root) come from the gitignored `scripts/deploy.config.ps1`; run
`Get-Help .\scripts\deploy.ps1 -Full` for every parameter and step (`AGENTS.md` §6).
