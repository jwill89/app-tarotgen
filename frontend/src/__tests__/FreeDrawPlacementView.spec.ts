import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import FreeDrawPlacementView from '@/views/FreeDrawPlacementView.vue'
import { useDecks } from '@/composables/useDecks'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useToasts } from '@/composables/useToasts'
import { cardImageUrls } from '@/utils/cardImages'
import { mountWithApp, cleanup, signIn, stubApi, testUser, lazyLoad } from './helpers/app'
import type { Deck, Reading, ReadingInfo, UserSpread } from '@/types'

const rws: Deck = {
  deck_id: 1,
  deck_system_id: 1,
  name: 'Rider-Waite Tarot',
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

const freeDraw: ReadingInfo = {
  deck_id: 1,
  draw: [
    { card_id: 0, reversed: false, card_name: 'The Fool' },
    { card_id: 9, reversed: true, card_name: 'Wheel of Fortune' },
  ],
}

// Owner drew a third card into a spread they had already placed.
const grownSpread: ReadingInfo = {
  deck_id: 1,
  spread: {
    spread_id: 0,
    name: 'My Layout',
    description: '',
    positions: [
      { order: 1, title: 'Root', x: 30, y: 40, rotation: 0 },
      { order: 2, title: 'Branch', x: 60, y: 40, rotation: 15 },
    ],
  },
  draw: [...freeDraw.draw, { card_id: 17, reversed: false, card_name: 'The Star' }],
}

function reading(info: ReadingInfo, overrides: Partial<Reading> = {}): Reading {
  return {
    reading_id: 'abc123',
    reading_info: info,
    reading_time: '2026-10-28 09:00:00',
    reading_name: null,
    reading_notes: null,
    reader: 'Reader',
    is_owner: true,
    is_final: false,
    can_draw_more: true,
    locked: false,
    visibility: 'link',
    expires_at: null,
    ...overrides,
  }
}

const savedSpread: UserSpread = {
  user_spread_id: 12,
  user_id: 7,
  name: 'Two Card Pull',
  description: '',
  card_count: 2,
  positions: [],
  created_at: '2026-10-28 09:00:00',
  updated_at: '2026-10-28 09:00:00',
}

function loads(body: Reading, extra: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    'GET /decks': { body: [rws] },
    'GET /readings/abc123': { body },
    ...extra,
  })
}

async function openPage() {
  // The canvas toolbar's tooltips need the provider App.vue mounts.
  const { wrapper, router } = await mountWithApp(
    { render: () => h(TooltipProvider, null, () => h(FreeDrawPlacementView)) },
    { path: '/reading/abc123/placement' },
  )
  return { wrapper, router }
}

const buttonNamed = (name: string) => {
  const found = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
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
async function click(name: string): Promise<void> {
  buttonNamed(name).click()
  await flushPromises()
}
/** Each tray token's number and (shortened) card name. */
const tray = (wrapper: VueWrapper) =>
  wrapper.findAll('.spread-token').map((t) =>
    t
      .findAll('span')
      .map((s) => s.text())
      .join(' '),
  )
const toastMessages = () => useToasts().toasts.value.map((t) => [t.type, t.message])
const saveDialog = () =>
  [...document.body.querySelectorAll('[role="dialog"]')].find((d) =>
    d.textContent.includes('Save This Layout as a Spread?'),
  )

async function placeAll(wrapper: VueWrapper): Promise<void> {
  while (tray(wrapper).length > 0) {
    wrapper.get('.spread-token').element.dispatchEvent(new MouseEvent('click'))
    await flushPromises()
  }
}

function sent(api: ReturnType<typeof stubApi>, method: string, url: string): unknown {
  const call = api.mock.calls.find(([u, init]) => u === url && init?.method === method)
  return call ? JSON.parse(String(call[1]?.body)) : undefined
}

async function typeInto(selector: string, text: string): Promise<void> {
  const field = document.body.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)
  if (!field) {
    throw new Error(`No ${selector}`)
  }
  field.value = text
  field.dispatchEvent(new Event('input'))
  await flushPromises()
}

describe('FreeDrawPlacementView', () => {
  beforeEach(() => {
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
    useUserSpreads().userSpreads.value = []
    useToasts().toasts.value = []
    signIn(null)
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('puts the drawn cards in the tray, named, to be placed', async () => {
    loads(reading(freeDraw))
    const { wrapper } = await openPage()

    expect(wrapper.get('h1').text()).toBe('Arrange Your Draw')
    expect(tray(wrapper)).toEqual(['1 The Fool', '2 Wheel of'])
  })

  it('shows each placed card face up, reversed ones turned', async () => {
    loads(reading(freeDraw))
    const { wrapper } = await openPage()

    await placeAll(wrapper)

    const images = wrapper.findAll('.fdp-card-img')
    expect(images.map((i) => [i.attributes('src'), i.attributes('alt')])).toEqual([
      [cardImageUrls(1, 0).thumb, 'The Fool'],
      [cardImageUrls(1, 9).thumb, 'Wheel of Fortune'],
    ])
    expect(images[1]!.classes()).toContain('reversed')
  })

  it('keeps cards already placed on a spread where they were, with only new ones in the tray', async () => {
    loads(reading(grownSpread))
    const { wrapper } = await openPage()

    expect(tray(wrapper)).toEqual(['3 The Star'])
    const placed = wrapper.findAll('.spread-card').map((c) => {
      const style = (c.element as HTMLElement).style
      return [style.left, style.top, style.getPropertyValue('--rotation')]
    })
    expect(placed).toEqual([
      ['30%', '40%', '0deg'],
      ['60%', '40%', '15deg'],
    ])
  })

  it('names the card in its position editor', async () => {
    loads(reading(freeDraw))
    const { wrapper } = await openPage()
    await placeAll(wrapper)

    await click('Edit title…')

    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('Position #2 — Wheel of Fortune')
    await typeInto('#freedraw-position-title', 'The Turn')
    await click('Done')
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })

  it('sends the visitor home when the reading cannot be loaded', async () => {
    loads(reading(freeDraw), { 'GET /readings/abc123': { status: 404, body: { error: 'Nope' } } })
    const { router } = await openPage()

    expect(toastMessages()).toEqual([['error', 'Could not load the reading.']])
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), lazyLoad)
  })

  it('sends the visitor home from a password-locked reading', async () => {
    loads(reading(freeDraw), {
      'GET /readings/abc123': { body: { locked: true, reading_name: null } },
    })
    const { router } = await openPage()

    expect(toastMessages()).toEqual([['error', 'Could not load the reading.']])
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), lazyLoad)
  })

  it('sends the owner back to a final reading, which can no longer change', async () => {
    loads(reading(freeDraw, { is_final: true }))
    const { router } = await openPage()

    expect(toastMessages()).toEqual([
      ['warning', 'This reading is final and can no longer be edited.'],
    ])
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
    expect(router.currentRoute.value.params.id).toBe('abc123')
  })

  it('lets the owner skip placing the cards', async () => {
    loads(reading(freeDraw))
    const { router } = await openPage()

    await click('Skip Placement')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
  })

  it('cannot be finalized until every card is placed', async () => {
    loads(reading(freeDraw))
    const { wrapper } = await openPage()

    expect(buttonNamed('Finalize Placement').disabled).toBe(true)
    await placeAll(wrapper)
    expect(buttonNamed('Finalize Placement').disabled).toBe(false)
  })

  it('saves where each card sits, then offers to keep the layout as a spread', async () => {
    const api = loads(reading(freeDraw), { 'PUT /readings/abc123/placement': { body: {} } })
    const { wrapper } = await openPage()
    await placeAll(wrapper)

    await click('Finalize Placement')

    expect(sent(api, 'PUT', '/api/readings/abc123/placement')).toEqual({
      positions: [
        { title: '', x: 40, y: 40, rotation: 0 },
        { title: '', x: 45, y: 40, rotation: 0 },
      ],
      spread_name: 'Free Draw Placement',
    })
    expect(toastMessages()).toEqual([['success', 'Placement saved!']])
    expect(saveDialog()).toBeDefined()
  })

  it('goes straight back to a grown spread once saved, under its own name', async () => {
    const api = loads(reading(grownSpread), { 'PUT /readings/abc123/placement': { body: {} } })
    const { wrapper, router } = await openPage()
    await placeAll(wrapper)

    await click('Finalize Placement')

    expect(sent(api, 'PUT', '/api/readings/abc123/placement')).toMatchObject({
      spread_name: 'My Layout',
    })
    expect(saveDialog()).toBeUndefined()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
  })

  it("shows the server's error when the placement can't be saved", async () => {
    loads(reading(freeDraw), {
      'PUT /readings/abc123/placement': { status: 403, body: { error: 'Not your reading.' } },
    })
    const { wrapper } = await openPage()
    await placeAll(wrapper)

    await click('Finalize Placement')

    expect(toastMessages()).toEqual([['error', 'Not your reading.']])
    expect(saveDialog()).toBeUndefined()
    expect(buttonNamed('Finalize Placement').disabled).toBe(false)
  })

  describe('keeping the layout as a spread', () => {
    async function finalized(extra: Parameters<typeof stubApi>[0] = {}) {
      const api = loads(reading(freeDraw), {
        'PUT /readings/abc123/placement': { body: {} },
        ...extra,
      })
      const page = await openPage()
      await placeAll(page.wrapper)
      await click('Finalize Placement')
      useToasts().toasts.value = []
      return { api, ...page }
    }

    it('needs a name', async () => {
      await finalized()

      expect(buttonNamed('Save Spread').disabled).toBe(true)
      await typeInto('#freedraw-spread-name', '   ')
      expect(buttonNamed('Save Spread').disabled).toBe(true)
    })

    it("submits a guest's layout for review as a public spread", async () => {
      const { api, router } = await finalized({ 'POST /spreads': { status: 201, body: {} } })

      expect(saveDialog()?.textContent).not.toContain('Save As')
      await typeInto('#freedraw-spread-name', '  Two Card Pull ')
      await typeInto('#freedraw-description', ' Left and right. ')
      await click('Save Spread')

      expect(sent(api, 'POST', '/api/spreads')).toEqual({
        name: 'Two Card Pull',
        description: 'Left and right.',
        card_count: 2,
        positions: [
          { order: 1, title: '', x: 40, y: 40, rotation: 0 },
          { order: 2, title: '', x: 45, y: 40, rotation: 0 },
        ],
      })
      expect(toastMessages()).toEqual([['success', 'Spread submitted for review!']])
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
    })

    it("saves a signed-in user's layout to their own spreads", async () => {
      signIn(testUser())
      const { api } = await finalized({
        'POST /account/spreads': { status: 201, body: savedSpread },
      })

      expect(saveDialog()?.textContent).toContain('Saved to your personal collection.')
      await typeInto('#freedraw-spread-name', 'Two Card Pull')
      await click('Save Spread')

      expect(sent(api, 'POST', '/api/account/spreads')).toMatchObject({ name: 'Two Card Pull' })
      expect(toastMessages()).toEqual([['success', 'Spread saved to your personal collection!']])
    })

    it('lets a signed-in user submit it as a public spread instead', async () => {
      signIn(testUser())
      const { api } = await finalized({ 'POST /spreads': { status: 201, body: {} } })

      await click('Public')
      expect(saveDialog()?.textContent).toContain('Submitted for review to become a public spread.')
      await typeInto('#freedraw-spread-name', 'Two Card Pull')
      await click('Save Spread')

      expect(sent(api, 'POST', '/api/spreads')).toMatchObject({ name: 'Two Card Pull' })
    })

    it('says when the spread could not be saved, and still moves on to the reading', async () => {
      const { router } = await finalized({
        'POST /spreads': { status: 422, body: { error: 'A spread needs at least one card.' } },
      })

      await typeInto('#freedraw-spread-name', 'Two Card Pull')
      await click('Save Spread')

      expect(toastMessages()).toEqual([['error', 'A spread needs at least one card.']])
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
    })

    it("says why a signed-in user's spread could not be saved", async () => {
      signIn(testUser())
      await finalized({
        'POST /account/spreads': { status: 400, body: { error: 'Invalid position data.' } },
      })

      await typeInto('#freedraw-spread-name', 'Two Card Pull')
      await click('Save Spread')

      expect(toastMessages()).toEqual([['error', 'Invalid position data.']])
    })

    it('can be skipped, going on to the reading', async () => {
      const { api, router } = await finalized()

      await click('Skip')

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'), lazyLoad)
      expect(api.mock.calls.map(([url]) => url)).not.toContain('/api/spreads')
    })
  })
})
