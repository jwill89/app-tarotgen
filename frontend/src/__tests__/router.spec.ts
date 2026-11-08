import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest'
import { stubApi, testUser } from './helpers/app'
import type { User } from '@/types'

/*
 * The app's router, guards included. Its last-revalidation time and the signed-in
 * user are module state, so each test loads a fresh copy of both: `signIn`
 * below sets the user on that copy, not on the one helpers/app imported.
 */
async function freshApp(user: User | null = null) {
  vi.resetModules()
  const { default: router, markAuthChecked } = await import('@/router')
  const { useUser } = await import('@/composables/useUser')
  useUser().currentUser.value = user
  return { router, markAuthChecked, useUser }
}

const meta = (name: string) =>
  document.head.querySelector(`meta[name="${name}"]`)?.getAttribute('content')
const canonical = () => document.head.querySelector('link[rel="canonical"]')?.getAttribute('href')

const ME = 'GET /auth/me'

// The first import of the router transforms the whole app. With `task check` running lint
// and the scans beside the tests, that alone can outlast a test's 5 s, so it happens once
// here, with time to spare; each freshApp() after it re-runs modules already transformed.
beforeAll(async () => {
  await import('@/router')
}, 60_000)

describe('router guards', () => {
  beforeEach(() => {
    sessionStorage.clear()
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('sends a signed-out visitor from an account page to login, and back after', async () => {
    const { router } = await freshApp()

    await router.push('/account/readings?page=2')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/account/readings?page=2')
  })

  it('sends a signed-out visitor from an admin page to login, and back after', async () => {
    const { router } = await freshApp()

    await router.push('/admin/decks')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/admin/decks')
  })

  it("sends a signed-in user who isn't an admin home from the admin pages", async () => {
    const { router, markAuthChecked } = await freshApp(testUser())
    markAuthChecked()

    await router.push('/admin')

    expect(router.currentRoute.value.name).toBe('home')
    expect(router.currentRoute.value.query).toEqual({})
  })

  it('lets an admin into the admin pages', async () => {
    const { router, markAuthChecked } = await freshApp(testUser({ is_admin: true }))
    markAuthChecked()

    await router.push('/admin/users')

    expect(router.currentRoute.value.name).toBe('admin-users')
  })

  it.each([
    ['/admin/spreads/new', 'admin-spread-new'],
    ['/admin/spreads/7/edit', 'admin-spread-edit'],
    ['/admin/spreads/categories', 'admin-spread-categories'],
    ['/admin/spreads/submissions', 'admin-spread-submissions'],
    ['/admin/deck-systems/new', 'admin-deck-system-new'],
    ['/admin/deck-systems/3/edit', 'admin-deck-system-edit'],
    ['/admin/deck-systems/submissions', 'admin-deck-system-submissions'],
  ])('keeps the admin sub-page %s to admins', async (path, name) => {
    const guest = await freshApp()
    await guest.router.push(path)
    expect(guest.router.currentRoute.value.name).toBe('login')
    expect(guest.router.currentRoute.value.query.redirect).toBe(path)

    const reader = await freshApp(testUser())
    reader.markAuthChecked()
    await reader.router.push(path)
    expect(reader.router.currentRoute.value.name).toBe('home')

    const admin = await freshApp(testUser({ is_admin: true }))
    admin.markAuthChecked()
    await admin.router.push(path)
    expect(admin.router.currentRoute.value.name).toBe(name)
    expect(admin.router.currentRoute.value.meta.noindex).toBe(true)
  })

  it('asks a signed-out visitor to sign in for the daily card, and lets a user in', async () => {
    const guest = await freshApp()
    await guest.router.push('/daily')
    expect(guest.router.currentRoute.value.name).toBe('login')
    expect(guest.router.currentRoute.value.query.redirect).toBe('/daily')

    const { router, markAuthChecked } = await freshApp(testUser())
    markAuthChecked()
    await router.push('/daily')
    expect(router.currentRoute.value.name).toBe('daily-card')
  })

  it('lets a signed-in user into their account pages', async () => {
    const { router, markAuthChecked } = await freshApp(testUser())
    markAuthChecked()

    await router.push('/account/settings')

    expect(router.currentRoute.value.name).toBe('account-settings')
  })

  it.each(['/login', '/register'])('sends a signed-in user away from %s', async (path) => {
    const { router, markAuthChecked } = await freshApp(testUser())
    markAuthChecked()

    await router.push(path)

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('lets anyone reach the public pages', async () => {
    const { router } = await freshApp()

    await router.push('/contact')

    expect(router.currentRoute.value.name).toBe('contact')
  })
})

describe('router session revalidation', () => {
  beforeEach(() => {
    sessionStorage.clear()
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-28T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('checks the session with the server before a signed-in navigation', async () => {
    const api = stubApi({ [ME]: { body: { user: testUser() } } })
    const { router } = await freshApp(testUser())

    await router.push('/account')

    expect(api).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('dashboard')
  })

  it('sends a user whose session expired to login instead of a broken account page', async () => {
    stubApi({ [ME]: { status: 401, body: { error: 'Not authenticated' } } })
    const { router, useUser } = await freshApp(testUser())

    await router.push('/account/spreads')

    expect(useUser().currentUser.value).toBeNull()
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/account/spreads')
  })

  it('takes a revoked admin flag into account at once', async () => {
    stubApi({ [ME]: { body: { user: testUser({ is_admin: false }) } } })
    const { router } = await freshApp(testUser({ is_admin: true }))

    await router.push('/admin')

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('checks at most once a minute', async () => {
    const api = stubApi({ [ME]: { body: { user: testUser() } } })
    const { router } = await freshApp(testUser())

    await router.push('/account')
    vi.setSystemTime(Date.now() + 30_000)
    await router.push('/account/readings')
    expect(api).toHaveBeenCalledTimes(1)

    vi.setSystemTime(Date.now() + 31_000)
    await router.push('/account/settings')
    expect(api).toHaveBeenCalledTimes(2)
  })

  it('skips the check right after the app has just made one', async () => {
    const api = stubApi({})
    const { router, markAuthChecked } = await freshApp(testUser())

    markAuthChecked()
    await router.push('/account')

    expect(api).not.toHaveBeenCalled()
  })

  it("doesn't call the server for a signed-out visitor", async () => {
    const api = stubApi({})
    const { router } = await freshApp()

    await router.push('/contact')

    expect(api).not.toHaveBeenCalled()
  })
})

describe('router page head', () => {
  beforeEach(() => {
    sessionStorage.clear()
    document.head.innerHTML = ''
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  })

  it('titles each page after the route and describes it for search engines', async () => {
    const { router } = await freshApp()

    await router.push('/privacy')

    expect(document.title).toBe('Privacy Policy | TarotGen.io Tarot Generator')
    expect(meta('description')).toBe('How TarotGen.io collects, uses, and protects your data.')
    expect(canonical()).toBe('https://tarotgen.io/privacy')
    expect(meta('robots')).toBe('index, follow')
  })

  it('keeps the title and meta of a page that stopped the navigation away from it', async () => {
    const { router } = await freshApp()
    await router.push('/privacy')
    // As a page with unsaved changes does when the visitor stays.
    router.beforeEach((to) => to.name !== 'contact')

    await router.push('/contact')

    expect(router.currentRoute.value.name).toBe('privacy-policy')
    expect(document.title).toBe('Privacy Policy | TarotGen.io Tarot Generator')
    expect(canonical()).toBe('https://tarotgen.io/privacy')
  })

  it('sends the retired changelog pages home, as any unknown page', async () => {
    const { router, markAuthChecked } = await freshApp(testUser({ is_admin: true }))
    markAuthChecked()

    for (const path of ['/changelog', '/admin/changelog']) {
      await router.push(path)
      expect(router.currentRoute.value.name).toBe('home')
    }
  })

  it('keeps the site title alone on the home page', async () => {
    const { router } = await freshApp()
    await router.push('/contact')

    await router.push('/')

    expect(document.title).toBe('TarotGen.io Tarot Generator')
    expect(canonical()).toBe('https://tarotgen.io/')
  })

  it('keeps private pages out of search results', async () => {
    const { router } = await freshApp()

    await router.push('/login')

    expect(document.title).toBe('Login | TarotGen.io Tarot Generator')
    expect(meta('robots')).toBe('noindex, nofollow')
  })

  it('starts every page at the top', async () => {
    const { router } = await freshApp()

    await router.push('/privacy')
    await vi.waitFor(() => expect(window.scrollTo).toHaveBeenCalled())

    expect(vi.mocked(window.scrollTo).mock.calls.at(-1)?.[0]).toMatchObject({ top: 0 })
  })
})

describe('route table', () => {
  beforeEach(() => {
    sessionStorage.clear()
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  })

  it('opens a shared ?rid= link as that reading', async () => {
    const { router } = await freshApp()

    await router.push('/?rid=abc123')

    expect(router.currentRoute.value.name).toBe('reading')
    expect(router.currentRoute.value.params.id).toBe('abc123')
  })

  it.each([
    ['/submit-spread', 'submit-spread'],
    ['/admin/login', 'login'],
    ['/no/such/page', 'home'],
  ])('sends the old or unknown URL %s to %s', async (from, name) => {
    const { router } = await freshApp()

    await router.push(from)

    expect(router.currentRoute.value.name).toBe(name)
  })
})
