import { describe, it, expect, vi } from 'vitest'
import { useContact } from '@/composables/useContact'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'

// Runs the composable's own submit logic, not a copy of it, so the spec fails
// when the real code changes.

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

const message = { name: '  Alice ', email: 'alice@example.test ', message: ' Hello there ' }

describe('useContact.sendMessage', () => {
  it('POSTs the trimmed fields as JSON to /api/contacts', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await useContact().sendMessage(message)

    expect(result).toEqual({ ok: true })
    expect(fetchMock).toHaveBeenCalledWith('/api/contacts', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: 'Alice',
        email: 'alice@example.test',
        message: 'Hello there',
        turnstile_token: '',
      }),
    })
  })

  it('sends the solved Turnstile challenge along', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    await useContact().sendMessage(message, 'cf-token')

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/contacts',
      expect.objectContaining({ body: expect.stringContaining('"turnstile_token":"cf-token"') }),
    )
  })

  it("passes the server's validation message through", async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          fakeResponse({ error: 'Please enter a valid email.' }, { ok: false, status: 400 }),
        ),
    )

    expect(await useContact().sendMessage(message)).toEqual({
      ok: false,
      error: 'Please enter a valid email.',
    })
  })

  it('explains a rate limit (429) in its own words', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ error: 'Too many' }, { ok: false, status: 429 })),
    )

    const result = await useContact().sendMessage(message)

    expect(result.ok).toBe(false)
    expect(!result.ok && result.error).toMatch(/too many messages recently/)
  })

  it('falls back to a generic message when the error has no body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })),
    )

    expect(await useContact().sendMessage(message)).toEqual({
      ok: false,
      error: 'Failed to send your message. Please try again.',
    })
  })

  it('reports a network failure without throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    expect(await useContact().sendMessage(message)).toEqual({
      ok: false,
      error: NETWORK_ERROR_MESSAGE,
    })
  })
})
