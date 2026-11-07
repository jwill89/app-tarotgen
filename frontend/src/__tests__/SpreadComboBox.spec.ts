import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import SpreadComboBox from '@/components/SpreadComboBox.vue'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { mountWithApp, cleanup, signIn, stubApi, testUser } from './helpers/app'
import type { SpreadOption } from '@/types'

const option = (
  id: string,
  name: string,
  card_count: number,
  extra: Partial<SpreadOption> = {},
): SpreadOption => ({
  id,
  name,
  description: '',
  card_count,
  positions: [],
  type: 'public',
  ...extra,
})

const celtic = option('public-1', 'Celtic Cross', 10, { spread_id: 1 })
const threeCard = option('public-2', 'Three Card', 3, { spread_id: 2, isFavorite: true })
const single = option('public-3', 'Single Card', 1, { spread_id: 3 })
const mine = option('user-9', 'My Morning Draw', 4, { type: 'personal', user_spread_id: 9 })
const options = [celtic, threeCard, single, mine]

const FREE_DRAW = 'No Spread (Free Draw)'

/** Every option's text as the list shows it, the free-draw choice included. */
const listed = () =>
  [...document.body.querySelectorAll('[role="option"]')].map(
    (o) => o.querySelector('.myst-combobox__item-name')?.textContent ?? o.textContent.trim(),
  )

async function mountPicker(modelValue: SpreadOption | null = null, list = options) {
  // The star is wrapped in a Tooltip, which needs the provider App.vue mounts.
  const { wrapper } = await mountWithApp(TooltipProvider, {
    slots: { default: () => h(SpreadComboBox, { options: list, modelValue, id: 'spread-picker' }) },
  })
  const picker = wrapper.getComponent(SpreadComboBox)
  return { picker, input: picker.get('input') }
}

async function openList(input: Awaited<ReturnType<typeof mountPicker>>['input']) {
  await input.trigger('keydown', { key: 'ArrowDown' })
  await flushPromises()
}

async function search(
  input: Awaited<ReturnType<typeof mountPicker>>['input'],
  text: string,
): Promise<void> {
  await input.setValue(text)
  await flushPromises()
}

describe('SpreadComboBox', () => {
  beforeEach(() => {
    signIn(null)
    useFavoriteSpreads().favorites.value = []
  })
  afterEach(cleanup)

  it("shows the selected spread's name and puts the id on the input", async () => {
    const { input } = await mountPicker(celtic)

    expect((input.element as HTMLInputElement).value).toBe('Celtic Cross')
    expect(input.attributes('id')).toBe('spread-picker')
  })

  it('shows the free-draw choice when no spread is chosen', async () => {
    const { input } = await mountPicker(null)

    expect((input.element as HTMLInputElement).value).toBe(FREE_DRAW)
  })

  it('lists free draw first, then favourites, my spreads and public spreads', async () => {
    const { input } = await mountPicker()
    await openList(input)

    const groups = [...document.body.querySelectorAll('.myst-combobox__group')].map(
      (g) => g.textContent,
    )
    expect(groups).toEqual(['Favorites', 'My Spreads', 'Public Spreads'])
    expect(listed()).toEqual([
      FREE_DRAW,
      'Three Card',
      'My Morning Draw',
      'Celtic Cross',
      'Single Card',
    ])
  })

  it("groups the public spreads under their library categories, in the categories' order", async () => {
    const inCategory = (o: SpreadOption, category: string, categoryOrder: number) => ({
      ...o,
      category,
      categoryOrder,
    })
    const { input } = await mountPicker(null, [
      inCategory(celtic, 'General', 1),
      inCategory(threeCard, 'General', 1),
      inCategory(single, 'Self-reflection', 5),
      option('public-4', 'Two Hearts', 2, {
        spread_id: 4,
        category: 'Love & relationships',
        categoryOrder: 2,
      }),
      // Given without a category: after the rest.
      option('public-5', 'Untold', 2, { spread_id: 5 }),
      mine,
    ])
    await openList(input)

    const groups = [...document.body.querySelectorAll('.myst-combobox__group')].map(
      (g) => g.textContent,
    )
    expect(groups).toEqual([
      'Favorites',
      'My Spreads',
      'General',
      'Love & relationships',
      'Self-reflection',
      'Public Spreads',
    ])
    // A favourite is listed once, under Favorites.
    expect(listed()).toEqual([
      FREE_DRAW,
      'Three Card',
      'My Morning Draw',
      'Celtic Cross',
      'Two Hearts',
      'Single Card',
      'Untold',
    ])
  })

  it('keeps the category groups while searching, dropping the empty ones', async () => {
    const { input } = await mountPicker(null, [
      { ...celtic, category: 'General', categoryOrder: 1 },
      { ...single, category: 'Self-reflection', categoryOrder: 5 },
    ])

    await search(input, 'single')

    expect(
      [...document.body.querySelectorAll('.myst-combobox__group')].map((g) => g.textContent),
    ).toEqual(['Self-reflection'])
    expect(listed()).toEqual(['Single Card'])
  })

  it('counts the cards in each spread', async () => {
    const { input } = await mountPicker()
    await search(input, 'card')

    const counts = [...document.body.querySelectorAll('.myst-combobox__card-count')].map((c) =>
      c.textContent.trim(),
    )
    expect(counts).toEqual(['3 cards', '1 card'])
  })

  it('filters spreads by name as the user types', async () => {
    const { input } = await mountPicker()

    await search(input, 'CELTIC')
    expect(listed()).toEqual(['Celtic Cross'])

    await search(input, 'card')
    expect(listed()).toEqual(['Three Card', 'Single Card'])
  })

  it('hides the free-draw choice during a search that does not name it', async () => {
    const { input } = await mountPicker(celtic)

    await search(input, 'cross')
    expect(listed()).not.toContain(FREE_DRAW)

    await search(input, 'free')
    expect(listed()).toEqual([FREE_DRAW])
  })

  it('says so when nothing matches', async () => {
    const { input } = await mountPicker()

    await search(input, 'zzz')

    expect(listed()).toEqual([])
    expect(document.body.textContent).toContain('No spreads found.')
  })

  it('picks the first match with Enter and reports it', async () => {
    const { picker, input } = await mountPicker()

    await search(input, 'single')
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(picker.emitted('update:modelValue')?.at(-1)).toEqual([single])
    expect(picker.emitted('free-draw')).toBeUndefined()
  })

  it('reports free draw as null plus a free-draw event when it is picked', async () => {
    const { picker, input } = await mountPicker(celtic)

    await search(input, 'free')
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(picker.emitted('update:modelValue')?.at(-1)).toEqual([null])
    expect(picker.emitted('free-draw')).toHaveLength(1)
  })

  it('clears back to free draw from the clear button', async () => {
    const { picker } = await mountPicker(celtic)

    await picker.get('button[aria-label="Clear spread (free draw)"]').trigger('pointerdown')

    expect(picker.emitted('update:modelValue')?.at(-1)).toEqual([null])
    expect(picker.emitted('free-draw')).toHaveLength(1)
  })

  // The New Draw form's Reset sets the spread back to null from outside.
  it('shows free draw again when its parent clears the spread', async () => {
    const chosen = ref<SpreadOption | null>(null)
    const { wrapper } = await mountWithApp(TooltipProvider, {
      slots: {
        default: () =>
          h(SpreadComboBox, {
            options,
            modelValue: chosen.value,
            'onUpdate:modelValue': (value: SpreadOption | null) => (chosen.value = value),
          }),
      },
    })
    const input = wrapper.get('input')
    const value = () => (input.element as HTMLInputElement).value

    await search(input, 'celtic')
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(value()).toBe('Celtic Cross')

    chosen.value = null
    await flushPromises()
    expect(value()).toBe(FREE_DRAW)
  })

  it('offers no clear button while on free draw', async () => {
    const { picker } = await mountPicker(null)

    expect(picker.find('button[aria-label="Clear spread (free draw)"]').exists()).toBe(false)
  })

  it('shows every spread again when the list reopens after a search', async () => {
    const { input } = await mountPicker()

    await search(input, 'celtic')
    await input.trigger('keydown', { key: 'Escape' })
    await flushPromises()
    await openList(input)

    expect(listed()).toHaveLength(5)
  })

  it('offers no favourite stars to a guest', async () => {
    const { input } = await mountPicker()
    await openList(input)

    expect(document.body.querySelector('.myst-combobox__star')).toBeNull()
  })

  it("toggles a signed-in user's favourite from the star without picking the spread", async () => {
    signIn(testUser())
    const api = stubApi({
      'POST /account/favorites': { status: 201, body: {} },
      'DELETE /account/favorites/public/2': { status: 204 },
    })
    useFavoriteSpreads().favorites.value = [{ spread_type: 'public', spread_id: 2 }]
    const { picker, input } = await mountPicker()
    await openList(input)

    const star = (name: string) =>
      [...document.body.querySelectorAll<HTMLElement>('[role="option"]')]
        .find((o) => o.textContent.includes(name))
        ?.querySelector<HTMLElement>('.myst-combobox__star')

    expect(star('Three Card')?.getAttribute('aria-label')).toBe('Remove from favorites')
    expect(star('Celtic Cross')?.getAttribute('aria-label')).toBe('Add to favorites')

    star('Celtic Cross')?.click()
    await flushPromises()
    star('Three Card')?.click()
    await flushPromises()

    const calls = api.mock.calls.map(([url, init]) => [init?.method, url, init?.body])
    expect(calls).toEqual([
      ['POST', '/api/account/favorites', JSON.stringify({ spread_type: 'public', spread_id: 1 })],
      ['DELETE', '/api/account/favorites/public/2', undefined],
    ])
    expect(picker.emitted('update:modelValue')).toBeUndefined()
  })

  it('favourites a personal spread by its user-spread id', async () => {
    signIn(testUser())
    const api = stubApi({ 'POST /account/favorites': { status: 201, body: {} } })
    const { input } = await mountPicker()
    await search(input, 'morning')

    document.body.querySelector<HTMLElement>('.myst-combobox__star')?.click()
    await flushPromises()

    expect(api.mock.calls[0]![1]?.body).toBe(
      JSON.stringify({ spread_type: 'personal', spread_id: 9 }),
    )
  })
})
