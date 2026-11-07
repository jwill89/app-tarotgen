import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { defineComponent } from 'vue'
import { useAdminSpreads } from '@/composables/useAdminSpreads'
import { cleanup, mountWithApp, signIn, stubApi, testUser } from './helpers/app'
import { apiCalls } from './helpers/page'
import { categories, crossroads, spreads } from './helpers/spreads'

/** The composable as a page holds it (useAdminApi needs the router). */
async function lists() {
  let held: ReturnType<typeof useAdminSpreads> | undefined
  const Page = defineComponent({
    setup() {
      held = useAdminSpreads()
      return () => null
    },
  })
  await mountWithApp(Page, { path: '/admin/spreads' })
  if (!held) {
    throw new Error('Not mounted')
  }
  return held
}

describe('useAdminSpreads', () => {
  beforeEach(() => {
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('loads nothing until a page asks, then each list from its own endpoint', async () => {
    const api = stubApi({
      'GET /admin/spreads': { body: spreads },
      'GET /admin/spread-categories': { body: categories },
      'GET /admin/pending-spreads': { body: [crossroads] },
    })
    const held = await lists()
    expect(api).not.toHaveBeenCalled()

    await held.loadSpreads()
    await held.loadCategories()
    await held.loadPending()

    expect(apiCalls(api)).toEqual([
      ['GET', '/api/admin/spreads'],
      ['GET', '/api/admin/spread-categories'],
      ['GET', '/api/admin/pending-spreads'],
    ])
    expect(held.spreads.value).toEqual(spreads)
    expect(held.categories.value).toEqual(categories)
    expect(held.pending.value).toEqual([crossroads])
  })

  it('keeps the last list when a reload fails', async () => {
    let failing = false
    stubApi({
      'GET /admin/spreads': () =>
        failing ? { status: 500, body: { error: 'Busy' } } : { body: spreads },
      'GET /admin/spread-categories': () =>
        failing ? { status: 500, body: { error: 'Busy' } } : { body: categories },
      'GET /admin/pending-spreads': () =>
        failing ? { status: 500, body: { error: 'Busy' } } : { body: [crossroads] },
    })
    const held = await lists()
    await Promise.all([held.loadSpreads(), held.loadCategories(), held.loadPending()])

    failing = true
    await Promise.all([held.loadSpreads(), held.loadCategories(), held.loadPending()])

    expect(held.spreads.value).toEqual(spreads)
    expect(held.categories.value).toEqual(categories)
    expect(held.pending.value).toEqual([crossroads])
  })
})
