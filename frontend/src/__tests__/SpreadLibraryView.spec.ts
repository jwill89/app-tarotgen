import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import SpreadLibraryView from '@/views/SpreadLibraryView.vue'
import { useSpreads } from '@/composables/useSpreads'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { cleanup, signIn, stubApi, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  dialog,
  toasts,
  labelled,
  typeInto,
  apiCalls,
} from './helpers/page'
import type { Spread, SpreadPosition, UserSpread } from '@/types'

const position = (order: number, title: string, description?: string): SpreadPosition => ({
  order,
  title,
  x: 20 * order,
  y: 50,
  rotation: 0,
  ...(description ? { description } : {}),
})

/** A category as each spread carries it: its id, name and place in the admins' list. */
type Category = [id: number, name: string, sortOrder: number]
const general: Category = [1, 'General', 1]
// Its place isn't its id: the tabs follow the place.
const love: Category = [2, 'Love & relationships', 3]
const decisions: Category = [4, 'Decisions', 2]

const spread = (
  spread_id: number,
  name: string,
  [category_id, category_name, category_sort_order]: Category,
  positions: SpreadPosition[],
  description = '',
): Spread => ({
  spread_id,
  name,
  description,
  card_count: positions.length,
  category_id,
  category_name,
  category_sort_order,
  positions,
})

const threeCard = spread(
  3,
  'Past, Present, Future',
  general,
  // Out of order on purpose: the detail lists them by `order`.
  [position(3, 'Future'), position(1, 'Past', 'What led here'), position(2, '')],
  'A **classic** line through time.',
)
const twoHearts = spread(
  4,
  'Two Hearts',
  love,
  [position(1, 'You'), position(2, 'Them')],
  'For a bond.',
)
const crossroads = spread(5, 'Crossroads', decisions, [position(1, 'Left'), position(2, 'Right')])

// The one request the page makes: each spread names its category.
const loads = (spreads: Spread[] = [threeCard, twoHearts, crossroads]) => ({
  'GET /spreads': { body: spreads },
})

const open = () => mountPage(SpreadLibraryView, { path: '/spreads' })

const listed = () =>
  [...document.body.querySelectorAll('.library-entry__open')].map((b) => b.textContent.trim())
const tabs = () =>
  [...document.body.querySelectorAll('[role="tab"]')].map((t) =>
    t.textContent.replace(/\s+/g, ' ').trim(),
  )

/** Pick a category tab as a pointer does (Reka activates on mousedown). */
async function chooseTab(label: string): Promise<void> {
  const tab = [...document.body.querySelectorAll<HTMLElement>('[role="tab"]')].find((t) =>
    t.textContent.trim().startsWith(label),
  )
  if (!tab) {
    throw new Error(`No "${label}" tab`)
  }
  tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }))
  await flushPromises()
}

async function openSpread(name: string): Promise<HTMLElement> {
  await click(button(name))
  const detail = dialog(name)
  if (!detail) {
    throw new Error(`"${name}" did not open`)
  }
  return detail
}

describe('SpreadLibraryView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(null)
    useSpreads().spreads.value = []
    useUserSpreads().userSpreads.value = []
  })
  afterEach(cleanup)

  it('lists every spread with its category, card count and the gist of its description', async () => {
    stubApi(loads())
    await open()

    expect(listed()).toEqual(['Past, Present, Future', 'Two Hearts', 'Crossroads'])
    const first = document.body.querySelector('.library-entry')
    expect(first?.querySelector('.library-entry__meta')?.textContent).toBe('General · 3 cards')
    // Markdown as plain text, not markup.
    expect(first?.querySelector('.library-entry__excerpt')?.textContent.trim()).toBe(
      'A classic line through time.',
    )
    // Each row shows the spread's shape.
    expect(first?.querySelectorAll('.spread-canvas--thumb .spread-card')).toHaveLength(3)
  })

  it('offers a tab for each category that holds a spread, in their order', async () => {
    const morning = spread(6, 'Morning Light', general, [position(1, 'Today')])
    stubApi(loads([morning, threeCard, twoHearts, crossroads]))
    await open()

    expect(tabs()).toEqual(['All 4', 'General 2', 'Decisions 1', 'Love & relationships 1'])
    expect(document.body.querySelector('[role="tablist"]')?.getAttribute('aria-label')).toBe(
      'Categories',
    )
  })

  it('filters by category', async () => {
    stubApi(loads())
    await open()

    await chooseTab('Decisions')
    expect(listed()).toEqual(['Crossroads'])

    await chooseTab('All')
    expect(listed()).toHaveLength(3)
  })

  it('searches names and descriptions, within the category chosen', async () => {
    stubApi(loads())
    await open()
    const search = labelled('Search spreads')

    await typeInto(search, 'HEARTS')
    expect(listed()).toEqual(['Two Hearts'])

    await typeInto(search, 'through time')
    expect(listed()).toEqual(['Past, Present, Future'])

    await chooseTab('Love & relationships')
    await typeInto(search, 'bond')
    expect(listed()).toEqual(['Two Hearts'])
  })

  it('says when nothing matches, and clears the search on request', async () => {
    stubApi(loads())
    await open()

    await chooseTab('General')
    await typeInto(labelled('Search spreads'), 'zodiac')
    expect(document.body.querySelector('.library-empty')?.textContent).toContain(
      'No spreads match “zodiac” in General.',
    )

    await click(button('Clear the search'))
    expect(listed()).toEqual(['Past, Present, Future'])
  })

  it('leads on to creating a spread', async () => {
    stubApi(loads())
    await open()

    const create = [...document.body.querySelectorAll('.page-header a')].find(
      (a) => a.textContent.trim() === 'Create a Spread',
    )
    expect(create?.getAttribute('href')).toBe('/create-spread')
  })

  it('says so when there are no spreads yet', async () => {
    stubApi(loads([]))
    await open()

    expect(document.body.textContent).toContain('There are no public spreads yet.')
    expect(document.body.querySelector('[role="tablist"]')).toBeNull()
  })

  it("says the spreads couldn't load, rather than that there are none", async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    stubApi({ ...loads(), 'GET /spreads': { status: 503, body: { error: 'Down.' } } })
    await open()

    expect(document.body.textContent).toContain("The spreads couldn't be loaded just now.")
    expect(document.body.textContent).not.toContain('There are no public spreads yet.')
    expect(toasts()).toEqual(["Couldn't load the spreads. Down."])
  })

  it('opens a spread to its numbered layout and what each position asks', async () => {
    stubApi(loads())
    await open()

    const detail = await openSpread('Past, Present, Future')

    expect(detail.querySelector('.library-detail__meta')?.textContent).toBe('General · 3 cards')
    expect([...detail.querySelectorAll('.preview-number')].map((n) => n.textContent)).toEqual([
      '1',
      '2',
      '3',
    ])
    expect(
      [...detail.querySelectorAll('.library-position')].map((li) => [
        li.querySelector('.library-position__title')?.textContent.replace(/\s+/g, ' ').trim(),
        li.querySelector('.library-position__about')?.textContent.trim() ?? null,
      ]),
    ).toEqual([
      ['1. Past', 'What led here'],
      // An untitled position is called by its number.
      ['2. Position 2', null],
      ['3. Future', null],
    ])
    expect(detail.querySelector('.content strong')?.textContent).toBe('classic')
  })

  it('draws with the spread on New Draw', async () => {
    stubApi(loads())
    await open()

    const detail = await openSpread('Crossroads')
    const draw = [...detail.querySelectorAll('a')].find((a) =>
      a.textContent.includes('Draw with this spread'),
    )

    expect(draw?.getAttribute('href')).toBe('/new?spread=5')
  })

  it('asks a guest to sign in to copy a spread', async () => {
    stubApi(loads())
    await open()

    const detail = await openSpread('Two Hearts')

    expect(hasButton('Copy to My Spreads')).toBe(false)
    const signInLink = [...detail.querySelectorAll('a')].find((a) => a.textContent === 'Sign in')
    expect(signInLink?.getAttribute('href')).toBe('/login?redirect=/spreads')
  })

  it('copies a spread into My Spreads for a signed-in reader', async () => {
    signIn(testUser())
    const copy: UserSpread = {
      user_spread_id: 21,
      user_id: 7,
      name: 'Two Hearts (copy)',
      description: 'For a bond.',
      card_count: 2,
      positions: twoHearts.positions,
      created_at: '2026-10-30 12:00:00',
      updated_at: '2026-10-30 12:00:00',
    }
    const api = stubApi({ ...loads(), 'POST /spreads/4/copy': { status: 201, body: copy } })
    await open()
    const detail = await openSpread('Two Hearts')
    api.mockClear()

    await click(button('Copy to My Spreads'))

    expect(apiCalls(api)).toEqual([['POST', '/api/spreads/4/copy']])
    expect(toasts()).toEqual(['Copied to My Spreads as “Two Hearts (copy)”.'])
    expect(detail.querySelector('.library-copied')?.textContent).toContain(
      'Copied as “Two Hearts (copy)”.',
    )
    const mySpreads = [...detail.querySelectorAll('a')].find(
      (a) => a.textContent === 'Open My Spreads',
    )
    expect(mySpreads?.getAttribute('href')).toBe('/account/spreads')
    expect(button('Copied').disabled).toBe(true)
    expect(useUserSpreads().userSpreads.value.map((s) => s.name)).toEqual(['Two Hearts (copy)'])
  })

  it('says why a copy failed, and lets the reader try again', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    signIn(testUser())
    stubApi({
      ...loads(),
      'POST /spreads/4/copy': { status: 401, body: { error: 'Not authenticated' } },
    })
    await open()
    await openSpread('Two Hearts')

    await click(button('Copy to My Spreads'))

    expect(toasts()).toEqual(['Not authenticated'])
    expect(button('Copy to My Spreads').disabled).toBe(false)
  })

  it('starts each spread it opens afresh, and closes', async () => {
    signIn(testUser())
    stubApi({
      ...loads(),
      'POST /spreads/4/copy': {
        status: 201,
        body: { user_spread_id: 21, name: 'Two Hearts (copy)' },
      },
    })
    await open()
    await openSpread('Two Hearts')
    await click(button('Copy to My Spreads'))

    const footer = dialog('Two Hearts')?.querySelector('footer')
    if (!footer) {
      throw new Error('No footer')
    }
    await click(button('Close', footer))
    expect(dialog('Two Hearts')).toBeNull()

    await openSpread('Crossroads')
    expect(button('Copy to My Spreads').disabled).toBe(false)
  })
})
