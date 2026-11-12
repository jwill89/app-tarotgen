import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SubmitSpreadView from '@/views/SubmitSpreadView.vue'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  toasts,
  labelled,
  valueOf,
  typeInto,
  apiCalls,
} from './helpers/page'
import type { UserSpread } from '@/types'

/** What the editor sends for a one-card spread named Daily Draw, its card placed once. */
const dailyDraw = {
  name: 'Daily Draw',
  description: 'One card for the day.',
  card_count: 1,
  positions: [{ order: 1, title: '', x: 40, y: 40, rotation: 0 }],
}

const savedSpread: UserSpread = {
  user_spread_id: 12,
  user_id: 7,
  ...dailyDraw,
  created_at: '2026-10-28 10:00:00',
  updated_at: '2026-10-28 10:00:00',
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

/** Fill in the Daily Draw spread and place its one card, ready to save. */
async function designDailyDraw(): Promise<void> {
  await typeInto(labelled('Spread Name'), dailyDraw.name)
  await typeInto(descriptionField(), dailyDraw.description)
  const token = document.body.querySelector<HTMLElement>('button[title="Place card 1"]')
  if (!token) {
    throw new Error('No unplaced card to place')
  }
  await click(token)
}

const thanks = 'Thanks for your submission!'

describe('SubmitSpreadView', () => {
  beforeEach(() => {
    resetPageState()
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('as a guest', () => {
    beforeEach(() => signIn(null))

    it('submits as Guest, offers a log-in for credit, and has no personal option', async () => {
      stubApi({})
      await mountPage(SubmitSpreadView, { path: '/create-spread' })

      expect(document.body.textContent).toContain('Submitting as Guest.')
      const login = [...document.body.querySelectorAll('a')].find(
        (a) => a.textContent.trim() === 'Log in',
      )
      expect(login?.getAttribute('href')).toBe('/login')
      expect(hasButton('Personal Spread')).toBe(false)
      expect(hasButton('Submit Spread')).toBe(true)
    })

    it('sends the spread for review and thanks the submitter', async () => {
      const api = stubApi({ 'POST /spreads': { status: 201, body: { success: true } } })
      await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await designDailyDraw()

      await click(button('Submit Spread'))

      expect(apiCalls(api)).toEqual([['POST', '/api/spreads', dailyDraw]])
      expect(document.body.textContent).toContain(thanks)
      expect(hasButton('Submit Spread')).toBe(false)
    })

    it('starts a fresh spread from "Create Another"', async () => {
      stubApi({ 'POST /spreads': { status: 201, body: { success: true } } })
      await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await designDailyDraw()
      await click(button('Submit Spread'))

      await click(button('Create Another'))

      expect(document.body.textContent).not.toContain(thanks)
      expect(valueOf(labelled('Spread Name'))).toBe('')
    })

    it('goes home from "Back to Home" after submitting', async () => {
      stubApi({ 'POST /spreads': { status: 201, body: { success: true } } })
      const { router } = await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await designDailyDraw()
      await click(button('Submit Spread'))

      await click(button('Back to Home'))

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), lazyLoad)
    })

    it("shows the server's reason, such as the rate limit, and keeps the form", async () => {
      stubApi({
        'POST /spreads': {
          status: 429,
          body: { error: 'Too many submissions. Please try again later.' },
        },
      })
      await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await designDailyDraw()

      await click(button('Submit Spread'))

      expect(document.body.textContent).toContain('Too many submissions. Please try again later.')
      expect(document.body.textContent).not.toContain(thanks)
      expect(valueOf(labelled('Spread Name'))).toBe('Daily Draw')
    })

    it('goes home when the editor is cancelled', async () => {
      const api = stubApi({})
      const { router } = await mountPage(SubmitSpreadView, { path: '/create-spread' })

      await click(button('Cancel'))

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), lazyLoad)
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('signed in', () => {
    beforeEach(() => signIn(testUser({ display_name: 'Morgana' })))

    it('credits the display name and offers saving it publicly or privately', async () => {
      stubApi({})
      await mountPage(SubmitSpreadView, { path: '/create-spread' })

      expect(document.body.textContent).toContain('Submitting as Morgana.')
      expect(document.body.textContent).not.toContain('Log in')
      expect(button('Public Spread').getAttribute('aria-pressed')).toBe('true')
      expect(button('Personal Spread').getAttribute('aria-pressed')).toBe('false')
    })

    it('labels the save for the chosen mode', async () => {
      stubApi({})
      await mountPage(SubmitSpreadView, { path: '/create-spread' })

      await click(button('Personal Spread'))
      expect(hasButton('Save Spread')).toBe(true)
      expect(document.body.textContent).toContain('only you can use it')

      await click(button('Public Spread'))
      expect(hasButton('Submit Spread')).toBe(true)
      expect(document.body.textContent).toContain('submitted for admin review')
    })

    it('still submits for review in public mode', async () => {
      const api = stubApi({ 'POST /spreads': { status: 201, body: { success: true } } })
      await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await designDailyDraw()

      await click(button('Submit Spread'))

      expect(apiCalls(api)).toEqual([['POST', '/api/spreads', dailyDraw]])
      expect(document.body.textContent).toContain(thanks)
    })

    it('saves a personal spread to the account and opens My Spreads', async () => {
      const api = stubApi({ 'POST /account/spreads': { status: 201, body: savedSpread } })
      const { router } = await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await click(button('Personal Spread'))
      await designDailyDraw()

      await click(button('Save Spread'))

      expect(apiCalls(api)).toEqual([['POST', '/api/account/spreads', dailyDraw]])
      expect(toasts()).toEqual(['Spread saved to your personal collection!'])
      await vi.waitFor(
        () => expect(router.currentRoute.value.name).toBe('account-spreads'),
        lazyLoad,
      )
    })

    it("says why the personal save failed, in the server's words, and stays on the form", async () => {
      stubApi({
        'POST /account/spreads': { status: 400, body: { error: 'Invalid position data.' } },
      })
      const { router } = await mountPage(SubmitSpreadView, { path: '/create-spread' })
      await click(button('Personal Spread'))
      await designDailyDraw()

      await click(button('Save Spread'))

      expect(document.body.textContent).toContain('Invalid position data.')
      expect(toasts()).toEqual([])
      expect(router.currentRoute.value.name).toBe('submit-spread')
    })
  })
})
