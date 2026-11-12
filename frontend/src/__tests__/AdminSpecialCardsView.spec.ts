import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SpecialCardsView from '@/views/admin/SpecialCardsView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  click,
  confirmDialog,
  answerConfirm,
  dialog,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  valueOf,
  typeInto,
  enterNumber,
  choose,
  optionsOf,
  apiCalls,
} from './helpers/page'
import type { Deck, SpecialCard } from '@/types'

const deck = (deck_id: number, name: string, system_short_name = 'RWS'): Deck => ({
  deck_id,
  deck_system_id: 1,
  name,
  artist: `Artist ${deck_id}`,
  purchase_url: '',
  additional_cards: 2,
  card_aspect_w: 5,
  card_aspect_h: 8.6,
  approved: true,
  usable: true,
  submitted_by: null,
  system_short_name,
  system_total_cards: 78,
})

const special = (deck_id: number, card_id: number, name: string): SpecialCard => ({
  deck_id,
  card_id,
  name,
  keywords: null,
  meaning: null,
  advice: null,
  keywords_reversed: null,
  meaning_reversed: null,
  advice_reversed: null,
})

const decks = [deck(1, 'Wild Unknown'), deck(2, 'Celestial Tarot', 'Thoth')]
const wildCards = [special(1, 79, 'The Seeker'), special(1, 80, 'Wildcard')]
const celestialCards = [special(2, 79, 'The Comet')]

/** The calls the page makes on load: the decks, then each deck's special cards. */
const loads = (one: SpecialCard[] = wildCards, two: SpecialCard[] = celestialCards) => ({
  'GET /admin/decks': { body: decks },
  'GET /admin/decks/1/special-cards': { body: one },
  'GET /admin/decks/2/special-cards': { body: two },
})

const cardNames = () => column(3)

describe('admin SpecialCardsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the list', () => {
    it("lists every deck's special cards, by deck, with the deck's system", async () => {
      const api = stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/decks'],
        ['GET', '/api/admin/decks/1/special-cards'],
        ['GET', '/api/admin/decks/2/special-cards'],
      ])
      expect(tableRows()).toEqual([
        ['Celestial Tarot', 'Thoth', '79', 'The Comet', ''],
        ['Wild Unknown', 'RWS', '79', 'The Seeker', ''],
        ['Wild Unknown', 'RWS', '80', 'Wildcard', ''],
      ])
    })

    it('says so when no deck has special cards', async () => {
      stubApi(loads([], []))
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      expect(tableRows()).toEqual([])
      expect(document.body.textContent).toContain('No special cards found.')
    })

    it("still lists the other decks' cards when one deck's cards fail to load", async () => {
      stubApi({
        ...loads(),
        'GET /admin/decks/2/special-cards': { status: 500, body: { error: 'Database error' } },
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      expect(cardNames()).toEqual(['The Seeker', 'Wildcard'])
      expect(toasts()).toEqual(['Database error'])
    })

    it('narrows the list to one deck, and back to all', async () => {
      const api = stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      const filter = labelled('Filter by Deck')
      expect(await optionsOf(filter)).toEqual(['All Decks', 'Wild Unknown', 'Celestial Tarot'])
      api.mockClear()

      await choose(filter, 'Celestial Tarot')
      expect(cardNames()).toEqual(['The Comet'])

      await choose(filter, 'All Decks')
      expect(cardNames()).toEqual(['The Comet', 'The Seeker', 'Wildcard'])
      expect(api).not.toHaveBeenCalled()
    })

    it('searches by card name, card ID or deck name', async () => {
      stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      const search = labelled('Search')

      await typeInto(search, 'seek')
      expect(cardNames()).toEqual(['The Seeker'])

      await typeInto(search, '80')
      expect(cardNames()).toEqual(['Wildcard'])

      await typeInto(search, 'celestial')
      expect(cardNames()).toEqual(['The Comet'])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      await click(header('Name'))
      expect(cardNames()).toEqual(['The Comet', 'The Seeker', 'Wildcard'])

      await click(header('Card ID'))
      await click(header('Card ID'))
      expect(column(2)).toEqual(['80', '79', '79'])
    })

    it('drops to the login page when the session has expired', async () => {
      stubApi({ 'GET /admin/decks': { status: 401, body: { error: 'Unauthorized' } } })
      const { router } = await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
    })
  })

  describe('adding a card', () => {
    it('opens a blank form on the first deck', async () => {
      stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      await click(button('Add Special Card'))

      expect(dialog('Add Special Card')).not.toBeNull()
      expect(labelled('Deck').textContent.trim()).toBe('Wild Unknown')
      expect(valueOf(labelled('Name'))).toBe('')
    })

    it('opens on the deck the list is narrowed to', async () => {
      stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await choose(labelled('Filter by Deck'), 'Celestial Tarot')

      await click(button('Add Special Card'))

      expect(labelled('Deck').textContent.trim()).toBe('Celestial Tarot')
    })

    it('creates the card under the chosen deck, then reloads and closes', async () => {
      const api = stubApi({
        ...loads(),
        'POST /admin/decks/2/special-cards': (body) => ({ status: 201, body }),
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Add Special Card'))

      await choose(labelled('Deck'), 'Celestial Tarot')
      await enterNumber(labelled('Card ID'), 80)
      await typeInto(labelled('Name'), 'The Eclipse')
      await typeInto(labelled('Keywords'), 'shadow, hidden')
      await typeInto(labelled('Meaning (Reversed)'), 'Clarity returns.')
      api.mockClear()
      await click(button('Create'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/admin/decks/2/special-cards',
          {
            deck_id: 2,
            card_id: 80,
            name: 'The Eclipse',
            keywords: 'shadow, hidden',
            meaning_reversed: 'Clarity returns.',
          },
        ],
        ['GET', '/api/admin/decks'],
        ['GET', '/api/admin/decks/1/special-cards'],
        ['GET', '/api/admin/decks/2/special-cards'],
      ])
      expect(toasts()).toEqual(['Special card created.'])
      expect(dialog('Add Special Card')).toBeNull()
    })

    it('asks for a deck when there is none to put the card in', async () => {
      const api = stubApi({ 'GET /admin/decks': { body: [] } })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Add Special Card'))
      api.mockClear()

      await click(button('Create'))

      expect(toasts()).toEqual(['Choose the deck this card belongs to.'])
      expect(api).not.toHaveBeenCalled()
      expect(dialog('Add Special Card')).not.toBeNull()
    })

    it("keeps the form open and shows the server's reason when creating fails", async () => {
      stubApi({
        ...loads(),
        'POST /admin/decks/1/special-cards': {
          status: 500,
          body: { error: 'Card ID 79 is taken.' },
        },
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Add Special Card'))
      await enterNumber(labelled('Card ID'), 79)
      await typeInto(labelled('Name'), 'Duplicate')

      await click(button('Create'))

      expect(toasts()).toEqual(['Card ID 79 is taken.'])
      expect(valueOf(labelled('Name'))).toBe('Duplicate')
    })

    it('keeps the other decks listed after saving while narrowed to one deck', async () => {
      const added = special(2, 80, 'The Eclipse')
      let saved = false
      stubApi({
        ...loads(),
        'GET /admin/decks/2/special-cards': () => ({
          body: saved ? [...celestialCards, added] : celestialCards,
        }),
        'POST /admin/decks/2/special-cards': () => {
          saved = true
          return { status: 201, body: added }
        },
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      const filter = labelled('Filter by Deck')
      await choose(filter, 'Celestial Tarot')
      await click(button('Add Special Card'))
      await enterNumber(labelled('Card ID'), 80)
      await typeInto(labelled('Name'), 'The Eclipse')
      await click(button('Create'))

      expect(cardNames()).toEqual(['The Comet', 'The Eclipse'])

      await choose(filter, 'All Decks')
      expect(cardNames()).toEqual(['The Comet', 'The Eclipse', 'The Seeker', 'Wildcard'])
    })
  })

  describe('editing a card', () => {
    it("opens the card's details with its deck and ID fixed, and saves over it", async () => {
      const api = stubApi({
        ...loads(),
        'PUT /admin/decks/1/special-cards/80': (body) => ({ body }),
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })

      await click(button('Edit', row('Wildcard')))
      expect(dialog('Edit Special Card')).not.toBeNull()
      expect(labelled('Deck').hasAttribute('disabled')).toBe(true)
      expect(valueOf(labelled('Card ID'))).toBe('80')
      expect(labelled('Card ID').hasAttribute('disabled')).toBe(true)

      await typeInto(labelled('Advice'), 'Trust the unexpected.')
      api.mockClear()
      await click(button('Save'))

      expect(apiCalls(api)[0]).toEqual([
        'PUT',
        '/api/admin/decks/1/special-cards/80',
        { ...wildCards[1], advice: 'Trust the unexpected.' },
      ])
      expect(toasts()).toEqual(['Special card updated.'])
      expect(dialog('Edit Special Card')).toBeNull()
    })

    it('closes on Cancel without saving', async () => {
      const api = stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Edit', row('Wildcard')))
      api.mockClear()

      await click(button('Cancel'))

      expect(dialog('Edit Special Card')).toBeNull()
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('deleting a card', () => {
    it('asks first, naming the card and its deck, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      api.mockClear()

      await click(button('Delete', row('The Comet')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "The Comet" (Card ID 79) from Celestial Tarot? This cannot be undone.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and reloads the list', async () => {
      let deleted = false
      const api = stubApi({
        ...loads(),
        'GET /admin/decks/2/special-cards': () => ({ body: deleted ? [] : celestialCards }),
        'DELETE /admin/decks/2/special-cards/79': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Delete', row('The Comet')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)[0]).toEqual(['DELETE', '/api/admin/decks/2/special-cards/79'])
      expect(toasts()).toEqual(['Special card deleted.'])
      expect(cardNames()).toEqual(['The Seeker', 'Wildcard'])
    })

    it('keeps the card listed when the delete fails', async () => {
      stubApi({
        ...loads(),
        'DELETE /admin/decks/2/special-cards/79': {
          status: 500,
          body: { error: 'Failed to delete special card.' },
        },
      })
      await mountPage(SpecialCardsView, { path: '/admin/special-cards' })
      await click(button('Delete', row('The Comet')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Failed to delete special card.'])
      expect(cardNames()).toContain('The Comet')
    })
  })
})
