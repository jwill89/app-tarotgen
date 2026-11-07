import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import DeckSystemSubmissionsView from '@/views/admin/DeckSystemSubmissionsView.vue'
import { useUser } from '@/composables/useUser'
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
  link,
  pageTitle,
  apiCalls,
} from './helpers/page'
import { pending, solaBusca, solaBuscaCards, system } from './helpers/deckSystems'
import type { DeckSystem } from '@/types'

const PATH = '/admin/deck-systems/submissions'

const loads = (waiting: DeckSystem[] = []) => ({
  'GET /admin/deck-systems/pending': { body: waiting },
})

/** A queue that empties once `done` is set, for the approve and reject tests. */
function emptiedBy(done: () => boolean) {
  return { 'GET /admin/deck-systems/pending': () => ({ body: done() ? [] : pending }) }
}

/** The open preview of the Sola Busca submission. */
function preview(): HTMLElement {
  const open = dialog('Sola Busca')
  if (!open) {
    throw new Error('The preview is not open')
  }
  return open
}

/** The preview's footer, where its own buttons are (the corner × is also "Close"). */
function previewFooter(): HTMLElement {
  const footer = preview().querySelector('footer')
  if (!footer) {
    throw new Error('The preview has no footer')
  }
  return footer
}

describe('admin DeckSystemSubmissionsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the queue', () => {
    it('says so when nothing is waiting for review', async () => {
      const api = stubApi(loads())
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/deck-systems/pending']])
      expect(pageTitle()).toBe('Deck System Submissions')
      expect(document.body.textContent).toContain('No pending submissions right now.')
      expect(document.body.querySelector('table')).toBeNull()
    })

    it('leads back to the deck-system list', async () => {
      stubApi(loads())
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      expect(link('Back to Deck Systems').getAttribute('href')).toBe('/admin/deck-systems')
    })

    it('lists each submission with its card count and who submitted it, under a count', async () => {
      stubApi(
        loads([solaBusca, system(10, 'Minchiate', 'MIN', { approved: false, total_cards: 97 })]),
      )
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      expect(document.body.textContent).toContain('2 submissions waiting for review.')
      expect(tableRows()).toEqual([
        ['9', 'Sola Busca', 'SB', '2', '7', ''],
        ['10', 'Minchiate', 'MIN', '97', '—', ''],
      ])
    })

    it('counts one submission as one', async () => {
      stubApi(loads(pending))
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      expect(document.body.textContent).toContain('1 submission waiting for review.')
    })

    it('drops to the login page when the session has expired', async () => {
      stubApi({
        'GET /admin/deck-systems/pending': { status: 401, body: { error: 'Unauthorized' } },
      })
      const { router } = await mountPage(DeckSystemSubmissionsView, { path: PATH })

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })
  })

  describe('previewing a submission', () => {
    it('shows who sent it and its cards in order, with their keywords', async () => {
      const api = stubApi({
        ...loads(pending),
        'GET /admin/deck-systems/9': { body: solaBuscaCards },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      api.mockClear()

      await click(button('Preview', row('Sola Busca')))

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/deck-systems/9']])
      expect(preview().textContent).toContain('SB · 2 cards · submitted by user #7')
      expect(
        [...preview().querySelectorAll('ol li')].map((li) =>
          li.textContent.replace(/\s+/g, ' ').trim(),
        ),
      ).toEqual(['Mars Drive, courage', 'Venus'])
      expect(
        [...preview().querySelectorAll('.preview-card-keywords')].map((el) => el.textContent),
      ).toEqual(['Drive, courage'])
    })

    it('says so while the cards are on their way, and when none were sent', async () => {
      let answer: (reply: Response) => void = () => {}
      const anonymous = system(10, 'Minchiate', 'MIN', { approved: false })
      vi.stubGlobal(
        'fetch',
        vi.fn((url: string) =>
          url.endsWith('/pending')
            ? Promise.resolve(Response.json([anonymous]))
            : new Promise<Response>((resolve) => (answer = resolve)),
        ),
      )
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      await click(button('Preview', row('Minchiate')))
      const open = dialog('Minchiate')
      expect(open?.textContent).toContain('Loading the cards…')
      // No submitter to name.
      expect(open?.textContent).not.toContain('submitted by')

      answer(Response.json({ ...anonymous, cards: [] }))
      await vi.waitFor(() => expect(open?.textContent).toContain('No cards were submitted.'))
    })

    it("closes the preview when the cards can't be loaded, saying why", async () => {
      stubApi({
        ...loads(pending),
        'GET /admin/deck-systems/9': { status: 404, body: { error: 'Deck system not found' } },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      await click(button('Preview', row('Sola Busca')))

      expect(toasts()).toEqual(['Deck system not found'])
      expect(dialog('Sola Busca')).toBeNull()
    })

    it('keeps a preview closed when its cards arrive after it was', async () => {
      let answer: (reply: Response) => void = () => {}
      vi.stubGlobal(
        'fetch',
        vi.fn((url: string) =>
          url.endsWith('/pending')
            ? Promise.resolve(Response.json(pending))
            : new Promise<Response>((resolve) => (answer = resolve)),
        ),
      )
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Preview', row('Sola Busca')))

      await click(button('Close', previewFooter()))
      answer(Response.json(solaBuscaCards))
      await vi.waitFor(() => expect(dialog('Sola Busca')).toBeNull())
    })

    it('closes with Close, or the corner ×', async () => {
      stubApi({ ...loads(pending), 'GET /admin/deck-systems/9': { body: solaBuscaCards } })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })

      await click(button('Preview', row('Sola Busca')))
      await click(button('Close', previewFooter()))
      expect(dialog('Sola Busca')).toBeNull()

      await click(button('Preview', row('Sola Busca')))
      const corner = preview().querySelector<HTMLElement>('.myst-modal-close')
      if (!corner) {
        throw new Error('The preview has no corner ×')
      }
      await click(corner)
      expect(dialog('Sola Busca')).toBeNull()
    })
  })

  describe('approving', () => {
    it('approves a submission from its row and empties the queue', async () => {
      let approved = false
      const api = stubApi({
        ...emptiedBy(() => approved),
        'POST /admin/deck-systems/9/approve': () => {
          approved = true
          return { body: { ...solaBusca, approved: true } }
        },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      api.mockClear()

      await click(button('Approve', row('Sola Busca')))

      expect(apiCalls(api)).toEqual([
        ['POST', '/api/admin/deck-systems/9/approve', {}],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system approved.'])
      expect(document.body.textContent).toContain('No pending submissions right now.')
    })

    it('approves from the preview, and closes it', async () => {
      let approved = false
      stubApi({
        ...emptiedBy(() => approved),
        'GET /admin/deck-systems/9': { body: solaBuscaCards },
        'POST /admin/deck-systems/9/approve': () => {
          approved = true
          return { body: { ...solaBusca, approved: true } }
        },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Preview', row('Sola Busca')))

      await click(button('Approve', previewFooter()))

      expect(toasts()).toEqual(['Deck system approved.'])
      expect(dialog('Sola Busca')).toBeNull()
    })

    it("keeps the submission queued and the preview open, with the server's reason, when approving fails", async () => {
      stubApi({
        ...loads(pending),
        'GET /admin/deck-systems/9': { body: solaBuscaCards },
        'POST /admin/deck-systems/9/approve': {
          status: 404,
          body: { error: 'Deck system not found' },
        },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Preview', row('Sola Busca')))

      await click(button('Approve', previewFooter()))

      expect(toasts()).toEqual(['Deck system not found'])
      expect(dialog('Sola Busca')).not.toBeNull()
      expect(column(1)).toEqual(['Sola Busca'])
    })
  })

  describe('editing', () => {
    it('opens a submission in the editor from its row', async () => {
      const api = stubApi(loads(pending))
      const { router } = await mountPage(DeckSystemSubmissionsView, { path: PATH })
      api.mockClear()

      await click(button('Edit', row('Sola Busca')))

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-deck-system-edit'))
      expect(router.currentRoute.value.params.id).toBe('9')
      expect(api).not.toHaveBeenCalled()
    })

    it('opens it from the preview, too', async () => {
      stubApi({ ...loads(pending), 'GET /admin/deck-systems/9': { body: solaBuscaCards } })
      const { router } = await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Preview', row('Sola Busca')))

      await click(button('Edit', previewFooter()))

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-deck-system-edit'))
      expect(router.currentRoute.value.params.id).toBe('9')
    })
  })

  describe('rejecting', () => {
    it('asks first, and keeps the submission on Cancel', async () => {
      const api = stubApi(loads(pending))
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      api.mockClear()

      await click(button('Reject', row('Sola Busca')))
      expect(confirmDialog()?.textContent).toContain(
        'Reject and permanently delete the submission "Sola Busca"? Its card list is deleted with it.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
      expect(column(1)).toEqual(['Sola Busca'])
    })

    it('deletes it on confirmation and reloads the queue', async () => {
      let rejected = false
      const api = stubApi({
        ...emptiedBy(() => rejected),
        'DELETE /admin/deck-systems/9': () => {
          rejected = true
          return { status: 204 }
        },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Reject', row('Sola Busca')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/deck-systems/9'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Submission rejected.'])
      expect(document.body.textContent).toContain('No pending submissions right now.')
    })

    it('rejects from the preview and closes it', async () => {
      let rejected = false
      stubApi({
        ...emptiedBy(() => rejected),
        'GET /admin/deck-systems/9': { body: solaBuscaCards },
        'DELETE /admin/deck-systems/9': () => {
          rejected = true
          return { status: 204 }
        },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Preview', row('Sola Busca')))

      await click(button('Reject', previewFooter()))
      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Submission rejected.'])
      expect(dialog('Sola Busca')).toBeNull()
    })

    it("keeps the submission queued and shows the server's reason when rejecting fails", async () => {
      stubApi({
        ...loads(pending),
        'DELETE /admin/deck-systems/9': { status: 404, body: { error: 'Deck system not found' } },
      })
      await mountPage(DeckSystemSubmissionsView, { path: PATH })
      await click(button('Reject', row('Sola Busca')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Deck system not found'])
      expect(column(1)).toEqual(['Sola Busca'])
    })
  })
})
