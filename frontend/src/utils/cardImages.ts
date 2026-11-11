/**
 * Where a card's images live. One place for the `Card_XXXX` naming and the
 * /assets/decks layout. The files are served by the PHP host, not bundled:
 * the dev server proxies /assets there, so a Vue `import` of one would compile
 * into a module that returns raw image bytes, which is why the URLs are built
 * as plain strings.
 *
 * Per card the backend's CardImageService derives, from the master PNG scan:
 *   display — full-size WebP for the lightbox and image export (~8% of the PNG)
 *   thumb   — 400 px WebP for grids and pickers
 * `png` is the master scan itself: the fallback while a deck's web images
 * haven't been generated yet, and what admins inspect for scan flaws.
 */
export interface CardImageUrls {
  display: string
  png: string
  thumb: string
}

function urlsFor(deckId: number, fileName: string): CardImageUrls {
  const base = `/assets/decks/${deckId}`
  return {
    display: `${base}/${fileName}.webp`,
    png: `${base}/${fileName}.png`,
    thumb: `${base}/thumbs/${fileName}.webp`,
  }
}

export function cardImageUrls(deckId: number, cardId: number): CardImageUrls {
  return urlsFor(deckId, `Card_${String(cardId).padStart(4, '0')}`)
}

export function cardBackImageUrls(deckId: number): CardImageUrls {
  return urlsFor(deckId, 'Card_Back')
}
