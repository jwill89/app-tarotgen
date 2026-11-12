import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import ErroredCardsView from '@/views/admin/ErroredCardsView.vue'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  dialog,
  confirmDialog,
  answerConfirm,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  typeInto,
  apiCalls,
} from './helpers/page'
import type { CardReport, CardReportSubmission } from '@/types'

const submission = (
  submission_id: number,
  report_id: number,
  round: number,
  reason: CardReportSubmission['reason'],
  details: string,
): CardReportSubmission => ({
  submission_id,
  report_id,
  round,
  reason,
  details,
  submitted_at: `2026-10-${String(submission_id).padStart(2, '0')} 12:00:00`,
})

/** The Fool: reported twice for an artefact since it was last fixed, once for cropping before. */
const fool: CardReport = {
  report_id: 1,
  deck_id: 3,
  deck_name: 'Thoth Tarot',
  card_id: 1,
  card_name: 'The Fool',
  report_count: 5,
  resolved_at: null,
  first_reported_at: '2026-09-01 08:00:00',
  last_reported_at: '2026-10-12 12:00:00',
  round: 2,
  submissions: [
    submission(12, 1, 2, 'artefact', 'A dust speck near the top-left corner'),
    submission(11, 1, 2, 'artefact', ''),
    submission(10, 1, 1, 'cropping', 'Bottom border cut off'),
  ],
}

/** A card of a deck since deleted, reported once from an old plugin (no reason). */
const orphan: CardReport = {
  report_id: 2,
  deck_id: 5,
  deck_name: '',
  card_id: 12,
  card_name: '',
  report_count: 1,
  resolved_at: null,
  first_reported_at: '2026-10-02 09:00:00',
  last_reported_at: '2026-10-02 09:00:00',
  round: 1,
  submissions: [],
}

const fixed: CardReport = {
  ...orphan,
  report_id: 3,
  deck_id: 1,
  deck_name: 'Rider-Waite Tarot',
  card_id: 22,
  card_name: 'The World',
  resolved_at: '2026-10-20 10:00:00',
  submissions: [submission(5, 3, 1, 'quality', 'Blurry')],
}

describe('admin ErroredCardsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('lists the open reports, most reported first, with what is wrong with each', async () => {
      const api = stubApi({ 'GET /admin/card-reports': { body: [orphan, fool] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/card-reports?show_resolved=0']])
      expect(tableRows()).toEqual([
        [
          '',
          'Thoth Tarot',
          'The Fool #1',
          // Only the current round's reasons, then the newest description quoted beneath.
          'Scan artefact ×2' + '“A dust speck near the top-left corner”',
          '5',
          formatDateTime(fool.last_reported_at),
          '',
        ],
        [
          '',
          'Deck #5',
          '— #12',
          'No reason given',
          '1',
          formatDateTime(orphan.last_reported_at),
          '',
        ],
      ])
    })

    it('shows each card as a thumbnail linking to its master scan', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      const scan = row('Thoth Tarot').querySelector('a')
      expect(scan?.getAttribute('href')).toBe('/assets/decks/3/Card_0001.png')
      expect(scan?.querySelector('img')?.getAttribute('src')).toBe(
        '/assets/decks/3/thumbs/Card_0001.webp',
      )
      expect(scan?.querySelector('img')?.getAttribute('alt')).toBe('The Fool')
    })

    it('says there are no open reports when every card is fine', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      expect(document.body.textContent).toContain('No reported cards (open).')
    })

    it('reloads with resolved reports included, and back again', async () => {
      const api = stubApi({
        'GET /admin/card-reports': (_body, url) => ({
          body: url.searchParams.get('show_resolved') === '1' ? [fool, fixed] : [fool],
        }),
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('Show Resolved'))
      expect(column(2)).toEqual(['The Fool #1', 'The World #22'])
      expect(hasButton('Reopen', row('Rider-Waite Tarot'))).toBe(true)

      await click(button('Showing Resolved'))
      expect(column(2)).toEqual(['The Fool #1'])
      expect(apiCalls(api).map(([, url]) => url)).toEqual([
        '/api/admin/card-reports?show_resolved=0',
        '/api/admin/card-reports?show_resolved=1',
        '/api/admin/card-reports?show_resolved=0',
      ])
    })

    it('says there are no reports at all when none exist, resolved ones included', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('Show Resolved'))

      expect(document.body.textContent).toContain('No reported cards.')
    })

    it('finds reports by deck, card, reason or description, earlier rounds included', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool, orphan] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      const search = labelled('Search by deck, card, or reason...')

      await typeInto(search, 'thoth')
      expect(column(1)).toEqual(['Thoth Tarot'])

      await typeInto(search, 'cropped')
      expect(column(1)).toEqual(['Thoth Tarot'])

      await typeInto(search, 'bottom border')
      expect(column(1)).toEqual(['Thoth Tarot'])

      await typeInto(search, 'fool')
      expect(column(1)).toEqual(['Thoth Tarot'])

      await typeInto(search, 'nothing like this')
      expect(tableRows()).toEqual([])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool, orphan, fixed] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(header('Last Reported'))
      expect(column(2)).toEqual(['— #12', 'The World #22', 'The Fool #1'])

      await click(header('Last Reported'))
      expect(column(2)).toEqual(['The Fool #1', '— #12', 'The World #22'])
    })

    it('drops to the login page when the session has expired', async () => {
      stubApi({ 'GET /admin/card-reports': { status: 401, body: { error: 'Unauthorized' } } })
      const { router } = await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
    })
  })

  describe('resolving', () => {
    it('resolves an open report and reloads the list, which then leaves it out', async () => {
      let resolved = false
      const api = stubApi({
        'GET /admin/card-reports': () => ({ body: resolved ? [orphan] : [fool, orphan] }),
        'PATCH /admin/card-reports/1': () => {
          resolved = true
          return { body: { success: true } }
        },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      api.mockClear()

      await click(button('Resolve', row('Thoth Tarot')))

      expect(apiCalls(api)).toEqual([
        ['PATCH', '/api/admin/card-reports/1', { resolved: true }],
        ['GET', '/api/admin/card-reports?show_resolved=0'],
      ])
      expect(column(1)).toEqual(['Deck #5'])
    })

    it('reopens a resolved report', async () => {
      const api = stubApi({
        'GET /admin/card-reports': { body: [fool, fixed] },
        'PATCH /admin/card-reports/3': { body: { success: true } },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      await click(button('Show Resolved'))
      api.mockClear()

      await click(button('Reopen', row('Rider-Waite Tarot')))

      expect(apiCalls(api)[0]).toEqual(['PATCH', '/api/admin/card-reports/3', { resolved: false }])
    })

    it("shows the server's reason and keeps the report when resolving fails", async () => {
      const api = stubApi({
        'GET /admin/card-reports': { body: [fool] },
        'PATCH /admin/card-reports/1': { status: 400, body: { error: 'Report not found.' } },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      api.mockClear()

      await click(button('Resolve', row('Thoth Tarot')))

      expect(apiCalls(api)).toHaveLength(1)
      expect(toasts()).toEqual(['Report not found.'])
      expect(button('Resolve', row('Thoth Tarot')).disabled).toBe(false)
    })
  })

  describe('regenerating', () => {
    const regenerated: CardReport = { ...fool, resolved_at: '2026-10-30 09:15:00' }
    const purgeUrls = [
      `${window.location.origin}/assets/decks/3/Card_0001.webp`,
      `${window.location.origin}/assets/decks/3/thumbs/Card_0001.webp`,
      `${window.location.origin}/assets/decks/3/Card_0001.png`,
    ]

    /** The purge dialog's URLs, as listed. */
    function listedUrls(): string[] {
      const open = dialog('Purge the old images')
      if (!open) {
        throw new Error('No purge dialog')
      }
      return [...open.querySelectorAll('li code')].map((c) => c.textContent.trim())
    }

    it('asks first, saying the new scan must be uploaded, and sends nothing when cancelled', async () => {
      const api = stubApi({ 'GET /admin/card-reports': { body: [fool] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      api.mockClear()

      await click(button('Regenerate images & resolve', row('Thoth Tarot')))

      expect(confirmDialog()?.textContent).toContain(
        "Upload the new scan to /assets/decks/3/Card_0001.png first. This rebuilds The Fool's web images",
      )
      await answerConfirm('cancel')

      expect(apiCalls(api)).toEqual([])
      expect(hasButton('Reopen', row('Thoth Tarot'))).toBe(false)
      expect(dialog('Purge the old images')).toBeNull()
    })

    it('resolves the report in its row and lists the URLs to purge from the cache', async () => {
      const api = stubApi({
        'GET /admin/card-reports': { body: [fool, orphan] },
        'POST /admin/card-reports/1/regenerate': { body: regenerated },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      api.mockClear()

      await click(button('Regenerate images & resolve', row('Thoth Tarot')))
      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([['POST', '/api/admin/card-reports/1/regenerate', {}]])
      // The row reads as resolved; there is nothing left to regenerate.
      expect(row('Thoth Tarot').classList).toContain('has-text-grey')
      expect(hasButton('Reopen', row('Thoth Tarot'))).toBe(true)
      expect(hasButton('Regenerate images & resolve', row('Thoth Tarot'))).toBe(false)
      expect(hasButton('Regenerate images & resolve', row('Deck #5'))).toBe(true)
      expect(dialog('Purge the old images')?.textContent).toContain(
        'The Fool has new web images and its report is resolved.',
      )
      expect(listedUrls()).toEqual(purgeUrls)
    })

    it('copies the URLs to purge, one per line, and closes when done', async () => {
      const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
      stubApi({
        'GET /admin/card-reports': { body: [fool] },
        'POST /admin/card-reports/1/regenerate': { body: regenerated },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      await click(button('Regenerate images & resolve', row('Thoth Tarot')))
      await answerConfirm('confirm')

      await click(button('Copy URLs'))

      expect(writeText).toHaveBeenCalledWith(purgeUrls.join('\n'))
      expect(toasts()).toEqual(['URLs copied to your clipboard.'])

      await click(button('Done'))
      expect(dialog('Purge the old images')).toBeNull()
    })

    it('asks for a manual copy when the clipboard is refused', async () => {
      vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(
        new DOMException('Denied', 'NotAllowedError'),
      )
      stubApi({
        'GET /admin/card-reports': { body: [fool] },
        'POST /admin/card-reports/1/regenerate': { body: regenerated },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      await click(button('Regenerate images & resolve', row('Thoth Tarot')))
      await answerConfirm('confirm')

      await click(button('Copy URLs'))

      expect(toasts()).toEqual(['Could not copy — select the URLs and copy them manually.'])
      expect(listedUrls()).toEqual(purgeUrls)
    })

    it("shows the server's reason and leaves the report open when it fails", async () => {
      const message =
        'There is no master scan for this card on the server. Upload Card_0001.png to deck 3’s folder, then try again.'
      stubApi({
        'GET /admin/card-reports': { body: [fool] },
        'POST /admin/card-reports/1/regenerate': { status: 409, body: { error: message } },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('Regenerate images & resolve', row('Thoth Tarot')))
      await answerConfirm('confirm')

      expect(toasts()).toEqual([message])
      expect(hasButton('Resolve', row('Thoth Tarot'))).toBe(true)
      expect(button('Regenerate images & resolve', row('Thoth Tarot')).disabled).toBe(false)
      expect(dialog('Purge the old images')).toBeNull()
    })

    it('is not offered on a resolved report', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool, fixed] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('Show Resolved'))

      expect(hasButton('Regenerate images & resolve', row('Rider-Waite Tarot'))).toBe(false)
      expect(hasButton('Regenerate images & resolve', row('Thoth Tarot'))).toBe(true)
    })
  })

  describe('the reports dialog', () => {
    /** The dialog's facts list as label → value. */
    function facts(el: HTMLElement): Record<string, string> {
      return Object.fromEntries(
        [...el.querySelectorAll('dl > div')].map((pair) => [
          pair.querySelector('dt')?.textContent.trim(),
          pair.querySelector('dd')?.textContent.replace(/\s+/g, ' ').trim(),
        ]),
      )
    }

    /** Each listed report as "reason | details" within one list. */
    function entries(list: Element | null | undefined): string[] {
      return [...(list?.querySelectorAll('li') ?? [])].map((li) => {
        const reason = li.querySelector('.data-chip')?.textContent.trim()
        const details = li.querySelector('p')?.textContent.trim()
        return `${reason} | ${details}`
      })
    }

    it("sums up the card and lists this round's reports, then the ones resolved earlier", async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('View reports', row('Thoth Tarot')))

      const open = dialog('The Fool')
      if (!open) {
        throw new Error('No dialog for The Fool')
      }
      expect(facts(open)).toEqual({
        Deck: 'Thoth Tarot',
        Card: '#1',
        Status: 'Open',
        Reports: '5',
        'First reported': formatDateTime(fool.first_reported_at),
        'Last reported': formatDateTime(fool.last_reported_at),
      })
      const [current, earlier] = open.querySelectorAll('ul')
      expect(entries(current)).toEqual([
        'Scan artefact | A dust speck near the top-left corner',
        'Scan artefact | No details given.',
      ])
      expect(open.textContent).toContain('Resolved earlier')
      expect(entries(earlier)).toEqual(['Cropped or crooked | Bottom border cut off'])
      // Five reports, three with a reason: two came from before reasons were collected.
      expect(open.textContent).toContain('2 reports came without a reason')
    })

    it('says when a resolved card was fixed, and names a lone reasonless report', async () => {
      stubApi({
        'GET /admin/card-reports': { body: [{ ...orphan, resolved_at: fixed.resolved_at }] },
      })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })

      await click(button('View reports', row('Deck #5')))

      const open = dialog('Card #12')
      if (!open) {
        throw new Error('No dialog for card #12')
      }
      expect(facts(open).Status).toBe(`Resolved ${formatDateTime(fixed.resolved_at)}`)
      expect(open.querySelectorAll('li')).toHaveLength(0)
      expect(open.textContent).not.toContain('Resolved earlier')
      expect(open.textContent).toContain('1 report came without a reason')
    })

    it('closes again', async () => {
      stubApi({ 'GET /admin/card-reports': { body: [fool] } })
      await mountPage(ErroredCardsView, { path: '/admin/card-reports' })
      await click(button('View reports', row('Thoth Tarot')))

      await click(button('Close', dialog('The Fool') ?? document.body))

      expect(dialog('The Fool')).toBeNull()
    })
  })
})
