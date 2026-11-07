import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import LoginView from '@/views/LoginView.vue'
import { useToasts } from '@/composables/useToasts'
import { useUser } from '@/composables/useUser'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()

function button(name: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].filter((b) => text(b) === name)
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

/** The control a `<label>` with this text points at. */
function labelled(label: string): HTMLElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const control = el && document.getElementById(el.htmlFor)
  if (!control) {
    throw new Error(`Nothing is labelled "${label}"`)
  }
  return control
}

function field(label: string): HTMLInputElement {
  const input = labelled(label)
  if (!(input instanceof HTMLInputElement)) {
    throw new Error(`"${label}" is not a text field`)
  }
  return input
}

async function type(label: string, value: string) {
  const el = field(label)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

const toastMessages = () => useToasts().toasts.value.map((t) => t.message)
const pageText = () => text(document.body)
const sentTo = (api: ReturnType<typeof stubApi>, path: string): unknown =>
  JSON.parse(String(api.mock.calls.find(([url]) => url === '/api' + path)?.[1]?.body))

/** Every call the page makes on its own (the Turnstile widget asks for /config). */
const stub = (handlers: Parameters<typeof stubApi>[0] = {}, config: object = {}) =>
  stubApi({ 'GET /config': { body: config }, ...handlers })

async function fillIn(email = 'reader@example.test', password = 'correct horse') {
  await type('Email', email)
  await type('Password', password)
}

/**
 * Turn on WebAuthn: jsdom has no PublicKeyCredential and a null
 * navigator.credentials (vitest.setup.ts), so the passkey button would stay hidden.
 */
function supportPasskeys(get = vi.fn()) {
  vi.stubGlobal('PublicKeyCredential', vi.fn())
  const credentials: CredentialsContainer = {
    create: vi.fn(),
    get,
    preventSilentAccess: vi.fn(),
    store: vi.fn(),
  }
  vi.spyOn(navigator, 'credentials', 'get').mockReturnValue(credentials)
}

const assertion = {
  rawId: new Uint8Array([1]).buffer,
  response: {
    clientDataJSON: new Uint8Array([2]).buffer,
    authenticatorData: new Uint8Array([3]).buffer,
    signature: new Uint8Array([4]).buffer,
  },
}

describe('LoginView', () => {
  beforeEach(() => {
    signIn(null)
    useToasts().toasts.value = []
  })
  afterEach(cleanup)

  describe('with a password', () => {
    it('signs in and goes home', async () => {
      const api = stub({ 'POST /auth/login': { body: { user: testUser() } } })
      const { router } = await mountWithApp(LoginView, { path: '/login' })

      await fillIn()
      await click(button('Log In'))

      expect(sentTo(api, '/auth/login')).toEqual({
        email: 'reader@example.test',
        password: 'correct horse',
        remember_me: false,
        turnstile_token: '',
      })
      expect(toastMessages()).toEqual(['Welcome back!'])
      expect(useUser().isLoggedIn.value).toBe(true)
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
    })

    it('asks to be remembered when the switch is on', async () => {
      const api = stub({ 'POST /auth/login': { body: { user: testUser() } } })
      await mountWithApp(LoginView, { path: '/login' })

      await fillIn()
      await click(labelled('Remember me'))
      await click(button('Log In'))

      expect(sentTo(api, '/auth/login')).toMatchObject({ remember_me: true })
    })

    it('returns to the page that sent the user here', async () => {
      stub({ 'POST /auth/login': { body: { user: testUser() } } })
      const { router } = await mountWithApp(LoginView, {
        path: '/login?redirect=/account/readings',
      })

      await fillIn()
      await click(button('Log In'))

      await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/account/readings'))
    })

    it("shows the server's reason and stays put", async () => {
      stub({ 'POST /auth/login': { status: 401, body: { error: 'Invalid email or password.' } } })
      const { router } = await mountWithApp(LoginView, { path: '/login' })

      await fillIn('reader@example.test', 'wrong')
      await click(button('Log In'))

      expect(pageText()).toContain('Invalid email or password.')
      expect(useUser().isLoggedIn.value).toBe(false)
      expect(router.currentRoute.value.name).toBe('login')
    })

    it('shows only the latest error when the user tries again', async () => {
      stub({
        'POST /auth/login': { status: 401, body: { error: 'Invalid email or password.' } },
      })
      await mountWithApp(LoginView, { path: '/login' })
      await fillIn('reader@example.test', 'wrong')
      await click(button('Log In'))

      stub({ 'POST /auth/login': { status: 429, body: { error: 'Too many attempts.' } } })
      await click(button('Log In'))

      expect(pageText()).not.toContain('Invalid email or password.')
      expect(pageText()).toContain('Too many attempts.')
    })
  })

  describe('with Cloudflare Turnstile switched on', () => {
    let solve: (token: string) => void
    const turnstile = { render: vi.fn(), reset: vi.fn(), remove: vi.fn() }

    beforeEach(() => {
      solve = () => {}
      turnstile.render
        .mockReset()
        .mockImplementation((_el: HTMLElement, opts: Record<string, unknown>) => {
          const callback = opts.callback
          if (typeof callback === 'function') {
            solve = (token) => callback(token)
          }
          return 'widget-1'
        })
      turnstile.reset.mockReset()
      // Already "loaded", so the widget never fetches Cloudflare's script.
      vi.stubGlobal('turnstile', turnstile)
    })

    it('holds the form until the challenge is solved, then sends its token', async () => {
      const api = stub(
        { 'POST /auth/login': { body: { user: testUser() } } },
        { turnstile_sitekey: '0x4AAA' },
      )
      await mountWithApp(LoginView, { path: '/login' })
      await fillIn()

      expect(turnstile.render).toHaveBeenCalledWith(
        expect.any(HTMLElement),
        expect.objectContaining({ sitekey: '0x4AAA' }),
      )
      expect(button('Log In').disabled).toBe(true)

      solve('cf-token')
      await flushPromises()
      expect(button('Log In').disabled).toBe(false)
      await click(button('Log In'))

      expect(sentTo(api, '/auth/login')).toMatchObject({ turnstile_token: 'cf-token' })
    })

    it('asks for a fresh challenge after a failed attempt (tokens are single-use)', async () => {
      stub(
        { 'POST /auth/login': { status: 401, body: { error: 'Invalid email or password.' } } },
        { turnstile_sitekey: '0x4AAA' },
      )
      await mountWithApp(LoginView, { path: '/login' })
      await fillIn()
      solve('cf-token')
      await flushPromises()

      await click(button('Log In'))

      expect(turnstile.reset).toHaveBeenCalledWith('widget-1')
      expect(button('Log In').disabled).toBe(true)
    })
  })

  describe('with a passkey', () => {
    it("isn't offered where the browser has no passkeys", async () => {
      stub()
      await mountWithApp(LoginView, { path: '/login' })

      expect(pageText()).not.toContain('Sign in with Passkey')
    })

    it('signs in with the passkey for the typed email and returns to the page', async () => {
      const get = vi.fn().mockResolvedValue(assertion)
      supportPasskeys(get)
      const api = stub({
        'POST /auth/passkeys/login/options': { body: { publicKey: { challenge: 'AQID' } } },
        'POST /auth/passkeys/login': { body: { user: testUser() } },
      })
      const { router } = await mountWithApp(LoginView, {
        path: '/login?redirect=/account/spreads',
      })

      await type('Email', 'reader@example.test')
      await click(button('Sign in with Passkey'))

      expect(sentTo(api, '/auth/passkeys/login/options')).toEqual({
        email: 'reader@example.test',
      })
      expect(get).toHaveBeenCalledTimes(1)
      expect(toastMessages()).toEqual(['Welcome back!'])
      expect(useUser().isLoggedIn.value).toBe(true)
      await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/account/spreads'))
    })

    it('says why a passkey sign-in failed', async () => {
      supportPasskeys(vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')))
      stub({ 'POST /auth/passkeys/login/options': { body: { publicKey: { challenge: 'AQID' } } } })
      const { router } = await mountWithApp(LoginView, { path: '/login' })

      await click(button('Sign in with Passkey'))

      expect(pageText()).toContain('Passkey authentication was cancelled or not allowed.')
      expect(router.currentRoute.value.name).toBe('login')
    })
  })

  describe('with Google', () => {
    it('sends the browser to Google to sign in', async () => {
      vi.stubGlobal('location', { href: 'https://tarotgen.test/login' })
      stub()
      await mountWithApp(LoginView, { path: '/login' })

      await click(button('Sign in with Google'))

      expect(window.location.href).toBe('/api/auth/google?intent=login')
    })

    it('shows the error Google sign-in came back with, and tidies the address', async () => {
      stub()
      const { router } = await mountWithApp(LoginView, {
        path: '/login?oauth_error=No%20account%20for%20that%20Google%20address.',
      })

      expect(pageText()).toContain('No account for that Google address.')
      expect(router.currentRoute.value.query).toEqual({})
    })

    it('passes on a success message from the Google round trip', async () => {
      stub()
      const { router } = await mountWithApp(LoginView, {
        path: '/login?oauth_success=Google%20account%20linked.',
      })

      expect(toastMessages()).toEqual(['Google account linked.'])
      expect(router.currentRoute.value.query).toEqual({})
    })
  })
})
