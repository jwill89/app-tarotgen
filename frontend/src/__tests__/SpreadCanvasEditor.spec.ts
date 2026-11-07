import { describe, it, expect, afterEach, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, type DOMWrapper } from '@vue/test-utils'
import SpreadCanvasEditor from '@/components/SpreadCanvasEditor.vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'
import { mountWithApp, cleanup } from './helpers/app'
import { pointer } from './helpers/page'

const slot = (title: string, extra: Partial<SpreadSlotBase> = {}): SpreadSlotBase => ({
  title,
  x: 50,
  y: 50,
  rotation: 0,
  placed: false,
  ...extra,
})

// The editor's tooltips need a TooltipProvider (App.vue mounts one); they aren't
// what these tests are about, so each just renders the control it wraps.
const TooltipPassThrough = defineComponent({
  setup:
    (_, { slots }) =>
    () =>
      slots.default?.(),
})

async function mountCanvas(initial: SpreadSlotBase[]) {
  const slots = ref(initial)
  const restores: number[] = []
  const { wrapper } = await mountWithApp(SpreadCanvasEditor<SpreadSlotBase>, {
    props: {
      modelValue: slots.value,
      onRestore: (length: number) => restores.push(length),
    },
    slots: {
      card: ({ item }: { item: SpreadSlotBase }) => h('span', { class: 'title' }, item.title),
      'edit-modal': ({
        item,
        index,
        close,
      }: {
        item: SpreadSlotBase | null
        index: number | null
        close: () => void
      }) =>
        item
          ? h('div', { role: 'dialog', 'aria-label': `Position #${String((index ?? 0) + 1)}` }, [
              h('input', {
                'aria-label': 'Position title',
                value: item.title,
                onInput: (e: Event) => (item.title = (e.target as HTMLInputElement).value),
              }),
              h('button', { type: 'button', onClick: close }, 'Done'),
            ])
          : null,
    },
    global: { stubs: { Tooltip: TooltipPassThrough } },
  })

  // jsdom lays nothing out: give the canvas a size so drags map to percentages.
  const canvas = wrapper.get('.spread-canvas').element
  vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(
    DOMRect.fromRect({ x: 0, y: 0, width: 400, height: 200 }),
  )

  const button = (name: string): DOMWrapper<HTMLButtonElement> => {
    const found = wrapper
      .findAll<HTMLButtonElement>('button')
      .find(
        (b) =>
          b.attributes('aria-label') === name ||
          b.text() === name ||
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
  const cards = () => wrapper.findAll('.spread-card')
  const card = (n: number) => {
    const found = cards().find((c) => c.get('.spread-order-badge').text() === String(n))
    if (!found) {
      throw new Error(`Card ${String(n)} is not on the canvas`)
    }
    return found
  }
  const tray = () => wrapper.findAll('.spread-token').map((t) => t.text())
  const selected = () => wrapper.get('.spread-tools-hint').text()
  return { wrapper, slots, restores, button, click, cards, card, tray, selected }
}

type Canvas = Awaited<ReturnType<typeof mountCanvas>>

const primary = { button: 0, pointerId: 1 }

async function drag(canvas: Canvas, n: number, to: { clientX: number; clientY: number }) {
  const card = canvas.card(n)
  await pointer(card, 'pointerdown', primary)
  await pointer(card, 'pointermove', { ...primary, ...to })
  await pointer(card, 'pointerup', primary)
  await flushPromises()
}

async function placeAll(canvas: Canvas): Promise<void> {
  while (canvas.tray().length > 0) {
    await canvas.click(`Place card ${canvas.tray()[0]}`)
  }
}

describe('SpreadCanvasEditor', () => {
  afterEach(cleanup)

  it('holds unplaced cards in a labelled tray and placed ones on the canvas', async () => {
    const { wrapper, tray, cards } = await mountCanvas([
      slot('Past', { placed: true, x: 20, y: 30, rotation: 90 }),
      slot('Present'),
      slot('Future'),
    ])

    const group = wrapper.get('[role="group"]')
    const captionId = group.attributes('aria-labelledby') ?? ''
    expect(document.getElementById(captionId)?.textContent).toContain('Unplaced Cards')
    expect(tray()).toEqual(['2', '3'])
    expect(cards().map((c) => c.get('.title').text())).toEqual(['Past'])
    const style = (cards()[0]!.element as HTMLElement).style
    expect([style.left, style.top, style.getPropertyValue('--rotation')]).toEqual([
      '20%',
      '30%',
      '90deg',
    ])
  })

  it('places a card from the tray, cascading each one so they do not stack', async () => {
    const canvas = await mountCanvas([slot('Past'), slot('Present')])

    await canvas.click('Place card 1')
    await canvas.click('Place card 2')

    expect(canvas.tray()).toEqual([])
    expect(canvas.wrapper.text()).toContain('All cards placed.')
    expect(canvas.slots.value.map(({ x, y, placed }) => ({ x, y, placed }))).toEqual([
      { x: 40, y: 40, placed: true },
      { x: 45, y: 40, placed: true },
    ])
  })

  it('selects a card as it is placed and offers controls for it alone', async () => {
    const canvas = await mountCanvas([slot('Past'), slot('Present')])

    await canvas.click('Place card 1')

    expect(canvas.selected()).toBe('1 selected')
    expect(canvas.button('Rotate right').exists()).toBe(true)
  })

  it('rotates the selected card in 15° steps, both ways round', async () => {
    const canvas = await mountCanvas([slot('Past')])
    await canvas.click('Place card 1')

    await canvas.click('Rotate right')
    await canvas.click('Rotate right')
    expect(canvas.slots.value[0]!.rotation).toBe(30)

    await canvas.click('Rotate left')
    await canvas.click('Rotate left')
    await canvas.click('Rotate left')
    expect(canvas.slots.value[0]!.rotation).toBe(345)
  })

  it('sends a card back to the tray', async () => {
    const canvas = await mountCanvas([slot('Past')])
    await canvas.click('Place card 1')

    await canvas.click('Remove from layout')

    expect(canvas.tray()).toEqual(['1'])
    expect(canvas.cards()).toHaveLength(0)
    expect(canvas.selected()).toBe('0 selected')
  })

  it('drags a card to where the pointer goes, snapping to the grid', async () => {
    const canvas = await mountCanvas([slot('Past', { placed: true })])

    // 101 px of 400 is 25.25%, 151 px of 200 is 75.5%: the 2.5% grid makes them 25 and 75.
    await drag(canvas, 1, { clientX: 101, clientY: 151 })

    expect(canvas.slots.value[0]).toMatchObject({ x: 25, y: 75 })
  })

  it('drags freely with the grid off', async () => {
    const canvas = await mountCanvas([slot('Past', { placed: true })])
    await canvas.wrapper.get('[role="switch"]').trigger('click')

    await drag(canvas, 1, { clientX: 101, clientY: 151 })

    expect(canvas.slots.value[0]).toMatchObject({ x: 25.25, y: 75.5 })
  })

  it('undoes and redoes each change, a whole drag as one step', async () => {
    const canvas = await mountCanvas([slot('Past'), slot('Present')])
    expect(canvas.button('Undo').attributes('disabled')).toBeDefined()

    await canvas.click('Place card 1')
    await drag(canvas, 1, { clientX: 100, clientY: 100 })
    expect(canvas.slots.value[0]).toMatchObject({ x: 25, y: 50, placed: true })

    await canvas.click('Undo')
    expect(canvas.slots.value[0]).toMatchObject({ x: 40, y: 40, placed: true })

    // The very first change after loading can be undone too.
    await canvas.click('Undo')
    expect(canvas.slots.value[0]).toMatchObject({ placed: false })
    expect(canvas.tray()).toEqual(['1', '2'])
    expect(canvas.button('Undo').attributes('disabled')).toBeDefined()

    await canvas.click('Redo')
    await canvas.click('Redo')
    expect(canvas.slots.value[0]).toMatchObject({ x: 25, y: 50, placed: true })
    expect(canvas.button('Redo').attributes('disabled')).toBeDefined()
    expect(canvas.restores).toEqual([2, 2, 2, 2])
  })

  it('adds and removes cards from the selection with shift-click, and clears it on the background', async () => {
    const canvas = await mountCanvas([slot('A'), slot('B'), slot('C')])
    await placeAll(canvas)

    await pointer(canvas.card(1), 'pointerdown', { ...primary, shiftKey: true })
    await pointer(canvas.card(2), 'pointerdown', { ...primary, shiftKey: true })
    expect(canvas.selected()).toBe('3 selected')
    // With several selected, the single-card controls step aside.
    expect(canvas.wrapper.find('.card-controls').exists()).toBe(false)

    await pointer(canvas.card(2), 'pointerdown', { ...primary, ctrlKey: true })
    expect(canvas.selected()).toBe('2 selected')

    const viewport = canvas.wrapper.get('.spread-canvas-viewport')
    await pointer(viewport, 'pointerdown', { ...primary, clientX: 5, clientY: 5 })
    await pointer(viewport, 'pointerup', { ...primary, clientX: 5, clientY: 5 })
    expect(canvas.selected()).toBe('0 selected')
  })

  it('aligns two or more selected cards, and spaces three or more evenly', async () => {
    const canvas = await mountCanvas([
      slot('A', { placed: true, x: 10, y: 20 }),
      slot('B', { placed: true, x: 30, y: 60 }),
      slot('C', { placed: true, x: 80, y: 40 }),
    ])
    expect(canvas.button('Align left edges').attributes('disabled')).toBeDefined()

    await pointer(canvas.card(1), 'pointerdown', { ...primary, shiftKey: true })
    await pointer(canvas.card(2), 'pointerdown', { ...primary, shiftKey: true })
    expect(canvas.button('Equal horizontal spacing').attributes('disabled')).toBeDefined()
    await canvas.click('Align left edges')
    expect(canvas.slots.value.map((s) => s.x)).toEqual([10, 10, 80])

    await pointer(canvas.card(3), 'pointerdown', { ...primary, shiftKey: true })
    await canvas.click('Equal vertical spacing')
    expect(canvas.slots.value.map((s) => s.y)).toEqual([20, 60, 40])
  })

  it('centres the whole layout in the panel, keeping the spacing', async () => {
    const canvas = await mountCanvas([
      slot('A', { placed: true, x: 10, y: 10 }),
      slot('B', { placed: true, x: 30, y: 20 }),
    ])

    await canvas.click('Center')

    expect(canvas.slots.value.map(({ x, y }) => [x, y])).toEqual([
      [40, 45],
      [60, 55],
    ])
  })

  it('zooms the canvas between 100% and the maximum', async () => {
    const canvas = await mountCanvas([slot('A')])
    const level = () =>
      canvas.wrapper
        .findAll('button')
        .find((b) => b.text().endsWith('%'))
        ?.text()

    expect(canvas.button('Zoom out').attributes('disabled')).toBeDefined()
    await canvas.click('Zoom in')
    expect(level()).toBe('125%')

    for (let i = 0; i < 10; i++) {
      await canvas.click('Zoom in')
    }
    expect(level()).toBe('300%')
    expect(canvas.button('Zoom in').attributes('disabled')).toBeDefined()

    await canvas.click('300%')
    expect(level()).toBe('100%')
  })

  it("hands the caller's edit form the card to edit, and keeps the whole edit as one undo step", async () => {
    const canvas = await mountCanvas([slot('Past')])
    await canvas.click('Place card 1')

    await canvas.click('Edit title…')
    const form = canvas.wrapper.get('[role="dialog"]')
    expect(form.attributes('aria-label')).toBe('Position #1')
    await form.get('input').setValue('The Past')
    await form.get('input').setValue('The Distant Past')
    await form.get('button').trigger('click')
    await flushPromises()

    expect(canvas.wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(canvas.card(1).get('.title').text()).toBe('The Distant Past')

    await canvas.click('Undo')
    expect(canvas.slots.value[0]!.title).toBe('Past')
  })

  it('offers the card actions from a right-click menu', async () => {
    const canvas = await mountCanvas([slot('A', { placed: true }), slot('B', { placed: true })])

    await canvas.card(2).trigger('contextmenu', { clientX: 10, clientY: 10 })
    await flushPromises()

    const items = [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')]
    expect(items.map((i) => i.textContent.trim())).toEqual([
      'Rotate left',
      'Rotate right',
      'Edit title…',
      'Remove from layout',
    ])
    // Opening the menu selects the card it is for.
    expect(canvas.selected()).toBe('1 selected')

    items[1]!.click()
    await flushPromises()
    expect(canvas.slots.value.map((s) => s.rotation)).toEqual([0, 15])
  })

  async function openMenuOn(canvas: Canvas, n: number): Promise<void> {
    await canvas.card(n).trigger('contextmenu', { clientX: 10, clientY: 10 })
    await flushPromises()
  }
  const menuItem = (label: string) =>
    [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')].find(
      (i) => i.textContent.trim() === label,
    )

  it("opens a card's edit form from the right-click menu", async () => {
    const canvas = await mountCanvas([slot('A', { placed: true }), slot('B', { placed: true })])

    await openMenuOn(canvas, 2)
    menuItem('Edit title…')?.click()
    await flushPromises()

    expect(canvas.wrapper.get('[role="dialog"]').attributes('aria-label')).toBe('Position #2')
  })

  it('sends a card back to the tray from the right-click menu', async () => {
    const canvas = await mountCanvas([slot('A', { placed: true }), slot('B', { placed: true })])

    await openMenuOn(canvas, 2)
    menuItem('Remove from layout')?.click()
    await flushPromises()

    expect(canvas.tray()).toEqual(['2'])
  })

  it('does not drag or select on a right-button press, which is for the menu', async () => {
    const canvas = await mountCanvas([slot('A', { placed: true, x: 50, y: 50 })])

    const card = canvas.card(1)
    await pointer(card, 'pointerdown', { button: 2, pointerId: 1 })
    await pointer(card, 'pointermove', { button: 2, pointerId: 1, clientX: 0, clientY: 0 })

    expect(canvas.selected()).toBe('0 selected')
    expect(canvas.slots.value[0]).toMatchObject({ x: 50, y: 50 })
  })
})
