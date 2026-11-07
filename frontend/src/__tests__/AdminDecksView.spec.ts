import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import DecksView from '@/views/admin/DecksView.vue'
import { useUser } from '@/composables/useUser'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  confirmDialog,
  answerConfirm,
  dialog,
  toasts,
  row,
  tableRows,
  column,
  labelled,
  valueOf,
  typeInto,
  enterNumber,
  choose,
  header,
  apiCalls,
} from './helpers/page'
import type { Deck, DeckSystem } from '@/types'

const system = (deck_system_id: number, name: string, short_name: string): DeckSystem => ({
  deck_system_id,
  name,
  short_name,
  total_cards: 78,
  approved: true,
  submitted_by: null,
})

const deck = (deck_id: number, name: string, overrides: Partial<Deck> = {}): Deck => ({
  deck_id,
  deck_system_id: 1,
  name,
  artist: `Artist ${deck_id}`,
  purchase_url: '',
  additional_cards: 0,
  card_aspect_w: 5,
  card_aspect_h: 8.6,
  approved: true,
  usable: true,
  submitted_by: null,
  system_short_name: 'RWS',
  system_total_cards: 78,
  ...overrides,
})

// Thoth first, so "RWS is the default system" can't pass by being first.
const systems = [system(2, 'Thoth', 'Thoth'), system(1, 'Rider-Waite-Smith', 'RWS')]
const decks = [
  deck(3, 'Thoth Tarot', { deck_system_id: 2, system_short_name: 'Thoth', artist: 'Harris' }),
  deck(1, 'Rider-Waite Tarot', { artist: 'Pamela Colman Smith', additional_cards: 2 }),
  deck(2, 'Celestial Tarot', { artist: 'Kay Steventon', usable: false }),
]
const pending = [deck(9, 'Golden Tarot', { approved: false, usable: false, submitted_by: 7 })]

/** The calls the page makes on load, answered with these lists. */
const loads = (approved: Deck[] = decks, waiting: Deck[] = []) => ({
  'GET /admin/deck-systems': { body: systems },
  'GET /admin/decks': { body: approved },
  'GET /admin/decks/pending': { body: waiting },
})

/** The panel holding the approved-decks table (the submitted decks have their own). */
function approvedTable(): Element {
  const heading = [...document.body.querySelectorAll('h2')].find(
    (h) => h.textContent.trim() === 'Approved Decks',
  )
  const panel = heading?.parentElement?.querySelector(':scope > .settings-panel')
  if (!panel) {
    throw new Error('No approved-decks table')
  }
  return panel
}

describe('admin DecksView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the lists', () => {
    it('lists the approved decks by ID with their system, card count and status', async () => {
      stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })

      expect(tableRows(approvedTable())).toEqual([
        ['1', 'Rider-Waite Tarot', 'Pamela Colman Smith', 'RWS', '78 + 2', 'Usable', ''],
        ['2', 'Celestial Tarot', 'Kay Steventon', 'RWS', '78', 'Not usable', ''],
        ['3', 'Thoth Tarot', 'Harris', 'Thoth', '78', 'Usable', ''],
      ])
    })

    it('shows no submitted-decks section while nothing awaits approval', async () => {
      stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })

      expect(document.body.textContent).not.toContain('Submitted Decks')
    })

    it('lists submitted decks apart, with a count and who submitted them', async () => {
      stubApi(loads(decks, pending))
      await mountPage(DecksView, { path: '/admin/decks' })

      const heading = [...document.body.querySelectorAll('h2')].find((h) =>
        h.textContent.includes('Submitted Decks'),
      )
      expect(heading?.textContent.replace(/\s+/g, ' ').trim()).toBe('Submitted Decks 1')
      expect([...row('Golden Tarot').cells].map((td) => td.textContent.trim())).toEqual([
        '9',
        'Golden Tarot',
        'Artist 9',
        'RWS',
        '7',
        '',
      ])
      expect(hasButton('Approve', row('Golden Tarot'))).toBe(true)
      expect(column(1, approvedTable())).not.toContain('Golden Tarot')
    })

    it('filters by name, artist or ID as the admin types', async () => {
      stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })
      const search = labelled('Search decks by name, artist, or ID...')

      await typeInto(search, 'celest')
      expect(column(1, approvedTable())).toEqual(['Celestial Tarot'])

      await typeInto(search, 'harris')
      expect(column(1, approvedTable())).toEqual(['Thoth Tarot'])

      await typeInto(search, '1')
      expect(column(1, approvedTable())).toEqual(['Rider-Waite Tarot'])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(header('Name', approvedTable()))
      expect(column(1, approvedTable())).toEqual([
        'Celestial Tarot',
        'Rider-Waite Tarot',
        'Thoth Tarot',
      ])

      await click(header('Name', approvedTable()))
      expect(column(1, approvedTable())).toEqual([
        'Thoth Tarot',
        'Rider-Waite Tarot',
        'Celestial Tarot',
      ])
    })

    it("sorts the card count on the system's cards plus the extra ones", async () => {
      stubApi(
        loads([
          deck(1, 'Big', { system_total_cards: 78, additional_cards: 4 }),
          deck(2, 'Small', { system_total_cards: 22 }),
          deck(3, 'Middle', { system_total_cards: 78 }),
        ]),
      )
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(header('Cards', approvedTable()))

      expect(column(1, approvedTable())).toEqual(['Small', 'Middle', 'Big'])
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({
        'GET /admin/deck-systems': expired,
        'GET /admin/decks': expired,
        'GET /admin/decks/pending': expired,
      })
      const { router } = await mountPage(DecksView, { path: '/admin/decks' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })

    it("says why the lists couldn't load when the network is down", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
      await mountPage(DecksView, { path: '/admin/decks' })

      expect(toasts()).toContain(NETWORK_ERROR_MESSAGE)
      expect(tableRows(approvedTable())).toEqual([])
    })
  })

  describe('adding a deck', () => {
    it('opens a blank form on the RWS system with a standard card shape', async () => {
      stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Add Deck'))

      expect(dialog('Add Deck')).not.toBeNull()
      expect(valueOf(labelled('Name'))).toBe('')
      expect(labelled('Deck System').textContent.trim()).toBe('Rider-Waite-Smith — 78 cards')
      expect(valueOf(labelled('Additional Cards'))).toBe('0')
      expect(valueOf(labelled('Width'))).toBe('5')
      expect(valueOf(labelled('Height'))).toBe('8.6')
    })

    it('creates the deck from the form, then refreshes both lists and closes', async () => {
      const added = deck(4, 'Wild Unknown', { artist: 'Kim Krans', deck_system_id: 2 })
      const api = stubApi({ ...loads(), 'POST /admin/decks': { status: 201, body: added } })
      await mountPage(DecksView, { path: '/admin/decks' })
      await click(button('Add Deck'))

      await typeInto(labelled('Name'), 'Wild Unknown')
      await typeInto(labelled('Artist'), 'Kim Krans')
      await typeInto(labelled('Purchase URL'), 'https://example.test/wild')
      await choose(labelled('Deck System'), 'Thoth — 78 cards')
      await enterNumber(labelled('Additional Cards'), 2)
      await enterNumber(labelled('Height'), 9)
      api.mockClear()
      await click(button('Create'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/admin/decks',
          {
            name: 'Wild Unknown',
            artist: 'Kim Krans',
            purchase_url: 'https://example.test/wild',
            deck_system_id: 2,
            additional_cards: 2,
            card_aspect_w: 5,
            card_aspect_h: 9,
          },
        ],
        ['GET', '/api/admin/decks'],
        ['GET', '/api/admin/decks/pending'],
      ])
      expect(toasts()).toEqual(['Deck created.'])
      expect(dialog('Add Deck')).toBeNull()
    })

    it("keeps the form open and shows the server's reason when creating fails", async () => {
      const api = stubApi({
        ...loads(),
        'POST /admin/decks': { status: 500, body: { error: 'Could not create the deck.' } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      await click(button('Add Deck'))
      await typeInto(labelled('Name'), 'Wild Unknown')
      api.mockClear()

      await click(button('Create'))

      expect(apiCalls(api).map(([method, url]) => `${String(method)} ${String(url)}`)).toEqual([
        'POST /api/admin/decks',
      ])
      expect(toasts()).toEqual(['Could not create the deck.'])
      expect(valueOf(labelled('Name'))).toBe('Wild Unknown')
    })

    it('falls back to the first system when there is no RWS', async () => {
      stubApi({ ...loads(), 'GET /admin/deck-systems': { body: [system(2, 'Thoth', 'Thoth')] } })
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Add Deck'))

      expect(labelled('Deck System').textContent.trim()).toBe('Thoth — 78 cards')
    })

    it('closes on Cancel without saving', async () => {
      const api = stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })
      await click(button('Add Deck'))
      api.mockClear()

      await click(button('Cancel'))

      expect(dialog('Add Deck')).toBeNull()
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('editing a deck', () => {
    it("opens the deck's details and saves the changes over it", async () => {
      const api = stubApi({
        ...loads(),
        'PATCH /admin/decks/2': { body: { ...decks[2], name: 'Celestial Tarot (2nd ed.)' } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Edit', row('Celestial Tarot')))
      expect(dialog('Edit Deck #2')).not.toBeNull()
      expect(valueOf(labelled('Artist'))).toBe('Kay Steventon')

      await typeInto(labelled('Name'), 'Celestial Tarot (2nd ed.)')
      api.mockClear()
      await click(button('Save'))

      expect(apiCalls(api)[0]).toEqual([
        'PATCH',
        '/api/admin/decks/2',
        { ...decks[2], name: 'Celestial Tarot (2nd ed.)' },
      ])
      expect(toasts()).toEqual(['Deck updated.'])
      expect(dialog('Edit Deck #2')).toBeNull()
    })

    it('edits a submitted deck from its own row', async () => {
      stubApi(loads(decks, pending))
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Edit', row('Golden Tarot')))

      expect(dialog('Edit Deck #9')).not.toBeNull()
      expect(valueOf(labelled('Name'))).toBe('Golden Tarot')
    })
  })

  describe('row actions', () => {
    it('asks before deleting, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(DecksView, { path: '/admin/decks' })
      api.mockClear()

      await click(button('Delete', row('Celestial Tarot')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "Celestial Tarot"? This also deletes every special card for this deck and cannot be undone.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
      expect(confirmDialog()).toBeNull()
    })

    it('deletes on confirmation and refreshes the lists', async () => {
      const remaining = decks.filter((d) => d.deck_id !== 2)
      let deleted = false
      const api = stubApi({
        ...loads(),
        'GET /admin/decks': () => ({ body: deleted ? remaining : decks }),
        'DELETE /admin/decks/2': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      await click(button('Delete', row('Celestial Tarot')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/decks/2'],
        ['GET', '/api/admin/decks'],
        ['GET', '/api/admin/decks/pending'],
      ])
      expect(toasts()).toEqual(['Deck deleted.'])
      expect(column(1, approvedTable())).toEqual(['Rider-Waite Tarot', 'Thoth Tarot'])
    })

    it('keeps the deck listed when the delete fails', async () => {
      stubApi({
        ...loads(),
        'DELETE /admin/decks/2': { status: 500, body: { error: 'Failed to delete deck.' } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      await click(button('Delete', row('Celestial Tarot')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Failed to delete deck.'])
      expect(column(1, approvedTable())).toContain('Celestial Tarot')
    })

    it('approves a submitted deck and moves it into the approved list', async () => {
      const approved = { ...pending[0], approved: true }
      let done = false
      const api = stubApi({
        ...loads(),
        'GET /admin/decks': () => ({ body: done ? [...decks, approved] : decks }),
        'GET /admin/decks/pending': () => ({ body: done ? [] : pending }),
        'POST /admin/decks/9/approve': () => {
          done = true
          return { body: approved }
        },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      api.mockClear()

      await click(button('Approve', row('Golden Tarot')))

      expect(apiCalls(api)[0]).toEqual(['POST', '/api/admin/decks/9/approve', {}])
      expect(toasts()).toEqual(['Deck approved.'])
      expect(document.body.textContent).not.toContain('Submitted Decks')
      expect(column(1, approvedTable())).toContain('Golden Tarot')
    })

    it('marks a usable deck unusable, and an unusable one usable', async () => {
      const api = stubApi({
        ...loads(),
        'PATCH /admin/decks/1': { body: { ...decks[1], usable: false } },
        'PATCH /admin/decks/2': { body: { ...decks[2], usable: true } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      api.mockClear()

      await click(button('Mark as not usable', row('Rider-Waite Tarot')))
      await click(button('Mark as usable', row('Celestial Tarot')))

      expect(apiCalls(api)).toEqual([
        ['PATCH', '/api/admin/decks/1', { usable: false }],
        ['GET', '/api/admin/decks'],
        ['PATCH', '/api/admin/decks/2', { usable: true }],
        ['GET', '/api/admin/decks'],
      ])
      expect(toasts()).toEqual(['Deck marked unusable.', 'Deck marked usable.'])
    })
  })

  describe('web images', () => {
    it("generates one deck's images and reports what was done", async () => {
      const api = stubApi({
        ...loads(),
        'POST /admin/decks/1/images': {
          body: { deck_id: 1, generated: 80, skipped: 0, failed: 0 },
        },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      api.mockClear()

      await click(button('Generate web images', row('Rider-Waite Tarot')))

      expect(apiCalls(api)).toEqual([['POST', '/api/admin/decks/1/images', {}]])
      expect(toasts()).toEqual(['"Rider-Waite Tarot": 80 card(s) processed, 0 already current.'])
    })

    it('warns when some cards could not be generated', async () => {
      stubApi({
        ...loads(),
        'POST /admin/decks/1/images': {
          body: { deck_id: 1, generated: 78, skipped: 0, failed: 2 },
        },
      })
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Generate web images', row('Rider-Waite Tarot')))

      expect(toasts()).toEqual([
        '"Rider-Waite Tarot": 78 card(s) processed, 0 already current, 2 failed (unreadable scan or unwritable folder).',
      ])
    })

    it('generates every deck at once and reports the totals', async () => {
      const api = stubApi({
        ...loads(),
        'POST /admin/decks/images': { body: { decks: 3, generated: 12, skipped: 222, failed: 0 } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })
      api.mockClear()

      await click(button('Generate All Web Images'))

      expect(apiCalls(api)).toEqual([['POST', '/api/admin/decks/images', {}]])
      expect(toasts()).toEqual(['3 deck(s): 12 card(s) processed, 222 already current.'])
    })

    it("shows the server's reason when generating fails", async () => {
      stubApi({
        ...loads(),
        'POST /admin/decks/1/images': { status: 400, body: { error: 'Deck has no scans.' } },
      })
      await mountPage(DecksView, { path: '/admin/decks' })

      await click(button('Generate web images', row('Rider-Waite Tarot')))

      expect(toasts()).toEqual(['Deck has no scans.'])
    })
  })
})
