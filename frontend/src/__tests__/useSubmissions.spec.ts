import { describe, it, expect, vi } from 'vitest'
import { useSubmissions } from '@/composables/useSubmissions'
import { problemBody } from './helpers/api'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

const spread = { name: 'Cross', description: '', card_count: 1, positions: [] }

describe('useSubmissions', () => {
  it('posts a spread to the moderation queue as JSON', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    const res = await useSubmissions().submitSpread(spread)

    expect(res.ok).toBe(true)
    expect(fetchMock).toHaveBeenCalledWith('/api/spreads', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(spread),
    })
  })

  it("shows the server's rate-limit message as is", async () => {
    const limited = 'You have submitted too many spreads recently. Please try again later.'
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(fakeResponse(problemBody(429, limited), { ok: false, status: 429 })),
    )

    const res = await useSubmissions().submitSpread(spread)

    expect(!res.ok && res.error).toBe(limited)
  })

  it('falls back to its own message when the error has no body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })),
    )

    const res = await useSubmissions().submitDeck({
      name: 'Deck',
      artist: 'Artist',
      purchase_url: '',
      deck_system_id: 1,
      additional_cards: 0,
    })

    expect(!res.ok && res.error).toBe('Failed to submit deck. Please try again.')
  })
})
