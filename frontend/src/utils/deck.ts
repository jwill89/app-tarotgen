import type { Deck, DrawCard } from '@/types'

/**
 * A drawn card's name, or "Card #N" when the deck has none for it (an extra card
 * nobody has named yet — the API leaves card_name out then).
 */
export function cardLabel(card: DrawCard): string {
  return card.card_name ?? `Card #${String(card.card_id)}`
}

/**
 * The deck to select by default: a still-available remembered choice if given,
 * otherwise Rider-Waite (deck 1 / name match), otherwise the first deck; null
 * when there are none.
 */
export function defaultDeckId(decks: Deck[], rememberedId: number | null = null): number | null {
  if (rememberedId !== null && decks.some((d) => d.deck_id === rememberedId)) {
    return rememberedId
  }
  const riderWaite =
    decks.find((d) => d.deck_id === 1) ?? decks.find((d) => /rider[-\s]?waite/i.test(d.name))
  return (riderWaite ?? decks[0])?.deck_id ?? null
}
