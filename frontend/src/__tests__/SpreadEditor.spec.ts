import { describe, it, expect, afterEach } from 'vitest'
import { h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import { mountWithApp, cleanup } from './helpers/app'
import { button as bodyButton, choose, labelled, typeInto, valueOf } from './helpers/page'
import type { Spread, SpreadCategory, SpreadPosition } from '@/types'

/** What the editor emits on save, as far as these tests read it. */
interface Saved {
  name: string
  positions: SpreadPosition[]
  category_id?: number
}

const threeCard: Spread = {
  spread_id: 3,
  name: 'Past, Present, Future',
  description: 'A **classic** three-card line.',
  card_count: 3,
  category_id: 2,
  category_name: 'Love & relationships',
  category_sort_order: 2,
  // Out of order on purpose: the editor lays them out by `order`.
  positions: [
    { order: 3, title: 'Future', x: 70, y: 50, rotation: 0 },
    { order: 1, title: 'Past', x: 30, y: 50, rotation: 0, description: 'What led here' },
    { order: 2, title: 'Present', x: 50, y: 50, rotation: 90 },
  ],
}

const categories: SpreadCategory[] = [
  { category_id: 1, name: 'General', sort_order: 1, spread_count: 4 },
  { category_id: 2, name: 'Love & relationships', sort_order: 2, spread_count: 1 },
]

async function mountEditor(
  initial: Spread | null,
  saveLabel?: string,
  categoryList?: SpreadCategory[],
) {
  const spread = ref(initial)
  // The canvas toolbar's tooltips need the provider App.vue mounts.
  const { wrapper } = await mountWithApp(TooltipProvider, {
    slots: {
      default: () => h(SpreadEditor, { spread: spread.value, saveLabel, categories: categoryList }),
    },
  })
  const editor = wrapper.getComponent(SpreadEditor)
  const field = (label: string) => {
    const caption = wrapper.findAll('label').find((l) => l.text().startsWith(label))
    const id = caption?.attributes('for') ?? ''
    return wrapper.get<HTMLInputElement>(`#${CSS.escape(id)}`)
  }
  const button = (name: string) => {
    const found = wrapper
      .findAll('button')
      .find(
        (b) =>
          b.text() === name ||
          b.attributes('aria-label') === name ||
          b.attributes('title') === name,
      )
    if (!found) {
      throw new Error(`No "${name}" button`)
    }
    return found
  }
  const click = async (name: string) => {
    await button(name).trigger('click')
    await flushPromises()
  }
  const error = () => wrapper.find('.notification.is-danger')
  return { wrapper, editor, spread, field, button, click, error }
}

describe('SpreadEditor', () => {
  afterEach(cleanup)

  it('starts a new spread with one card waiting to be placed', async () => {
    const { wrapper, field } = await mountEditor(null)

    expect(field('Spread Name').element.value).toBe('')
    expect(field('Number of Cards').element.value).toBe('1')
    expect(wrapper.findAll('.spread-token').map((t) => t.text())).toEqual(['1'])
  })

  it("loads an existing spread's name, description and positions in order", async () => {
    const { wrapper, field } = await mountEditor(threeCard)

    expect(field('Spread Name').element.value).toBe('Past, Present, Future')
    expect(wrapper.get('textarea').element.value).toBe('A **classic** three-card line.')
    expect(field('Number of Cards').element.value).toBe('3')
    expect(wrapper.findAll('.editor-card-title').map((t) => t.text())).toEqual([
      'Past',
      'Present',
      'Future',
    ])
  })

  it('names the description box by its caption alone, not the preview button', async () => {
    const { wrapper } = await mountEditor(null)

    const box = wrapper.get('textarea')
    const captionId = box.attributes('aria-labelledby') ?? ''
    expect(document.getElementById(captionId)?.textContent).toBe('Description (Markdown)')
  })

  // Sanitizing is renderMarkdown's (markdown.spec.ts).
  it('previews the description as rendered markdown', async () => {
    const { wrapper, click } = await mountEditor(threeCard)

    await click('Preview')

    expect(wrapper.get('.content.box strong').text()).toBe('classic')
    expect(wrapper.find('textarea').exists()).toBe(false)

    await click('Edit')
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('adds and drops cards as the count changes, within 1 to 78', async () => {
    const { wrapper, field } = await mountEditor(null)
    const tray = () => wrapper.findAll('.spread-token').length

    await field('Number of Cards').setValue('4')
    expect(tray()).toBe(4)

    await field('Number of Cards').setValue('2')
    expect(tray()).toBe(2)

    await field('Number of Cards').setValue('200')
    expect(tray()).toBe(78)

    await field('Number of Cards').setValue('0')
    expect(tray()).toBe(1)
  })

  it('will not save without a name', async () => {
    const { editor, click, error } = await mountEditor({ ...threeCard, name: '' })

    await click('Save Spread')

    expect(error().text()).toBe('Please enter a spread name.')
    expect(editor.emitted('save')).toBeUndefined()
  })

  it('will not save until every card is on the layout', async () => {
    const { editor, field, click, error } = await mountEditor(threeCard)

    await field('Number of Cards').setValue('4')
    await click('Save Spread')

    expect(error().text()).toBe('Every card must be placed on the layout before saving.')
    expect(editor.emitted('save')).toBeUndefined()
  })

  it('saves the trimmed name, the description and every position in card order', async () => {
    const { editor, field, button, click, error } = await mountEditor(threeCard)

    await field('Spread Name').setValue('  Three Card Line  ')
    await click('Save Spread')

    expect(error().exists()).toBe(false)
    expect(editor.emitted('save')).toEqual([
      [
        {
          name: 'Three Card Line',
          description: 'A **classic** three-card line.',
          card_count: 3,
          positions: [
            { order: 1, title: 'Past', x: 30, y: 50, rotation: 0, description: 'What led here' },
            { order: 2, title: 'Present', x: 50, y: 50, rotation: 90 },
            { order: 3, title: 'Future', x: 70, y: 50, rotation: 0 },
          ],
        },
      ],
    ])
    expect(button('Save Spread').classes()).toContain('is-loading')
  })

  it('stops saving when the page says it is done, or shows the error it hit', async () => {
    const { editor, button, click, error } = await mountEditor(threeCard)

    await click('Save Spread')
    editor.vm.finishSaving()
    await flushPromises()
    expect(button('Save Spread').classes()).not.toContain('is-loading')

    await click('Save Spread')
    editor.vm.setError('A spread with that name already exists.')
    await flushPromises()
    expect(error().text()).toBe('A spread with that name already exists.')
    expect(button('Save Spread').classes()).not.toContain('is-loading')
  })

  it('takes a custom save label', async () => {
    const { button } = await mountEditor(null, 'Submit for Review')

    expect(button('Submit for Review').exists()).toBe(true)
  })

  it('asks to cancel', async () => {
    const { editor, click } = await mountEditor(threeCard)

    await click('Cancel')

    expect(editor.emitted('cancel')).toHaveLength(1)
  })

  it('titles a position from its edit dialog', async () => {
    const { wrapper, click } = await mountEditor(null)
    await click('Place card 1')

    await click('Edit position…')
    const dialog = document.body.querySelector<HTMLElement>('[role="dialog"]')
    expect(dialog?.textContent).toContain('Position #1')
    const input = dialog?.querySelector<HTMLInputElement>('input')
    if (!input) {
      throw new Error('No position title input')
    }
    input.value = 'The Heart of It'
    input.dispatchEvent(new Event('input'))
    input.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter' }))
    await flushPromises()

    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    expect(wrapper.get('.editor-card-title').text()).toBe('The Heart of It')
  })

  it('describes what a position asks from its edit dialog, trimmed, and saves it', async () => {
    const { editor, field, click } = await mountEditor(null)
    await click('Place card 1')
    await click('Edit position…')

    const about = labelled('What it asks (optional)')
    expect(about.getAttribute('maxlength')).toBe('280')
    expect(
      document.getElementById(about.getAttribute('aria-describedby') ?? '')?.textContent,
    ).toContain('not what a card here would mean. 280 characters left.')
    await typeInto(about, '  What stands in your way  ')
    expect(document.body.textContent).toContain('253 characters left.')
    bodyButton('Done').click()
    await flushPromises()
    await field('Spread Name').setValue('Obstacle')
    await click('Save Spread')

    expect(editor.emitted<[Saved]>('save')?.[0]?.[0].positions).toEqual([
      { order: 1, title: '', x: 40, y: 40, rotation: 0, description: 'What stands in your way' },
    ])
  })

  it("shows a loaded position's description in its dialog, and drops one cleared", async () => {
    const { editor, click } = await mountEditor(threeCard)
    // Select the first card (Past) on the canvas, then edit it.
    const past = [...document.body.querySelectorAll<HTMLElement>('.spread-card--editor')][0]!
    past.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0 }))
    past.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }))
    await flushPromises()
    await click('Edit position…')

    const about = labelled('What it asks (optional)')
    expect(valueOf(about)).toBe('What led here')
    await typeInto(about, '   ')
    bodyButton('Done').click()
    await flushPromises()
    await click('Save Spread')

    const saved = editor.emitted<[Saved]>('save')?.[0]?.[0]
    expect(saved?.positions[0]).toEqual({ order: 1, title: 'Past', x: 30, y: 50, rotation: 0 })
    // Without categories there is no picker, and no category is sent.
    expect(saved && 'category_id' in saved).toBe(false)
  })

  it('picks the library category when given the categories (admin)', async () => {
    const { editor, click } = await mountEditor(threeCard, undefined, categories)

    const picker = labelled('Category')
    expect(picker.textContent.trim()).toBe('Love & relationships')
    await choose(picker, 'General')
    await click('Save Spread')

    expect(editor.emitted('save')?.[0]?.[0]).toMatchObject({ category_id: 1 })
  })

  it('starts a new spread in the first category', async () => {
    const { editor, field, click } = await mountEditor(null, undefined, categories)
    await field('Spread Name').setValue('Fork')
    await click('Place card 1')

    expect(labelled('Category').textContent.trim()).toBe('General')
    await click('Save Spread')
    expect(editor.emitted('save')?.[0]?.[0]).toMatchObject({ name: 'Fork', category_id: 1 })
  })

  // The admin page loads its categories beside the form.
  it('starts a new spread in the first category when the categories arrive after it', async () => {
    const list = ref<SpreadCategory[]>([])
    await mountWithApp(TooltipProvider, {
      slots: { default: () => h(SpreadEditor, { spread: null, categories: list.value }) },
    })
    expect(labelled('Category').textContent.trim()).toBe('Choose a category')

    list.value = categories
    await flushPromises()

    expect(labelled('Category').textContent.trim()).toBe('General')
  })

  it('keeps the card count in step when an undo brings cards back', async () => {
    const { field, click } = await mountEditor(threeCard)

    await field('Number of Cards').setValue('2')
    await flushPromises()
    await click('Undo')

    expect(field('Number of Cards').element.value).toBe('3')
  })

  it('reloads when given another spread', async () => {
    const { spread, field } = await mountEditor(threeCard)

    spread.value = { ...threeCard, spread_id: 4, name: 'Single Card', card_count: 1, positions: [] }
    await flushPromises()

    expect(field('Spread Name').element.value).toBe('Single Card')
    expect(field('Number of Cards').element.value).toBe('1')
  })
})
