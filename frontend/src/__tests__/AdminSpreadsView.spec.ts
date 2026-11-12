import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SpreadsView from '@/views/admin/SpreadsView.vue'
import { useUser } from '@/composables/useUser'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
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
import { anonymous, crossroads, spreads } from './helpers/spreads'
import type { PendingSpread, Spread } from '@/types'

const loads = (approved: Spread[] = spreads, pending: PendingSpread[] = []) => ({
  'GET /admin/spreads': { body: approved },
  'GET /admin/pending-spreads': { body: pending },
})

describe('admin SpreadsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the spread list', () => {
    it('lists the spreads by ID with their categories and card counts', async () => {
      const api = stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(pageTitle()).toBe('Manage Spreads')
      // The queue only for its count; the categories come joined into each spread.
      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/spreads'],
        ['GET', '/api/admin/pending-spreads'],
      ])
      expect(tableRows()).toEqual([
        ['1', 'Three Card', 'Love & relationships', '3', ''],
        ['2', 'Celtic Cross', 'General', '2', ''],
        ['3', 'Single Card', 'General', '1', ''],
      ])
      expect(document.body.textContent).not.toContain('No spreads yet.')
    })

    it('says how to add one when there are no spreads yet', async () => {
      stubApi(loads([]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(tableRows()).toEqual([])
      expect(document.body.textContent).toContain(
        'No spreads yet. Click "Add Spread" to create one.',
      )
    })

    it('filters by name, category or ID as the admin types', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      const search = labelled('Search spreads by name, category or ID...')

      await typeInto(search, 'celtic')
      expect(column(1)).toEqual(['Celtic Cross'])

      await typeInto(search, '3')
      expect(column(1)).toEqual(['Single Card'])

      await typeInto(search, 'love')
      expect(column(1)).toEqual(['Three Card'])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(header('Name'))
      expect(column(1)).toEqual(['Celtic Cross', 'Single Card', 'Three Card'])

      await click(header('Cards'))
      await click(header('Cards'))
      expect(column(1)).toEqual(['Three Card', 'Celtic Cross', 'Single Card'])

      // By the categories' own order: General before Love & relationships.
      await click(header('Category'))
      expect(column(2)).toEqual(['General', 'General', 'Love & relationships'])
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({ 'GET /admin/spreads': expired, 'GET /admin/pending-spreads': expired })
      const { router } = await mountPage(SpreadsView, { path: '/admin/spreads' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })

    it("says why the list couldn't load when the network is down", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(toasts()).toContain(NETWORK_ERROR_MESSAGE)
      expect(tableRows()).toEqual([])
    })
  })

  describe('the other Spreads pages', () => {
    it('links to adding a spread, the categories and the submission queue', async () => {
      stubApi(loads())
      const { router } = await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(link('Add Spread').getAttribute('href')).toBe('/admin/spreads/new')
      expect(link('Categories').getAttribute('href')).toBe('/admin/spreads/categories')
      // Nothing waiting: no count on the link.
      expect(link('Submissions').getAttribute('href')).toBe('/admin/spreads/submissions')
      expect(link('Submissions').querySelector('.tag')).toBeNull()

      await click(link('Categories'))
      await vi.waitFor(
        () => expect(router.currentRoute.value.name).toBe('admin-spread-categories'),
        lazyLoad,
      )
    })

    it('counts the submissions waiting for review on their link', async () => {
      stubApi(loads(spreads, [crossroads, anonymous]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      const submissions = link('Submissions, 2 waiting')
      expect(submissions.querySelector('.tag')?.textContent).toBe('2')
      expect(submissions.getAttribute('href')).toBe('/admin/spreads/submissions')
    })

    it("opens a spread's editor at its own address", async () => {
      const api = stubApi(loads())
      const { router } = await mountPage(SpreadsView, { path: '/admin/spreads' })
      api.mockClear()

      await click(button('Edit', row('Three Card')))

      await vi.waitFor(
        () => expect(router.currentRoute.value.name).toBe('admin-spread-edit'),
        lazyLoad,
      )
      expect(router.currentRoute.value.params.id).toBe('1')
      // The editor loads the spread itself.
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('deleting a spread', () => {
    it('asks first, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      api.mockClear()

      await click(button('Delete', row('Celtic Cross')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "Celtic Cross"? Existing readings that used it keep their own saved copy and are unaffected.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and reloads the list', async () => {
      let deleted = false
      const api = stubApi({
        'GET /admin/spreads': () => ({
          body: deleted ? spreads.filter((s) => s.spread_id !== 2) : spreads,
        }),
        'GET /admin/pending-spreads': { body: [] },
        'DELETE /admin/spreads/2': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Delete', row('Celtic Cross')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/spreads/2'],
        ['GET', '/api/admin/spreads'],
      ])
      expect(toasts()).toEqual(['Spread deleted.'])
      expect(column(1)).toEqual(['Three Card', 'Single Card'])
    })

    it("keeps the spread and shows the server's reason when deleting fails", async () => {
      const api = stubApi({
        ...loads(),
        'DELETE /admin/spreads/2': { status: 500, body: { error: 'Failed to delete spread' } },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Delete', row('Celtic Cross')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([['DELETE', '/api/admin/spreads/2']])
      expect(toasts()).toEqual(['Failed to delete spread'])
      expect(column(1)).toContain('Celtic Cross')
    })
  })
})
