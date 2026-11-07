import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import DashboardView from '@/views/admin/DashboardView.vue'
import { useUser } from '@/composables/useUser'
import { APP_VERSION } from '@/constants'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import { mountPage, resetPageState, click, toasts, apiCalls, button, dialog } from './helpers/page'
import type { UsageStats } from '@/types'

const counts = {
  deckSystems: 3,
  decks: 12,
  specialCards: 4,
  spreads: 9,
  readings: 12345,
  users: 58,
  unreadContacts: 2,
  openCardReports: 3,
  pendingSpreads: 4,
  pendingDeckSystems: 1,
}

const stats: UsageStats = {
  totals: { readings: 12345, last7: 210, last30: 1004 },
  byType: { freeDraw: 6000, spread: 5000, custom: 1345 },
  topDecks: [
    { deck_id: 1, name: 'Rider-Waite Tarot', count: 800 },
    { deck_id: 3, name: 'Thoth Tarot', count: 200 },
  ],
  topSpreads: [
    { name: 'Celtic Cross', count: 300 },
    { name: 'Three Card', count: 150 },
  ],
  daily: [
    { date: '2026-10-26', count: 10 },
    { date: '2026-10-27', count: 40 },
    { date: '2026-10-28', count: 20 },
  ],
}

const emptyStats: UsageStats = {
  totals: { readings: 0, last7: 0, last30: 0 },
  byType: { freeDraw: 0, spread: 0, custom: 0 },
  topDecks: [],
  topSpreads: [],
  daily: [],
}

/**
 * An element's text with its child elements' text spaced apart, as they read on
 * screen: textContent runs "12,345" and "Total readings" together.
 */
function spaced(el: Element): string {
  return [...el.children]
    .map((child) => child.textContent.replace(/\s+/g, ' ').trim())
    .filter((part) => part !== '')
    .join(' ')
}

/** Each section link in the launcher as "label count" (or just the label when it has none). */
function launcher(): string[] {
  return [...document.body.querySelectorAll('nav[aria-label="Admin sections"] a')].map(spaced)
}

function link(label: string): HTMLAnchorElement {
  const found = [...document.body.querySelectorAll('nav[aria-label="Admin sections"] a')].find(
    (a): a is HTMLAnchorElement =>
      a instanceof HTMLAnchorElement && a.querySelector('.nav-label')?.textContent === label,
  )
  if (!found) {
    throw new Error(`No "${label}" section link`)
  }
  return found
}

/** The insights panel titled `title`. */
function panel(title: string): Element {
  const heading = [...document.body.querySelectorAll('h3')].find(
    (h) => h.textContent.trim() === title,
  )
  if (!heading?.parentElement) {
    throw new Error(`No "${title}" panel`)
  }
  return heading.parentElement
}

/** Each row of a ranking panel as "name count". */
function ranking(title: string): string[] {
  return [...panel(title).querySelectorAll('.rank-row')].map(spaced)
}

/** The fill of each bar, as the share of the panel's largest value it draws. */
function barWidths(title: string): string[] {
  return [...panel(title).querySelectorAll<HTMLElement>('.rank-fill')].map((f) => f.style.width)
}

describe('admin DashboardView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('shows the app version beside the title', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    const version = button(`v${APP_VERSION}`)
    expect(document.body.querySelector('.page-header')?.contains(version)).toBe(true)
    expect(dialog('Changelog')).toBeNull()
  })

  it('opens the changelog from the version, and closes it', async () => {
    const api = stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    await click(button(`v${APP_VERSION}`))

    // The dialog, and the notes with it, load on the first click.
    await vi.waitFor(() => expect(dialog('Changelog')).not.toBeNull())
    // CHANGELOG.md itself, through the build plugin: this version has its notes.
    const releases = [...(dialog('Changelog')?.querySelectorAll('h3 button') ?? [])].map(
      (trigger) => trigger.textContent.replace(/\s+/g, ' ').trim(),
    )
    expect(releases.some((release) => release.startsWith(`v${APP_VERSION}, released `))).toBe(true)

    await click(button('Close'))

    expect(dialog('Changelog')).toBeNull()
    // No API call for the notes: they are built in.
    expect(apiCalls(api)).toEqual([['GET', '/api/admin/summary']])
  })

  it('holds the counts and says insights are loading until the summary arrives', async () => {
    let answer: (value: Response) => void = () => {}
    vi.stubGlobal(
      'fetch',
      vi.fn(
        () =>
          new Promise<Response>((resolve) => {
            answer = resolve
          }),
      ),
    )
    await mountPage(DashboardView, { path: '/admin' })

    expect(launcher()).toContain('Decks ·')
    expect(document.body.textContent).toContain('Loading insights…')

    answer(Response.json({ counts, stats }))
    await flushPromises()

    expect(launcher()).toContain('Decks 12')
    expect(document.body.textContent).not.toContain('Loading insights…')
  })

  it('launches every admin section with its live count', async () => {
    const api = stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    expect(apiCalls(api)).toEqual([['GET', '/api/admin/summary']])
    expect(launcher()).toEqual([
      'Decks 12',
      'Deck Systems 3',
      'Deck System Submissions 1',
      'Special Cards 4',
      'Spreads 9',
      // Submissions waiting for review show as a badge, like unread messages.
      'Spread Submissions 4',
      'Readings 12,345',
      'Users 58',
      // Unread messages show as a badge; open card reports as a plain count.
      'Contacts 2',
      'Errored Cards 3',
    ])
    expect(link('Contacts').querySelector('.nav-badge')?.textContent).toBe('2')
    expect(link('Spread Submissions').querySelector('.nav-badge')?.textContent).toBe('4')
    expect(link('Deck System Submissions').querySelector('.nav-badge')?.textContent).toBe('1')
    expect(link('Errored Cards').querySelector('.nav-badge')).toBeNull()
    expect(link('Decks').getAttribute('href')).toBe('/admin/decks')
    expect(link('Spread Submissions').getAttribute('href')).toBe('/admin/spreads/submissions')
    expect(link('Deck System Submissions').getAttribute('href')).toBe(
      '/admin/deck-systems/submissions',
    )
    expect(link('Errored Cards').getAttribute('href')).toBe('/admin/card-reports')
  })

  it('shows no submissions badge when nothing is waiting for review', async () => {
    stubApi({
      'GET /admin/summary': {
        body: { counts: { ...counts, pendingSpreads: 0, pendingDeckSystems: 0 }, stats },
      },
    })
    await mountPage(DashboardView, { path: '/admin' })

    expect(link('Spread Submissions').querySelector('.nav-badge')).toBeNull()
    expect(launcher()).toContain('Spread Submissions 0')
    expect(link('Deck System Submissions').querySelector('.nav-badge')).toBeNull()
    expect(launcher()).toContain('Deck System Submissions 0')
  })

  it('counts no errored cards once every report is resolved', async () => {
    stubApi({
      'GET /admin/summary': { body: { counts: { ...counts, openCardReports: 0 }, stats } },
    })
    await mountPage(DashboardView, { path: '/admin' })

    expect(launcher()).toContain('Errored Cards 0')
  })

  it('shows no unread badge when every message has been read', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts: { ...counts, unreadContacts: 0 }, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    expect(link('Contacts').querySelector('.nav-badge')).toBeNull()
    expect(launcher()).toContain('Contacts 0')
  })

  it('opens a section from the launcher', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    const { router } = await mountPage(DashboardView, { path: '/admin' })

    await click(link('Users'))

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-users'))
  })

  it('sums up readings: totals, the last days, the types and what is used most', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    const totals = [...document.body.querySelectorAll('.stat-box')].map(spaced)
    expect(totals).toEqual(['12,345 Total readings', '210 Last 7 days', '1,004 Last 30 days'])
    expect([...panel('Reading types').querySelectorAll('.type-row')].map(spaced)).toEqual([
      'Free draw 6,000',
      'Spread 5,000',
      'Custom 1,345',
    ])
    expect(ranking('Most-used decks')).toEqual(['Rider-Waite Tarot 800', 'Thoth Tarot 200'])
    expect(ranking('Most-used spreads')).toEqual(['Celtic Cross 300', 'Three Card 150'])
  })

  it('scales each chart to its own busiest value', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts, stats } } })
    await mountPage(DashboardView, { path: '/admin' })

    const days = [...panel('Readings per day (last 14)').querySelectorAll('.daily-col')]
    expect(days.map((d) => d.querySelector('.daily-axis')?.textContent)).toEqual(['26', '27', '28'])
    expect(days.map((d) => d.getAttribute('title'))).toEqual([
      '2026-10-26: 10',
      '2026-10-27: 40',
      '2026-10-28: 20',
    ])
    expect(days.map((d) => d.querySelector<HTMLElement>('.daily-bar')?.style.height)).toEqual([
      '25%',
      '100%',
      '50%',
    ])
    expect(barWidths('Most-used decks')).toEqual(['100%', '25%'])
    expect(barWidths('Most-used spreads')).toEqual(['100%', '50%'])
  })

  it('says so where there is nothing to chart yet', async () => {
    stubApi({ 'GET /admin/summary': { body: { counts, stats: emptyStats } } })
    await mountPage(DashboardView, { path: '/admin' })

    expect(panel('Readings per day (last 14)').textContent).toContain('No readings yet.')
    expect(panel('Most-used decks').textContent).toContain('No data yet.')
    expect(panel('Most-used spreads').textContent).toContain('No spread readings yet.')
  })

  it("says insights aren't available and shows why when the summary fails", async () => {
    stubApi({ 'GET /admin/summary': { status: 500, body: { error: 'Stats are unavailable.' } } })
    await mountPage(DashboardView, { path: '/admin' })

    expect(toasts()).toEqual(['Stats are unavailable.'])
    expect(document.body.textContent).toContain('No insights available yet.')
    expect(document.body.textContent).not.toContain('Loading insights…')
    expect(launcher()).toContain('Decks 0')
  })

  it('drops to the login page when the session has expired', async () => {
    stubApi({ 'GET /admin/summary': { status: 401, body: { error: 'Unauthorized' } } })
    const { router } = await mountPage(DashboardView, { path: '/admin' })

    // The login route's component loads lazily, so the navigation lands a beat later.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(useUser().currentUser.value).toBeNull()
  })
})
