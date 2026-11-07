import { describe, it, expect, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import { mountWithApp, cleanup, testWebRouter } from './helpers/app'

const backToSpreads = { to: { name: 'admin-spreads' }, label: 'Back to Spreads' }

describe('AdminListPage', () => {
  afterEach(cleanup)

  it('titles the page and holds its content', async () => {
    const { wrapper } = await mountWithApp(AdminListPage, {
      props: { title: 'Manage Users', subtitle: 'Everyone with an account.' },
      slots: { default: '<table><caption>Users</caption></table>' },
    })

    expect(wrapper.get('h1').text()).toBe('Manage Users')
    expect(wrapper.text()).toContain('Everyone with an account.')
    expect(wrapper.find('caption').text()).toBe('Users')
  })

  it('leads back to the admin dashboard', async () => {
    const { wrapper, router } = await mountWithApp(AdminListPage, {
      props: { title: 'Manage Users' },
      path: '/admin/users',
    })

    const back = wrapper.get('a')
    expect(back.text()).toBe('Back to Dashboard')
    expect(back.attributes('href')).toBe(router.resolve({ name: 'admin-dashboard' }).href)

    await back.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-dashboard'))
  })

  it('leads back where the page says instead', async () => {
    const { wrapper, router } = await mountWithApp(AdminListPage, {
      props: { title: 'Spread Categories', back: backToSpreads },
      path: '/admin/spreads/categories',
    })

    const back = wrapper.get('a')
    expect(back.text()).toBe('Back to Spreads')
    expect(back.attributes('href')).toBe('/admin/spreads')

    // A plain link: the list is pushed as a new page.
    await back.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-spreads'))
  })

  it('puts actions beside the title only when there are some', async () => {
    const withActions = await mountWithApp(AdminListPage, {
      props: { title: 'Decks' },
      slots: { actions: '<button type="button">Add Deck</button>' },
    })
    expect(withActions.wrapper.get('.page-header__actions').text()).toBe('Add Deck')

    const without = await mountWithApp(AdminListPage, { props: { title: 'Decks' } })
    expect(without.wrapper.find('.page-header__actions').exists()).toBe(false)
  })

  describe('a returning link (a page opened from the one it leads back to)', () => {
    const returning = { ...backToSpreads, returning: true }

    /** The editor, opened from the list or straight from `entry`. */
    async function openEditor(entry: string) {
      const router = testWebRouter()
      await router.push(entry)
      if (entry !== '/admin/spreads/new') {
        await router.push('/admin/spreads/new')
      }
      const { wrapper } = await mountWithApp(AdminListPage, {
        props: { title: 'Add Spread', back: returning },
        router,
        path: '/admin/spreads/new',
      })
      return { router, back: wrapper.get('a') }
    }

    it('steps back to the list when the list is the previous page', async () => {
      const { router, back } = await openEditor('/admin/spreads')
      const entries = window.history.length

      await back.trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-spreads'))

      expect(window.history.length).toBe(entries)
      expect(window.history.state.forward).toBe('/admin/spreads/new')
    })

    it('replaces the page with the list when the admin came from elsewhere', async () => {
      const { router, back } = await openEditor('/admin')
      const entries = window.history.length

      await back.trigger('click')
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-spreads'))

      expect(window.history.length).toBe(entries)
      expect(window.history.state.back).toBe('/admin')
    })

    it('leaves a click meant for a new tab to the browser', async () => {
      const { router, back } = await openEditor('/admin/spreads')

      expect(back.attributes('href')).toBe('/admin/spreads')
      await back.trigger('click', { ctrlKey: true })
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('admin-spread-new')
    })
  })
})
