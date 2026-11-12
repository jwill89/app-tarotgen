import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import DeckSystemsView from '@/views/admin/DeckSystemsView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  confirmDialog,
  answerConfirm,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  link,
  pageTitle,
  typeInto,
  apiCalls,
} from './helpers/page'
import { pending, systems } from './helpers/deckSystems'
import type { DeckSystem } from '@/types'

/** The calls the page makes on load, answered with these lists. */
const loads = (approved: DeckSystem[] = systems, waiting: DeckSystem[] = []) => ({
  'GET /admin/deck-systems': { body: approved },
  'GET /admin/deck-systems/pending': { body: waiting },
})

describe('admin DeckSystemsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('lists the approved systems by name with their card counts', async () => {
      const api = stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      expect(pageTitle()).toBe('Manage Deck Systems')
      // The queue only for its count, on the Submissions link.
      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(tableRows()).toEqual([
        ['3', 'Lenormand', 'Len', '36', ''],
        ['1', 'Rider-Waite-Smith', 'RWS', '78', ''],
        ['2', 'Thoth', 'Thoth', '78', ''],
      ])
    })

    it('filters by name or short name, and says when nothing matches', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      const search = labelled('Search by name or short name...')

      await typeInto(search, 'len')
      expect(column(1)).toEqual(['Lenormand'])

      await typeInto(search, 'rws')
      expect(column(1)).toEqual(['Rider-Waite-Smith'])

      await typeInto(search, 'zzz')
      expect(tableRows()).toEqual([])
      expect(document.body.textContent).toContain('No deck systems match your search.')
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      await click(header('Total Cards'))
      expect(column(1)[0]).toBe('Lenormand')

      await click(header('Short Name'))
      expect(column(2)).toEqual(['Len', 'RWS', 'Thoth'])

      await click(header('ID'))
      expect(column(0)).toEqual(['1', '2', '3'])

      await click(header('ID'))
      expect(column(0)).toEqual(['3', '2', '1'])
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({
        'GET /admin/deck-systems': expired,
        'GET /admin/deck-systems/pending': expired,
      })
      const { router } = await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })
  })

  describe('the other Deck Systems pages', () => {
    it('links to adding a system and to the submission queue', async () => {
      stubApi(loads())
      const { router } = await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      expect(link('Add Deck System').getAttribute('href')).toBe('/admin/deck-systems/new')
      // Nothing waiting: no count on the link, and the submissions aren't listed here.
      expect(link('Submissions').getAttribute('href')).toBe('/admin/deck-systems/submissions')
      expect(link('Submissions').querySelector('.tag')).toBeNull()

      await click(link('Add Deck System'))
      await vi.waitFor(
        () => expect(router.currentRoute.value.name).toBe('admin-deck-system-new'),
        lazyLoad,
      )
    })

    it('counts the submissions waiting for review on their link, and lists none of them', async () => {
      stubApi(loads(systems, pending))
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      const submissions = link('Submissions, 1 waiting')
      expect(submissions.querySelector('.tag')?.textContent).toBe('1')
      expect(submissions.getAttribute('href')).toBe('/admin/deck-systems/submissions')
      expect(column(1)).not.toContain('Sola Busca')
    })

    it("opens a system's editor at its own address", async () => {
      const api = stubApi(loads())
      const { router } = await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Edit', row('Lenormand')))

      await vi.waitFor(
        () => expect(router.currentRoute.value.name).toBe('admin-deck-system-edit'),
        lazyLoad,
      )
      expect(router.currentRoute.value.params.id).toBe('3')
      // The editor loads the system itself.
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('deleting a system', () => {
    it('asks first, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Delete', row('Thoth')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "Thoth"? This will also delete all card data for this system. Decks using this system will need to be reassigned.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and reloads the list', async () => {
      let deleted = false
      const api = stubApi({
        ...loads(),
        'GET /admin/deck-systems': () => ({
          body: deleted ? systems.filter((s) => s.deck_system_id !== 2) : systems,
        }),
        'DELETE /admin/deck-systems/2': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Delete', row('Thoth')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/deck-systems/2'],
        ['GET', '/api/admin/deck-systems'],
      ])
      expect(toasts()).toEqual(['Deck system deleted.'])
      expect(column(1)).toEqual(['Lenormand', 'Rider-Waite-Smith'])
    })

    it('keeps the system listed when the delete fails', async () => {
      stubApi({
        ...loads(),
        'DELETE /admin/deck-systems/2': { status: 404, body: { error: 'Deck system not found.' } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Delete', row('Thoth')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Deck system not found.'])
      expect(column(1)).toContain('Thoth')
      expect(hasButton('Delete', row('Thoth'))).toBe(true)
    })
  })
})
