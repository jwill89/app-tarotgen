import type { DeckSystemCard } from '@/types'

/**
 * Shared helpers for the deck-system card editor.
 *
 * Lives in a plain `.ts` rather than alongside the SFC because a type exported
 * from a `.vue` resolves to `any` under the eslint type-checker — the same
 * reason `components/spreadCanvas.ts` exists.
 */

/**
 * Imperative surface of DeckCardListEditor, for callers that hold a template
 * ref (they annotate it with this type).
 */
export interface DeckCardListEditorApi {
  /**
   * Expand + focus the first card missing a name. Returns false when every card
   * is named, so a caller can use it as its own validation gate.
   */
  revealFirstMissing: () => boolean
  expandAll: () => void
  collapseAll: () => void
}

/**
 * A blank row. `deck_system_id` is 0 for not-yet-saved systems — the create
 * endpoint rebuilds every card row and re-derives card_id from the array index
 * (DeckSystemController::create), so the placeholder never reaches the DB.
 */
export function emptyCard(cardId: number): DeckSystemCard {
  return {
    deck_system_id: 0,
    card_id: cardId,
    name: '',
    keywords: null,
    meaning: null,
    advice: null,
    reversed_keywords: null,
    reversed_meaning: null,
    reversed_advice: null,
  }
}

/**
 * Grow/shrink to `total` rows, keeping the rows the user already typed (and
 * their object identity, so their inputs aren't remounted mid-edit).
 */
export function resizeCards(cards: DeckSystemCard[], total: number): DeckSystemCard[] {
  const count = Math.max(1, total)
  const next = cards.slice(0, count)
  for (let i = next.length; i < count; i++) {
    next.push(emptyCard(i + 1))
  }
  return next
}

export function missingNameCount(cards: DeckSystemCard[]): number {
  return cards.filter((c) => c.name.trim() === '').length
}

export function allCardsNamed(cards: DeckSystemCard[]): boolean {
  return cards.length > 0 && missingNameCount(cards) === 0
}
