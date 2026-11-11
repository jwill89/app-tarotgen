import type { Deck } from '@/types'

/** The standard tarot card ratio, used when a deck gives none. */
export const DEFAULT_CARD_ASPECT = '5 / 8.6'

/** A deck's card slot aspect ratio as a CSS `aspect-ratio` value (e.g. "5 / 8.6"). */
export function cardAspectRatio(deck?: Deck | null): string {
  if (deck && deck.card_aspect_w > 0 && deck.card_aspect_h > 0) {
    return `${deck.card_aspect_w} / ${deck.card_aspect_h}`
  }
  return DEFAULT_CARD_ASPECT
}

/** Inline style setting the `--card-aspect` custom property for a deck's cards. */
export function cardAspectStyle(deck?: Deck | null): Record<string, string> {
  return { '--card-aspect': cardAspectRatio(deck) }
}
