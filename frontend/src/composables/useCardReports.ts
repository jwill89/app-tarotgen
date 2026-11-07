import { ref } from 'vue'
import { apiRequest } from '@/composables/apiClient'
import { endpoints } from '@/api/endpoints'
import type { CardReportReason } from '@/types'

export interface CardReportInput {
  deckId: number
  cardId: number
  cardName: string
  reason: CardReportReason
  details: string
}

type CardReportOutcome = { ok: true; alreadyReported: boolean } | { ok: false; error: string }

// Cards reported this session, keyed "deck:card". Module-level so the lightbox
// still shows "Reported" after it is closed and reopened, instead of inviting a
// second write-up the server would only answer with `already_reported` (it
// accepts one report per card per IP per hour).
const reported = ref(new Set<string>())

function cardKey(deckId: number, cardId: number): string {
  return `${deckId}:${cardId}`
}

async function submitCardReport(input: CardReportInput): Promise<CardReportOutcome> {
  const result = await apiRequest<{ status?: string } | null>(
    endpoints.cardReports,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        deck_id: input.deckId,
        card_id: input.cardId,
        card_name: input.cardName,
        reason: input.reason,
        details: input.details.trim(),
      }),
    },
    'Could not send your report. Please try again.',
  )

  if (!result.ok) {
    return { ok: false, error: result.error }
  }

  reported.value = new Set(reported.value).add(cardKey(input.deckId, input.cardId))
  return { ok: true, alreadyReported: result.data?.status === 'already_reported' }
}

/** Report a problem with a card's image, and track which cards were reported. */
export function useCardReports() {
  return {
    hasReported: (deckId: number, cardId: number): boolean =>
      reported.value.has(cardKey(deckId, cardId)),
    submitCardReport,
  }
}
