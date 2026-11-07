import { describe, it, expect, beforeEach, vi } from 'vitest'

// useAdminApi() calls useRouter() from vue-router, which only works inside a
// component setup. Mock it so the composable can run standalone.
const { pushMock } = vi.hoisted(() => ({ pushMock: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: pushMock }) }))

import { apiFetch, apiRequest, useAdminApi, readApiError } from '@/composables/useApi'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'

/** Build a minimal Response-like object for the fetch mock. */
function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

describe('apiFetch', () => {
  beforeEach(() => {
    pushMock.mockClear()
  })

  it('prefixes /api and returns parsed JSON on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse([{ id: 1 }]))
    vi.stubGlobal('fetch', fetchMock)

    const result = await apiFetch<{ id: number }[]>('/decks')

    expect(fetchMock).toHaveBeenCalledWith('/api/decks', undefined)
    expect(result).toEqual([{ id: 1 }])
  })

  it('returns null on a non-ok response', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })),
    )
    expect(await apiFetch('/decks')).toBeNull()
  })

  it('returns null when fetch rejects (network error)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
    expect(await apiFetch('/decks')).toBeNull()
  })
})

describe('apiRequest', () => {
  it('returns ok with parsed data and status on success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({ id: 1 }, { status: 201 })))

    const res = await apiRequest<{ id: number }>('/decks')

    expect(res).toEqual({ ok: true, status: 201, data: { id: 1 } })
  })

  it('on an error status returns the server message, status, body, and networkError=false', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ error: 'Bad deck' }, { ok: false, status: 400 })),
    )

    const res = await apiRequest('/decks')

    expect(res.ok).toBe(false)
    if (!res.ok) {
      expect(res.status).toBe(400)
      expect(res.error).toBe('Bad deck')
      expect(res.networkError).toBe(false)
      expect(res.data).toEqual({ error: 'Bad deck' })
    }
  })

  it('uses the supplied fallback when the error body has no message', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({}, { ok: false, status: 500 })))

    const res = await apiRequest('/decks', undefined, 'Custom fallback.')

    expect(res.ok).toBe(false)
    if (!res.ok) {
      expect(res.error).toBe('Custom fallback.')
    }
  })

  it('flags transport failures with networkError=true and status 0', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const res = await apiRequest('/decks')

    expect(res.ok).toBe(false)
    if (!res.ok) {
      expect(res.networkError).toBe(true)
      expect(res.status).toBe(0)
    }
  })

  it('returns ok with null data for a 204 No Content (no body parse)', async () => {
    // A 204 double whose json() would throw if called — asserts the client
    // does not attempt to parse an empty body.
    const jsonSpy = vi.fn(async () => {
      throw new Error('should not be called')
    })
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        status: 204,
        json: jsonSpy,
        headers: new Headers(),
      }),
    )

    const res = await apiRequest('/account/readings/abc')

    expect(res).toEqual({ ok: true, status: 204, data: null, headers: expect.any(Headers) })
    expect(jsonSpy).not.toHaveBeenCalled()
  })

  it('returns ok with null data when Content-Length is 0', async () => {
    const jsonSpy = vi.fn(async () => {
      throw new Error('should not be called')
    })
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: jsonSpy,
        headers: new Headers({ 'content-length': '0' }),
      }),
    )

    const res = await apiRequest('/account/favorites/public/1')

    expect(res).toEqual({ ok: true, status: 200, data: null, headers: expect.any(Headers) })
    expect(jsonSpy).not.toHaveBeenCalled()
  })

  it('passes response headers through on success (e.g. X-Total-Count)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => [],
        headers: new Headers({ 'x-total-count': '42' }),
      }),
    )

    const res = await apiRequest<unknown[]>('/account/readings?limit=25&offset=0')

    expect(res.ok && res.headers?.get('X-Total-Count')).toBe('42')
  })
})

describe('readApiError', () => {
  it('extracts the server error message', async () => {
    const res = new Response(JSON.stringify({ error: 'Bad deck' }), { status: 400 })
    expect(await readApiError(res, 'fallback')).toBe('Bad deck')
  })

  it('falls back when there is no error field', async () => {
    const res = new Response(JSON.stringify({ ok: true }), { status: 500 })
    expect(await readApiError(res, 'fallback')).toBe('fallback')
  })

  it('falls back for a non-JSON body', async () => {
    const res = new Response('<html>oops</html>', { status: 500 })
    expect(await readApiError(res, 'fallback')).toBe('fallback')
  })

  it('falls back for a blank error string', async () => {
    const res = new Response(JSON.stringify({ error: '   ' }), { status: 400 })
    expect(await readApiError(res, 'fallback')).toBe('fallback')
  })
})

describe('useAdminApi', () => {
  beforeEach(() => {
    pushMock.mockClear()
    useUser().currentUser.value = { user_id: 1, is_admin: true } as never
  })

  it('prefixes /api/admin and returns JSON on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await useAdminApi().get<{ ok: boolean }>('/decks')

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/admin/decks',
      expect.objectContaining({
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    expect(result).toEqual({ ok: true })
  })

  it('on 401 clears the user, redirects to login, and returns null', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 401 })),
    )

    const { currentUser } = useUser()
    const result = await useAdminApi().get('/decks')

    expect(result).toBeNull()
    expect(currentUser.value).toBeNull()
    expect(pushMock).toHaveBeenCalledWith({ name: 'login' })
  })

  it('returns null on other error statuses without redirecting', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 404 })),
    )

    const result = await useAdminApi().get('/decks')

    expect(result).toBeNull()
    expect(pushMock).not.toHaveBeenCalled()
  })

  it('sends POST with a JSON-encoded body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ created: true }))
    vi.stubGlobal('fetch', fetchMock)

    await useAdminApi().post('/decks', { name: 'New Deck' })

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/admin/decks',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ name: 'New Deck' }),
      }),
    )
  })

  it('sends PATCH with a JSON-encoded body', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ updated: true }))
    vi.stubGlobal('fetch', fetchMock)

    await useAdminApi().patch('/decks/5', { usable: true })

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/admin/decks/5',
      expect.objectContaining({
        method: 'PATCH',
        body: JSON.stringify({ usable: true }),
      }),
    )
  })

  it('sends DELETE requests', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }))
    vi.stubGlobal('fetch', fetchMock)

    await useAdminApi().del('/decks/5')

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/admin/decks/5',
      expect.objectContaining({
        method: 'DELETE',
      }),
    )
  })

  it('del returns true on a 204 No Content success, so callers still refresh', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { status: 204 })))
    expect(await useAdminApi().del('/pending-spreads/7')).toBe(true)
  })

  it('del returns the JSON body when the DELETE responds with one', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({ deleted: 3 })))
    expect(await useAdminApi().del<{ deleted: number }>('/readings/clean')).toEqual({ deleted: 3 })
  })

  it('del returns null when the DELETE fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ error: 'nope' }, { ok: false, status: 500 })),
    )
    expect(await useAdminApi().del('/decks/5')).toBeNull()
  })

  it('returns null when fetch rejects', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
    expect(await useAdminApi().get('/decks')).toBeNull()
  })

  it('toasts why a GET failed', async () => {
    useToasts().toasts.value = []
    // The toast is mirrored to the console; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(fakeResponse({ error: 'Spread not found' }, { ok: false, status: 404 })),
    )

    await useAdminApi().get('/spreads/99')

    expect(useToasts().toasts.value.map((t) => t.message)).toEqual(['Spread not found'])
  })

  it('getResult hands a page the whole failure to show itself, toasting nothing', async () => {
    useToasts().toasts.value = []
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(fakeResponse({ error: 'Spread not found' }, { ok: false, status: 404 })),
    )

    const result = await useAdminApi().getResult('/spreads/99')

    expect(result).toMatchObject({ ok: false, status: 404, error: 'Spread not found' })
    expect(useToasts().toasts.value).toEqual([])
    expect(pushMock).not.toHaveBeenCalled()
  })

  it('getResult returns the data on success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({ spread_id: 1 })))

    expect(await useAdminApi().getResult('/spreads/1')).toMatchObject({
      ok: true,
      data: { spread_id: 1 },
    })
  })

  it('getResult still drops to the login page on a 401', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 401 })),
    )

    const result = await useAdminApi().getResult('/spreads/1')

    expect(result.ok).toBe(false)
    expect(useUser().currentUser.value).toBeNull()
    expect(pushMock).toHaveBeenCalledWith({ name: 'login' })
  })
})
