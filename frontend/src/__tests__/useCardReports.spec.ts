import { describe, it, expect, vi } from 'vitest'
import { useCardReports, type CardReportInput } from '@/composables/useCardReports'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { problemBody } from './helpers/api'

/** Build a minimal Response-like object for the fetch mock. */
function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

// The reported-cards set is module state shared across tests, so each test
// reports a different card.
function input(cardId: number, overrides: Partial<CardReportInput> = {}): CardReportInput {
  return {
    deckId: 1,
    cardId,
    cardName: 'The Star',
    reason: 'artefact',
    details: '',
    ...overrides,
  }
}

describe('useCardReports', () => {
  it('posts the card, reason, and trimmed details to /api/card-reports', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(fakeResponse({ status: 'reported' }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    const { submitCardReport } = useCardReports()
    await submitCardReport(input(1, { reason: 'wrong_card', details: '  Shows The Moon.  ' }))

    expect(fetchMock).toHaveBeenCalledWith('/api/card-reports', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        deck_id: 1,
        card_id: 1,
        card_name: 'The Star',
        reason: 'wrong_card',
        details: 'Shows The Moon.',
      }),
    })
  })

  it('marks the card reported on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ status: 'reported' }, { status: 201 })),
    )

    const { submitCardReport, hasReported } = useCardReports()
    expect(hasReported(1, 2)).toBe(false)

    const outcome = await submitCardReport(input(2))

    expect(outcome).toEqual({ ok: true, alreadyReported: false })
    expect(hasReported(1, 2)).toBe(true)
    expect(hasReported(2, 2)).toBe(false) // same card id, different deck
  })

  it('reports already_reported as a success the caller can word differently', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({ status: 'already_reported' })))

    const { submitCardReport, hasReported } = useCardReports()
    const outcome = await submitCardReport(input(3))

    expect(outcome).toEqual({ ok: true, alreadyReported: true })
    expect(hasReported(1, 3)).toBe(true)
  })

  it("surfaces the server's message on an error and leaves the card unreported", async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          fakeResponse(
            problemBody(429, 'You have reported too many cards recently. Please try again later.'),
            { ok: false, status: 429 },
          ),
        ),
    )

    const { submitCardReport, hasReported } = useCardReports()
    const outcome = await submitCardReport(input(4))

    expect(outcome).toEqual({
      ok: false,
      error: 'You have reported too many cards recently. Please try again later.',
    })
    expect(hasReported(1, 4)).toBe(false)
  })

  it('falls back to a generic message when the error body has none', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({}, { ok: false, status: 500 })))

    const outcome = await useCardReports().submitCardReport(input(5))

    expect(outcome).toEqual({ ok: false, error: 'Could not send your report. Please try again.' })
  })

  it('reports a network failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const outcome = await useCardReports().submitCardReport(input(6))

    expect(outcome).toEqual({ ok: false, error: NETWORK_ERROR_MESSAGE })
  })
})
