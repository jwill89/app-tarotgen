import { describe, it, expect, vi } from 'vitest'
import { usePluginLink, type AuthorizeParams } from '@/composables/usePluginLink'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { stubApi, problem } from './helpers/app'
import type { PluginToken } from '@/types'

const params: AuthorizeParams = {
  code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  code_challenge_method: 'S256',
  redirect_uri: 'http://127.0.0.1:51234/callback',
  state: 'xyz',
}

const token = (id: number, label: string): PluginToken => ({
  id,
  user_id: 7,
  label,
  scope: 'plugin',
  created_at: '2026-09-01T10:00:00Z',
  last_used_at: null,
  expires_at: null,
  revoked_at: null,
})

describe('usePluginLink.authorize', () => {
  it('sends the PKCE request and returns the loopback URL carrying the code', async () => {
    const api = stubApi({
      'POST /plugin/authorize': {
        body: { redirect_uri: 'http://127.0.0.1:51234/callback?code=abc&state=xyz' },
      },
    })

    const res = await usePluginLink().authorize(params)

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual(params)
    expect(res).toEqual({
      ok: true,
      redirectUri: 'http://127.0.0.1:51234/callback?code=abc&state=xyz',
    })
  })

  it("passes the server's refusal through", async () => {
    stubApi({
      'POST /plugin/authorize': {
        status: 422,
        body: problem('redirect_uri must be a loopback address.'),
      },
    })

    expect(await usePluginLink().authorize(params)).toEqual({
      ok: false,
      error: 'redirect_uri must be a loopback address.',
    })
  })

  it('falls back to its own message when the refusal has no body', async () => {
    stubApi({ 'POST /plugin/authorize': { status: 500 } })

    expect(await usePluginLink().authorize(params)).toEqual({
      ok: false,
      error: 'Could not authorize the plugin. Please try again.',
    })
  })

  it('does not send the browser anywhere when a success carries no redirect', async () => {
    stubApi({ 'POST /plugin/authorize': { body: { ok: true } } })

    expect(await usePluginLink().authorize(params)).toEqual({
      ok: false,
      error: 'The server returned an unexpected response.',
    })
  })
})

describe('usePluginLink.authorizeDrawKey', () => {
  it('sends the PKCE request with the challenge token and returns the loopback URL', async () => {
    const api = stubApi({
      'POST /plugin/draw-keys/authorize': {
        body: { redirect_uri: 'http://127.0.0.1:51234/callback?code=abc&state=xyz' },
      },
    })

    const res = await usePluginLink().authorizeDrawKey(params, 'cf-token')

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      ...params,
      turnstile_token: 'cf-token',
    })
    expect(res).toEqual({
      ok: true,
      redirectUri: 'http://127.0.0.1:51234/callback?code=abc&state=xyz',
    })
  })

  it("passes the server's refusal through, or its own message without one", async () => {
    stubApi({
      'POST /plugin/draw-keys/authorize': {
        status: 422,
        body: problem('Captcha verification failed. Please try again.'),
      },
    })
    expect(await usePluginLink().authorizeDrawKey(params, '')).toEqual({
      ok: false,
      error: 'Captcha verification failed. Please try again.',
    })

    stubApi({ 'POST /plugin/draw-keys/authorize': { status: 500 } })
    expect(await usePluginLink().authorizeDrawKey(params, '')).toEqual({
      ok: false,
      error: 'Could not get a draw key. Please try again.',
    })
  })
})

describe('usePluginLink connected apps', () => {
  it('lists the linked plugin tokens', async () => {
    stubApi({ 'GET /account/tokens': { body: [token(1, 'TarotGen FFXIV plugin')] } })

    expect((await usePluginLink().listTokens()).map((t) => t.label)).toEqual([
      'TarotGen FFXIV plugin',
    ])
  })

  it('shows no apps rather than failing when the list cannot load', async () => {
    stubApi({ 'GET /account/tokens': { status: 401, body: problem('Not authenticated') } })
    expect(await usePluginLink().listTokens()).toEqual([])

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError(NETWORK_ERROR_MESSAGE)))
    expect(await usePluginLink().listTokens()).toEqual([])
  })

  it('ignores a list that is not a list', async () => {
    stubApi({ 'GET /account/tokens': { body: { tokens: [token(1, 'x')] } } })

    expect(await usePluginLink().listTokens()).toEqual([])
  })

  it('revokes a token by id and reports whether it worked', async () => {
    stubApi({ 'DELETE /account/tokens/4': { status: 204 } })
    expect(await usePluginLink().revokeToken(4)).toBe(true)

    stubApi({ 'DELETE /account/tokens/4': { status: 404, body: problem('Not found.') } })
    expect(await usePluginLink().revokeToken(4)).toBe(false)
  })
})
