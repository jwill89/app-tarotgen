# TarotGen.io API

The backend is a **PHP 8.5 / Slim 4 hybrid-REST API**, served under the `/api` base
path and speaking JSON. This document is the **conventions** reference; the
authoritative, always-current **endpoint list is generated from the code** and served
as interactive docs:

| | |
|---|---|
| **Interactive reference (Swagger UI)** | [`GET /api/docs`](backend/api/Routes/Internal/ApiDocsController.php) |
| **Raw OpenAPI 3.1 spec** | [`GET /api/openapi.json`](backend/openapi.json) — committed at [`backend/openapi.json`](backend/openapi.json) |
| **Where it comes from** | swagger-php (`#[OA\...]`) attributes on the controllers in [`backend/api/Routes/Internal/`](backend/api/Routes/Internal/) and the `#[OA\Schema]` Structure classes in [`backend/includes/Tarot/Structure/`](backend/includes/Tarot/Structure/) |
| **Regenerate** | `cd backend && composer docs` (writes `openapi.json`); a PHPUnit test fails if the committed spec drifts |
| **Frontend types** | `cd frontend && npm run gen:types` → `frontend/src/types/api.generated.ts` |

> The route table itself lives in [`backend/api/app.php`](backend/api/app.php).
> See [`AGENTS.md`](AGENTS.md) §4 for the backend architecture (Controller → Service →
> Repository → Data → Structure) and the auth/CSRF model.

---

## Conventions (the "hybrid REST" rulebook)

The API is **hybrid REST**: resource-oriented CRUD with a small, deliberate set of
action endpoints for operations that aren't a plain field-set.

- **Base path** — every endpoint is under `/api`. Trailing slashes are accepted (`[/]`).
- **Methods**
  - `GET` reads.
  - `POST` **creates** (→ `201`) *or* runs a **command** (a non-CRUD process).
  - `PUT` fully **replaces** a resource representation.
  - `PATCH` applies a **partial update** or **flips a flag**.
  - `DELETE` removes (→ `204 No Content`, empty body).
- **Resources** — collections are **plural** nouns (`/decks`, `/spreads`, `/readings`);
  a single item is `/<resource>/{id}`; sub-collections nest
  (`/decks/{deck_id}/special-cards/{card_id}`).
- **Commands (non-CRUD)** — state transitions and processes that aren't a declarative
  field-set are `POST /<resource>/{id}/<verb>` — e.g. `…/finalize`, `…/unlock`,
  `…/draw`, `…/approve`, a user's `…/activate`. Flipping a boolean (a deck's `usable`,
  a user's `is_admin`, a contact's `is_read`) is a **`PATCH`** on the resource instead.
- **Bulk delete** — `DELETE /<resource>/all` (with a query filter where needed),
  returning `{ "deleted": N }` (e.g. `DELETE /admin/readings/all?older_than_days=30`).
- **Auth is the intentional exception** — credential/session endpoints stay
  action-oriented under `/auth/*` (`/auth/login`, `/auth/register`, `/auth/me`, …),
  alongside `/auth/google/*` and `/auth/passkeys/*`.

### Bodies & responses

| Topic | Detail |
|-------|--------|
| **Request body** | `POST`/`PUT`/`PATCH` send JSON (`Content-Type: application/json`); unknown fields are ignored. |
| **Success body** | The requested resource, a list, or `{ "success": true, … }`. Creates return `201`; deletes return `204` with no body. |
| **Error body** | Always RFC 9457 problem details, `application/problem+json` (see [Errors](#errors)). |
| **Caching** | Public, slow-changing reads send `Cache-Control: public, max-age=300`. Viewer-specific reads (a single reading) send `Cache-Control: private, no-store`. |
| **Rate limiting** | Several public endpoints are IP-rate-limited and answer `429` with `Retry-After` (seconds) when exceeded; each limit is noted on its endpoint in the spec. |

### Errors

Every error, the framework's own included (an unknown route, a method a route doesn't take, an uncaught exception), is an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details body sent as `application/problem+json`, whatever the request's `Accept`. The spec's `ProblemDetails` schema describes it, and every operation's 4xx and 5xx responses reference it.

```json
{
  "type": "about:blank",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "x must be a number from 0 to 100.",
  "errors": [{ "message": "x must be a number from 0 to 100.", "location": "body.positions[0].x" }]
}
```

- `type` is always `about:blank`, so the status says what kind of problem it is; `title` is the status's reason phrase.
- `detail` is the message to show the user: it says why. The SPA reads it as `ApiResult.error`.
- `errors` comes only with a failed validation (`422`): one entry per refused field, with the `message` to show beside it and its `location` as huma writes it (`body.email`, `body.positions[2].x`, `query.limit`, `path.deck_id`, or `body` for the body as a whole). The SPA reads them with `fieldErrors()`.
- A problem may carry members of its own, named in the spec: a second daily card for one day (`409`) names the day's `reading_id` (`DailyCardTaken`), and a plugin code exchange's refusal carries OAuth's `error: "invalid_grant"` (`InvalidGrant`).
- Outside production, an unexpected error (`500`) also carries `exception`, the exception chain. In production it says only "Something went wrong on our side. Please try again."

| Status | When |
|--------|------|
| `400` | The server can't read the request: a JSON body that isn't JSON or isn't an object. Also a plugin code exchange (`POST /plugin/token`, `POST /plugin/draw-keys/token`) with a code or verifier that is wrong, expired or spent, which is RFC 6749's `invalid_grant`. |
| `401` | Not signed in (or a plugin Bearer credential that no longer resolves); from the `/auth/*` sign-in operations, a sign-in that failed. |
| `403` | Signed in but not allowed: a signed-in account that isn't an admin on `/admin/*`, a wrong **current** password when changing it or deleting the account, a reading that isn't yours to place or draw into, a wrong reading password, a protected account, a cross-origin state change. |
| `404` | An unknown id, or anything the requester may not see (someone else's reading or spread, a submission awaiting approval), so ids can't be probed. |
| `409` | A conflict with what is stored: a taken name, a passkey already registered, the last way to sign in, a final or custom reading, a day that already has its card, a ceremony with no challenge pending, an account already active. |
| `422` | A failed validation: a missing field, a value out of range or over its length (refused, never clamped or cut), a reference to nothing (an unknown deck or category), a weak password, a failed captcha. |
| `429` | A rate limit, with `Retry-After`. |
| `500` | The server's own failure. |

The Google sign-in start and callback (`GET /auth/google`, `GET /auth/google/callback`) are navigated to, not called, so they always answer `302`: a failure goes back to the page it came from (`/login`, `/register` or `/account/settings`) with the reason in `?oauth_error=`.

### Authentication & authorization

A signed-in **browser** user is identified by the `__Host-tarotgen` **session cookie** (HttpOnly, SameSite=Lax, Secure, bound to the exact host by its `__Host-` prefix; `tarotgen` outside production, over plain HTTP) — the `sessionCookie` OpenAPI scheme. The first-party SPA uses only this; there are no API keys.

The **Dalamud plugin** is the one non-browser client. Viewing readings needs no auth. Drawing one needs, when Turnstile is configured, a linked account or the plugin's **draw key** (`Authorization: Bearer tg_dk_…`), which opens nothing but draws (AGENTS.md §4, "Who may make a reading"). A linked account adds a per-account **plugin token** (`pluginToken` scheme, Bearer over HTTPS — a native `HttpClient` sends no `Origin`, which `OriginGuard` already permits) for the read-only account routes it may reach. The token and the draw key both come through a browser loopback + PKCE flow, and the token is individually revocable. (The per-install client token and share relay were removed — AUDIT.md §2.1.)

| Audience | How a request authenticates | Guard |
|----------|-----------------------------|-------|
| Public | No session needed | — |
| Signed-in user (`/account/*`) | The session cookie for an **active** account | `UserAuth` → `401` "Not authenticated" |
| Admin (`/admin/*`) | The session cookie for an active **`is_admin`** account | `AdminAuth` → `401` "Not authenticated" signed out, `403` "Only an admin can do that." signed in |
| Linked plugin (account routes it may reach) | session **or** a plugin Bearer token | `AccountAuth` → `401` "Not authenticated" |

- **CSRF** — the `OriginGuard` middleware rejects state-changing requests
  (`POST`/`PUT`/`PATCH`/`DELETE`) from a foreign origin. Safe methods and the GET OAuth
  callback pass through.
- **CORS** — same-origin by default; cross-origin is enabled only when `APP_ORIGIN` is
  configured (never `*` with credentials).
- **No account enumeration.** Nothing tells an outsider whether an email has an
  account. `POST /auth/register` with a taken email answers exactly like a new
  sign-up (201, same message), creates nothing, and emails that address's owner a
  notice instead; `POST /auth/forgot-password` answers the same either way; a
  password sign-in with an unknown email takes as long as a wrong password; and
  `POST /auth/passkeys/login/options` with an email lists credentials whether or
  not it has passkeys (stand-ins when it has none). Only a taken **display name**
  is reported, since names are public anyway.
- **Refusals that protect accounts.** `403` when an admin tries to demote or
  delete their own account, or any account listed in `PROTECTED_ADMIN_EMAILS`
  (which can't delete itself either). `409` when a change would leave an account
  no way to sign in (turning password login off, deleting the last passkey,
  unlinking Google); the message says what to set up first.
- **Sessions end with the password.** Changing it ends the account's other
  sessions (the browser that changed it continues under a new session id);
  resetting it, or deleting the account, ends all of them. Plugin tokens are
  revoked separately (Connected Apps).

Logging in is done via the auth endpoints (`POST /api/auth/login`, passkeys, or
Google) — there is no separate admin login.
