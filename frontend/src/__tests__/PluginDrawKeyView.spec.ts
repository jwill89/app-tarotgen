import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import PluginDrawKeyView from '@/views/PluginDrawKeyView.vue'
import { mountWithApp, cleanup, stubApi, signIn } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const getKeyButton = () =>
  [...document.body.querySelectorAll('button')].find((b) => text(b) === 'Get Draw Key')

const pkce = {
  code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  code_challenge_method: 'S256',
  redirect_uri: 'http://127.0.0.1:51234/callback',
  state: 'af0ifjsldkj',
}
const keyPath = (params: Record<string, string> = pkce) =>
  '/draw-key?' + new URLSearchParams(params).toString()

const loopbackWithCode = 'http://127.0.0.1:51234/callback?code=xyz&state=af0ifjsldkj'
const noTurnstile = { 'GET /config': { body: { turnstile_sitekey: null } } }
const withTurnstile = { 'GET /config': { body: { turnstile_sitekey: '0x4AAA' } } }

async function getKey(): Promise<void> {
  getKeyButton()?.click()
  await flushPromises()
}

const sentKeyRequest = (api: ReturnType<typeof stubApi>): unknown =>
  JSON.parse(
    String(api.mock.calls.find(([url]) => url === '/api/plugin/draw-keys/authorize')?.[1]?.body),
  )

describe('PluginDrawKeyView', () => {
  beforeEach(() => {
    signIn(null)
    vi.stubGlobal('location', { href: 'https://tarotgen.test/draw-key' })
  })
  afterEach(cleanup)

  it('says what the key is for, and that an account is the other way', async () => {
    stubApi(noTurnstile)
    await mountWithApp(PluginDrawKeyView, { path: keyPath() })

    expect(pageText()).toContain('A draw key lets the plugin draw readings without an account.')
    expect(pageText()).toContain("link your account from the plugin's Settings instead")
  })

  it("hands the code to the plugin's listener", async () => {
    const api = stubApi({
      ...noTurnstile,
      'POST /plugin/draw-keys/authorize': { body: { redirect_uri: loopbackWithCode } },
    })
    await mountWithApp(PluginDrawKeyView, { path: keyPath() })

    // Turnstile is off here: nothing to solve first.
    expect(getKeyButton()?.disabled).toBe(false)
    await getKey()

    expect(sentKeyRequest(api)).toEqual({ ...pkce, turnstile_token: '' })
    expect(window.location.href).toBe(loopbackWithCode)
    expect(pageText()).toContain('The plugin has its draw key.')
    expect(getKeyButton()).toBeUndefined()
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

    it('holds the key until the challenge is solved, then sends its token', async () => {
      const api = stubApi({
        ...withTurnstile,
        'POST /plugin/draw-keys/authorize': { body: { redirect_uri: loopbackWithCode } },
      })
      await mountWithApp(PluginDrawKeyView, { path: keyPath() })

      expect(turnstile.render).toHaveBeenCalledWith(
        expect.any(HTMLElement),
        expect.objectContaining({ sitekey: '0x4AAA' }),
      )
      expect(getKeyButton()?.disabled).toBe(true)

      solve('cf-token')
      await flushPromises()
      expect(getKeyButton()?.disabled).toBe(false)
      await getKey()

      expect(sentKeyRequest(api)).toEqual({ ...pkce, turnstile_token: 'cf-token' })
      expect(window.location.href).toBe(loopbackWithCode)
    })

    it("shows the server's refusal and asks for a fresh challenge", async () => {
      stubApi({
        ...withTurnstile,
        'POST /plugin/draw-keys/authorize': {
          status: 400,
          body: { error: 'Captcha verification failed. Please try again.' },
        },
      })
      await mountWithApp(PluginDrawKeyView, { path: keyPath() })
      solve('cf-token')
      await flushPromises()

      await getKey()

      expect(document.body.querySelector('[role="alert"]')?.textContent).toBe(
        'Captcha verification failed. Please try again.',
      )
      expect(turnstile.reset).toHaveBeenCalledWith('widget-1')
      expect(getKeyButton()?.disabled).toBe(true)
      expect(window.location.href).toBe('https://tarotgen.test/draw-key')
    })
  })

  it('says so when the key page is too busy', async () => {
    stubApi({
      ...noTurnstile,
      'POST /plugin/draw-keys/authorize': {
        status: 429,
        body: { error: 'Too many attempts. Please try again later.' },
      },
    })
    await mountWithApp(PluginDrawKeyView, { path: keyPath() })

    await getKey()

    expect(pageText()).toContain('Too many attempts. Please try again later.')
    expect(getKeyButton()?.disabled).toBe(false)
  })

  it.each([
    ['no code challenge', { ...pkce, code_challenge: '' }],
    ['a plain (non-S256) challenge', { ...pkce, code_challenge_method: 'plain' }],
    ['a redirect off this machine', { ...pkce, redirect_uri: 'https://evil.example/callback' }],
  ])('offers nothing for a request with %s', async (_case, params) => {
    stubApi(noTurnstile)
    await mountWithApp(PluginDrawKeyView, { path: keyPath(params) })

    expect(pageText()).toContain('This link is missing or has invalid parameters.')
    expect(getKeyButton()).toBeUndefined()
  })
})
