import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ReadingsView from '@/views/admin/ReadingsView.vue'
import { useUser } from '@/composables/useUser'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
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
  header,
  labelled,
  typeInto,
  choose,
  apiCalls,
} from './helpers/page'
import type { ReadingSummary } from '@/types'

const reading = (
  reading_id: string,
  reading_time: string,
  overrides: Partial<ReadingSummary> = {},
): ReadingSummary => ({
  reading_id,
  reading_time,
  user_id: null,
  display_name: 'Guest',
  deck_id: 1,
  deck_name: 'Rider-Waite Tarot',
  spread_name: '',
  ...overrides,
})

const readings = [
  reading('aaa111', '2026-10-01 09:00:00', { spread_name: 'Celtic Cross' }),
  reading('bbb222', '2026-10-20 18:30:00', {
    user_id: 7,
    display_name: 'Morgana',
    deck_name: 'Thoth Tarot',
    spread_name: 'Three Card',
  }),
  reading('ccc333', '2026-10-10 12:15:00', { deck_name: 'Celestial Tarot' }),
]

/** `count` guest readings, the newest first: r001 is the latest. */
function many(count: number): ReadingSummary[] {
  return Array.from({ length: count }, (_, i) => {
    const n = i + 1
    const time = new Date(Date.UTC(2026, 9, 28, 12) - n * 60_000).toISOString()
    return reading(`r${String(n).padStart(3, '0')}`, time.slice(0, 19).replace('T', ' '))
  })
}

const list = (rows: ReadingSummary[]) => ({
  'GET /admin/readings': { body: { rows, total: rows.length } },
})

const summary = () =>
  /Showing .*? readings \(page \d+ of \d+\)/.exec(document.body.textContent)?.[0]

/** The page-number controls, above (0) or below (1) the table. */
function pager(index: 0 | 1): HTMLElement {
  const lists = document.body.querySelectorAll<HTMLElement>('.pagination-list-reka')
  const list = lists[index]
  if (!list || lists.length !== 2) {
    throw new Error(`Expected two pagers, found ${lists.length}`)
  }
  return list
}

function perPageSelect(): HTMLElement {
  const trigger = document.body.querySelector<HTMLElement>(
    '[role="combobox"][aria-label="Per page"]',
  )
  if (!trigger) {
    throw new Error('No per-page select')
  }
  return trigger
}

describe('admin ReadingsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    // Paging from the bottom controls scrolls back up; jsdom has no layout to scroll.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('says it is loading until the readings arrive', async () => {
      let answer: (res: Response) => void = () => {}
      vi.stubGlobal(
        'fetch',
        vi.fn(
          () =>
            new Promise<Response>((resolve) => {
              answer = resolve
            }),
        ),
      )
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(document.body.textContent).toContain('Loading readings…')
      expect(document.body.querySelector('table')).toBeNull()

      answer(Response.json({ rows: readings, total: 3 }))
      await flushPromises()

      expect(document.body.textContent).not.toContain('Loading readings…')
      expect(tableRows()).toHaveLength(3)
    })

    it('asks for every reading in one request', async () => {
      const api = stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/readings?limit=100000&offset=0']])
    })

    it('lists the newest reading first, with a dash for a reading without a spread', async () => {
      stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(tableRows()).toEqual([
        [
          formatDateTime('2026-10-20 18:30:00'),
          'bbb222',
          'Morgana',
          'Three Card',
          'Thoth Tarot',
          '',
        ],
        [formatDateTime('2026-10-10 12:15:00'), 'ccc333', 'Guest', '—', 'Celestial Tarot', ''],
        [
          formatDateTime('2026-10-01 09:00:00'),
          'aaa111',
          'Guest',
          'Celtic Cross',
          'Rider-Waite Tarot',
          '',
        ],
      ])
      expect(summary()).toBe('Showing 3 of 3 readings (page 1 of 1)')
    })

    it('filters by reading ID, deck, spread or user', async () => {
      stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })
      const search = labelled('Search by reading ID, deck, spread, or user...')

      await typeInto(search, 'ccc')
      expect(column(1)).toEqual(['ccc333'])

      await typeInto(search, 'thoth')
      expect(column(1)).toEqual(['bbb222'])

      await typeInto(search, 'celtic')
      expect(column(1)).toEqual(['aaa111'])

      await typeInto(search, 'morgana')
      expect(column(1)).toEqual(['bbb222'])
      expect(summary()).toBe('Showing 1 of 1 readings (page 1 of 1)')
    })

    it('sorts by a column, and turns the date order around on a click', async () => {
      stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      await click(header('Deck'))
      expect(column(4)).toEqual(['Celestial Tarot', 'Rider-Waite Tarot', 'Thoth Tarot'])

      await click(header('Date'))
      expect(column(1)).toEqual(['aaa111', 'ccc333', 'bbb222'])
    })

    it('drops to the login page when the session has expired', async () => {
      stubApi({ 'GET /admin/readings': { status: 401, body: { error: 'Unauthorized' } } })
      const { router } = await mountPage(ReadingsView, { path: '/admin/readings' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })

    it("says why the list couldn't load when the network is down", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(toasts()).toEqual([NETWORK_ERROR_MESSAGE])
      expect(document.body.textContent).not.toContain('Loading readings…')
      expect(summary()).toBe('Showing 0 of 0 readings (page 1 of 1)')
    })
  })

  describe('paging', () => {
    it('shows no page controls while everything fits on one page', async () => {
      stubApi(list(many(50)))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(hasButton('Next Page')).toBe(false)
      expect(summary()).toBe('Showing 50 of 50 readings (page 1 of 1)')
    })

    it('shows 50 readings a page and moves between pages', async () => {
      stubApi(list(many(120)))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      expect(summary()).toBe('Showing 50 of 120 readings (page 1 of 3)')
      expect(column(1).slice(0, 2)).toEqual(['r001', 'r002'])

      await click(button('Next Page', pager(0)))
      expect(summary()).toBe('Showing 50 of 120 readings (page 2 of 3)')
      expect(column(1)[0]).toBe('r051')

      await click(button('Page 3', pager(0)))
      expect(summary()).toBe('Showing 20 of 120 readings (page 3 of 3)')
      expect(column(1)).toEqual(
        many(120)
          .slice(100)
          .map((r) => r.reading_id),
      )
    })

    it('scrolls back to the top when paging from the controls below the table', async () => {
      stubApi(list(many(120)))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      await click(button('Page 2', pager(1)))

      expect(summary()).toBe('Showing 50 of 120 readings (page 2 of 3)')
      expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })
    })

    it('changes how many readings a page holds', async () => {
      stubApi(list(many(120)))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      await choose(perPageSelect(), '25')
      expect(summary()).toBe('Showing 25 of 120 readings (page 1 of 5)')

      await choose(perPageSelect(), '200')
      expect(summary()).toBe('Showing 120 of 120 readings (page 1 of 1)')
      expect(hasButton('Next Page')).toBe(false)
    })

    it('brings the page back in range when a search shrinks the list', async () => {
      stubApi(list(many(120)))
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Page 3', pager(0)))

      await typeInto(labelled('Search by reading ID, deck, spread, or user...'), 'r00')

      expect(summary()).toBe('Showing 9 of 9 readings (page 1 of 1)')
      expect(column(1)).toEqual(many(9).map((r) => r.reading_id))
    })
  })

  describe('row actions', () => {
    it('opens the reading in a new tab', async () => {
      const open = vi.spyOn(window, 'open').mockImplementation(() => null)
      stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      await click(button('View', row('ccc333')))

      expect(open).toHaveBeenCalledWith('/reading/ccc333', '_blank')
    })

    it('asks before deleting, and deletes nothing on Cancel', async () => {
      const api = stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })
      api.mockClear()

      await click(button('Delete', row('ccc333')))
      expect(confirmDialog()?.textContent).toContain(
        'Permanently delete reading "ccc333"? This cannot be undone.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
      expect(column(1)).toContain('ccc333')
    })

    it('deletes on confirmation and drops the row without reloading the list', async () => {
      const api = stubApi({ ...list(readings), 'DELETE /admin/readings/ccc333': { status: 204 } })
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Delete', row('ccc333')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([['DELETE', '/api/admin/readings/ccc333']])
      expect(toasts()).toEqual(['Reading deleted.'])
      expect(column(1)).toEqual(['bbb222', 'aaa111'])
      expect(summary()).toBe('Showing 2 of 2 readings (page 1 of 1)')
    })

    it("keeps the reading listed and shows the server's reason when the delete fails", async () => {
      stubApi({
        ...list(readings),
        'DELETE /admin/readings/ccc333': { status: 404, body: { error: 'Reading not found' } },
      })
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Delete', row('ccc333')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Reading not found'])
      expect(column(1)).toContain('ccc333')
    })
  })

  describe('cleaning old guest readings', () => {
    it('offers the cleanup windows, 30 days by default', async () => {
      stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })

      await click(button('Clean Readings'))

      expect(dialog('Clean Guest Readings')?.textContent).toContain(
        'do not belong to a registered user',
      )
      expect(labelled('Older than').textContent.trim()).toBe('30 days')
    })

    it('deletes the guest readings older than the chosen period, then reloads the list', async () => {
      const api = stubApi({
        ...list(readings),
        'DELETE /admin/readings/all': { body: { deleted: 12 } },
      })
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Clean Readings'))
      await choose(labelled('Older than'), '90 days')
      api.mockClear()

      await click(button('Clean'))

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/readings/all?older_than_days=90'],
        ['GET', '/api/admin/readings?limit=100000&offset=0'],
      ])
      expect(toasts()).toEqual(['Cleaned 12 guest reading(s) older than 90 days.'])
      expect(dialog('Clean Guest Readings')).toBeNull()
    })

    it("stays open with the server's reason when the cleanup fails", async () => {
      const api = stubApi({
        ...list(readings),
        'DELETE /admin/readings/all': {
          status: 400,
          body: { error: 'older_than_days must be at least 1' },
        },
      })
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Clean Readings'))
      api.mockClear()

      await click(button('Clean'))

      expect(apiCalls(api)).toEqual([['DELETE', '/api/admin/readings/all?older_than_days=30']])
      expect(toasts()).toEqual(['older_than_days must be at least 1'])
      expect(dialog('Clean Guest Readings')).not.toBeNull()
      expect(button('Clean').disabled).toBe(false)
    })

    it('closes on Cancel without deleting anything', async () => {
      const api = stubApi(list(readings))
      await mountPage(ReadingsView, { path: '/admin/readings' })
      await click(button('Clean Readings'))
      api.mockClear()

      await click(button('Cancel'))

      expect(dialog('Clean Guest Readings')).toBeNull()
      expect(api).not.toHaveBeenCalled()
    })
  })
})
