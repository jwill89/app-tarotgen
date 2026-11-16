import { describe, it, expect, vi } from 'vitest'
import { useDailyCards } from '@/composables/useDailyCards'
import { stubApi, problem } from './helpers/app'
import type { DailyCard } from '@/types'

const card: DailyCard = {
  day: '2026-10-30',
  reading_id: 'abc123',
  deck_id: 1,
  deck_name: 'Rider-Waite',
  card_id: 17,
  card_name: 'The Star',
  reversed: false,
}

describe('useDailyCards', () => {
  it("asks for one month's cards", async () => {
    const api = stubApi({ 'GET /account/daily-cards': { body: [card] } })

    const res = await useDailyCards().fetchMonth('2026-10')

    expect(api).toHaveBeenCalledWith('/api/account/daily-cards?month=2026-10', undefined)
    expect(res.ok && res.data).toEqual([card])
  })

  it('draws with the day, deck and reversals chosen', async () => {
    const api = stubApi({ 'POST /account/daily-cards': { status: 201, body: card } })

    const res = await useDailyCards().drawDailyCard({
      day: '2026-10-30',
      deckId: 3,
      useReversals: true,
    })

    expect(res).toEqual({ ok: true, card })
    expect(api.mock.calls[0]?.[1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ day: '2026-10-30', deck_id: 3, use_reversals: true }),
    })
  })

  it("names the day's existing reading when it already has its card", async () => {
    stubApi({
      'POST /account/daily-cards': {
        status: 409,
        body: problem('Already drawn.', { reading_id: 'first1' }),
      },
    })

    const res = await useDailyCards().drawDailyCard({
      day: '2026-10-30',
      deckId: 1,
      useReversals: false,
    })

    expect(res).toEqual({
      ok: false,
      error: 'Already drawn.',
      taken: true,
      existingReadingId: 'first1',
    })
  })

  it('says the day is taken, naming no reading, when its reading was deleted', async () => {
    stubApi({
      'POST /account/daily-cards': {
        status: 409,
        body: problem('Already drawn, and its reading was deleted.', { reading_id: null }),
      },
    })

    const res = await useDailyCards().drawDailyCard({
      day: '2026-10-30',
      deckId: 1,
      useReversals: false,
    })

    expect(res).toEqual({
      ok: false,
      error: 'Already drawn, and its reading was deleted.',
      taken: true,
      existingReadingId: null,
    })
  })

  it('names no reading for any other refusal, or a 409 without one', async () => {
    stubApi({
      'POST /account/daily-cards': (body) =>
        (body as { deck_id: number }).deck_id === 1
          ? { status: 422, body: problem('Choose a deck to draw from.', { reading_id: 'ignored' }) }
          : { status: 409, body: problem('Already drawn.') },
    })
    const { drawDailyCard } = useDailyCards()

    expect(await drawDailyCard({ day: '2026-10-30', deckId: 1, useReversals: false })).toEqual({
      ok: false,
      error: 'Choose a deck to draw from.',
      taken: false,
      existingReadingId: null,
    })
    expect(await drawDailyCard({ day: '2026-10-30', deckId: 2, useReversals: false })).toEqual({
      ok: false,
      error: 'Already drawn.',
      taken: true,
      existingReadingId: null,
    })
  })

  it('falls back to its own message when the server gives none', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 502 })))

    const res = await useDailyCards().drawDailyCard({
      day: '2026-10-30',
      deckId: 1,
      useReversals: false,
    })

    expect(res).toEqual({
      ok: false,
      error: "Couldn't draw your card.",
      taken: false,
      existingReadingId: null,
    })
  })
})
