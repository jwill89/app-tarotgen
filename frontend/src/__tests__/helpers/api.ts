import { vi, type Mock } from 'vitest'
import type { ProblemDetails } from '@/types'

/** The reason phrases the API's problems carry as `title`, by status. */
const TITLES: Record<number, string> = {
  400: 'Bad Request',
  401: 'Unauthorized',
  403: 'Forbidden',
  404: 'Not Found',
  405: 'Method Not Allowed',
  409: 'Conflict',
  422: 'Unprocessable Entity',
  429: 'Too Many Requests',
  500: 'Internal Server Error',
  503: 'Service Unavailable',
}

/**
 * The RFC 9457 problem details body the API answers an error with: `detail`
 * says why, and `members` adds the problem's others (a validation's `errors`,
 * a taken daily card's `reading_id`).
 */
export function problemBody(
  status: number,
  detail: string,
  members: Partial<ProblemDetails> & Record<string, unknown> = {},
): ProblemDetails & Record<string, unknown> {
  return { type: 'about:blank', title: TITLES[status] ?? 'Error', status, detail, ...members }
}

/** A stub reply's body that stands for the problem details of the reply's status. */
class ProblemStub {
  constructor(
    readonly detail: string,
    readonly members: Partial<ProblemDetails> & Record<string, unknown>,
  ) {}
}

/**
 * The problem details body of a stubbed error, filled in with the reply's
 * status: `{ status: 422, body: problem('Name is required.') }`.
 */
export function problem(
  detail: string,
  members: Partial<ProblemDetails> & Record<string, unknown> = {},
): ProblemStub {
  return new ProblemStub(detail, members)
}

interface ApiReply {
  status?: number
  body?: unknown
  headers?: Record<string, string>
}

type ApiHandler = ApiReply | ((body: unknown, url: URL) => ApiReply)

/**
 * Requests no stub matched since the last check. The stub's fetch rejects them,
 * but apiRequest turns a rejection into a "network error" that a view may handle
 * quietly, so vitest.setup.ts fails the test after the fact instead.
 */
const unexpected: string[] = []

/** The unmatched requests so far, emptying the list. */
export function takeUnexpectedApiCalls(): string[] {
  return unexpected.splice(0)
}

/**
 * Stub `fetch` for the API: keys are `'GET /decks'` style (method + path after
 * `/api`, query ignored), values a reply (status, JSON body, headers) or a
 * function of the parsed JSON body and URL. An error's body is best written
 * with {@link problem}. A request nothing matches fails the
 * test, so a spec states every call its component makes. Returns the mock, to
 * inspect the calls.
 */
export function stubApi(handlers: Record<string, ApiHandler>): Mock {
  const mock = vi.fn(async (input: string, init?: RequestInit) => {
    const url = new URL(input, 'https://tarotgen.test')
    const key = `${(init?.method ?? 'GET').toUpperCase()} ${url.pathname.replace(/^\/api/, '')}`
    // Own keys only: a call named like an Object.prototype key is unexpected too.
    const handler = Object.hasOwn(handlers, key) ? handlers[key] : undefined
    if (handler === undefined) {
      unexpected.push(key)
      throw new Error(`Unexpected API call: ${key}`)
    }
    const sent =
      typeof init?.body === 'string' && init.body.startsWith('{')
        ? JSON.parse(init.body)
        : init?.body
    const {
      status = 200,
      body = null,
      headers = {},
    } = typeof handler === 'function' ? handler(sent, url) : handler
    const payload =
      body instanceof ProblemStub ? problemBody(status, body.detail, body.members) : body
    // Errors come as problem details, as the API sends them.
    const contentType = status >= 400 ? 'application/problem+json' : 'application/json'
    return status === 204 || payload === null
      ? new Response(null, { status, headers })
      : new Response(JSON.stringify(payload), {
          status,
          headers: { 'Content-Type': contentType, ...headers },
        })
  })
  vi.stubGlobal('fetch', mock)
  return mock
}
