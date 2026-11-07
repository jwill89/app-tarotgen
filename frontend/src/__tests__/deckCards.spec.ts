import { describe, it, expect } from 'vitest'
import { emptyCard, resizeCards, missingNameCount, allCardsNamed } from '@/components/deckCards'

describe('emptyCard', () => {
  it('numbers the card and leaves every optional field null', () => {
    expect(emptyCard(7)).toEqual({
      deck_system_id: 0,
      card_id: 7,
      name: '',
      keywords: null,
      meaning: null,
      advice: null,
      reversed_keywords: null,
      reversed_meaning: null,
      reversed_advice: null,
    })
  })
})

describe('resizeCards', () => {
  it('seeds an empty list with sequential card ids', () => {
    const cards = resizeCards([], 78)
    expect(cards).toHaveLength(78)
    expect(cards[0]!.card_id).toBe(1)
    expect(cards[77]!.card_id).toBe(78)
  })

  it('grows without disturbing the rows already typed', () => {
    const cards = resizeCards([], 2)
    cards[0]!.name = 'The Fool'
    cards[1]!.name = 'The Magician'

    const grown = resizeCards(cards, 4)

    expect(grown).toHaveLength(4)
    expect(grown[0]!.name).toBe('The Fool')
    expect(grown[1]!.name).toBe('The Magician')
    expect(grown[2]!.name).toBe('')
    expect(grown[3]!.card_id).toBe(4)
  })

  it('preserves object identity when growing, so open inputs are not remounted', () => {
    const cards = resizeCards([], 2)
    const first = cards[0]
    expect(resizeCards(cards, 5)[0]).toBe(first)
  })

  it('truncates from the end when shrinking', () => {
    const cards = resizeCards([], 5)
    cards[0]!.name = 'kept'
    const shrunk = resizeCards(cards, 2)
    expect(shrunk).toHaveLength(2)
    expect(shrunk[0]!.name).toBe('kept')
  })

  it('never drops below a single row', () => {
    expect(resizeCards(resizeCards([], 5), 0)).toHaveLength(1)
    expect(resizeCards(resizeCards([], 5), -3)).toHaveLength(1)
  })

  it('does not mutate the array it is given', () => {
    const cards = resizeCards([], 3)
    resizeCards(cards, 1)
    expect(cards).toHaveLength(3)
  })
})

describe('missingNameCount / allCardsNamed', () => {
  it('counts blank and whitespace-only names as missing', () => {
    const cards = resizeCards([], 3)
    cards[0]!.name = 'The Fool'
    cards[1]!.name = '   '
    expect(missingNameCount(cards)).toBe(2)
    expect(allCardsNamed(cards)).toBe(false)
  })

  it('is satisfied once every card has a non-blank name', () => {
    const cards = resizeCards([], 2)
    cards[0]!.name = 'The Fool'
    cards[1]!.name = 'The Magician'
    expect(missingNameCount(cards)).toBe(0)
    expect(allCardsNamed(cards)).toBe(true)
  })

  it('treats an empty list as not-named (nothing to submit)', () => {
    expect(allCardsNamed([])).toBe(false)
  })
})
