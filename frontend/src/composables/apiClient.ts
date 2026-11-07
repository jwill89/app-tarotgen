/**
 * Framework-free API client core. No Vue/router/toast imports, so it can be used
 * by any composable (incl. useUser) without creating an import cycle. The
 * Vue-aware `useAdminApi` lives in useApi.ts and builds on these.
 */

/** Shown for transport-level failures (fetch rejected: offline, DNS, CORS, …). */
export const NETWORK_ERROR_MESSAGE = 'Network error. Please check your connection and try again.'

/**
 * Pull a human-readable message out of an already-parsed response body. The
 * backend returns `{ "error": "..." }` bodies; fall back when it's missing.
 */
function messageFromBody(body: unknown, fallback: string): string {
  if (body && typeof body === 'object') {
    const err = (body as { error?: unknown }).error
    if (typeof err === 'string' && err.trim() !== '') {
      return err
    }
  }
  return fallback
}

/**
 * Pull a human-readable message out of a failed `Response`. Kept for callers
 * that only hold the raw response; internally it defers to {@link messageFromBody}.
 */
export async function readApiError(res: Response, fallback: string): Promise<string> {
  try {
    return messageFromBody(await res.clone().json(), fallback)
  } catch {
    // Non-JSON or empty body — use the fallback.
    return fallback
  }
}

/**
 * Result of an API call. Unlike {@link apiFetch}, this preserves *why* a call
 * failed — the HTTP status, the server's error message, the parsed body (so
 * callers can read structured fields like a validation `errors` array), and
 * whether the failure was a transport error vs. an error response.
 */
export type ApiResult<T> =
  // `headers` carries metadata such as X-Total-Count on paged lists (optional
  // because hand-rolled test doubles of Response omit it).
  | { ok: true; status: number; data: T; headers?: Headers }
  | { ok: false; status: number; data: unknown; error: string; networkError: boolean }

/**
 * True when the response provably carries no body — a `204 No Content`, a `205`,
 * or an explicit `Content-Length: 0`. Used to avoid calling `res.json()` (which
 * throws on an empty body) for the DELETE/PATCH endpoints that answer 204.
 */
function hasNoBody(res: Response): boolean {
  if (res.status === 204 || res.status === 205) {
    return true
  }
  // `headers` may be absent on hand-rolled test doubles; guard defensively.
  const headers = res.headers as Headers | undefined
  return headers?.get('content-length') === '0'
}

/** Parse a JSON body, returning `null` for an empty or non-JSON body. */
async function parseJsonSafe(res: Response): Promise<unknown> {
  try {
    return await res.json()
  } catch {
    return null
  }
}

/**
 * Core fetch wrapper. Always resolves (never throws): a rejected fetch becomes a
 * `networkError` failure, an error status becomes a failure carrying the server's
 * message, and a 2xx becomes a success carrying the parsed JSON body (or `null`
 * for a 204 No Content).
 *
 * `apiFetch` and `useAdminApi` are both built on this; prefer it directly when
 * you need the status or error message rather than just the data.
 */
export async function apiRequest<T>(
  path: string,
  options?: RequestInit,
  fallbackError = 'Request failed. Please try again.',
): Promise<ApiResult<T>> {
  let res: Response
  try {
    res = await fetch('/api' + path, options)
  } catch {
    return { ok: false, status: 0, data: null, error: NETWORK_ERROR_MESSAGE, networkError: true }
  }

  // Parse the body once; tolerate an empty/non-JSON body (e.g. 204, HTML error).
  // Several DELETE/PATCH endpoints now answer 204 No Content with no body, so
  // skip parsing entirely when there is provably nothing to read — calling
  // `res.json()` on an empty body throws.
  const body = hasNoBody(res) ? null : await parseJsonSafe(res)

  if (res.ok) {
    return {
      ok: true,
      status: res.status,
      data: body as T,
      headers: res.headers,
    }
  }

  return {
    ok: false,
    status: res.status,
    data: body,
    error: messageFromBody(body, fallbackError),
    networkError: false,
  }
}

/**
 * Options for a request with a JSON body:
 * `apiRequest(path, sendJson('PATCH', { name }))`.
 */
export function sendJson(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body: unknown): RequestInit {
  return { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
}

/** Options for a POST with a form-encoded body (reading generate and draw send these). */
export function sendForm(body: URLSearchParams): RequestInit {
  return {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  }
}

/**
 * Generic fetch helper for public API calls. Returns the parsed body on success
 * or `null` on any failure. Use {@link apiRequest} when you need to tell failures
 * apart or surface the server's message.
 */
export async function apiFetch<T>(path: string, options?: RequestInit): Promise<T | null> {
  const result = await apiRequest<T>(path, options)
  return result.ok ? result.data : null
}
