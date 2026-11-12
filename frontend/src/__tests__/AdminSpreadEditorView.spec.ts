import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { RouterView, type Router } from 'vue-router'
import SpreadEditorView from '@/views/admin/SpreadEditorView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser, testWebRouter, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  toasts,
  row,
  column,
  labelled,
  link,
  pageTitle,
  valueOf,
  typeInto,
  apiCalls,
  choose,
} from './helpers/page'
import { categories, position, spread, spreads, threeCard } from './helpers/spreads'

const editing = {
  'GET /admin/spread-categories': { body: categories },
  'GET /admin/spreads/1': { body: threeCard },
}

/** The list's own calls, for a test that goes back to it. */
const listing = {
  'GET /admin/spreads': { body: spreads },
  'GET /admin/pending-spreads': { body: [] },
}

function descriptionField(): HTMLElement {
  const field = document.body.querySelector<HTMLElement>(
    'textarea[placeholder="Describe the spread. Markdown is supported."]',
  )
  if (!field) {
    throw new Error('No description field')
  }
  return field
}

/** Place the first card still waiting in the editor's "Unplaced Cards" tray. */
async function placeNextCard(): Promise<void> {
  const token = document.body.querySelector<HTMLElement>('button[title^="Place card"]')
  if (!token) {
    throw new Error('No unplaced card to place')
  }
  await click(token)
}

/** Wait for a navigation (its view loads lazily) and the calls the new page makes. */
async function arrive(router: Router, name: string): Promise<void> {
  await vi.waitFor(() => expect(router.currentRoute.value.name).toBe(name), lazyLoad)
  await flushPromises()
}

/** The app's pages from `path`, on the window's history: how an admin moves between them. */
async function browse(path: string) {
  const router = testWebRouter()
  await mountPage(RouterView, { router, path })
  return router
}

describe('admin SpreadEditorView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('adding a spread', () => {
    it('creates a spread once its card is placed, in the first category, then returns to the list', async () => {
      const created = spread(4, 'Daily Draw', [position(1, '', 40, 40)])
      const api = stubApi({
        'GET /admin/spread-categories': { body: categories },
        'POST /admin/spreads': { status: 201, body: created },
      })
      const { router } = await mountPage(SpreadEditorView, { path: '/admin/spreads/new' })

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/spread-categories']])
      expect(pageTitle()).toBe('Add Spread')
      expect(valueOf(labelled('Spread Name'))).toBe('')
      expect(labelled('Category').textContent.trim()).toBe('General')

      await typeInto(labelled('Spread Name'), '  Daily Draw  ')
      await typeInto(descriptionField(), 'One card for the day.')
      await placeNextCard()
      api.mockClear()
      await click(button('Save Spread'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/admin/spreads',
          {
            name: 'Daily Draw',
            description: 'One card for the day.',
            card_count: 1,
            positions: [{ order: 1, title: '', x: 40, y: 40, rotation: 0 }],
            category_id: 1,
          },
        ],
      ])
      expect(toasts()).toEqual(['Spread created.'])
      await arrive(router, 'admin-spreads')
    })

    it("won't save until every card is placed", async () => {
      const api = stubApi({ 'GET /admin/spread-categories': { body: categories } })
      await mountPage(SpreadEditorView, { path: '/admin/spreads/new' })
      await typeInto(labelled('Spread Name'), 'Daily Draw')
      api.mockClear()

      await click(button('Save Spread'))

      expect(api).not.toHaveBeenCalled()
      expect(document.body.textContent).toContain(
        'Every card must be placed on the layout before saving.',
      )
    })
  })

  describe('editing a spread', () => {
    it('opens the spread the address names and saves the changes over it', async () => {
      const api = stubApi({
        ...editing,
        'PUT /admin/spreads/1': { body: { ...threeCard, name: 'Past, Present, Future' } },
      })
      const { router } = await mountPage(SpreadEditorView, { path: '/admin/spreads/1/edit' })

      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/spread-categories'],
        ['GET', '/api/admin/spreads/1'],
      ])
      expect(pageTitle()).toBe('Edit Spread')
      expect(valueOf(labelled('Spread Name'))).toBe('Three Card')
      expect(valueOf(labelled('Number of Cards'))).toBe('3')

      await typeInto(labelled('Spread Name'), 'Past, Present, Future')
      expect(labelled('Category').textContent.trim()).toBe('Love & relationships')
      await choose(labelled('Category'), 'Career')
      api.mockClear()
      await click(button('Save Spread'))

      expect(apiCalls(api)).toEqual([
        [
          'PUT',
          '/api/admin/spreads/1',
          {
            name: 'Past, Present, Future',
            description: 'About Three Card',
            card_count: 3,
            positions: threeCard.positions,
            category_id: 3,
          },
        ],
      ])
      expect(toasts()).toEqual(['Spread updated.'])
      await arrive(router, 'admin-spreads')
    })

    it('says so while the spread is on its way', async () => {
      // The categories arrive; the spread never does.
      vi.stubGlobal(
        'fetch',
        vi.fn((url: string) =>
          url.endsWith('/spread-categories')
            ? Promise.resolve(Response.json(categories))
            : new Promise<Response>(() => {}),
        ),
      )
      await mountPage(SpreadEditorView, { path: '/admin/spreads/1/edit' })

      expect(document.body.textContent).toContain('Loading the spread…')
      expect(hasButton('Save Spread')).toBe(false)
    })

    it('stays in the editor and says the save failed when the server refuses it', async () => {
      stubApi({
        ...editing,
        'PUT /admin/spreads/1': { status: 404, body: { error: 'Spread not found' } },
      })
      const { router } = await mountPage(SpreadEditorView, { path: '/admin/spreads/1/edit' })

      await click(button('Save Spread'))

      expect(toasts()).toEqual(['Spread not found'])
      expect(router.currentRoute.value.name).toBe('admin-spread-edit')
      expect(document.body.textContent).toContain('Failed to save the spread. Please try again.')
    })
  })

  describe('a spread that cannot be opened', () => {
    it('says there is no such spread, with the way back, for an unknown id', async () => {
      stubApi({
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/spreads/99': { status: 404, body: { error: 'Spread not found' } },
      })
      await mountPage(SpreadEditorView, { path: '/admin/spreads/99/edit' })

      expect(pageTitle()).toBe('Spread Not Found')
      expect(document.body.textContent).toContain(
        'There is no spread #99. It may have been deleted.',
      )
      // The page says it; no toast on top.
      expect(toasts()).toEqual([])
      expect(hasButton('Save Spread')).toBe(false)
      expect(link('Back to Spreads').getAttribute('href')).toBe('/admin/spreads')
    })

    it('asks the server nothing for an id that cannot name a spread', async () => {
      const api = stubApi({ 'GET /admin/spread-categories': { body: categories } })
      await mountPage(SpreadEditorView, { path: '/admin/spreads/three-card/edit' })

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/spread-categories']])
      expect(pageTitle()).toBe('Spread Not Found')
      expect(document.body.textContent).toContain('There is no spread #three-card.')
    })

    it("says why when the spread couldn't be loaded", async () => {
      stubApi({
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/spreads/1': { status: 500, body: { error: 'The database is busy.' } },
      })
      await mountPage(SpreadEditorView, { path: '/admin/spreads/1/edit' })

      expect(pageTitle()).toBe('Edit Spread')
      expect(document.body.querySelector('.notification')?.textContent.trim()).toBe(
        "The spread couldn't be loaded: The database is busy.",
      )
      expect(toasts()).toEqual([])
      expect(hasButton('Save Spread')).toBe(false)
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({ 'GET /admin/spread-categories': expired, 'GET /admin/spreads/1': expired })
      const { router } = await mountPage(SpreadEditorView, { path: '/admin/spreads/1/edit' })

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })
  })

  describe('getting there and back', () => {
    it('Cancel returns to the list it came from, with no new history entry', async () => {
      const api = stubApi({ ...listing, ...editing })
      const router = await browse('/admin/spreads')
      await click(button('Edit', row('Three Card')))
      await arrive(router, 'admin-spread-edit')
      expect(valueOf(labelled('Spread Name'))).toBe('Three Card')
      const entries = window.history.length
      api.mockClear()

      await click(button('Cancel'))
      await arrive(router, 'admin-spreads')

      expect(window.history.length).toBe(entries)
      // Stepped back, so Forward would reopen the editor.
      expect(window.history.state.forward).toBe('/admin/spreads/1/edit')
      expect(apiCalls(api)).toEqual([
        ['GET', '/api/admin/spreads'],
        ['GET', '/api/admin/pending-spreads'],
      ])
      expect(pageTitle()).toBe('Manage Spreads')
    })

    it('Save returns to the list, with the toast, the same way', async () => {
      stubApi({ ...listing, ...editing, 'PUT /admin/spreads/1': { body: threeCard } })
      const router = await browse('/admin/spreads')
      await click(button('Edit', row('Three Card')))
      await arrive(router, 'admin-spread-edit')
      const entries = window.history.length

      await click(button('Save Spread'))
      await arrive(router, 'admin-spreads')

      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe('/admin/spreads/1/edit')
      expect(toasts()).toEqual(['Spread updated.'])
      expect(column(1)).toContain('Three Card')
    })

    it('"Back to Spreads" returns to the list the same way', async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse('/admin/spreads')
      await click(link('Add Spread'))
      await arrive(router, 'admin-spread-new')
      const entries = window.history.length

      await click(link('Back to Spreads'))
      await arrive(router, 'admin-spreads')

      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe('/admin/spreads/new')
    })

    it("the browser's Back from the editor returns to the list", async () => {
      stubApi({ ...listing, ...editing })
      const router = await browse('/admin/spreads')
      await click(button('Edit', row('Three Card')))
      await arrive(router, 'admin-spread-edit')

      window.history.back()
      await arrive(router, 'admin-spreads')

      expect(pageTitle()).toBe('Manage Spreads')
    })

    it('from a deep link, Cancel goes to the list in place of the editor', async () => {
      stubApi({
        ...listing,
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/spreads/7': { body: spread(7, 'Horseshoe', [position(1, 'Past')]) },
      })
      const router = await browse('/admin/spreads/7/edit')
      expect(valueOf(labelled('Spread Name'))).toBe('Horseshoe')
      const entries = window.history.length

      await click(button('Cancel'))
      await arrive(router, 'admin-spreads')

      // Replaced: no extra entry, and Back won't reopen the editor.
      expect(window.history.length).toBe(entries)
      expect(window.history.state.back).toBeNull()
      expect(window.history.state.forward).toBeNull()
      expect(pageTitle()).toBe('Manage Spreads')
    })
  })
})
