import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import AuthorizePluginView from '@/views/AuthorizePluginView.vue'
import { mountWithApp, cleanup, stubApi, signIn, testUser, problem } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const authorizeButton = () =>
  [...document.body.querySelectorAll('button')].find((b) => text(b).startsWith('Authorize as'))
const link = (name: string) =>
  [...document.body.querySelectorAll('a')].find((a) => text(a) === name)?.getAttribute('href')

const pkce = {
  code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  code_challenge_method: 'S256',
  redirect_uri: 'http://127.0.0.1:51234/callback',
  state: 'af0ifjsldkj',
}
const authorizePath = (params: Record<string, string> = pkce) =>
  '/authorize?' + new URLSearchParams(params).toString()

const me = (user = testUser({ display_name: 'Lyra' })) => ({ 'GET /auth/me': { body: { user } } })
const signedOut = { 'GET /auth/me': { status: 401, body: problem('Not authenticated') } }

describe('AuthorizePluginView', () => {
  beforeEach(() => {
    signIn(null)
    vi.stubGlobal('location', { href: 'https://tarotgen.test/authorize' })
  })
  afterEach(cleanup)

  it('checks the session with the server before offering anything', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>(() => {})),
    )
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    expect(pageText()).toContain('Checking your session…')
    expect(authorizeButton()).toBeUndefined()
  })

  it('asks a signed-in user to approve the link, by name', async () => {
    stubApi(me())
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    expect(authorizeButton()?.textContent.trim()).toBe('Authorize as Lyra')
  })

  it('approves the PKCE request and hands the code to the plugin', async () => {
    const api = stubApi({
      ...me(),
      'POST /plugin/authorize': {
        body: { redirect_uri: 'http://127.0.0.1:51234/callback?code=xyz&state=af0ifjsldkj' },
      },
    })
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    authorizeButton()?.click()
    await flushPromises()

    const sent = api.mock.calls.find(([url]) => url === '/api/plugin/authorize')
    expect(JSON.parse(String(sent?.[1]?.body))).toEqual(pkce)
    expect(window.location.href).toBe('http://127.0.0.1:51234/callback?code=xyz&state=af0ifjsldkj')
    expect(pageText()).toContain('Connected.')
  })

  it("shows the server's refusal and lets the user try again", async () => {
    stubApi({
      ...me(),
      'POST /plugin/authorize': { status: 422, body: problem('This link request has expired.') },
    })
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    authorizeButton()?.click()
    await flushPromises()

    expect(pageText()).toContain('This link request has expired.')
    expect(authorizeButton()?.disabled).toBe(false)
    expect(window.location.href).toBe('https://tarotgen.test/authorize')
  })

  it('sends a signed-out user to log in and back to this very request', async () => {
    stubApi(signedOut)
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    expect(authorizeButton()).toBeUndefined()
    const login = new URL(link('Log in to link your account') ?? '', 'https://tarotgen.test')
    expect(login.pathname).toBe('/login')
    expect(login.searchParams.get('redirect')).toBe(authorizePath())
  })

  it('lets the user back out', async () => {
    stubApi(me())
    await mountWithApp(AuthorizePluginView, { path: authorizePath() })

    expect(link('Cancel')).toBe('/')
  })

  it.each([
    ['no code challenge', { ...pkce, code_challenge: '' }],
    ['a plain (non-S256) challenge', { ...pkce, code_challenge_method: 'plain' }],
    ['a redirect off this machine', { ...pkce, redirect_uri: 'https://evil.example/callback' }],
    [
      'a redirect to a look-alike host',
      { ...pkce, redirect_uri: 'http://127.0.0.1.evil.example/' },
    ],
  ])('refuses a request with %s', async (_case, params) => {
    stubApi(me())
    await mountWithApp(AuthorizePluginView, { path: authorizePath(params) })

    expect(pageText()).toContain('This connection link is missing or has invalid parameters.')
    expect(authorizeButton()).toBeUndefined()
  })

  it.each(['http://localhost:5000/cb', 'http://[::1]:5000/cb', 'http://127.0.0.1/'])(
    'accepts the loopback redirect %s',
    async (redirect_uri) => {
      stubApi(me())
      await mountWithApp(AuthorizePluginView, { path: authorizePath({ ...pkce, redirect_uri }) })

      expect(authorizeButton()).toBeDefined()
    },
  )
})
