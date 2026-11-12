import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import DashboardView from '@/views/DashboardView.vue'
import { useUser } from '@/composables/useUser'
import { mountWithApp, cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const linkTo = (title: string) =>
  [...document.body.querySelectorAll('a')]
    .find((a) => a.querySelector('.title')?.textContent === title)
    ?.getAttribute('href')
const logoutButton = () =>
  [...document.body.querySelectorAll('button')].find((b) => text(b).startsWith('Logout'))

/** What the card-frequency panel loads with the page, plus `extra`. */
function loads(extra: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    'GET /decks': { body: [] },
    'GET /account/card-frequency': {
      body: { readings: 0, deck_id: null, days: null, cards: [] },
    },
    ...extra,
  })
}

describe('DashboardView', () => {
  beforeEach(() => signIn(testUser()))
  afterEach(cleanup)

  it('leads to each part of the account', async () => {
    loads()
    await mountWithApp(DashboardView, { path: '/account' })

    expect(linkTo('My Readings')).toBe('/account/readings')
    expect(linkTo('My Spreads')).toBe('/account/spreads')
    expect(linkTo('Account Settings')).toBe('/account/settings')
  })

  it('leads to the daily card', async () => {
    loads()
    await mountWithApp(DashboardView, { path: '/account' })

    const daily = document.body.querySelector('a.daily-strip')
    expect(daily?.getAttribute('href')).toBe('/daily')
    expect(text(daily ?? document.body)).toContain('Daily Card')
  })

  it('shows which cards keep turning up', async () => {
    loads({
      'GET /account/card-frequency': {
        body: {
          readings: 2,
          deck_id: null,
          days: null,
          cards: [
            {
              card_name: 'The Tower',
              card_id: null,
              deck_id: null,
              count: 2,
              upright: 1,
              reversed: 1,
            },
          ],
        },
      },
    })
    await mountWithApp(DashboardView, { path: '/account' })

    expect(text(document.body)).toContain('Card Frequency')
    expect(text(document.body)).toContain('The Tower')
  })

  it('signs out and goes home', async () => {
    const api = loads({ 'POST /auth/logout': { status: 204 } })
    const { router } = await mountWithApp(DashboardView, { path: '/account' })

    logoutButton()?.click()
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/api/auth/logout', { method: 'POST' })
    expect(useUser().isLoggedIn.value).toBe(false)
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), lazyLoad)
  })

  it('signs out on this device even if the server cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await mountWithApp(DashboardView, { path: '/account' })

    logoutButton()?.click()
    await flushPromises()

    expect(useUser().isLoggedIn.value).toBe(false)
  })
})
