import { describe, it, expect, afterEach, vi } from 'vitest'
import { h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import DeckCardListEditor from '@/components/DeckCardListEditor.vue'
import { emptyCard } from '@/components/deckCards'
import { mountWithApp, cleanup } from './helpers/app'
import type { DeckSystemCard } from '@/types'

function named(cardId: number, name: string): DeckSystemCard {
  return { ...emptyCard(cardId), deck_system_id: 4, name }
}

async function mountEditor(
  cards: DeckSystemCard[],
  props: {
    title?: string
    headingTag?: 'h4' | 'h5'
    maxHeight?: string
    initialOpen?: string[]
  } = {},
) {
  const model = ref(cards)
  const { wrapper } = await mountWithApp({
    render: () =>
      h(
        DeckCardListEditor,
        {
          modelValue: model.value,
          'onUpdate:modelValue': (value: DeckSystemCard[]) => (model.value = value),
          ...props,
        },
        { hint: () => h('p', { class: 'hint' }, 'Name every card.') },
      ),
  })
  const editor = wrapper.getComponent(DeckCardListEditor)
  /** Each card's header: [number, title, needs-a-name] and whether it is open. */
  const rows = () =>
    wrapper.findAll('.deck-card-header').map((t) => ({
      number: t.get('.deck-card-number').text(),
      title: t.get('.deck-card-title').text(),
      nameRequired: t.text().includes('Name required'),
      open: t.attributes('aria-expanded') === 'true',
    }))
  const titleInputs = () => wrapper.findAll('input[placeholder="e.g. The Fool"]')
  const button = (label: string) => {
    const found = wrapper.findAll('button').find((b) => b.text() === label)
    if (!found) {
      throw new Error(`No "${label}" button`)
    }
    return found
  }
  return { wrapper, editor, model, rows, titleInputs, button }
}

describe('DeckCardListEditor', () => {
  afterEach(cleanup)

  it('lists every card, flagging the ones still missing a name', async () => {
    const { rows } = await mountEditor([named(1, 'The Fool'), named(2, '  '), emptyCard(3)])

    expect(rows()).toEqual([
      { number: '1', title: 'The Fool', nameRequired: false, open: true },
      { number: '2', title: 'Untitled Card', nameRequired: true, open: false },
      { number: '3', title: 'Untitled Card', nameRequired: true, open: false },
    ])
  })

  it('titles the list at the heading level the page needs, with its hint below', async () => {
    const { wrapper } = await mountEditor([emptyCard(1)], { title: 'Cards', headingTag: 'h5' })

    expect(wrapper.get('h5').text()).toBe('Cards')
    expect(wrapper.get('.hint').text()).toBe('Name every card.')
  })

  it('defaults to a "Card Definitions" h4', async () => {
    const { wrapper } = await mountEditor([emptyCard(1)])

    expect(wrapper.get('h4').text()).toBe('Card Definitions')
  })

  it('edits the card in the bound array as the user types', async () => {
    const { model, rows, titleInputs, wrapper } = await mountEditor([emptyCard(1), emptyCard(2)])

    await titleInputs()[0]!.setValue('The Magician')
    const keywords = wrapper
      .findAll('input')
      .find((i) => i.attributes('placeholder') === 'Keywords...')
    await keywords?.setValue('skill, will')

    expect(model.value[0]).toMatchObject({ name: 'The Magician', keywords: 'skill, will' })
    expect(rows()[0]).toMatchObject({ title: 'The Magician', nameRequired: false })
  })

  it("labels every one of a card's fields", async () => {
    const { wrapper } = await mountEditor([emptyCard(1)])

    const labelled = wrapper
      .findAll('.deck-card-body input, .deck-card-body textarea')
      .map((field) =>
        wrapper
          .get(`label[for="${field.attributes('id')}"]`)
          .text()
          .replace(' *', ''),
      )
    expect(labelled).toEqual([
      'Card Title',
      'Keywords',
      'Reversed Keywords',
      'Meaning',
      'Reversed Meaning',
      'Advice',
      'Reversed Advice',
    ])
  })

  it('opens and closes a card from its header', async () => {
    const { wrapper, rows } = await mountEditor([emptyCard(1), emptyCard(2)])

    await wrapper.findAll('.deck-card-header')[1]!.trigger('click')
    expect(rows().map((r) => r.open)).toEqual([true, true])

    await wrapper.findAll('.deck-card-header')[0]!.trigger('click')
    expect(rows().map((r) => r.open)).toEqual([false, true])
  })

  it('moves on to the next card with Done, focusing its title', async () => {
    const { rows, titleInputs, button } = await mountEditor([emptyCard(1), emptyCard(2)])

    await button('Done').trigger('click')
    await flushPromises()

    expect(rows().map((r) => r.open)).toEqual([false, true])
    // Reka mounts the newly opened card a frame later; the focus waits for it.
    await vi.waitFor(() => expect(document.activeElement).toBe(titleInputs()[0]!.element))
  })

  it('closes the last card with Done', async () => {
    const { rows, button } = await mountEditor([emptyCard(1), emptyCard(2)], {
      initialOpen: ['1'],
    })

    await button('Done').trigger('click')

    expect(rows().map((r) => r.open)).toEqual([false, false])
  })

  it('expands and collapses every card at once', async () => {
    const { rows, button } = await mountEditor([emptyCard(1), emptyCard(2), emptyCard(3)])

    await button('Expand All').trigger('click')
    expect(rows().map((r) => r.open)).toEqual([true, true, true])

    await button('Collapse All').trigger('click')
    expect(rows().map((r) => r.open)).toEqual([false, false, false])
  })

  it('reveals and focuses the first card missing a name', async () => {
    const { editor, rows, titleInputs } = await mountEditor(
      [named(1, 'The Fool'), named(2, 'The Magician'), emptyCard(3)],
      { initialOpen: [] },
    )

    expect(editor.vm.revealFirstMissing()).toBe(true)
    await flushPromises()

    expect(rows().map((r) => r.open)).toEqual([false, false, true])
    await vi.waitFor(() => expect(document.activeElement).toBe(titleInputs()[0]!.element))
  })

  it('reports that every card is named, so a page can use it as its check', async () => {
    const { editor, rows } = await mountEditor([named(1, 'The Fool'), named(2, 'The Magician')])

    expect(editor.vm.revealFirstMissing()).toBe(false)
    expect(rows().map((r) => r.open)).toEqual([true, false])
  })

  it('scrolls internally at the height given, instead of growing the page', async () => {
    const { wrapper } = await mountEditor([emptyCard(1)], { maxHeight: '600px' })

    const list = wrapper.get('.deck-system-cards').element as HTMLElement
    expect(list.style.getPropertyValue('--deck-cards-max-height')).toBe('600px')
  })
})
