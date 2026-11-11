/**
 * Per-route SEO head management for the SPA.
 *
 * The app serves a single static index.html for every route, so without this
 * crawlers (and Google's rendered index) would see the homepage's description,
 * canonical URL and indexability on every page. We keep the same lightweight,
 * dependency-free approach as the existing document.title handling in the
 * router: imperatively patch the relevant <head> tags on each navigation.
 *
 * Note: og:/twitter: tags are deliberately NOT updated here — social crawlers
 * don't run JS, so per-route social meta only matters for server-rendered
 * responses (see og.php for shared reading links).
 */

const SITE_URL = 'https://tarotgen.io'

/** Site-wide fallback, kept in sync with index.html's static description. */
const DEFAULT_DESCRIPTION =
  'Draw your own tarot spreads! Select one of various decks, draw a specific spread or freely choose a number of cards, then draw! Tools for tarot readers to share their craft online!'

function upsertMetaByName(name: string, content: string): void {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute('name', name)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

function upsertCanonical(href: string): void {
  let el = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]')
  if (!el) {
    el = document.createElement('link')
    el.setAttribute('rel', 'canonical')
    document.head.appendChild(el)
  }
  el.setAttribute('href', href)
}

export interface RouteSeo {
  /** Meta description for this route; falls back to the site default. */
  description?: string
  /** When true, emit `<meta name="robots" content="noindex, nofollow">`. */
  noindex?: boolean
}

/**
 * Apply description, canonical and robots tags for the given route.
 * @param seo - the route's `meta` (only description/noindex are read)
 * @param path - the route path used to build a self-referencing canonical URL
 */
export function applyRouteSeo(seo: RouteSeo, path: string): void {
  upsertMetaByName('description', seo.description || DEFAULT_DESCRIPTION)
  upsertCanonical(SITE_URL + (path === '/' ? '/' : path.replace(/\/+$/, '')))
  upsertMetaByName('robots', seo.noindex ? 'noindex, nofollow' : 'index, follow')
}
