import type { DeckSystem, DeckSystemCard, DeckSystemWithCards } from '@/types'

/*
 * The catalog the admin Deck Systems specs share: three approved systems, one
 * submission waiting for review, and one system with its cards, as the admin
 * API sends them.
 */

export const system = (
  deck_system_id: number,
  name: string,
  short_name: string,
  overrides: Partial<DeckSystem> = {},
): DeckSystem => ({
  deck_system_id,
  name,
  short_name,
  total_cards: 78,
  approved: true,
  submitted_by: null,
  ...overrides,
})

export const card = (deck_system_id: number, card_id: number, name: string): DeckSystemCard => ({
  deck_system_id,
  card_id,
  name,
  keywords: null,
  meaning: null,
  advice: null,
  reversed_keywords: null,
  reversed_meaning: null,
  reversed_advice: null,
})

const lenormandSystem = system(3, 'Lenormand', 'Len', { total_cards: 36 })

export const systems = [
  system(1, 'Rider-Waite-Smith', 'RWS'),
  system(2, 'Thoth', 'Thoth'),
  lenormandSystem,
]

/** A visitor's submission, waiting for review. */
export const solaBusca = system(9, 'Sola Busca', 'SB', {
  approved: false,
  submitted_by: 7,
  total_cards: 2,
})
export const pending = [solaBusca]

/** Lenormand with its cards, as GET /admin/deck-systems/3 sends it (cut to two). */
export const lenormand: DeckSystemWithCards = {
  ...lenormandSystem,
  total_cards: 2,
  cards: [card(3, 1, 'Rider'), card(3, 2, 'Clover')],
}

/** The submission with its cards, the first with keywords. */
export const solaBuscaCards: DeckSystemWithCards = {
  ...solaBusca,
  cards: [{ ...card(9, 1, 'Mars'), keywords: 'Drive, courage' }, card(9, 2, 'Venus')],
}
