import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import AccountReadingsView from '@/views/AccountReadingsView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useDecks } from '@/composables/useDecks'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { mountWithApp, cleanup, stubApi, signIn, testRouter, testUser } from './helpers/app'
import { choose, labelled, optionsOf, typeInto, valueOf } from './helpers/page'
import type { AccountReading, Deck, ReadingSpread, ReadingTag } from '@/types'

// The view inside what App.vue gives it: the TooltipProvider the notes editor's
// toolbar needs and the shared ConfirmDialog its confirm() calls open.
const Page = defineComponent({
  render: () => h(TooltipProvider, null, () => [h(AccountReadingsView), h(ConfirmDialog)]),
})

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

function button(name: string, root: ParentNode = document.body): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].filter((b) => text(b) === name)
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

/** The table row whose title (the first line of its first cell) is `title`. */
function row(title: string): HTMLTableRowElement {
  const found = [...document.body.querySelectorAll('tbody tr')].find(
    (tr): tr is HTMLTableRowElement => tr instanceof HTMLTableRowElement && titleOf(tr) === title,
  )
  if (!found) {
    throw new Error(`No reading titled "${title}"`)
  }
  return found
}

/** A row's title: its first cell's text before the question, querent and tags. */
function titleOf(tr: Element): string {
  const cell = tr.querySelector('td')
  if (!cell) {
    return ''
  }
  return [...cell.childNodes]
    .filter(
      (n) =>
        n.nodeType === Node.TEXT_NODE ||
        (n instanceof HTMLElement && !(n instanceof HTMLParagraphElement)),
    )
    .map((n) => n.textContent)
    .join('')
    .replace(/\s+/g, ' ')
    .trim()
}

const titles = () => [...document.body.querySelectorAll('tbody tr')].map(titleOf)

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

const editDialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')
const confirmDialog = () => document.body.querySelector<HTMLElement>('[role="alertdialog"]')

async function answer(choice: string) {
  const open = confirmDialog()
  if (!open) {
    throw new Error('No confirm dialog is open')
  }
  await click(button(choice, open))
}

const reading = (reading_id: string, extra: Partial<AccountReading> = {}): AccountReading => ({
  reading_id,
  reading_info: {
    deck_id: 1,
    draw: [
      { card_id: 1, reversed: false },
      { card_id: 2, reversed: true },
      { card_id: 3, reversed: false },
    ],
  },
  reading_time: '2026-10-01 12:00:00',
  user_id: 7,
  hide_user: false,
  reading_name: null,
  reading_notes: null,
  is_final: false,
  password_protected: false,
  daily_card_day: null,
  question: null,
  querent: null,
  tags: [],
  visibility: 'link',
  expires_at: null,
  ...extra,
})

const named = (reading_id: string, reading_name: string, extra: Partial<AccountReading> = {}) =>
  reading(reading_id, { reading_name, ...extra })

const rws = { deck_id: 1, name: 'Rider-Waite' } as Deck
const thoth = { deck_id: 2, name: 'Thoth' } as Deck
const myTags: ReadingTag[] = [
  { tag: 'career', count: 2 },
  { tag: 'monthly', count: 1 },
]
const mySpreads: ReadingSpread[] = [
  { name: 'Celtic Cross', count: 3 },
  { name: 'Free Draw', count: 2 },
]

/**
 * Serve the user's readings a page at a time with the total in X-Total-Count, as
 * the API does, plus the decks, spreads and tags the filters offer, plus `handlers`.
 * `rows` is read on every request, so a test can change it; `asked` records
 * each list request's query.
 */
function serve(rows: () => AccountReading[], handlers: Parameters<typeof stubApi>[0] = {}) {
  const asked: Record<string, string>[] = []
  const api = stubApi({
    'GET /account/readings': (_body, url) => {
      const query = Object.fromEntries(url.searchParams)
      asked.push(query)
      const limit = Number(query.limit)
      const offset = Number(query.offset)
      return {
        body: rows().slice(offset, offset + limit),
        headers: { 'X-Total-Count': String(rows().length) },
      }
    },
    'GET /decks': { body: [rws, thoth] },
    'GET /account/reading-spreads': { body: mySpreads },
    'GET /account/tags': { body: myTags },
    ...handlers,
  })
  // What the view changed: every call but the reads.
  const writes = () => api.mock.calls.filter(([, init]) => init?.method !== undefined)
  /** The filters of the last list request, without the paging. */
  const lastFilters = () => {
    const { limit: _limit, offset: _offset, ...filters } = asked.at(-1) ?? {}
    return filters
  }
  return { asked, writes, lastFilters }
}

const many = (n: number) =>
  Array.from({ length: n }, (_, i) => named(`r${i + 1}`, `Reading ${i + 1}`))

async function open(path = '/account/readings') {
  const router = testRouter()
  const mounted = await mountWithApp(Page, { path, router })
  return { ...mounted, router }
}

describe('AccountReadingsView', () => {
  beforeEach(() => {
    signIn(testUser())
    useToasts().toasts.value = []
    useConfirm().settle(false)
    useDecks().decks.value = []
  })
  afterEach(() => {
    vi.useRealTimers()
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('shows it is loading until the first page arrives', async () => {
      // Only the list hangs: the decks are shared module state the next test reads.
      vi.stubGlobal(
        'fetch',
        vi.fn((url: string) =>
          url.startsWith('/api/account/readings')
            ? new Promise<Response>(() => {})
            : Promise.resolve(Response.json([])),
        ),
      )
      await open()

      expect(pageText()).toContain('Loading your readings…')
    })

    it('names each reading by its title, else its spread, else its card count', async () => {
      const spread = { spread_id: 3, name: 'Celtic Cross', description: '', positions: [] }
      serve(() => [
        named('a', 'Morning reflection'),
        reading('b', { reading_info: { deck_id: 1, spread, draw: [] } }),
        reading('c'),
        reading('d', { reading_info: { deck_id: 1, draw: [{ card_id: 5, reversed: false }] } }),
      ])
      await open()

      expect(titles()).toEqual(['Morning reflection', 'Celtic Cross', '3 cards', '1 card'])
      expect(pageText()).toContain('4 readings')
    })

    it('marks a daily card as one', async () => {
      serve(() => [
        named('a', 'Daily card — 30 October 2026', { daily_card_day: '2026-10-30' }),
        named('b', 'Morning reflection'),
      ])
      await open()

      const tags = (title: string) =>
        [...row(title).cells[0]!.querySelectorAll('.tag')].map((t) => text(t))
      expect(tags('Daily card — 30 October 2026 Daily card')).toEqual(['Daily card'])
      expect(tags('Morning reflection')).toEqual([])
    })

    it('shows each reading’s question, who it was for and its tags', async () => {
      serve(() => [
        named('a', 'Morning reflection', {
          question: 'Should I take the job?',
          querent: 'My sister',
          tags: ['career', 'monthly'],
        }),
        named('b', 'Plain'),
      ])
      await open()

      const cell = row('Morning reflection').cells[0]!
      expect(text(cell.querySelector('.reading-question') ?? cell)).toBe('“Should I take the job?”')
      expect(text(cell.querySelector('.reading-querent') ?? cell)).toBe('For My sister')
      expect([...cell.querySelectorAll('.data-chip')].map(text)).toEqual(['career', 'monthly'])
      expect(row('Plain').cells[0]!.querySelector('p')).toBeNull()
    })

    it('shows who can open each reading', async () => {
      serve(() => [
        named('a', 'Locked', { visibility: 'password', password_protected: true }),
        named('b', 'Anonymous', { hide_user: true }),
        named('c', 'Open'),
        named('d', 'Mine alone', { visibility: 'private' }),
      ])
      await open()

      expect(text(row('Locked').cells[2]!)).toBe('Password')
      expect(text(row('Anonymous').cells[2]!)).toBe('Anyone with the link Name hidden')
      expect(text(row('Open').cells[2]!)).toBe('Anyone with the link')
      expect(text(row('Mine alone').cells[2]!)).toBe('Private')
    })

    it('links each reading to its page', async () => {
      serve(() => [named('abc123', 'Morning reflection')])
      await open()

      const view = [...row('Morning reflection').querySelectorAll('a')].find(
        (a) => text(a) === 'View',
      )
      expect(view?.getAttribute('href')).toBe('/reading/abc123')
    })

    it('says so when there are no readings yet', async () => {
      serve(() => [])
      await open()

      expect(pageText()).toContain("You haven't saved any readings yet.")
    })

    it("says the readings couldn't load (rather than that there are none), and retries", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
      vi.spyOn(console, 'error').mockImplementation(() => {})
      await open()

      expect(pageText()).toContain("Couldn't load your readings. Network error.")
      expect(pageText()).not.toContain("You haven't saved any readings yet.")

      serve(() => [named('a', 'Morning reflection')])
      await click(button('Try again'))

      expect(titles()).toEqual(['Morning reflection'])
    })

    it('asks for one page at a time and pages through the rest, in the address', async () => {
      const { asked } = serve(() => many(30))
      const { router } = await open()

      expect(asked.map((q) => [q.limit, q.offset])).toEqual([['25', '0']])
      expect(titles()).toHaveLength(25)

      await click(button('2'))
      await vi.waitFor(() =>
        expect(titles()).toEqual(
          many(30)
            .slice(25)
            .map((r) => r.reading_name),
        ),
      )
      expect(asked.at(-1)?.offset).toBe('25')
      expect(router.currentRoute.value.query).toEqual({ page: '2' })
    })

    it('opens on the page the address names', async () => {
      const { asked } = serve(() => many(30))
      await open('/account/readings?page=2')

      expect(asked.map((q) => q.offset)).toEqual(['25'])
      expect(titles()).toEqual([
        'Reading 26',
        'Reading 27',
        'Reading 28',
        'Reading 29',
        'Reading 30',
      ])
    })

    it('has no pager when everything fits on one page', async () => {
      serve(() => many(25))
      await open()

      expect([...document.body.querySelectorAll('button')].some((b) => text(b) === '2')).toBe(false)
    })
  })

  describe('finding a reading', () => {
    it('searches once the reader stops typing', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      const { asked, lastFilters } = serve(() => [named('a', 'Career check')])
      const { router } = await open()

      await typeInto(labelled('Search'), 'car')
      await typeInto(labelled('Search'), 'career ')
      expect(asked).toHaveLength(1)

      vi.advanceTimersByTime(400)
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({ q: 'career' })
      expect(asked).toHaveLength(2)
      expect(lastFilters()).toEqual({ q: 'career' })
    })

    it('filters by deck', async () => {
      const { lastFilters } = serve(() => [named('a', 'Thoth reading')])
      const { router } = await open()

      await choose(labelled('Deck'), 'Thoth')

      expect(router.currentRoute.value.query).toEqual({ deck: '2' })
      expect(lastFilters()).toEqual({ deck_id: '2' })
    })

    it("offers only the spreads the reader's readings were laid out in, with how many each", async () => {
      serve(() => [named('a', 'Reading')])
      await open()

      expect(text(labelled('Spread'))).toBe('All spreads')
      expect(await optionsOf(labelled('Spread'))).toEqual([
        'All spreads',
        'Celtic Cross (3)',
        'Free Draw (2)',
      ])
    })

    it('filters by spread, in the address, and back to every spread', async () => {
      const { lastFilters } = serve(() => [named('a', 'Free draw')])
      const { router } = await open()

      await choose(labelled('Spread'), 'Free Draw (2)')

      expect(router.currentRoute.value.query).toEqual({ spread: 'Free Draw' })
      expect(lastFilters()).toEqual({ spread: 'Free Draw' })
      expect(text(labelled('Spread'))).toBe('Free Draw (2)')

      await choose(labelled('Spread'), 'All spreads')

      expect(router.currentRoute.value.query).toEqual({})
      expect(lastFilters()).toEqual({})
    })

    it('still filters when the spreads could not be listed, offering none', async () => {
      const { lastFilters } = serve(() => [named('a', 'Reading')], {
        'GET /account/reading-spreads': { status: 500, body: { error: 'Server error' } },
      })
      await open()

      expect(await optionsOf(labelled('Spread'))).toEqual(['All spreads'])
      await choose(labelled('Deck'), 'Thoth')
      expect(lastFilters()).toEqual({ deck_id: '2' })
      expect(titles()).toEqual(['Reading'])
    })

    it("filters by one of the reader's tags, showing how many carry each", async () => {
      const { lastFilters } = serve(() => [named('a', 'Tagged', { tags: ['career'] })])
      const { router } = await open()

      await choose(labelled('Tag'), 'career (2)')

      expect(router.currentRoute.value.query).toEqual({ tag: 'career' })
      expect(lastFilters()).toEqual({ tag: 'career' })
    })

    it("filters by a reading's tag from its row", async () => {
      const { lastFilters } = serve(() => [named('a', 'Tagged', { tags: ['monthly'] })])
      const { router } = await open()

      const chip = row('Tagged').querySelector<HTMLAnchorElement>('.data-chip')
      if (!chip) {
        throw new Error('No tag')
      }
      await click(chip)

      expect(router.currentRoute.value.query).toEqual({ tag: 'monthly' })
      expect(lastFilters()).toEqual({ tag: 'monthly' })
    })

    it("filters by the reader's own dates, in their time zone", async () => {
      const { lastFilters } = serve(() => [named('a', 'March')])
      const { router } = await open()

      const from = labelled('From') as HTMLInputElement
      from.value = '2026-03-01'
      from.dispatchEvent(new Event('change'))
      await flushPromises()
      const to = labelled('To') as HTMLInputElement
      to.value = '2026-03-31'
      to.dispatchEvent(new Event('change'))
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({ from: '2026-03-01', to: '2026-03-31' })
      expect(lastFilters()).toEqual({ from: '2026-03-01', to: '2026-03-31', tz: 'UTC' })
      // Neither end can pass the other.
      expect(from.max).toBe('2026-03-31')
      expect(to.min).toBe('2026-03-01')
    })

    it('leaves daily cards out, or shows only them', async () => {
      const { lastFilters } = serve(() => [named('a', 'Reading')])
      const { router } = await open()

      await choose(labelled('Daily Cards'), 'Left out')
      expect(lastFilters()).toEqual({ daily: 'exclude' })

      await choose(labelled('Daily Cards'), 'Only daily cards')
      expect(router.currentRoute.value.query).toEqual({ daily: 'only' })
      expect(lastFilters()).toEqual({ daily: 'only' })

      await choose(labelled('Daily Cards'), 'Included')
      expect(router.currentRoute.value.query).toEqual({})
    })

    it('opens with the filters the address holds, and shows them', async () => {
      const { asked } = serve(() => many(30))
      await open(
        '/account/readings?q=moon&deck=1&spread=Celtic%20Cross&tag=career&from=2026-03-01&daily=exclude&page=2',
      )

      expect(asked).toEqual([
        {
          limit: '25',
          offset: '25',
          q: 'moon',
          deck_id: '1',
          spread: 'Celtic Cross',
          tag: 'career',
          from: '2026-03-01',
          daily: 'exclude',
          tz: 'UTC',
        },
      ])
      expect(valueOf(labelled('Search'))).toBe('moon')
      expect(text(labelled('Deck'))).toBe('Rider-Waite')
      expect(text(labelled('Spread'))).toBe('Celtic Cross (3)')
      expect(text(labelled('Tag'))).toBe('career (2)')
      expect((labelled('From') as HTMLInputElement).value).toBe('2026-03-01')
      expect(text(labelled('Daily Cards'))).toBe('Left out')
    })

    it('keeps a tag or spread from the address that no reading uses any more', async () => {
      const { lastFilters } = serve(() => [])
      await open('/account/readings?tag=gone&spread=Horseshoe')

      expect(lastFilters()).toEqual({ tag: 'gone', spread: 'Horseshoe' })
      expect(text(labelled('Tag'))).toBe('gone')
      expect(text(labelled('Spread'))).toBe('Horseshoe')
      expect(await optionsOf(labelled('Spread'))).toEqual([
        'All spreads',
        'Celtic Cross (3)',
        'Free Draw (2)',
        'Horseshoe',
      ])
    })

    it('starts again at page 1 when a filter changes', async () => {
      const { asked } = serve(() => many(30))
      const { router } = await open('/account/readings?page=2')

      await choose(labelled('Deck'), 'Rider-Waite')

      expect(router.currentRoute.value.query).toEqual({ deck: '1' })
      expect(asked.at(-1)?.offset).toBe('0')
    })

    it('goes back to the filters before with the Back button', async () => {
      const { lastFilters } = serve(() => [named('a', 'Reading')])
      const { router } = await open('/account/readings?tag=career')

      await choose(labelled('Deck'), 'Thoth')
      expect(lastFilters()).toEqual({ tag: 'career', deck_id: '2' })

      router.back()
      await vi.waitFor(() => expect(router.currentRoute.value.query).toEqual({ tag: 'career' }))
      await flushPromises()

      expect(lastFilters()).toEqual({ tag: 'career' })
      expect(text(labelled('Deck'))).toBe('All decks')
    })

    it('adds a spread to the other filters, and Back takes it off again', async () => {
      const { lastFilters } = serve(() => [named('a', 'Reading')])
      const { router } = await open('/account/readings?deck=2&tag=career')

      await choose(labelled('Spread'), 'Celtic Cross (3)')
      expect(router.currentRoute.value.query).toEqual({
        deck: '2',
        tag: 'career',
        spread: 'Celtic Cross',
      })
      expect(lastFilters()).toEqual({ deck_id: '2', tag: 'career', spread: 'Celtic Cross' })

      router.back()
      await vi.waitFor(() =>
        expect(router.currentRoute.value.query).toEqual({ deck: '2', tag: 'career' }),
      )
      await flushPromises()

      expect(lastFilters()).toEqual({ deck_id: '2', tag: 'career' })
      expect(text(labelled('Spread'))).toBe('All spreads')
    })

    it('clears every filter at once', async () => {
      const { lastFilters } = serve(() => [named('a', 'Reading')])
      const { router } = await open(
        '/account/readings?q=moon&spread=Free%20Draw&tag=career&daily=only',
      )

      await click(button('Clear filters'))

      expect(router.currentRoute.value.query).toEqual({})
      expect(lastFilters()).toEqual({})
      expect(valueOf(labelled('Search'))).toBe('')
      expect(text(labelled('Spread'))).toBe('All spreads')
      expect(
        [...document.body.querySelectorAll('button')].some((b) => text(b) === 'Clear filters'),
      ).toBe(false)
    })

    it('says when nothing matches, rather than that there are no readings', async () => {
      serve(() => [])
      await open('/account/readings?q=nothing')

      expect(pageText()).toContain('No readings match these filters.')
      expect(pageText()).not.toContain("You haven't saved any readings yet.")
    })
  })

  describe('editing a reading', () => {
    it('opens with the reading as it is', async () => {
      serve(() => [
        named('a', 'Morning reflection', {
          hide_user: true,
          reading_notes: 'The **Tower**.',
          question: 'Should I take the job?',
          tags: ['career'],
        }),
      ])
      await open()

      await click(button('Edit', row('Morning reflection')))

      expect(editDialog()).not.toBeNull()
      expect(valueOf(labelled('Reading Title'))).toBe('Morning reflection')
      expect(valueOf(labelled('Question'))).toBe('Should I take the job?')
      expect(labelled('Hide my display name on this reading').getAttribute('aria-checked')).toBe(
        'true',
      )
      expect(editDialog()?.querySelector('[role="textbox"] strong')?.textContent).toBe('Tower')
    })

    it('saves the changes, then refreshes the list and the tags', async () => {
      let rows = [named('a', 'Morning reflection')]
      const { writes, asked } = serve(() => rows, {
        'PATCH /account/readings/a': () => {
          rows = [named('a', 'Evening reflection', { visibility: 'private' })]
          return { body: rows[0] }
        },
      })
      await open()

      await click(button('Edit', row('Morning reflection')))
      await typeInto(labelled('Reading Title'), '  Evening reflection ')
      await click(button('Private'))
      await click(button('Save'))

      expect(JSON.parse(String(writes()[0]?.[1]?.body))).toMatchObject({
        reading_name: 'Evening reflection',
        visibility: 'private',
      })
      expect(toastMessages()).toEqual(['Reading updated.'])
      expect(editDialog()).toBeNull()
      expect(asked).toHaveLength(2)
      expect(text(row('Evening reflection').cells[2]!)).toBe('Private')
    })

    it("keeps the dialog open with the server's reason when a save is refused", async () => {
      serve(() => [named('a', 'Morning reflection')], {
        'PATCH /account/readings/a': {
          status: 422,
          body: { error: 'Choose a password of at least 4 characters for this reading.' },
        },
      })
      await open()

      await click(button('Edit', row('Morning reflection')))
      await click(button('Password'))
      await click(button('Save'))

      expect(editDialog()?.textContent).toContain(
        'Choose a password of at least 4 characters for this reading.',
      )
      expect(toastMessages()).toEqual([])
    })

    it('closes without saving on Cancel', async () => {
      const { writes } = serve(() => [named('a', 'Morning reflection')])
      await open()

      await click(button('Edit', row('Morning reflection')))
      await click(button('Cancel'))

      expect(editDialog()).toBeNull()
      expect(writes()).toEqual([])
    })
  })

  describe('deleting a reading', () => {
    it('deletes after a warning naming the reading, then refreshes the list and the spreads', async () => {
      let rows = [named('a', 'Morning reflection'), named('b', 'Evening reflection')]
      let spreads: ReadingSpread[] = [{ name: 'Celtic Cross', count: 2 }]
      const { writes } = serve(() => rows, {
        'GET /account/reading-spreads': () => ({ body: spreads }),
        'DELETE /account/readings/a': () => {
          rows = rows.filter((r) => r.reading_id !== 'a')
          spreads = [{ name: 'Celtic Cross', count: 1 }]
          return { status: 204 }
        },
      })
      await open()

      await click(button('Delete', row('Morning reflection')))
      expect(confirmDialog()?.textContent).toContain('Permanently delete "Morning reflection"?')
      await answer('Delete')

      expect(writes()).toHaveLength(1)
      expect(toastMessages()).toEqual(['Reading deleted.'])
      expect(titles()).toEqual(['Evening reflection'])
      expect(await optionsOf(labelled('Spread'))).toEqual(['All spreads', 'Celtic Cross (1)'])
    })

    it('keeps the reading when the warning is cancelled', async () => {
      const { writes } = serve(() => [named('a', 'Morning reflection')])
      await open()

      await click(button('Delete', row('Morning reflection')))
      await answer('Cancel')

      expect(writes()).toEqual([])
      expect(titles()).toEqual(['Morning reflection'])
    })

    it("says why a reading couldn't be deleted", async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      serve(() => [named('a', 'Morning reflection')], {
        'DELETE /account/readings/a': { status: 404, body: { error: 'Reading not found.' } },
      })
      await open()

      await click(button('Delete', row('Morning reflection')))
      await answer('Delete')

      expect(toastMessages()).toEqual(['Reading not found.'])
      expect(titles()).toEqual(['Morning reflection'])
    })

    it('steps back a page after deleting the only reading on the last one', async () => {
      let rows = many(26)
      const { asked } = serve(() => rows, {
        'DELETE /account/readings/r26': () => {
          rows = rows.slice(0, 25)
          return { status: 204 }
        },
      })
      const { router } = await open()
      await click(button('2'))
      await vi.waitFor(() => expect(titles()).toEqual(['Reading 26']))

      await click(button('Delete', row('Reading 26')))
      await answer('Delete')

      await vi.waitFor(() => expect(titles()).toHaveLength(25))
      expect(asked.map((q) => q.offset)).toEqual(['0', '25', '25', '0'])
      expect(router.currentRoute.value.query).toEqual({})
    })
  })
})
