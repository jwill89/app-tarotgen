import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import SpreadCategoryManager from '@/components/admin/SpreadCategoryManager.vue'
import { cleanup, signIn, stubApi, testUser, problem } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  click,
  confirmDialog,
  answerConfirm,
  toasts,
  labelled,
  typeInto,
  valueOf,
  apiCalls,
} from './helpers/page'
import type { SpreadCategory } from '@/types'

const category = (category_id: number, name: string, spread_count = 0): SpreadCategory => ({
  category_id,
  name,
  sort_order: category_id,
  spread_count,
})

const starting = [
  category(1, 'General', 3),
  category(2, 'Love & relationships', 1),
  category(3, 'Career'),
]

/** The manager as the Spread Categories page holds it: the list in, `changed` counted. */
async function mountManager(list: SpreadCategory[] = starting) {
  const changed = ref(0)
  const Host = defineComponent({
    render: () => h(SpreadCategoryManager, { categories: list, onChanged: () => changed.value++ }),
  })
  await mountPage(Host, { path: '/admin/spreads/categories' })
  return { changed }
}

/** Each row as its name and spread count. */
const rows = () =>
  [...document.body.querySelectorAll('.category-row')].map((li) => [
    li.querySelector('.category-row__name')?.textContent,
    li.querySelector('.category-row__count')?.textContent,
  ])

describe('SpreadCategoryManager', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('lists the categories in order with how many spreads each holds', async () => {
    stubApi({})
    await mountManager()

    expect(rows()).toEqual([
      ['General', '3 spreads'],
      ['Love & relationships', '1 spread'],
      ['Career', '0 spreads'],
    ])
  })

  it('adds a category by name, trimmed, and asks the page to reload', async () => {
    const api = stubApi({
      'POST /admin/spread-categories': { status: 201, body: category(4, 'Shadow work') },
    })
    const { changed } = await mountManager()
    const name = labelled('Add a category')
    expect(button('Add').disabled).toBe(true)

    await typeInto(name, '  Shadow work ')
    await click(button('Add'))

    expect(apiCalls(api)).toEqual([
      ['POST', '/api/admin/spread-categories', { name: 'Shadow work' }],
    ])
    expect(toasts()).toEqual(['Added “Shadow work”.'])
    expect(valueOf(name)).toBe('')
    expect(changed.value).toBe(1)
  })

  it("keeps the name and shows the server's reason when adding is refused", async () => {
    stubApi({
      'POST /admin/spread-categories': {
        status: 409,
        body: problem('A spread category with that name already exists.'),
      },
    })
    const { changed } = await mountManager()

    await typeInto(labelled('Add a category'), 'career')
    await click(button('Add'))

    expect(toasts()).toEqual(['A spread category with that name already exists.'])
    expect(valueOf(labelled('Add a category'))).toBe('career')
    expect(changed.value).toBe(0)
  })

  it('renames a category in place', async () => {
    const api = stubApi({
      'PATCH /admin/spread-categories/2': { body: { ...category(2, 'Love'), spread_count: 1 } },
    })
    const { changed } = await mountManager()

    await click(button('Rename Love & relationships'))
    const input = labelled('New name for Love & relationships')
    expect(document.activeElement).toBe(input)
    expect(valueOf(input)).toBe('Love & relationships')
    await typeInto(input, ' Love ')
    await click(button('Save'))

    expect(apiCalls(api)).toEqual([['PATCH', '/api/admin/spread-categories/2', { name: 'Love' }]])
    expect(toasts()).toEqual(['Category renamed.'])
    expect(changed.value).toBe(1)
  })

  it('sends nothing for an unchanged or blank name, and Escape cancels', async () => {
    const api = stubApi({})
    await mountManager()

    await click(button('Rename Career'))
    await click(button('Save'))
    await click(button('Rename Career'))
    await typeInto(labelled('New name for Career'), '  ')
    await click(button('Save'))
    await click(button('Rename Career'))
    labelled('New name for Career').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()

    expect(api).not.toHaveBeenCalled()
    expect(document.body.querySelector('.category-row__rename')).toBeNull()
  })

  it('moves a category up or down by sending the whole new order', async () => {
    const api = stubApi({ 'PUT /admin/spread-categories/order': { body: starting } })
    const { changed } = await mountManager()

    expect(button('Move General up').disabled).toBe(true)
    expect(button('Move Career down').disabled).toBe(true)
    await click(button('Move Career up'))
    await click(button('Move General down'))

    expect(apiCalls(api)).toEqual([
      ['PUT', '/api/admin/spread-categories/order', { category_ids: [1, 3, 2] }],
      ['PUT', '/api/admin/spread-categories/order', { category_ids: [2, 1, 3] }],
    ])
    expect(changed.value).toBe(2)
  })

  it('removes an empty category once confirmed, and nothing on Cancel', async () => {
    const api = stubApi({ 'DELETE /admin/spread-categories/3': { status: 204 } })
    const { changed } = await mountManager()

    await click(button('Remove Career'))
    expect(confirmDialog()?.textContent).toContain('Remove “Career” from the spread library?')
    await answerConfirm('cancel')
    expect(api).not.toHaveBeenCalled()

    await click(button('Remove Career'))
    await answerConfirm('confirm')

    expect(apiCalls(api)).toEqual([['DELETE', '/api/admin/spread-categories/3']])
    expect(toasts()).toEqual(['Category removed.'])
    expect(changed.value).toBe(1)
  })

  it('offers no removal while a category holds spreads, or for the last one', async () => {
    stubApi({})
    await mountManager()

    expect(button('Move its 3 spreads to another category to remove it').disabled).toBe(true)
    expect(button('Move its 1 spread to another category to remove it').disabled).toBe(true)

    await cleanup()
    await mountManager([category(1, 'General')])
    expect(button('Remove General').disabled).toBe(true)
  })
})
