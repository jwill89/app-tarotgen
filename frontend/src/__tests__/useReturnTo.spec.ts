import { describe, it, expect, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import type { RouteLocationRaw, Router } from 'vue-router'
import { useReturnTo } from '@/composables/useReturnTo'
import { cleanup, mountWithApp, testRouter, testWebRouter, lazyLoad } from './helpers/app'

/** useReturnTo as a page holds it, with `from` the page it is on. */
async function onPage(router: Router, from: string) {
  let returnTo: (to: RouteLocationRaw) => void = () => {}
  const Page = defineComponent({
    setup() {
      returnTo = useReturnTo()
      return () => h('p', 'An editor')
    },
  })
  await mountWithApp(Page, { router, path: from })
  return (to: RouteLocationRaw) => returnTo(to)
}

/** A web-history router that has been through `pages`, in order. */
async function visited(...pages: string[]) {
  const router = testWebRouter()
  for (const page of pages.slice(0, -1)) {
    await router.push(page)
  }
  const returnTo = await onPage(router, pages.at(-1)!)
  return { router, returnTo }
}

const arrived = (router: Router, name: string) =>
  vi.waitFor(() => expect(router.currentRoute.value.name).toBe(name), lazyLoad)

describe('useReturnTo', () => {
  afterEach(cleanup)

  it.each([
    ['its name', { name: 'admin-spreads' }],
    ['its path', '/admin/spreads'],
  ])('steps back when the previous page is the one named by %s', async (_how, to) => {
    const { router, returnTo } = await visited('/admin/spreads', '/admin/spreads/1/edit')
    const entries = window.history.length

    returnTo(to)
    await arrived(router, 'admin-spreads')

    expect(window.history.length).toBe(entries)
    expect(window.history.state.forward).toBe('/admin/spreads/1/edit')
  })

  it('replaces the page when the previous one is another', async () => {
    const { router, returnTo } = await visited('/admin', '/admin/spreads/1/edit')
    const entries = window.history.length

    returnTo({ name: 'admin-spreads' })
    await arrived(router, 'admin-spreads')

    expect(window.history.length).toBe(entries)
    expect(window.history.state.back).toBe('/admin')
    expect(window.history.state.forward).toBeNull()
  })

  it('replaces the page when there is no previous one', async () => {
    const { router, returnTo } = await visited('/admin/spreads/1/edit')

    returnTo({ name: 'admin-spreads' })
    await arrived(router, 'admin-spreads')

    expect(window.history.state.back).toBeNull()
  })

  it('replaces on a history that keeps no previous page', async () => {
    const router = testRouter()
    await router.push('/admin/spreads')
    const returnTo = await onPage(router, '/admin/spreads/1/edit')
    const back = vi.spyOn(router, 'back')

    returnTo({ name: 'admin-spreads' })
    await arrived(router, 'admin-spreads')

    expect(back).not.toHaveBeenCalled()
  })
})
