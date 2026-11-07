import { endpoints } from '@/api/endpoints'
import { apiRequest, type ApiResult } from './apiClient'
import type { CardFrequency } from '@/types'

/** Which readings to count: one deck or all (null), the last N days or all time (null). */
export interface CardFrequencyFilter {
  deckId: number | null
  days: number | null
}

/** Card counts over the signed-in user's own readings (GET /account/card-frequency). */
export function useCardFrequency() {
  function fetchCardFrequency({
    deckId,
    days,
  }: CardFrequencyFilter): Promise<ApiResult<CardFrequency>> {
    const query = new URLSearchParams()
    if (deckId !== null) {
      query.set('deck_id', String(deckId))
    }
    if (days !== null) {
      query.set('days', String(days))
    }
    const search = query.toString()

    return apiRequest<CardFrequency>(
      search ? `${endpoints.account.cardFrequency}?${search}` : endpoints.account.cardFrequency,
      undefined,
      "Couldn't count your cards.",
    )
  }

  return { fetchCardFrequency }
}
