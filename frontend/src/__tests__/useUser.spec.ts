import { describe, it, expect, beforeEach, vi } from 'vitest'
import { nextTick } from 'vue'
import { useUser } from '@/composables/useUser'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { STORAGE_KEYS } from '@/constants'
import { stubApi, signIn, testUser, problem } from './helpers/app'

const sentBody = (api: ReturnType<typeof stubApi>, call = 0): unknown =>
  JSON.parse(String(api.mock.calls[call]?.[1]?.body))

const offline = () => vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))

describe('useUser session', () => {
  beforeEach(() => {
    signIn(null)
    sessionStorage.clear()
  })

  it('takes the user the server reports', async () => {
    stubApi({ 'GET /auth/me': { body: { user: testUser({ display_name: 'Morgan' }) } } })
    const { fetchMe, currentUser, isLoggedIn } = useUser()

    await fetchMe()

    expect(currentUser.value?.display_name).toBe('Morgan')
    expect(isLoggedIn.value).toBe(true)
  })

  it('signs out locally when the session has expired', async () => {
    signIn(testUser())
    stubApi({ 'GET /auth/me': { status: 401, body: problem('Not authenticated') } })
    const { fetchMe, currentUser, isLoggedIn } = useUser()

    await fetchMe()

    expect(currentUser.value).toBeNull()
    expect(isLoggedIn.value).toBe(false)
  })

  it('treats an answer without a user as signed out', async () => {
    signIn(testUser())
    stubApi({ 'GET /auth/me': { body: { user: null } } })

    await useUser().fetchMe()

    expect(useUser().currentUser.value).toBeNull()
  })

  it('remembers the user for this tab, and forgets them on sign-out', async () => {
    signIn(testUser({ display_name: 'Morgan' }))
    await nextTick()
    expect(JSON.parse(sessionStorage.getItem(STORAGE_KEYS.currentUser) ?? 'null')).toMatchObject({
      display_name: 'Morgan',
    })

    signIn(null)
    await nextTick()
    expect(sessionStorage.getItem(STORAGE_KEYS.currentUser)).toBeNull()
  })

  it('starts from the remembered user, so a reload shows them signed in at once', async () => {
    sessionStorage.setItem(
      STORAGE_KEYS.currentUser,
      JSON.stringify(testUser({ display_name: 'Morgan' })),
    )
    vi.resetModules()

    const fresh = await import('@/composables/useUser')

    expect(fresh.useUser().currentUser.value?.display_name).toBe('Morgan')
  })
})

describe('useUser.login', () => {
  beforeEach(() => signIn(null))

  it('sends the credentials, remember-me and Turnstile token, then signs in', async () => {
    const user = testUser()
    const api = stubApi({ 'POST /auth/login': { body: { user } } })
    const { login, currentUser } = useUser()

    expect(await login('reader@example.test', 'correct horse', true, 'cf-token')).toEqual({
      ok: true,
    })

    expect(sentBody(api)).toEqual({
      email: 'reader@example.test',
      password: 'correct horse',
      remember_me: true,
      turnstile_token: 'cf-token',
    })
    expect(currentUser.value).toEqual(user)
  })

  it("stays signed out and passes the server's reason on", async () => {
    stubApi({ 'POST /auth/login': { status: 401, body: problem('Invalid email or password.') } })
    const { login, currentUser } = useUser()

    expect(await login('reader@example.test', 'wrong')).toEqual({
      ok: false,
      error: 'Invalid email or password.',
    })
    expect(currentUser.value).toBeNull()
  })

  it('reports a network failure', async () => {
    offline()

    expect(await useUser().login('a@b.test', 'pw')).toEqual({
      ok: false,
      error: NETWORK_ERROR_MESSAGE,
    })
  })
})

describe('useUser.logout', () => {
  it('signs out', async () => {
    signIn(testUser())
    stubApi({ 'POST /auth/logout': { status: 204 } })

    await useUser().logout()

    expect(useUser().currentUser.value).toBeNull()
  })

  it('signs out locally even when the server cannot be reached', async () => {
    signIn(testUser())
    offline()

    await useUser().logout()

    expect(useUser().isLoggedIn.value).toBe(false)
  })
})

describe('useUser.register', () => {
  it('creates the account with the display name the API expects', async () => {
    const api = stubApi({
      'POST /auth/register': {
        status: 201,
        body: { message: 'Check your email to activate your account.' },
      },
    })

    const res = await useUser().register('new@example.test', 'Newcomer', 'a long passphrase')

    expect(sentBody(api)).toEqual({
      email: 'new@example.test',
      display_name: 'Newcomer',
      password: 'a long passphrase',
    })
    expect(res).toEqual({
      ok: true,
      message: 'Check your email to activate your account.',
      activationLink: undefined,
    })
  })

  it('passes on the activation link a server without email hands back', async () => {
    stubApi({
      'POST /auth/register': {
        status: 201,
        body: { message: 'Created.', activation_link: 'http://localhost/activate?token=t' },
      },
    })

    const res = await useUser().register('new@example.test', 'Newcomer', 'a long passphrase')

    expect(res.activationLink).toBe('http://localhost/activate?token=t')
  })

  it('returns every field the server refused', async () => {
    stubApi({
      'POST /auth/register': {
        status: 422,
        body: problem(
          'Please enter a valid email address. Password must be at least 12 characters.',
          {
            errors: [
              { message: 'Please enter a valid email address.', location: 'body.email' },
              { message: 'Password must be at least 12 characters.', location: 'body.password' },
            ],
          },
        ),
      },
    })

    expect(await useUser().register('not-an-email', 'X', 'short')).toEqual({
      ok: false,
      errors: ['Please enter a valid email address.', 'Password must be at least 12 characters.'],
    })
  })

  it('returns a taken display name as its one error', async () => {
    stubApi({
      'POST /auth/register': { status: 409, body: problem('That display name is already taken.') },
    })

    expect(await useUser().register('new@example.test', 'Reader', 'a long passphrase')).toEqual({
      ok: false,
      errors: ['That display name is already taken.'],
    })
  })

  it('falls back to the single error when there is no list', async () => {
    stubApi({ 'POST /auth/register': { status: 429, body: problem('Too many attempts.') } })

    expect(await useUser().register('a@b.test', 'X', 'pw')).toEqual({
      ok: false,
      errors: ['Too many attempts.'],
    })
  })

  it('explains a failure with no body in its own words', async () => {
    stubApi({ 'POST /auth/register': { status: 500 } })

    expect(await useUser().register('a@b.test', 'X', 'pw')).toEqual({
      ok: false,
      errors: ['Registration failed.'],
    })
  })
})

describe('useUser account recovery', () => {
  it('activates an account from its emailed token', async () => {
    const api = stubApi({
      'POST /auth/activate': { body: { message: 'Your account is now active.' } },
    })

    expect(await useUser().activate('tok')).toEqual({
      ok: true,
      message: 'Your account is now active.',
    })
    expect(sentBody(api)).toEqual({ token: 'tok' })
  })

  it('says why an activation link was refused', async () => {
    stubApi({ 'POST /auth/activate': { status: 422, body: problem('Link expired.') } })

    expect(await useUser().activate('old')).toEqual({ ok: false, error: 'Link expired.' })
  })

  it('asks for a reset email and passes on a dev reset link', async () => {
    const api = stubApi({
      'POST /auth/forgot-password': {
        body: { message: 'If that account exists…', reset_link: 'http://localhost/r?t=1' },
      },
    })

    expect(await useUser().requestPasswordReset('reader@example.test')).toEqual({
      ok: true,
      message: 'If that account exists…',
      resetLink: 'http://localhost/r?t=1',
    })
    expect(sentBody(api)).toEqual({ email: 'reader@example.test' })
  })

  it('reports why a reset email could not be requested', async () => {
    stubApi({ 'POST /auth/forgot-password': { status: 429, body: problem('Slow down.') } })

    expect(await useUser().requestPasswordReset('reader@example.test')).toEqual({
      ok: false,
      message: 'Slow down.',
    })
  })

  it('sets the new password with the token from the link', async () => {
    const api = stubApi({ 'POST /auth/reset-password': { body: { message: 'Password updated.' } } })

    expect(await useUser().resetPassword('tok', 'a new passphrase')).toEqual({
      ok: true,
      message: 'Password updated.',
    })
    expect(sentBody(api)).toEqual({ token: 'tok', password: 'a new passphrase' })
  })

  it('says why a new password was refused', async () => {
    stubApi({ 'POST /auth/reset-password': { status: 422 } })

    expect(await useUser().resetPassword('tok', 'pw')).toEqual({
      ok: false,
      error: 'Reset failed.',
    })
  })
})
