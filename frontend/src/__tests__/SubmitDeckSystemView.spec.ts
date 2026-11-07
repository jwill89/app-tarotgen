import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import SubmitDeckSystemView from '@/views/SubmitDeckSystemView.vue'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
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
  enterNumber,
  apiCalls,
  cardHeadings,
  nameCards,
} from './helpers/page'
import type { DeckSystemCard } from '@/types'

const blankCard = (card_id: number, name: string): DeckSystemCard => ({
  deck_system_id: 0,
  card_id,
  name,
  keywords: null,
  meaning: null,
  advice: null,
  reversed_keywords: null,
  reversed_meaning: null,
  reversed_advice: null,
})

const errorBox = () => document.body.querySelector('.notification.is-danger')
const submitButton = () => button('Submit Deck System')

describe('SubmitDeckSystemView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser())
  })
  afterEach(cleanup)

  it('asks a signed-out visitor to log in, returning them here after', async () => {
    signIn(null)
    stubApi({})
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })

    expect(document.body.textContent).toContain('You must be logged in to submit a deck system.')
    const link = [...document.body.querySelectorAll('a')].find(
      (a) => a.textContent.trim() === 'logged in',
    )
    expect(link?.getAttribute('href')).toBe('/login?redirect=/submit-deck-system')
    expect(hasButton('Submit Deck System')).toBe(false)
  })

  it('starts with 78 untitled cards and submitting held back until each is named', async () => {
    stubApi({})
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })

    expect(valueOf(labelled('Total Cards'))).toBe('78')
    expect(cardHeadings()).toHaveLength(78)
    expect(cardHeadings()[0]).toBe('1 Untitled Card')
    expect(submitButton().disabled).toBe(true)
    expect(document.body.textContent).toContain('All cards must have a title before submitting.')
  })

  it('grows and shrinks the card list with the total, keeping the names typed', async () => {
    stubApi({})
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })

    await nameCards(['The Fool', 'The Magician', 'The High Priestess'])
    await enterNumber(labelled('Total Cards'), 2)
    expect(cardHeadings()).toEqual(['1 The Fool', '2 The Magician'])

    await enterNumber(labelled('Total Cards'), 4)
    expect(cardHeadings()).toEqual([
      '1 The Fool',
      '2 The Magician',
      '3 Untitled Card',
      '4 Untitled Card',
    ])
    expect(submitButton().disabled).toBe(true)
  })

  it('allows submitting once every card has a name', async () => {
    stubApi({})
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })

    await nameCards(['The Fool', 'The Magician'])

    expect(submitButton().disabled).toBe(false)
    expect(document.body.textContent).not.toContain(
      'All cards must have a title before submitting.',
    )
  })

  it('requires a name and a short name before sending anything', async () => {
    const api = stubApi({})
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })
    await nameCards(['The Fool'])

    await typeInto(labelled('System Name'), 'Marseille')
    await click(submitButton())

    expect(errorBox()?.textContent.trim()).toBe('Name and Short Name are required.')
    expect(api).not.toHaveBeenCalled()
  })

  it('sends the trimmed system with every card, then thanks the submitter', async () => {
    const api = stubApi({ 'POST /deck-systems': { status: 201, body: {} } })
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })

    await typeInto(labelled('System Name'), ' Marseille ')
    await typeInto(labelled('Short Name'), ' TdM ')
    await nameCards(['Le Mat', 'Le Bateleur'])
    const [keywords] = [...document.body.querySelectorAll('label')]
      .filter((l) => l.textContent.trim() === 'Keywords')
      .map((l) => document.getElementById(l.htmlFor))
    if (!keywords) {
      throw new Error('No Keywords field for the first card')
    }
    await typeInto(keywords, 'beginnings')
    await click(submitButton())

    expect(apiCalls(api)).toEqual([
      [
        'POST',
        '/api/deck-systems',
        {
          name: 'Marseille',
          short_name: 'TdM',
          total_cards: 2,
          cards: [
            { ...blankCard(1, 'Le Mat'), keywords: 'beginnings' },
            blankCard(2, 'Le Bateleur'),
          ],
        },
      ],
    ])
    expect(document.body.textContent).toContain(
      'Thank you! Your deck system submission has been received and will be reviewed by an admin.',
    )
    expect(toasts()).toEqual(['Deck system submitted successfully!'])
    expect(hasButton('Submit Deck System')).toBe(false)
  })

  it("keeps the form and shows the server's reason when the submission is refused", async () => {
    stubApi({
      'POST /deck-systems': { status: 400, body: { error: 'A system with that name exists.' } },
    })
    await mountPage(SubmitDeckSystemView, { path: '/submit-deck-system' })
    await typeInto(labelled('System Name'), 'Marseille')
    await typeInto(labelled('Short Name'), 'TdM')
    await nameCards(['Le Mat'])

    await click(submitButton())

    expect(errorBox()?.textContent.trim()).toBe('A system with that name exists.')
    expect(hasButton('Submit Deck System')).toBe(true)
    expect(toasts()).toEqual([])
  })
})
