import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { RouterView, type Router } from 'vue-router'
import DeckSystemEditorView from '@/views/admin/DeckSystemEditorView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser, testWebRouter } from './helpers/app'
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
  column,
  labelled,
  link,
  pageTitle,
  valueOf,
  typeInto,
  enterNumber,
  apiCalls,
  cardHeadings,
  nameCards,
} from './helpers/page'
import {
  card,
  lenormand,
  pending,
  solaBusca,
  solaBuscaCards,
  system,
  systems,
} from './helpers/deckSystems'

const LIST = '/admin/deck-systems'
const EDIT = '/admin/deck-systems/3/edit'
const NEW = '/admin/deck-systems/new'

/** The list's own calls, for a test that goes back to it. */
const listing = {
  'GET /admin/deck-systems': { body: systems },
  'GET /admin/deck-systems/pending': { body: [] },
}
const editing = { 'GET /admin/deck-systems/3': { body: lenormand } }

/** Wait for a navigation (its view loads lazily) and the calls the new page makes. */
async function arrive(router: Router, name: string): Promise<void> {
  await vi.waitFor(() => expect(router.currentRoute.value.name).toBe(name))
  await flushPromises()
}

/**
 * The app's pages on the window's history, as an admin moves between them: the
 * last of `paths` is shown, the ones before it are the history behind it.
 */
async function browse(...paths: string[]): Promise<Router> {
  const router = testWebRouter()
  for (const path of paths.slice(0, -1)) {
    await router.push(path)
  }
  await mountPage(RouterView, { router, path: paths[paths.length - 1] })
  return router
}

/** The leave-without-saving question, while it is open. */
const unsavedPrompt = () =>
  confirmDialog()?.textContent.includes("Your changes to this deck system haven't been saved")

/** A beforeunload as the browser sends one, and whether the page asked to keep it. */
function reloadIsHeldBack(): boolean {
  const event = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(event)
  return event.defaultPrevented
}

describe('admin DeckSystemEditorView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('adding a system', () => {
    it('opens a blank 78-card form, and holds Create back until every card is named', async () => {
      const api = stubApi({})
      await browse(NEW)

      expect(api).not.toHaveBeenCalled()
      expect(pageTitle()).toBe('Add Deck System')
      expect(valueOf(labelled('Name'))).toBe('')
      expect(valueOf(labelled('Total Cards'))).toBe('78')
      expect(cardHeadings()).toHaveLength(78)
      expect(button('Create').disabled).toBe(true)
      expect(document.body.textContent).toContain('All cards must have a title before saving.')
      expect(link('Back to Deck Systems').getAttribute('href')).toBe(LIST)
    })

    it('resizes the card list with the total, keeping the names typed', async () => {
      stubApi({})
      await browse(NEW)

      await nameCards(['Rider', 'Clover', 'Ship'])
      await enterNumber(labelled('Total Cards'), 2)
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover'])
      expect(button('Create').disabled).toBe(false)

      await enterNumber(labelled('Total Cards'), 3)
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover', '3 Untitled Card'])
      expect(button('Create').disabled).toBe(true)
    })

    it('creates it through the submission endpoint, then returns to the refreshed list', async () => {
      const created = system(4, 'Lenormand Grand', 'LG', { total_cards: 2 })
      let saved = false
      const api = stubApi({
        ...listing,
        'GET /admin/deck-systems': () => ({ body: saved ? [...systems, created] : systems }),
        'POST /deck-systems': () => {
          saved = true
          return { status: 201, body: created }
        },
      })
      const router = await browse(LIST, NEW)

      await typeInto(labelled('Name'), 'Lenormand Grand')
      await typeInto(labelled('Short Name'), 'LG')
      await nameCards(['Rider', 'Clover'])
      api.mockClear()
      await click(button('Create'))
      await arrive(router, 'admin-deck-systems')

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/deck-systems',
          {
            name: 'Lenormand Grand',
            short_name: 'LG',
            total_cards: 2,
            cards: [card(0, 1, 'Rider'), card(0, 2, 'Clover')],
          },
        ],
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system created.'])
      // Saved, so nothing was left to ask about.
      expect(confirmDialog()).toBeNull()
      expect(column(1)).toContain('Lenormand Grand')
    })

    it("stays in the editor and shows the server's reason when creating fails", async () => {
      const api = stubApi({
        'POST /deck-systems': {
          status: 409,
          body: { error: 'A deck system with that name already exists' },
        },
      })
      const router = await browse(NEW)
      await typeInto(labelled('Name'), 'Lenormand')
      await typeInto(labelled('Short Name'), 'Len')
      await nameCards(['Rider'])
      api.mockClear()

      await click(button('Create'))

      expect(apiCalls(api)).toHaveLength(1)
      expect(toasts()).toEqual(['A deck system with that name already exists'])
      expect(router.currentRoute.value.name).toBe('admin-deck-system-new')
      expect(pageTitle()).toBe('Add Deck System')
      expect(button('Create').disabled).toBe(false)
    })
  })

  describe('editing a system', () => {
    it('opens the system the address names, with its cards', async () => {
      const api = stubApi(editing)
      await browse(EDIT)

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/deck-systems/3']])
      expect(pageTitle()).toBe('Edit Deck System')
      expect(valueOf(labelled('Name'))).toBe('Lenormand')
      expect(valueOf(labelled('Short Name'))).toBe('Len')
      expect(valueOf(labelled('Total Cards'))).toBe('2')
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover'])
      expect(button('Save').disabled).toBe(false)
      expect(link('Back to Deck Systems').getAttribute('href')).toBe(LIST)
    })

    it('saves the edited fields over the system and returns to the list, with no new history entry', async () => {
      const api = stubApi({
        ...listing,
        ...editing,
        'PUT /admin/deck-systems/3': (body) => ({ body: { ...systems[2], ...(body as object) } }),
      })
      const router = await browse(LIST)
      await click(button('Edit', row('Lenormand')))
      await arrive(router, 'admin-deck-system-edit')
      const entries = window.history.length

      await typeInto(labelled('Name'), 'Petit Lenormand')
      api.mockClear()
      await click(button('Save'))
      await arrive(router, 'admin-deck-systems')

      expect(apiCalls(api)).toEqual([
        [
          'PUT',
          '/api/admin/deck-systems/3',
          { name: 'Petit Lenormand', short_name: 'Len', total_cards: 2, cards: lenormand.cards },
        ],
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system updated.'])
      expect(confirmDialog()).toBeNull()
      // Stepped back, so Forward would reopen the editor.
      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe(EDIT)
      expect(pageTitle()).toBe('Manage Deck Systems')
    })

    it('stays in the editor when saving fails', async () => {
      stubApi({
        ...editing,
        'PUT /admin/deck-systems/3': { status: 404, body: { error: 'Deck system not found' } },
      })
      const router = await browse(EDIT)

      await click(button('Save'))

      expect(toasts()).toEqual(['Deck system not found'])
      expect(router.currentRoute.value.name).toBe('admin-deck-system-edit')
      expect(pageTitle()).toBe('Edit Deck System')
    })

    it('gives the form one card per card the total names, as it opens', async () => {
      stubApi({
        ...listing,
        'GET /admin/deck-systems/3': { body: { ...lenormand, total_cards: 3 } },
      })
      const router = await browse(LIST, EDIT)

      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover', '3 Untitled Card'])
      expect(button('Save').disabled).toBe(true)

      // Sized as it opened: not a change of the admin's to ask about.
      await click(button('Cancel'))
      await arrive(router, 'admin-deck-systems')
      expect(confirmDialog()).toBeNull()
    })

    it('says so while the system is on its way', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise<Response>(() => {})),
      )
      await browse(EDIT)

      expect(document.body.textContent).toContain('Loading the deck system…')
      expect(hasButton('Save')).toBe(false)
    })
  })

  describe('a submission waiting for review', () => {
    const reviewing = {
      'GET /admin/deck-systems/pending': { body: pending },
      'GET /admin/deck-systems/9': { body: solaBuscaCards },
    }

    it('is edited from the queue, and saving returns there', async () => {
      const api = stubApi({
        ...reviewing,
        'PUT /admin/deck-systems/9': { body: solaBusca },
      })
      const router = await browse('/admin/deck-systems/submissions')
      await click(button('Edit', row('Sola Busca')))
      await arrive(router, 'admin-deck-system-edit')

      expect(document.body.textContent).toContain(
        'A submission waiting for review: not yet approved.',
      )
      expect(link('Back to Submissions').getAttribute('href')).toBe(
        '/admin/deck-systems/submissions',
      )
      expect(cardHeadings()).toEqual(['1 Mars', '2 Venus'])

      await typeInto(labelled('Short Name'), 'Sola')
      api.mockClear()
      await click(button('Save'))
      await arrive(router, 'admin-deck-system-submissions')

      expect(apiCalls(api)).toEqual([
        [
          'PUT',
          '/api/admin/deck-systems/9',
          { name: 'Sola Busca', short_name: 'Sola', total_cards: 2, cards: solaBuscaCards.cards },
        ],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(window.history.state.forward).toBe('/admin/deck-systems/9/edit')
    })

    it('"Back to Submissions" returns to the queue from a deep link, too', async () => {
      stubApi(reviewing)
      const router = await browse('/admin/deck-systems/9/edit')

      await click(link('Back to Submissions'))
      await arrive(router, 'admin-deck-system-submissions')

      expect(window.history.state.back).toBeNull()
      expect(pageTitle()).toBe('Deck System Submissions')
    })
  })

  describe('a system that cannot be opened', () => {
    it('says there is no such system, with the way back, for an unknown id', async () => {
      stubApi({
        'GET /admin/deck-systems/99': { status: 404, body: { error: 'Deck system not found' } },
      })
      await browse('/admin/deck-systems/99/edit')

      expect(pageTitle()).toBe('Deck System Not Found')
      expect(document.body.textContent).toContain(
        'There is no deck system #99. It may have been deleted.',
      )
      // The page says it; no toast on top.
      expect(toasts()).toEqual([])
      expect(hasButton('Save')).toBe(false)
      expect(link('Back to Deck Systems').getAttribute('href')).toBe(LIST)
    })

    it('asks the server nothing for an id that cannot name a system', async () => {
      const api = stubApi({})
      await browse('/admin/deck-systems/lenormand/edit')

      expect(api).not.toHaveBeenCalled()
      expect(pageTitle()).toBe('Deck System Not Found')
      expect(document.body.textContent).toContain('There is no deck system #lenormand.')
    })

    it("says why when the system couldn't be loaded", async () => {
      stubApi({
        'GET /admin/deck-systems/3': { status: 500, body: { error: 'The database is busy.' } },
      })
      await browse(EDIT)

      expect(pageTitle()).toBe('Edit Deck System')
      expect(document.body.querySelector('.notification')?.textContent.trim()).toBe(
        "The deck system couldn't be loaded: The database is busy.",
      )
      expect(toasts()).toEqual([])
      expect(hasButton('Save')).toBe(false)
    })

    it('drops to the login page when the session has expired', async () => {
      // Mounted bare, so the login page isn't drawn (and asks nothing); its leave
      // guard then has no route to watch, which vue-router warns about.
      vi.spyOn(console, 'warn').mockImplementation(() => {})
      stubApi({ 'GET /admin/deck-systems/3': { status: 401, body: { error: 'Unauthorized' } } })
      const { router } = await mountPage(DeckSystemEditorView, { path: EDIT })

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })
  })

  describe('getting there and back', () => {
    it('Cancel returns to the list it came from, with no new history entry', async () => {
      const api = stubApi({ ...listing, ...editing })
      const router = await browse(LIST)
      await click(button('Edit', row('Lenormand')))
      await arrive(router, 'admin-deck-system-edit')
      const entries = window.history.length
      api.mockClear()

      await click(button('Cancel'))
      await arrive(router, 'admin-deck-systems')

      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe(EDIT)
      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(pageTitle()).toBe('Manage Deck Systems')
    })

    it('"Back to Deck Systems" returns to the list the same way', async () => {
      stubApi(listing)
      const router = await browse(LIST)
      await click(link('Add Deck System'))
      await arrive(router, 'admin-deck-system-new')
      const entries = window.history.length

      await click(link('Back to Deck Systems'))
      await arrive(router, 'admin-deck-systems')

      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe(NEW)
    })

    it("the browser's Back from the editor returns to the list", async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST)
      await click(button('Edit', row('Lenormand')))
      await arrive(router, 'admin-deck-system-edit')

      window.history.back()
      await arrive(router, 'admin-deck-systems')

      expect(pageTitle()).toBe('Manage Deck Systems')
    })

    it('from a deep link, Cancel goes to the list in place of the editor', async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(EDIT)
      expect(valueOf(labelled('Name'))).toBe('Lenormand')
      const entries = window.history.length

      await click(button('Cancel'))
      await arrive(router, 'admin-deck-systems')

      // Replaced: no extra entry, and Back won't reopen the editor.
      expect(window.history.length).toBe(entries)
      expect(window.history.state.back).toBeNull()
      expect(window.history.state.forward).toBeNull()
      expect(pageTitle()).toBe('Manage Deck Systems')
    })

    it('from an unknown id, the way back goes to the list in place of it', async () => {
      stubApi({
        ...listing,
        'GET /admin/deck-systems/99': { status: 404, body: { error: 'Deck system not found' } },
      })
      const router = await browse('/admin/deck-systems/99/edit')
      const entries = window.history.length

      await click(link('Back to Deck Systems'))
      await arrive(router, 'admin-deck-systems')

      expect(window.history.length).toBe(entries)
      expect(window.history.state.back).toBeNull()
    })
  })

  describe('leaving with unsaved changes', () => {
    it('asks before Cancel throws edits away, and stays on Keep Editing', async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST, EDIT)
      await typeInto(labelled('Name'), 'Petit Lenormand')

      await click(button('Cancel'))
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      expect(confirmDialog()?.textContent).toContain('Keep Editing')

      await answerConfirm('cancel')

      // The address is put back on the editor once the browser has gone forward.
      await vi.waitFor(() => expect(window.location.pathname).toBe(EDIT))
      expect(router.currentRoute.value.name).toBe('admin-deck-system-edit')
      expect(valueOf(labelled('Name'))).toBe('Petit Lenormand')
    })

    it('leaves, dropping the edits, on Discard Changes', async () => {
      const api = stubApi({ ...listing, ...editing })
      const router = await browse(LIST, EDIT)
      await typeInto(labelled('Name'), 'Petit Lenormand')
      api.mockClear()

      await click(link('Back to Deck Systems'))
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      await answerConfirm('confirm')
      await arrive(router, 'admin-deck-systems')

      // Nothing was saved.
      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(pageTitle()).toBe('Manage Deck Systems')
    })

    it("asks when the browser's Back would lose card edits, and keeps the page on Keep Editing", async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST, EDIT)
      await nameCards(['Rider', 'Cloverleaf'])

      window.history.back()
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      await answerConfirm('cancel')

      // The address is put back on the editor, edits and all.
      await vi.waitFor(() => expect(window.location.pathname).toBe(EDIT))
      expect(router.currentRoute.value.name).toBe('admin-deck-system-edit')
      expect(cardHeadings()).toEqual(['1 Rider', '2 Cloverleaf'])

      window.history.back()
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      await answerConfirm('confirm')
      await arrive(router, 'admin-deck-systems')
    })

    it('asks about a new system once anything is entered', async () => {
      stubApi(listing)
      const router = await browse(LIST, NEW)
      await enterNumber(labelled('Total Cards'), 22)

      await click(button('Cancel'))
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      await answerConfirm('confirm')
      await arrive(router, 'admin-deck-systems')
    })

    it.each([
      ['a new system', NEW],
      ['a system opened for editing', EDIT],
    ])('leaves %s that was not changed without asking', async (_what, path) => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST, path)

      await click(button('Cancel'))
      await arrive(router, 'admin-deck-systems')

      expect(confirmDialog()).toBeNull()
    })

    it('counts an edit put back as it was as no change', async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST, EDIT)
      await typeInto(labelled('Name'), 'Petit Lenormand')
      await typeInto(labelled('Name'), 'Lenormand')

      await click(button('Cancel'))
      await arrive(router, 'admin-deck-systems')

      expect(confirmDialog()).toBeNull()
    })

    it('has the browser ask before a reload or a closed tab loses the edits', async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse(LIST, EDIT)
      expect(reloadIsHeldBack()).toBe(false)

      await typeInto(labelled('Short Name'), 'PL')
      expect(reloadIsHeldBack()).toBe(true)

      await click(button('Cancel'))
      await vi.waitFor(() => expect(unsavedPrompt()).toBe(true))
      await answerConfirm('confirm')
      await arrive(router, 'admin-deck-systems')
      // The editor is gone, and its question with it.
      expect(reloadIsHeldBack()).toBe(false)
    })
  })
})
