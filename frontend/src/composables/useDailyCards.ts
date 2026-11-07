import { endpoints } from '@/api/endpoints'
import { apiRequest, sendJson, type ApiResult } from './apiClient'
import type { DailyCard } from '@/types'

/** What a draw was made with: the user's local day and the deck/reversals chosen for it. */
export interface DailyCardChoice {
  day: string
  deckId: number
  useReversals: boolean
}

/**
 * A draw's outcome. When the day already had its card (409), `taken` is set and
 * `existingReadingId` names that card's reading so the page can open it — or is
 * null when that reading was deleted: the day stays drawn all the same.
 */
export type DailyCardDraw =
  | { ok: true; card: DailyCard }
  | { ok: false; error: string; taken: boolean; existingReadingId: string | null }

/** The signed-in user's daily card (/api/account/daily-cards). */
export function useDailyCards() {
  /** A month's ("YYYY-MM") daily cards, oldest first. */
  function fetchMonth(month: string): Promise<ApiResult<DailyCard[]>> {
    return apiRequest<DailyCard[]>(
      `${endpoints.account.dailyCards}?month=${encodeURIComponent(month)}`,
      undefined,
      "Couldn't load your daily cards.",
    )
  }

  async function drawDailyCard({
    day,
    deckId,
    useReversals,
  }: DailyCardChoice): Promise<DailyCardDraw> {
    const res = await apiRequest<DailyCard>(
      endpoints.account.dailyCards,
      sendJson('POST', { day, deck_id: deckId, use_reversals: useReversals }),
      "Couldn't draw your card.",
    )
    if (res.ok) {
      return { ok: true, card: res.data }
    }

    const taken = res.status === 409
    const existing = taken ? (res.data as { reading_id?: unknown } | null) : null
    return {
      ok: false,
      error: res.error,
      taken,
      existingReadingId: typeof existing?.reading_id === 'string' ? existing.reading_id : null,
    }
  }

  return { fetchMonth, drawDailyCard }
}
