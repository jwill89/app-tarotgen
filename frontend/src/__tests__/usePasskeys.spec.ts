import { describe, it, expect, beforeEach, vi } from 'vitest'
import { usePasskeys } from '@/composables/usePasskeys'
import { useUser } from '@/composables/useUser'
import type { User } from '@/types'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

/** A fetch stub that answers by URL and records what was sent. */
function routes(table: Record<string, Response>) {
  // The init parameter is unused, but typed so specs can read what was sent.
  const fetchMock = vi.fn((url: string, _init?: RequestInit) =>
    Promise.resolve(table[url] ?? fakeResponse({ error: 'unrouted' }, { ok: false, status: 404 })),
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const bytes = (...b: number[]) => new Uint8Array(b).buffer
const asBytes = (buf: unknown) => [...new Uint8Array(buf as ArrayBuffer)]
const user = { user_id: 1, display_name: 'Reader', has_passkeys: true } as User

describe('usePasskeys.registerPasskey', () => {
  it('hands WebAuthn bytes, sends the attestation back as base64url, and lists the new key', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/register/options': fakeResponse({
        publicKey: {
          challenge: 'AQID',
          rp: { name: 'TarotGen.io' },
          user: { id: 'BAUG', name: 'reader', displayName: 'Reader' },
          pubKeyCredParams: [],
          excludeCredentials: [{ type: 'public-key', id: 'BwgJ' }],
        },
      }),
      '/api/auth/passkeys/register': fakeResponse({
        success: true,
        passkey: { passkey_id: 9, name: 'Laptop', created_at: 'now', last_used_at: null },
      }),
      '/api/auth/passkeys': fakeResponse([
        {
          passkey_id: 9,
          credential_id: 'BwgJ',
          name: 'Laptop',
          created_at: 'now',
          last_used_at: null,
        },
      ]),
      '/api/auth/me': fakeResponse({ user }),
    })
    const create = vi.fn().mockResolvedValue({
      response: { clientDataJSON: bytes(10), attestationObject: bytes(11, 12) },
    })
    vi.stubGlobal('navigator', { credentials: { create } })

    const passkeys = usePasskeys()
    const result = await passkeys.registerPasskey('Laptop')

    const { publicKey } = create.mock.calls[0]?.[0] as CredentialCreationOptions & {
      publicKey: PublicKeyCredentialCreationOptions
    }
    expect(asBytes(publicKey.challenge)).toEqual([1, 2, 3])
    expect(asBytes(publicKey.user.id)).toEqual([4, 5, 6])
    expect(asBytes(publicKey.excludeCredentials?.[0]?.id)).toEqual([7, 8, 9])

    const sent = fetchMock.mock.calls.find(([url]) => url === '/api/auth/passkeys/register')
    expect(JSON.parse(sent![1]!.body as string)).toEqual({
      clientDataJSON: 'Cg',
      attestationObject: 'Cww',
      name: 'Laptop',
    })
    expect(result.ok).toBe(true)
    expect(passkeys.passkeys.value.map((p) => p.passkey_id)).toEqual([9])
    expect(useUser().currentUser.value?.has_passkeys).toBe(true)
  })

  it("shows the server's reason when it won't start a registration", async () => {
    routes({
      '/api/auth/passkeys/register/options': fakeResponse(
        { error: 'Not authenticated' },
        { ok: false, status: 401 },
      ),
    })

    expect(await usePasskeys().registerPasskey('Laptop')).toEqual({
      ok: false,
      error: 'Not authenticated',
    })
  })

  it('reports a cancelled prompt', async () => {
    routes({
      '/api/auth/passkeys/register/options': fakeResponse({
        publicKey: {
          challenge: 'AQID',
          rp: { name: 'T' },
          user: { id: 'BAUG' },
          pubKeyCredParams: [],
        },
      }),
    })
    vi.stubGlobal('navigator', {
      credentials: { create: vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')) },
    })

    const result = await usePasskeys().registerPasskey('Laptop')

    expect(result).toEqual({ ok: false, error: 'Passkey creation was cancelled or not allowed.' })
  })
})

describe('usePasskeys.loginWithPasskey', () => {
  it('signs in with the assertion and keeps the returned user', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/login/options': fakeResponse({
        publicKey: { challenge: 'AQID', allowCredentials: [{ type: 'public-key', id: 'BAUG' }] },
      }),
      '/api/auth/passkeys/login': fakeResponse({ success: true, user }),
    })
    const get = vi.fn().mockResolvedValue({
      rawId: bytes(1),
      response: { clientDataJSON: bytes(2), authenticatorData: bytes(3), signature: bytes(4) },
    })
    vi.stubGlobal('navigator', { credentials: { get } })
    useUser().currentUser.value = null

    const result = await usePasskeys().loginWithPasskey('reader@example.test')

    const { publicKey } = get.mock.calls[0]?.[0] as { publicKey: PublicKeyCredentialRequestOptions }
    expect(asBytes(publicKey.challenge)).toEqual([1, 2, 3])
    expect(asBytes(publicKey.allowCredentials?.[0]?.id)).toEqual([4, 5, 6])
    const sent = fetchMock.mock.calls.find(([url]) => url === '/api/auth/passkeys/login')
    expect(JSON.parse(sent![1]!.body as string)).toEqual({
      id: 'AQ',
      clientDataJSON: 'Ag',
      authenticatorData: 'Aw',
      signature: 'BA',
    })
    expect(result).toEqual({ ok: true })
    expect(useUser().currentUser.value?.user_id).toBe(1)
  })
})

describe('usePasskeys management', () => {
  it('renames locally only once the server agrees', async () => {
    routes({
      '/api/auth/passkeys': fakeResponse([{ passkey_id: 3, name: 'Old' }]),
      '/api/auth/passkeys/3': fakeResponse({ error: 'Name too long.' }, { ok: false, status: 400 }),
    })
    const passkeys = usePasskeys()
    await passkeys.listPasskeys()

    const result = await passkeys.renamePasskey(3, 'x'.repeat(300))

    expect(result).toEqual({ ok: false, error: 'Name too long.' })
    expect(passkeys.passkeys.value[0]?.name).toBe('Old')
  })

  it('reports a network failure instead of throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const result = await usePasskeys().deletePasskey(3)

    expect(result.ok).toBe(false)
    expect(result.error).toMatch(/Network error/)
  })
})

const creationOptions = () =>
  fakeResponse({
    publicKey: { challenge: 'AQID', rp: { name: 'T' }, user: { id: 'BAUG' }, pubKeyCredParams: [] },
  })
const requestOptions = () => fakeResponse({ publicKey: { challenge: 'AQID' } })
const attestation = { response: { clientDataJSON: bytes(1), attestationObject: bytes(2) } }
const assertion = {
  rawId: bytes(1),
  response: { clientDataJSON: bytes(2), authenticatorData: bytes(3), signature: bytes(4) },
}

describe('usePasskeys.registerPasskey failures', () => {
  it('reports a prompt the user closed', async () => {
    routes({ '/api/auth/passkeys/register/options': creationOptions() })
    vi.stubGlobal('navigator', { credentials: { create: vi.fn().mockResolvedValue(null) } })

    expect(await usePasskeys().registerPasskey('Laptop')).toEqual({
      ok: false,
      error: 'Passkey creation was cancelled.',
    })
  })

  it("adds nothing when the server won't accept the new key", async () => {
    routes({
      '/api/auth/passkeys/register/options': creationOptions(),
      '/api/auth/passkeys/register': fakeResponse(
        { error: 'Attestation could not be verified.' },
        { ok: false, status: 400 },
      ),
    })
    vi.stubGlobal('navigator', { credentials: { create: vi.fn().mockResolvedValue(attestation) } })
    const passkeys = usePasskeys()

    expect(await passkeys.registerPasskey('Laptop')).toEqual({
      ok: false,
      error: 'Attestation could not be verified.',
    })
    expect(passkeys.passkeys.value).toEqual([])
  })

  it('reports an authenticator error it does not recognise', async () => {
    routes({ '/api/auth/passkeys/register/options': creationOptions() })
    vi.stubGlobal('navigator', {
      credentials: {
        create: vi.fn().mockRejectedValue(new DOMException('bad', 'InvalidStateError')),
      },
    })

    expect(await usePasskeys().registerPasskey('Laptop')).toEqual({
      ok: false,
      error: 'An unexpected error occurred during passkey registration.',
    })
  })
})

describe('usePasskeys.loginWithPasskey failures', () => {
  beforeEach(() => {
    useUser().currentUser.value = null
  })

  it('asks for options without an email when none was typed (discoverable keys)', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/login/options': fakeResponse(
        { error: 'Passkeys are unavailable.' },
        { ok: false, status: 503 },
      ),
    })

    expect(await usePasskeys().loginWithPasskey()).toEqual({
      ok: false,
      error: 'Passkeys are unavailable.',
    })
    expect(fetchMock.mock.calls[0]?.[1]?.body).toBe(JSON.stringify({ email: '' }))
  })

  it('reports a prompt the user closed', async () => {
    routes({ '/api/auth/passkeys/login/options': requestOptions() })
    vi.stubGlobal('navigator', { credentials: { get: vi.fn().mockResolvedValue(null) } })

    expect(await usePasskeys().loginWithPasskey()).toEqual({
      ok: false,
      error: 'Passkey authentication was cancelled.',
    })
  })

  it('reports a refused prompt', async () => {
    routes({ '/api/auth/passkeys/login/options': requestOptions() })
    vi.stubGlobal('navigator', {
      credentials: { get: vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')) },
    })

    expect(await usePasskeys().loginWithPasskey()).toEqual({
      ok: false,
      error: 'Passkey authentication was cancelled or not allowed.',
    })
  })

  it('reports an authenticator error it does not recognise', async () => {
    routes({ '/api/auth/passkeys/login/options': requestOptions() })
    vi.stubGlobal('navigator', { credentials: { get: vi.fn().mockRejectedValue(new Error('?')) } })

    expect(await usePasskeys().loginWithPasskey()).toEqual({
      ok: false,
      error: 'An unexpected error occurred during passkey login.',
    })
  })

  it('stays signed out when the server rejects the assertion', async () => {
    routes({
      '/api/auth/passkeys/login/options': requestOptions(),
      '/api/auth/passkeys/login': fakeResponse(
        { error: 'Unknown passkey.' },
        { ok: false, status: 401 },
      ),
    })
    vi.stubGlobal('navigator', { credentials: { get: vi.fn().mockResolvedValue(assertion) } })

    expect(await usePasskeys().loginWithPasskey()).toEqual({
      ok: false,
      error: 'Unknown passkey.',
    })
    expect(useUser().currentUser.value).toBeNull()
  })
})

describe('usePasskeys list and settings', () => {
  it('shows it is loading until the list arrives', async () => {
    let answer: (r: Response) => void = () => {}
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((resolve) => (answer = resolve))),
    )
    const passkeys = usePasskeys()

    const loaded = passkeys.listPasskeys()
    expect(passkeys.loading.value).toBe(true)
    answer(fakeResponse([{ passkey_id: 3, name: 'Phone' }]))
    await loaded

    expect(passkeys.loading.value).toBe(false)
    expect(passkeys.passkeys.value.map((p) => p.name)).toEqual(['Phone'])
  })

  it('renames the key locally once the server agrees', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys': fakeResponse([{ passkey_id: 3, name: 'Old' }]),
      '/api/auth/passkeys/3': fakeResponse(null, { status: 204 }),
    })
    const passkeys = usePasskeys()
    await passkeys.listPasskeys()

    expect(await passkeys.renamePasskey(3, 'Phone')).toEqual({ ok: true })

    expect(passkeys.passkeys.value[0]?.name).toBe('Phone')
    const sent = fetchMock.mock.calls.find(([url]) => url === '/api/auth/passkeys/3')
    expect(sent?.[1]).toMatchObject({ method: 'PATCH', body: JSON.stringify({ name: 'Phone' }) })
  })

  it('removes a deleted key and refreshes whether the user still has any', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys': fakeResponse([
        { passkey_id: 3, name: 'Phone' },
        { passkey_id: 4, name: 'Laptop' },
      ]),
      '/api/auth/passkeys/3': fakeResponse(null, { status: 204 }),
      '/api/auth/me': fakeResponse({ user }),
    })
    const passkeys = usePasskeys()
    await passkeys.listPasskeys()

    expect(await passkeys.deletePasskey(3)).toEqual({ ok: true })

    expect(passkeys.passkeys.value.map((p) => p.passkey_id)).toEqual([4])
    expect(fetchMock.mock.calls.map(([url]) => url)).toContain('/api/auth/me')
  })

  it('turns password login off and takes the updated user', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/password-login': fakeResponse({
        user: { ...user, password_login_disabled: true },
      }),
    })
    useUser().currentUser.value = user

    expect(await usePasskeys().togglePasswordLogin(true)).toEqual({ ok: true })

    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({
      method: 'PATCH',
      body: JSON.stringify({ disable: true }),
    })
    expect(useUser().currentUser.value?.password_login_disabled).toBe(true)
  })

  it('keeps password login as it was when the server refuses', async () => {
    routes({
      '/api/auth/passkeys/password-login': fakeResponse(
        { error: 'That would leave you no way to sign in. First add a passkey.' },
        { ok: false, status: 409 },
      ),
    })
    useUser().currentUser.value = user

    expect(await usePasskeys().togglePasswordLogin(true)).toEqual({
      ok: false,
      error: 'That would leave you no way to sign in. First add a passkey.',
    })
    expect(useUser().currentUser.value).toEqual(user)
  })

  it('offers passkeys only where the browser has WebAuthn', () => {
    // jsdom has no PublicKeyCredential (vitest.setup.ts gives it navigator.credentials).
    expect(usePasskeys().isSupported()).toBe(false)

    vi.stubGlobal('PublicKeyCredential', vi.fn())
    expect(usePasskeys().isSupported()).toBe(true)
  })
})
