import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SpreadCategoriesView from '@/views/admin/SpreadCategoriesView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  click,
  answerConfirm,
  toasts,
  labelled,
  link,
  pageTitle,
  typeInto,
  apiCalls,
} from './helpers/page'
import { categories } from './helpers/spreads'

/** Each category row as its name and spread count. */
const rows = () =>
  [...document.body.querySelectorAll('.category-row')].map((li) => [
    li.querySelector('.category-row__name')?.textContent,
    li.querySelector('.category-row__count')?.textContent,
  ])

describe('admin SpreadCategoriesView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('lists the categories in order with how many spreads each holds', async () => {
    const api = stubApi({ 'GET /admin/spread-categories': { body: categories } })
    await mountPage(SpreadCategoriesView, { path: '/admin/spreads/categories' })

    expect(apiCalls(api)).toEqual([['GET', '/api/admin/spread-categories']])
    expect(pageTitle()).toBe('Spread Categories')
    expect(document.body.textContent).toContain(
      'A new or approved spread goes into the first unless you choose another.',
    )
    expect(rows()).toEqual([
      ['General', '2 spreads'],
      ['Love & relationships', '1 spread'],
      ['Career', '0 spreads'],
    ])
  })

  it('leads back to the spread list', async () => {
    stubApi({ 'GET /admin/spread-categories': { body: categories } })
    await mountPage(SpreadCategoriesView, { path: '/admin/spreads/categories' })

    expect(link('Back to Spreads').getAttribute('href')).toBe('/admin/spreads')
  })

  it('reloads the list after a change', async () => {
    let added = false
    const shadowWork = { category_id: 4, name: 'Shadow work', sort_order: 4, spread_count: 0 }
    const api = stubApi({
      'GET /admin/spread-categories': () => ({
        body: added ? [...categories, shadowWork] : categories,
      }),
      'POST /admin/spread-categories': () => {
        added = true
        return { status: 201, body: shadowWork }
      },
    })
    await mountPage(SpreadCategoriesView, { path: '/admin/spreads/categories' })
    await typeInto(labelled('Add a category'), 'Shadow work')
    api.mockClear()

    await click(button('Add'))

    expect(apiCalls(api)).toEqual([
      ['POST', '/api/admin/spread-categories', { name: 'Shadow work' }],
      ['GET', '/api/admin/spread-categories'],
    ])
    expect(rows().at(-1)).toEqual(['Shadow work', '0 spreads'])
  })

  it("shows the server's reason when a category it thought empty still holds a spread", async () => {
    const reason = '“Career” still holds 1 spread. Move it to another category first.'
    const api = stubApi({
      'GET /admin/spread-categories': { body: categories },
      'DELETE /admin/spread-categories/3': { status: 409, body: { error: reason } },
    })
    await mountPage(SpreadCategoriesView, { path: '/admin/spreads/categories' })
    await click(button('Remove Career'))
    api.mockClear()

    await answerConfirm('confirm')

    expect(apiCalls(api)).toEqual([['DELETE', '/api/admin/spread-categories/3']])
    expect(toasts()).toEqual([reason])
    expect(rows()).toHaveLength(3)
  })

  it('drops to the login page when the session has expired', async () => {
    stubApi({ 'GET /admin/spread-categories': { status: 401, body: { error: 'Unauthorized' } } })
    const { router } = await mountPage(SpreadCategoriesView, { path: '/admin/spreads/categories' })

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
    expect(useUser().currentUser.value).toBeNull()
  })
})
