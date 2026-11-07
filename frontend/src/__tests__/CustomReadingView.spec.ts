import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import CustomReadingView from '@/views/CustomReadingView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/composables/useConfirm'
import { useDecks } from '@/composables/useDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useToasts } from '@/composables/useToasts'
import { STORAGE_KEYS } from '@/constants'
import { cardImageUrls } from '@/utils/cardImages'
import { mountWithApp, cleanup, signIn, stubApi, testUser } from './helpers/app'
import type { AccountReading, Deck, DeckCard, Spread } from '@/types'

function deck(deck_id: number, name: string): Deck {
  return {
    deck_id,
    deck_system_id: 1,
    name,
    artist: 'Artist',
    purchase_url: '',
    additional_cards: 0,
    card_aspect_w: 5,
    card_aspect_h: 8.6,
    approved: true,
    usable: true,
    system_short_name: 'RWS',
    system_total_cards: 78,
  }
}

const rws = deck(1, 'Rider-Waite Tarot')
const thoth = deck(2, 'Thoth Tarot')

const rwsCards: DeckCard[] = [
  { card_id: 0, name: 'The Fool' },
  { card_id: 1, name: 'The Magician' },
  { card_id: 2, name: 'The High Priestess' },
]

const threeCard: Spread = {
  spread_id: 3,
  name: 'Past, Present, Future',
  description: '',
  card_count: 3,
  category_id: 1,
  category_name: 'General',
  category_sort_order: 1,
  positions: [
    { order: 2, title: 'Present', x: 50, y: 50, rotation: 0 },
    { order: 1, title: 'Past', x: 25, y: 50, rotation: 0 },
    { order: 3, title: 'Future', x: 75, y: 50, rotation: 0 },
  ],
}

const saved: AccountReading = {
  reading_id: 'cus123',
  reading_info: { deck_id: 1, origin: 'custom', draw: [] },
  reading_time: '2026-10-28 10:00:00',
  user_id: null,
  hide_user: false,
  reading_name: null,
  reading_notes: null,
  is_final: false,
  password_protected: false,
  daily_card_day: null,
  question: null,
  querent: null,
  tags: [],
  visibility: 'link',
  expires_at: null,
}

/** What the page loads, plus `extra`. */
function loads(extra: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    'GET /decks': { body: [thoth, rws] },
    'GET /spreads': { body: [threeCard] },
    'GET /decks/1/cards': { body: rwsCards },
    'GET /decks/2/cards': { body: [{ card_id: 0, name: 'The Fool (Thoth)' }] },
    'GET /account/spreads': { body: [] },
    'GET /account/favorites': { body: [] },
    'GET /account/favorite-decks': { body: [] },
    // The Turnstile widget a signed-out visitor gets asks for the site key (none here).
    'GET /config': { body: { turnstile_sitekey: null } },
    ...extra,
  })
}

/** The page as App.vue hosts it: under the tooltip provider, beside the ConfirmDialog. */
async function openPage() {
  const { wrapper, router } = await mountWithApp(
    { render: () => h(TooltipProvider, null, () => [h(CustomReadingView), h(ConfirmDialog)]) },
    { path: '/custom' },
  )
  return { wrapper, router }
}

const body = () => document.body
const buttonIn = (root: ParentNode, name: string) => {
  const found = [...root.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) =>
      b.textContent.trim() === name ||
      b.getAttribute('aria-label') === name ||
      b.getAttribute('title') === name,
  )
  if (!found) {
    throw new Error(`No "${name}" button`)
  }
  return found
}
async function click(name: string, root: ParentNode = body()): Promise<void> {
  buttonIn(root, name).click()
  await flushPromises()
}
const tray = (wrapper: VueWrapper) => wrapper.findAll('.spread-token').map((t) => t.text())
const count = (wrapper: VueWrapper) =>
  wrapper.get<HTMLInputElement>('#custom-number-of-cards').element.value
const toastMessages = () => useToasts().toasts.value.map((t) => [t.type, t.message])

/** Open a Reka select by its trigger and choose the option with this label. */
async function choose(triggerId: string, label: string): Promise<void> {
  const trigger = body().querySelector<HTMLElement>(`#${triggerId}`)
  trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
  await flushPromises()
  const option = [...body().querySelectorAll<HTMLElement>('[role="option"]')].find(
    (o) => o.textContent.trim() === label,
  )
  if (!option) {
    throw new Error(`No "${label}" option`)
  }
  option.focus()
  option.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
  await flushPromises()
}

/** Place card `n` from the tray and give it a card from the deck. */
async function placeWithCard(n: number, cardName: string): Promise<void> {
  await click(`Place card ${String(n)}`)
  await click('Choose card / title…')
  await choose('custom-card', cardName)
  await click('Done', body().querySelector('[role="dialog"]') ?? body())
}

function sentPayload(api: ReturnType<typeof stubApi>): unknown {
  const call = api.mock.calls.find(
    ([url, init]) => url === '/api/readings' && init?.method === 'POST',
  )
  return JSON.parse(String(call?.[1]?.body))
}

describe('CustomReadingView', () => {
  beforeEach(() => {
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
    useSpreads().spreads.value = []
    useToasts().toasts.value = []
    useRecentReadings().clear()
    useConfirm().settle(false)
    localStorage.removeItem(STORAGE_KEYS.lastDeck)
    signIn(null)
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it("starts with one card to place, from Rider-Waite's cards", async () => {
    const api = loads()
    const { wrapper } = await openPage()

    expect(wrapper.get<HTMLInputElement>('#cr-deck').element.value).toBe('Rider-Waite Tarot')
    expect(count(wrapper)).toBe('1')
    expect(tray(wrapper)).toEqual(['1'])
    expect(api.mock.calls.map(([url]) => url)).toContain('/api/decks/1/cards')
    expect(wrapper.get('#custom-base-on-a-spread').text()).toBe('Start from scratch')
  })

  it('adds and drops cards to place as the count changes', async () => {
    loads()
    const { wrapper } = await openPage()

    await wrapper.get('#custom-number-of-cards').setValue('3')
    await wrapper.get('#custom-number-of-cards').trigger('blur')
    await flushPromises()

    expect(tray(wrapper)).toEqual(['1', '2', '3'])
  })

  it("lays out a spread's positions to fill in, when based on one", async () => {
    loads()
    const { wrapper } = await openPage()

    await choose('custom-base-on-a-spread', 'Past, Present, Future (3 cards)')

    expect(count(wrapper)).toBe('3')
    expect(tray(wrapper)).toEqual([])
    expect(wrapper.findAll('.spread-card .spread-order-badge').map((b) => b.text())).toEqual([
      '1',
      '2',
      '3',
    ])
    expect(toastMessages()).toEqual([['success', 'Applied the "Past, Present, Future" template.']])
  })

  it('asks before a template replaces work already done, and keeps it on Cancel', async () => {
    loads()
    const { wrapper } = await openPage()
    await click('Place card 1')

    await choose('custom-base-on-a-spread', 'Past, Present, Future (3 cards)')
    const prompt = body().querySelector('[role="alertdialog"]')
    expect(prompt?.textContent).toContain(
      'Replace your current layout with the "Past, Present, Future" template?',
    )
    await click('Cancel', prompt ?? body())

    expect(count(wrapper)).toBe('1')
    expect(wrapper.findAll('.spread-card')).toHaveLength(1)
    expect(wrapper.get('#custom-base-on-a-spread').text()).toBe('Start from scratch')
  })

  it('replaces the work with the template once the user agrees', async () => {
    loads()
    const { wrapper } = await openPage()
    await click('Place card 1')

    await choose('custom-base-on-a-spread', 'Past, Present, Future (3 cards)')
    await click('Apply template')

    expect(count(wrapper)).toBe('3')
    expect(wrapper.findAll('.spread-card')).toHaveLength(3)
  })

  it("picks each position's card from the deck, a card at most once", async () => {
    loads()
    const { wrapper } = await openPage()
    await wrapper.get('#custom-number-of-cards').setValue('2')
    await wrapper.get('#custom-number-of-cards').trigger('blur')
    await flushPromises()

    await placeWithCard(1, 'The Magician')
    expect(wrapper.get('.cr-card-img').attributes('src')).toBe(cardImageUrls(1, 1).thumb)

    await click('Place card 2')
    await click('Choose card / title…')
    const trigger = body().querySelector<HTMLElement>('#custom-card')
    trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()
    const taken = [...body().querySelectorAll<HTMLElement>('[role="option"]')].find(
      (o) => o.textContent.trim() === 'The Magician',
    )
    expect(taken?.hasAttribute('data-disabled')).toBe(true)
  })

  it('will not save until every card is placed and chosen', async () => {
    const api = loads()
    const { wrapper } = await openPage()
    await click('Place card 1')

    await click('Recreate Draw')

    expect(toastMessages()).toEqual([
      ['warning', 'Every card must be placed on the layout and have a card selected.'],
    ])
    expect(api.mock.calls.map(([url]) => url)).not.toContain('/api/readings')
    expect(wrapper.text()).not.toContain('Saving your reading...')
  })

  it('saves the recreated reading and opens it', async () => {
    const api = loads({ 'POST /readings': { status: 201, body: saved } })
    const { wrapper, router } = await openPage()
    await wrapper.get('#cr-name').setValue('Morning pull')
    await placeWithCard(1, 'The High Priestess')
    await click('Toggle reversed')

    await click('Recreate Draw')

    expect(sentPayload(api)).toEqual({
      deck_id: 1,
      name: 'Morning pull',
      cards: [{ card_id: 2, reversed: true, title: '', x: 40, y: 40, rotation: 0 }],
    })
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'))
    expect(router.currentRoute.value.params.id).toBe('cus123')
    expect(toastMessages()).toEqual([['success', 'Your custom reading has been saved!']])
    expect(useRecentReadings().recent.value).toEqual([
      {
        id: 'cus123',
        deckName: 'Rider-Waite Tarot',
        summary: 'Morning pull',
        at: '2026-10-28 10:00:00',
      },
    ])
  })

  it("sends a signed-in user's title, journal, tags and who can open it", async () => {
    signIn(testUser())
    const api = loads({ 'POST /readings': { status: 201, body: saved } })
    const { wrapper } = await openPage()
    await placeWithCard(1, 'The Fool')
    await wrapper.get('#ro-name').setValue('Recreated')
    await wrapper.get('#ro-question').setValue('What now?')
    await wrapper.get('#ro-querent').setValue('Sam')
    await wrapper.get('#ro-tags').setValue('career')
    await wrapper.get('#ro-tags').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    await click('Private')

    await click('Recreate Draw')

    expect(sentPayload(api)).toMatchObject({
      reading_name: 'Recreated',
      question: 'What now?',
      querent: 'Sam',
      tags: ['career'],
      hide_user: false,
      visibility: 'private',
    })
    expect(sentPayload(api)).not.toHaveProperty('password')
    expect(useRecentReadings().recent.value[0]!.summary).toBe('1 card')
  })

  it("shows the server's error and stays on the form", async () => {
    loads({ 'POST /readings': { status: 422, body: { error: 'That card is not in this deck.' } } })
    const { router } = await openPage()
    await placeWithCard(1, 'The Fool')

    await click('Recreate Draw')

    expect(toastMessages()).toEqual([['error', 'That card is not in this deck.']])
    expect(router.currentRoute.value.name).toBe('custom-reading')
  })

  it("switches to another deck's cards, clearing the cards chosen from the last one", async () => {
    loads()
    const { wrapper } = await openPage()
    await placeWithCard(1, 'The Fool')

    const deckBox = wrapper.get('#cr-deck')
    await deckBox.setValue('thoth')
    await flushPromises()
    await deckBox.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(localStorage.getItem(STORAGE_KEYS.lastDeck)).toBe('2')
    expect(wrapper.find('.cr-card-img').exists()).toBe(false)
    await click('Choose card / title…')
    await choose('custom-card', 'The Fool (Thoth)')
    expect(wrapper.get('.cr-card-img').attributes('src')).toBe(cardImageUrls(2, 0).thumb)
  })

  it("says so when the deck's cards can't be loaded", async () => {
    loads({ 'GET /decks/1/cards': { status: 500, body: { error: 'Boom' } } })
    await openPage()

    expect(toastMessages()).toEqual([
      ['error', "Couldn't load this deck's cards. Please try again."],
    ])
  })

  it('resets the layout, the name and the owner options', async () => {
    signIn(testUser())
    loads()
    const { wrapper } = await openPage()
    await wrapper.get('#cr-name').setValue('Morning pull')
    await choose('custom-base-on-a-spread', 'Past, Present, Future (3 cards)')
    await click('Password')
    await wrapper.get('#ro-password').setValue('s3cret')

    await click('Reset')

    expect(wrapper.get<HTMLInputElement>('#cr-name').element.value).toBe('')
    expect(count(wrapper)).toBe('1')
    expect(tray(wrapper)).toEqual(['1'])
    expect(wrapper.get('#custom-base-on-a-spread').text()).toBe('Start from scratch')
    expect(wrapper.find('#ro-password').exists()).toBe(false)
  })

  describe('with Cloudflare Turnstile switched on', () => {
    let solve: (token: string) => void
    const turnstile = { render: vi.fn(), reset: vi.fn(), remove: vi.fn() }
    const siteKey = { 'GET /config': { body: { turnstile_sitekey: '0x4AAA' } } }

    beforeEach(() => {
      solve = () => {}
      turnstile.render
        .mockReset()
        .mockImplementation((_el: HTMLElement, opts: Record<string, unknown>) => {
          const callback = opts.callback
          if (typeof callback === 'function') {
            solve = (token) => callback(token)
          }
          return 'widget-1'
        })
      turnstile.reset.mockReset()
      // Already "loaded", so the widget never fetches Cloudflare's script.
      vi.stubGlobal('turnstile', turnstile)
    })

    it('holds a signed-out reading until the challenge is solved, then sends its token', async () => {
      const api = loads({ ...siteKey, 'POST /readings': { status: 201, body: saved } })
      const { router } = await openPage()
      await placeWithCard(1, 'The Fool')

      expect(turnstile.render).toHaveBeenCalledWith(
        expect.any(HTMLElement),
        expect.objectContaining({ sitekey: '0x4AAA' }),
      )
      expect(buttonIn(body(), 'Recreate Draw').disabled).toBe(true)

      solve('cf-token')
      await flushPromises()
      expect(buttonIn(body(), 'Recreate Draw').disabled).toBe(false)
      await click('Recreate Draw')

      expect(sentPayload(api)).toMatchObject({ turnstile_token: 'cf-token' })
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'))
    })

    it('asks for a fresh challenge after a refused save (tokens are single-use)', async () => {
      loads({
        ...siteKey,
        'POST /readings': {
          status: 400,
          body: { error: 'Captcha verification failed. Please try again.' },
        },
      })
      const { router } = await openPage()
      await placeWithCard(1, 'The Fool')
      solve('cf-token')
      await flushPromises()

      await click('Recreate Draw')

      expect(toastMessages()).toEqual([['error', 'Captcha verification failed. Please try again.']])
      expect(turnstile.reset).toHaveBeenCalledWith('widget-1')
      expect(buttonIn(body(), 'Recreate Draw').disabled).toBe(true)
      expect(router.currentRoute.value.name).toBe('custom-reading')
    })

    it('never asks a signed-in reader', async () => {
      signIn(testUser())
      const api = loads({ ...siteKey, 'POST /readings': { status: 201, body: saved } })
      await openPage()
      await placeWithCard(1, 'The Fool')

      expect(turnstile.render).not.toHaveBeenCalled()
      expect(buttonIn(body(), 'Recreate Draw').disabled).toBe(false)
      await click('Recreate Draw')

      expect(sentPayload(api)).not.toHaveProperty('turnstile_token')
    })
  })
})
