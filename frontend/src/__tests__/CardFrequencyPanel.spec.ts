import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import CardFrequencyPanel from '@/components/CardFrequencyPanel.vue'
import { useDecks } from '@/composables/useDecks'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'
import type { CardCount, CardFrequency, Deck } from '@/types'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const panelText = () => text(document.body)
const rows = () =>
  [...document.body.querySelectorAll('.frequency__row')].map((li) => ({
    name: text(li.querySelector('.frequency__card') ?? li),
    deck: li.querySelector('.frequency__deck')?.textContent.trim() ?? null,
    count: text(li.querySelector('.frequency__count strong') ?? li),
    split: text(li.querySelector('.frequency__split') ?? li),
  }))

function button(name: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find((b) => text(b) === name)
  if (!found) {
    throw new Error(`No "${name}" button`)
  }
  return found
}

const deck = (deck_id: number, name: string): Deck => ({
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
})

const card = (card_name: string, count: number, extra: Partial<CardCount> = {}): CardCount => ({
  card_name,
  card_id: null,
  deck_id: null,
  count,
  upright: count,
  reversed: 0,
  ...extra,
})

const frequency = (cards: CardCount[], extra: Partial<CardFrequency> = {}): CardFrequency => ({
  readings: cards.length,
  deck_id: null,
  days: null,
  cards,
  ...extra,
})

/** Serve the decks and the counts; `answer` gets the query each count request sent. */
function serve(answer: (query: URLSearchParams) => { status?: number; body: unknown }) {
  const queries: string[] = []
  const api = stubApi({
    'GET /decks': { body: [deck(1, 'Rider-Waite'), deck(2, 'Thoth')] },
    'GET /account/card-frequency': (_body, url) => {
      queries.push(url.search)
      return answer(url.searchParams)
    },
  })
  return { api, queries }
}

describe('CardFrequencyPanel', () => {
  beforeEach(() => {
    signIn(testUser())
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
  })
  afterEach(cleanup)

  it('lists the cards drawn most, with how often each came upright and reversed', async () => {
    const { queries } = serve(() => ({
      body: frequency(
        [
          card('The Tower', 3, { upright: 2, reversed: 1 }),
          card('Card #79', 1, { card_id: 79, deck_id: 2 }),
        ],
        { readings: 3 },
      ),
    }))

    await mountWithApp(CardFrequencyPanel)

    expect(queries).toEqual([''])
    expect(panelText()).toContain('4 cards across 3 readings')
    expect(rows()).toEqual([
      { name: 'The Tower', deck: null, count: '3', split: '2 upright · 1 reversed' },
      // An unnamed card counted across decks says which deck it's from.
      { name: 'Card #79', deck: 'Thoth', count: '1', split: '1 upright · 0 reversed' },
    ])
    // The bar is proportional to the card drawn most, split upright/reversed.
    const fills = [...document.body.querySelectorAll<HTMLElement>('.frequency__fill')]
    expect(fills.map((f) => f.style.width)).toEqual(['100%', '33.33333333333333%'])
  })

  it('shows the top ten, and all of them on request', async () => {
    const many = Array.from({ length: 12 }, (_, i) => card(`Card ${String(i + 1)}`, 12 - i))
    serve(() => ({ body: frequency(many) }))
    await mountWithApp(CardFrequencyPanel)

    expect(rows()).toHaveLength(10)
    const more = button('Show all 12 cards')
    expect(more.getAttribute('aria-expanded')).toBe('false')

    more.click()
    await flushPromises()
    expect(rows()).toHaveLength(12)

    button('Show the top 10').click()
    await flushPromises()
    expect(rows()).toHaveLength(10)
  })

  it('counts over a window and a deck when they are chosen', async () => {
    const { queries } = serve((query) => ({
      body: frequency(
        query.get('deck_id') === '2'
          ? [card('The Lovers', 1, { card_id: 6, deck_id: 2 })]
          : [card('The Star', 1)],
      ),
    }))
    await mountWithApp(CardFrequencyPanel)

    button('90 days').click()
    await flushPromises()
    expect(queries.at(-1)).toBe('?days=90')

    const trigger = document.body.querySelector<HTMLElement>('button[role="combobox"]')
    trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()
    const thoth = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
      (o) => text(o) === 'Thoth',
    )
    thoth?.focus()
    thoth?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
    await flushPromises()

    expect(queries.at(-1)).toBe('?deck_id=2&days=90')
    // Within one deck a card needs no deck beside it.
    expect(rows()).toEqual([
      { name: 'The Lovers', deck: null, count: '1', split: '1 upright · 0 reversed' },
    ])

    button('All time').click()
    await flushPromises()
    expect(queries.at(-1)).toBe('?deck_id=2')
  })

  it('invites a first reading when nothing is drawn yet', async () => {
    serve(() => ({ body: frequency([], { readings: 0 }) }))
    await mountWithApp(CardFrequencyPanel)

    expect(panelText()).toContain(
      'Draw a few readings and your most frequent cards will show here.',
    )
    expect(rows()).toEqual([])
  })

  it('says when no reading matches the filters chosen', async () => {
    serve((query) => ({
      body: query.has('days') ? frequency([], { readings: 0 }) : frequency([card('The Star', 1)]),
    }))
    await mountWithApp(CardFrequencyPanel)

    button('30 days').click()
    await flushPromises()

    expect(panelText()).toContain(
      'None of your readings match. Try another deck or a longer period.',
    )
    expect(panelText()).not.toContain('Draw a few readings')
  })

  it("shows the server's message when the count fails, and tries again", async () => {
    let fail = true
    serve(() =>
      fail
        ? { status: 500, body: { error: 'Database is resting.' } }
        : { body: frequency([card('The Moon', 2)]) },
    )
    await mountWithApp(CardFrequencyPanel)

    expect(panelText()).toContain("Couldn't count your cards. Database is resting.")

    fail = false
    button('Try again').click()
    await flushPromises()

    expect(panelText()).not.toContain("Couldn't count your cards.")
    expect(rows().map((r) => r.name)).toEqual(['The Moon'])
  })

  it('names every filter control', async () => {
    serve(() => ({ body: frequency([]) }))
    await mountWithApp(CardFrequencyPanel)

    const label = [...document.body.querySelectorAll('label')].find((l) => text(l) === 'Deck')
    expect(document.getElementById(label?.htmlFor ?? '')?.getAttribute('role')).toBe('combobox')
    const group = document.body.querySelector('.myst-segmented')
    const caption = document.getElementById(group?.getAttribute('aria-labelledby') ?? '')
    expect(caption?.textContent).toBe('Period')
  })
})
