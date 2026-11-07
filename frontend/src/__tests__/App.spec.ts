import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import App from '@/App.vue'
import { useUser } from '@/composables/useUser'
import { useDecks } from '@/composables/useDecks'
import { useToasts } from '@/composables/useToasts'
import { mountWithApp, cleanup, signIn, stubApi, testUser } from './helpers/app'

// A page with no API calls of its own, so each test states only the app's.
async function openApp(path = '/privacy') {
  return mountWithApp(App, { path })
}

const menuItems = () =>
  [...document.body.querySelectorAll('[role="menuitem"]')].map((i) => i.textContent.trim())

async function openAccountMenu(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('.account-trigger').trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

const codeDialog = () =>
  [...document.body.querySelectorAll<HTMLElement>('[role="dialog"]')].find((d) =>
    d.textContent.includes('Enter the code you were given'),
  )

async function submitCode(code: string): Promise<void> {
  const dialog = codeDialog()
  const input = dialog?.querySelector<HTMLInputElement>('input[aria-label="Reading Code"]')
  if (!input) {
    throw new Error('No reading code box')
  }
  input.value = code
  input.dispatchEvent(new Event('input'))
  dialog?.querySelector('form')?.dispatchEvent(new Event('submit'))
  await flushPromises()
}

/** What New Draw loads for a signed-out visitor (its Turnstile widget asks for /config). */
const newDrawLoads = {
  'GET /decks': { body: [] },
  'GET /spreads': { body: [] },
  'GET /config': { body: { turnstile_sitekey: null } },
}

describe('App', () => {
  beforeEach(() => {
    signIn(null)
    useDecks().decks.value = []
    useToasts().toasts.value = []
    // The cookie notice is App's too; it isn't what these tests look at.
    localStorage.setItem('cookie_notice_dismissed', '1')
  })
  afterEach(cleanup)

  describe('navigation', () => {
    it('links to every main page', async () => {
      const { wrapper, router } = await openApp()

      const links = wrapper.findAll('.nav-links a').map((a) => [a.text(), a.attributes('href')])
      expect(links).toEqual(
        (
          [
            ['Home', 'home'],
            ['New Draw', 'new-reading'],
            ['Spreads', 'spread-library'],
            ['Recreate Draw', 'custom-reading'],
            ['FFXIV Plugin', 'ffxiv-plugin'],
          ] as const
        ).map(([label, name]) => [label, router.resolve({ name }).href]),
      )
      expect(wrapper.get('nav').attributes('aria-label')).toBe('main navigation')
      expect(wrapper.get('a.brand').attributes('aria-label')).toBe('TarotGen.io home')
    })

    it('marks the link for the page being shown', async () => {
      stubApi(newDrawLoads)
      const { wrapper } = await openApp('/new')

      const active = wrapper.findAll('.nav-links a.active').map((a) => a.text())
      expect(active).toEqual(['New Draw'])
    })

    it('shows the page for the route', async () => {
      const { wrapper } = await openApp('/privacy')

      expect(wrapper.get('main').text()).toContain('Privacy Policy')
    })

    it('skips straight to the content, without touching the URL', async () => {
      const { wrapper, router } = await openApp()

      await wrapper.get('a.skip-link').trigger('click')

      expect(document.activeElement).toBe(wrapper.get('main').element)
      expect(router.currentRoute.value.hash).toBe('')
    })

    it('links the footer to the policies and the contact form', async () => {
      const { wrapper } = await openApp()

      expect(wrapper.findAll('footer a').map((a) => a.text())).toEqual([
        'MathDad',
        'Privacy Policy',
        'Terms of Service',
        'Contact Us',
      ])
      expect(wrapper.get('footer').text()).toContain(
        `Copyright © ${String(new Date().getFullYear())}`,
      )
    })
  })

  describe('for a guest', () => {
    it('offers a log in link and no account menu', async () => {
      const { wrapper, router } = await openApp()

      expect(wrapper.get('a.btn-login').attributes('href')).toBe(
        router.resolve({ name: 'login' }).href,
      )
      expect(wrapper.find('.account-trigger').exists()).toBe(false)
    })
  })

  describe('for a signed-in user', () => {
    beforeEach(() => {
      signIn(testUser({ display_name: 'Morgana' }))
    })

    it('shows their name on an account menu in place of the log in link', async () => {
      const { wrapper } = await openApp()

      expect(wrapper.find('a.btn-login').exists()).toBe(false)
      expect(wrapper.get('.account-trigger').text()).toBe('Morgana')
    })

    it("lists their account pages, and no admin section if they aren't one", async () => {
      const { wrapper } = await openApp()

      await openAccountMenu(wrapper)

      expect(menuItems()).toEqual([
        'Dashboard',
        'My Readings',
        'Daily Card',
        'My Spreads',
        'Account Settings',
        'Log Out',
      ])
      expect(document.body.textContent).not.toContain('Admin')
    })

    it('logs out, forgets the user and goes home', async () => {
      const api = stubApi({ 'POST /auth/logout': { status: 204 } })
      const { wrapper, router } = await openApp()
      await openAccountMenu(wrapper)

      const logOut = [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')].find(
        (i) => i.textContent.trim() === 'Log Out',
      )
      logOut?.click()
      await flushPromises()

      expect(api).toHaveBeenCalledWith('/api/auth/logout', { method: 'POST' })
      expect(useUser().currentUser.value).toBeNull()
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
      expect(wrapper.find('a.btn-login').exists()).toBe(true)
    })
  })

  it("adds the admin pages to an admin's menu", async () => {
    signIn(testUser({ is_admin: true }))
    const { wrapper } = await openApp()

    await openAccountMenu(wrapper)

    expect(document.body.querySelector('[role="menu"]')?.textContent).toContain('Admin')
    expect(menuItems()).toEqual([
      'Dashboard',
      'My Readings',
      'Daily Card',
      'My Spreads',
      'Account Settings',
      'Dashboard',
      'Decks',
      'Special Cards',
      'Spreads',
      'Readings',
      'Users',
      'Contacts',
      'Errored Cards',
      'Log Out',
    ])
  })

  describe('viewing a reading by its code', () => {
    it('opens the reading for the code entered, trimmed', async () => {
      stubApi({
        'GET /decks': { body: [] },
        'GET /readings/abc123': { status: 404, body: { error: 'Not found' } },
      })
      vi.spyOn(console, 'error').mockImplementation(() => {})
      const { wrapper, router } = await openApp()

      await wrapper.get('.nav-code-btn').trigger('click')
      await flushPromises()
      expect(codeDialog()?.querySelector('h2')?.textContent).toBe('View a Reading')
      await submitCode('  abc123 ')

      await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/reading/abc123'))
      expect(codeDialog()).toBeUndefined()
    })

    it('starts a new draw instead when no code is entered', async () => {
      stubApi(newDrawLoads)
      const { wrapper, router } = await openApp()

      await wrapper.get('.nav-code-btn').trigger('click')
      await flushPromises()
      await submitCode('   ')

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('new-reading'))
    })
  })

  describe('the mobile menu', () => {
    const burger = (wrapper: VueWrapper) =>
      wrapper.get('button[aria-label="Toggle navigation menu"]')
    const mobileLinks = (wrapper: VueWrapper) =>
      wrapper.findAll('.mobile-menu a').map((a) => a.text())

    it('opens and closes from the menu button, saying which it is', async () => {
      const { wrapper } = await openApp()

      expect(burger(wrapper).attributes('aria-expanded')).toBe('false')
      await burger(wrapper).trigger('click')
      expect(burger(wrapper).attributes('aria-expanded')).toBe('true')
      await burger(wrapper).trigger('click')
      expect(burger(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('closes once a page is chosen from it', async () => {
      const { wrapper } = await openApp()
      await burger(wrapper).trigger('click')

      await wrapper
        .findAll('.mobile-menu a')
        .find((a) => a.text() === 'Home')
        ?.trigger('click')

      expect(burger(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('offers a guest the main pages and log in', async () => {
      const { wrapper } = await openApp()

      expect(mobileLinks(wrapper)).toEqual([
        'Home',
        'New Draw',
        'Spreads',
        'Recreate Draw',
        'FFXIV Plugin',
        'Log In',
      ])
    })

    it('offers an admin their account and admin pages, and log out', async () => {
      signIn(testUser({ is_admin: true }))
      const { wrapper } = await openApp()

      const links = mobileLinks(wrapper)
      expect(links.slice(5, 10)).toEqual([
        'Dashboard',
        'My Readings',
        'Daily Card',
        'My Spreads',
        'Account Settings',
      ])
      expect(links).toContain('Admin · Errored Cards')
      expect(links.at(-1)).toBe('Log Out')
    })

    it('opens the reading-code dialog, closing the menu', async () => {
      const { wrapper } = await openApp()
      await burger(wrapper).trigger('click')

      await wrapper.get('.mm-code-btn').trigger('click')
      await flushPromises()

      expect(codeDialog()).toBeDefined()
      expect(burger(wrapper).attributes('aria-expanded')).toBe('false')
    })

    it('logs out from the keyboard, with Enter or Space', async () => {
      signIn(testUser())
      const api = stubApi({ 'POST /auth/logout': { status: 204 } })
      const { wrapper } = await openApp()

      await wrapper.get('.mm-logout').trigger('keydown', { key: ' ' })
      await flushPromises()
      expect(api).toHaveBeenCalledTimes(1)

      signIn(testUser())
      await flushPromises()
      await wrapper.get('.mm-logout').trigger('keyup', { key: 'Enter' })
      await flushPromises()
      expect(api).toHaveBeenCalledTimes(2)
      expect(useUser().currentUser.value).toBeNull()
    })
  })
})
