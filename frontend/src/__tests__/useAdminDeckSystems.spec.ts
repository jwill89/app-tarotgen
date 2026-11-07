import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { defineComponent } from 'vue'
import { useAdminDeckSystems } from '@/composables/useAdminDeckSystems'
import { cleanup, mountWithApp, signIn, stubApi, testUser } from './helpers/app'
import { apiCalls } from './helpers/page'
import { pending, systems } from './helpers/deckSystems'

/** The composable as a page holds it (useAdminApi needs the router). */
async function lists() {
  let held: ReturnType<typeof useAdminDeckSystems> | undefined
  const Page = defineComponent({
    setup() {
      held = useAdminDeckSystems()
      return () => null
    },
  })
  await mountWithApp(Page, { path: '/admin/deck-systems' })
  if (!held) {
    throw new Error('Not mounted')
  }
  return held
}

describe('useAdminDeckSystems', () => {
  beforeEach(() => {
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('loads nothing until a page asks, then each list from its own endpoint', async () => {
    const api = stubApi({
      'GET /admin/deck-systems': { body: systems },
      'GET /admin/deck-systems/pending': { body: pending },
    })
    const held = await lists()
    expect(api).not.toHaveBeenCalled()

    await held.loadSystems()
    await held.loadPending()

    expect(apiCalls(api)).toEqual([
      ['GET', '/api/admin/deck-systems'],
      ['GET', '/api/admin/deck-systems/pending'],
    ])
    expect(held.systems.value).toEqual(systems)
    expect(held.pending.value).toEqual(pending)
  })

  it('keeps the last list when a reload fails', async () => {
    let failing = false
    const busy = { status: 500, body: { error: 'Busy' } }
    stubApi({
      'GET /admin/deck-systems': () => (failing ? busy : { body: systems }),
      'GET /admin/deck-systems/pending': () => (failing ? busy : { body: pending }),
    })
    const held = await lists()
    await Promise.all([held.loadSystems(), held.loadPending()])

    failing = true
    await Promise.all([held.loadSystems(), held.loadPending()])

    expect(held.systems.value).toEqual(systems)
    expect(held.pending.value).toEqual(pending)
  })
})
