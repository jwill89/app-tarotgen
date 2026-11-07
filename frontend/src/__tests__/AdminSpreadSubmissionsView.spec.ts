import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SpreadSubmissionsView from '@/views/admin/SpreadSubmissionsView.vue'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
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
  labelled,
  link,
  pageTitle,
  apiCalls,
  choose,
} from './helpers/page'
import {
  anonymous,
  categories,
  crossroads,
  pendingSpread,
  position,
  spread,
} from './helpers/spreads'
import type { PendingSpread } from '@/types'

const loads = (pending: PendingSpread[] = []) => ({
  'GET /admin/pending-spreads': { body: pending },
  'GET /admin/spread-categories': { body: categories },
})

/** The open preview of the Crossroads submission. */
function preview(): HTMLElement {
  const open = dialog('Crossroads')
  if (!open) {
    throw new Error('The preview is not open')
  }
  return open
}

/** The queue's table, once there is one. */
const queueTable = (): Element | null => document.body.querySelector('table')

describe('admin SpreadSubmissionsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('says so when nothing is waiting for review', async () => {
    const api = stubApi(loads())
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    expect(apiCalls(api)).toEqual([
      ['GET', '/api/admin/pending-spreads'],
      ['GET', '/api/admin/spread-categories'],
    ])
    expect(pageTitle()).toBe('Spread Submissions')
    expect(document.body.textContent).toContain('No pending submissions right now.')
    expect(queueTable()).toBeNull()
  })

  it('leads back to the spread list', async () => {
    stubApi(loads())
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    expect(link('Back to Spreads').getAttribute('href')).toBe('/admin/spreads')
  })

  it('lists each submission with when, who and how many cards, under a count', async () => {
    stubApi(loads([crossroads, anonymous]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    expect(document.body.textContent).toContain('2 submissions waiting for review.')
    expect(tableRows()).toEqual([
      [formatDateTime('2026-10-27 08:30:00'), 'Crossroads', 'Morgana', '3', ''],
      [formatDateTime('2026-10-27 08:30:00'), 'Quick Look', '—', '2', ''],
    ])
  })

  it('counts one submission as one', async () => {
    stubApi(loads([crossroads]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    expect(document.body.textContent).toContain('1 submission waiting for review.')
  })

  it('previews a submission: who sent it, its positions in order, and its description', async () => {
    stubApi(loads([crossroads]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    await click(button('Preview', row('Crossroads')))

    expect(preview().textContent).toContain('Submitted by Morgana')
    expect([...preview().querySelectorAll('ol li')].map((li) => li.textContent.trim())).toEqual([
      '(untitled)',
      'Path B',
      'Choice',
    ])
    expect(preview().textContent).toContain('Two paths, one choice.')
  })

  it('closes the preview with Close', async () => {
    stubApi(loads([crossroads]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Preview', row('Crossroads')))

    // The footer's Close, not the corner ×, which shares its name.
    const footer = preview().querySelector('footer')
    if (!footer) {
      throw new Error('The preview has no footer')
    }
    await click(button('Close', footer))

    expect(dialog('Crossroads')).toBeNull()
  })

  it('closes the preview with the corner ×, too', async () => {
    stubApi(loads([crossroads]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Preview', row('Crossroads')))

    const corner = preview().querySelector<HTMLElement>('.myst-modal-close')
    if (!corner) {
      throw new Error('The preview has no corner ×')
    }
    await click(corner)

    expect(dialog('Crossroads')).toBeNull()
  })

  it('offers a plain Approve while there are no categories to name', async () => {
    stubApi({ ...loads([crossroads]), 'GET /admin/spread-categories': { body: [] } })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    expect(button('Approve', row('Crossroads'))).toBeTruthy()
  })

  it('approves a submission into the first category and empties the queue', async () => {
    let approved = false
    const api = stubApi({
      'GET /admin/spread-categories': { body: categories },
      'GET /admin/pending-spreads': () => ({ body: approved ? [] : [crossroads] }),
      'POST /admin/pending-spreads/5/approve': () => {
        approved = true
        return { status: 201, body: spread(4, 'Crossroads', crossroads.positions) }
      },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    api.mockClear()

    // From the row, into the first category (the server's default too).
    await click(button('Approve into General', row('Crossroads')))

    expect(apiCalls(api)).toEqual([
      ['POST', '/api/admin/pending-spreads/5/approve', {}],
      ['GET', '/api/admin/pending-spreads'],
    ])
    expect(toasts()).toEqual(['Spread approved and published.'])
    expect(document.body.textContent).toContain('No pending submissions right now.')
  })

  it('approves from the preview into the category chosen there, and closes it', async () => {
    let approved = false
    const api = stubApi({
      'GET /admin/spread-categories': { body: categories },
      'GET /admin/pending-spreads': () => ({ body: approved ? [] : [crossroads] }),
      'POST /admin/pending-spreads/5/approve': () => {
        approved = true
        return { status: 201, body: spread(4, 'Crossroads', crossroads.positions, 3) }
      },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Preview', row('Crossroads')))

    expect(labelled('Publish in').textContent.trim()).toBe('General')
    await choose(labelled('Publish in'), 'Career')
    await click(button('Approve', preview()))

    expect(apiCalls(api)).toContainEqual([
      'POST',
      '/api/admin/pending-spreads/5/approve',
      { category_id: 3 },
    ])
    expect(toasts()).toEqual(['Spread approved and published.'])
    expect(dialog('Crossroads')).toBeNull()
  })

  it('shows what each submitted position asks, under its title', async () => {
    stubApi(
      loads([
        pendingSpread(7, 'Crossroads', {
          positions: [
            { ...position(1, 'Left'), description: 'The road you know' },
            position(2, 'Right'),
          ],
        }),
      ]),
    )
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })

    await click(button('Preview', row('Crossroads')))

    expect(
      [...preview().querySelectorAll('.pending-position-about')].map((el) => el.textContent),
    ).toEqual(['The road you know'])
  })

  it("keeps the submission queued, preview open, and shows the server's reason when approving fails", async () => {
    stubApi({
      ...loads([crossroads]),
      'POST /admin/pending-spreads/5/approve': {
        status: 500,
        body: { error: 'Failed to approve spread' },
      },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Preview', row('Crossroads')))

    await click(button('Approve', preview()))

    expect(toasts()).toEqual(['Failed to approve spread'])
    expect(dialog('Crossroads')).not.toBeNull()
    expect(column(1)).toEqual(['Crossroads'])
  })

  it('asks before rejecting, and keeps the submission on Cancel', async () => {
    const api = stubApi(loads([crossroads]))
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    api.mockClear()

    await click(button('Reject', row('Crossroads')))
    expect(confirmDialog()?.textContent).toContain(
      'Reject and permanently delete the submission "Crossroads"?',
    )

    await answerConfirm('cancel')
    expect(api).not.toHaveBeenCalled()
    expect(column(1)).toEqual(['Crossroads'])
  })

  it('rejects on confirmation and reloads the queue', async () => {
    let rejected = false
    const api = stubApi({
      'GET /admin/spread-categories': { body: categories },
      'GET /admin/pending-spreads': () => ({ body: rejected ? [] : [crossroads] }),
      'DELETE /admin/pending-spreads/5': () => {
        rejected = true
        return { status: 204 }
      },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Reject', row('Crossroads')))
    api.mockClear()

    await answerConfirm('confirm')

    expect(apiCalls(api)).toEqual([
      ['DELETE', '/api/admin/pending-spreads/5'],
      ['GET', '/api/admin/pending-spreads'],
    ])
    expect(toasts()).toEqual(['Submission rejected.'])
    expect(document.body.textContent).toContain('No pending submissions right now.')
  })

  it('rejects from the preview and closes it', async () => {
    let rejected = false
    stubApi({
      'GET /admin/spread-categories': { body: categories },
      'GET /admin/pending-spreads': () => ({ body: rejected ? [] : [crossroads] }),
      'DELETE /admin/pending-spreads/5': () => {
        rejected = true
        return { status: 204 }
      },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Preview', row('Crossroads')))

    await click(button('Reject', preview()))
    await answerConfirm('confirm')

    expect(toasts()).toEqual(['Submission rejected.'])
    expect(dialog('Crossroads')).toBeNull()
  })

  it("keeps the submission queued and shows the server's reason when rejecting fails", async () => {
    stubApi({
      ...loads([crossroads]),
      'DELETE /admin/pending-spreads/5': { status: 500, body: { error: 'Failed to reject' } },
    })
    await mountPage(SpreadSubmissionsView, { path: '/admin/spreads/submissions' })
    await click(button('Reject', row('Crossroads')))

    await answerConfirm('confirm')

    expect(toasts()).toEqual(['Failed to reject'])
    expect(column(1)).toEqual(['Crossroads'])
  })

  it('drops to the login page when the session has expired', async () => {
    const expired = { status: 401, body: { error: 'Unauthorized' } }
    stubApi({ 'GET /admin/pending-spreads': expired, 'GET /admin/spread-categories': expired })
    const { router } = await mountPage(SpreadSubmissionsView, {
      path: '/admin/spreads/submissions',
    })

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(useUser().currentUser.value).toBeNull()
    expect(toasts()).toEqual([])
  })
})
