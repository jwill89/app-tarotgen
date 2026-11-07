import { vi, type Mock } from 'vitest'

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
 * function of the parsed JSON body and URL. A request nothing matches fails the
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
    return status === 204 || body === null
      ? new Response(null, { status, headers })
      : new Response(JSON.stringify(body), {
          status,
          headers: { 'Content-Type': 'application/json', ...headers },
        })
  })
  vi.stubGlobal('fetch', mock)
  return mock
}
