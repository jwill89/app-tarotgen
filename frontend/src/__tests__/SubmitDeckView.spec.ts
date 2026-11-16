import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SubmitDeckView from '@/views/SubmitDeckView.vue'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { cleanup, stubApi, signIn, testUser, problem } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  toasts,
  labelled,
  typeInto,
  choose,
  apiCalls,
} from './helpers/page'
import type { DeckSystem } from '@/types'

const system = (deck_system_id: number, name: string, short_name: string): DeckSystem => ({
  deck_system_id,
  name,
  short_name,
  total_cards: 78,
  approved: true,
  submitted_by: null,
})

// RWS second, so "RWS is the default system" can't pass by being first.
const systems = [system(2, 'Thoth', 'Thoth'), system(1, 'Rider-Waite-Smith', 'RWS')]

const errorBox = () => document.body.querySelector('.notification.is-danger')

async function fillRequired(): Promise<void> {
  await typeInto(labelled('Deck Name'), '  Wild Unknown  ')
  await typeInto(labelled('Artist'), ' Kim Krans ')
}

describe('SubmitDeckView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser())
  })
  afterEach(cleanup)

  it('asks a signed-out visitor to log in, returning them here after', async () => {
    signIn(null)
    stubApi({ 'GET /deck-systems': { body: systems } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    expect(document.body.textContent).toContain('You must be logged in to submit a deck.')
    const link = [...document.body.querySelectorAll('a')].find(
      (a) => a.textContent.trim() === 'logged in',
    )
    expect(link?.getAttribute('href')).toBe('/login?redirect=/submit-deck')
    expect(hasButton('Submit Deck')).toBe(false)
  })

  it('offers the approved deck systems and starts on RWS', async () => {
    stubApi({ 'GET /deck-systems': { body: systems } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    expect(labelled('Deck System').textContent.trim()).toBe('Rider-Waite-Smith — 78 cards')
  })

  it('starts on the first system when there is no RWS', async () => {
    stubApi({ 'GET /deck-systems': { body: [system(2, 'Thoth', 'Thoth')] } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    expect(labelled('Deck System').textContent.trim()).toBe('Thoth — 78 cards')
  })

  it("says so when the deck systems can't be loaded", async () => {
    stubApi({ 'GET /deck-systems': { status: 500, body: problem('Database error') } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    expect(errorBox()?.textContent.trim()).toBe(
      "Couldn't load the deck systems. Please reload the page.",
    )
  })

  it('requires a name and an artist before sending anything', async () => {
    const api = stubApi({ 'GET /deck-systems': { body: systems } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })
    api.mockClear()

    await typeInto(labelled('Deck Name'), 'Wild Unknown')
    await typeInto(labelled('Artist'), '   ')
    await click(button('Submit Deck'))

    expect(errorBox()?.textContent.trim()).toBe('Name and Artist are required.')
    expect(api).not.toHaveBeenCalled()
  })

  it('requires a deck system when none could be offered', async () => {
    const api = stubApi({ 'GET /deck-systems': { body: [] } })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })
    api.mockClear()

    await fillRequired()
    await click(button('Submit Deck'))

    expect(errorBox()?.textContent.trim()).toBe('Please select a deck system.')
    expect(api).not.toHaveBeenCalled()
  })

  it('sends the trimmed deck for review and thanks the submitter', async () => {
    const api = stubApi({
      'GET /deck-systems': { body: systems },
      'POST /decks': { status: 201, body: {} },
    })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    await fillRequired()
    await choose(labelled('Deck System'), 'Thoth — 78 cards')
    await typeInto(labelled('Additional Cards (optional)'), '2')
    await typeInto(labelled('Purchase URL (optional)'), ' https://example.test/wild ')
    api.mockClear()
    await click(button('Submit Deck'))

    expect(apiCalls(api)).toEqual([
      [
        'POST',
        '/api/decks',
        {
          name: 'Wild Unknown',
          artist: 'Kim Krans',
          purchase_url: 'https://example.test/wild',
          deck_system_id: 2,
          additional_cards: 2,
        },
      ],
    ])
    expect(document.body.textContent).toContain(
      'Thank you! Your deck submission has been received and will be reviewed by an admin.',
    )
    expect(toasts()).toEqual(['Deck submitted! It will be reviewed by an admin.'])
    expect(hasButton('Submit Deck')).toBe(false)
  })

  it("keeps the form and shows the server's reason when the submission is refused", async () => {
    stubApi({
      'GET /deck-systems': { body: systems },
      'POST /decks': { status: 429, body: problem('Too many submissions. Try again later.') },
    })
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    await fillRequired()
    await click(button('Submit Deck'))

    expect(errorBox()?.textContent.trim()).toBe('Too many submissions. Try again later.')
    expect(hasButton('Submit Deck')).toBe(true)
    expect(toasts()).toEqual([])
  })

  it('says the network is down when the submission never arrives', async () => {
    const fetch = vi.fn(async (input: string) => {
      if (input === '/api/deck-systems') {
        return new Response(JSON.stringify(systems), {
          headers: { 'Content-Type': 'application/json' },
        })
      }
      throw new TypeError('Failed to fetch')
    })
    vi.stubGlobal('fetch', fetch)
    await mountPage(SubmitDeckView, { path: '/submit-deck' })

    await fillRequired()
    await click(button('Submit Deck'))

    expect(errorBox()?.textContent.trim()).toBe(NETWORK_ERROR_MESSAGE)
  })
})
