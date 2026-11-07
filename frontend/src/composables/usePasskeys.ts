import { ref } from 'vue'
import { useUser } from './useUser'
import { endpoints } from '@/api/endpoints'
import { apiFetch, apiRequest, sendJson } from './apiClient'
import type { Passkey, User } from '@/types'

/** A credential reference as the server sends it: the id is base64url, not bytes. */
type CredentialDescriptorJson = Omit<PublicKeyCredentialDescriptor, 'id'> & { id: string }

/** `navigator.credentials.create()` options as the server sends them (binary fields base64url). */
interface CreationOptionsJson {
  publicKey: Omit<
    PublicKeyCredentialCreationOptions,
    'challenge' | 'user' | 'excludeCredentials'
  > & {
    challenge: string
    user: Omit<PublicKeyCredentialUserEntity, 'id'> & { id: string }
    excludeCredentials?: CredentialDescriptorJson[]
  }
}

/** `navigator.credentials.get()` options as the server sends them (binary fields base64url). */
interface RequestOptionsJson {
  publicKey: Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
    challenge: string
    allowCredentials?: CredentialDescriptorJson[]
  }
}

/**
 * Encode an ArrayBuffer to base64url string.
 */
function bufferToBase64Url(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode a base64url string to an ArrayBuffer.
 */
function base64UrlToBuffer(base64url: string): ArrayBuffer {
  const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes.buffer
}

function toDescriptor(cred: CredentialDescriptorJson): PublicKeyCredentialDescriptor {
  return { ...cred, id: base64UrlToBuffer(cred.id) }
}

export function usePasskeys() {
  const { currentUser, fetchMe } = useUser()
  const passkeys = ref<Passkey[]>([])
  const loading = ref(false)

  async function listPasskeys(): Promise<void> {
    loading.value = true
    try {
      const data = await apiFetch<Passkey[]>(endpoints.passkeys.list)
      if (data) {
        passkeys.value = data
      }
    } finally {
      loading.value = false
    }
  }

  async function registerPasskey(name: string): Promise<{ ok: boolean; error?: string }> {
    try {
      // 1. Get creation options from server.
      const options = await apiRequest<CreationOptionsJson>(
        endpoints.passkeys.registerOptions,
        { method: 'POST' },
        'Failed to get registration options.',
      )
      if (!options.ok) {
        return { ok: false, error: options.error }
      }

      // 2. Call the WebAuthn browser API, with the base64url fields as bytes.
      const { publicKey } = options.data
      const credential = (await navigator.credentials.create({
        publicKey: {
          ...publicKey,
          challenge: base64UrlToBuffer(publicKey.challenge),
          user: { ...publicKey.user, id: base64UrlToBuffer(publicKey.user.id) },
          excludeCredentials: publicKey.excludeCredentials?.map(toDescriptor),
        },
      })) as PublicKeyCredential | null
      if (!credential) {
        return { ok: false, error: 'Passkey creation was cancelled.' }
      }

      const attestationResponse = credential.response as AuthenticatorAttestationResponse

      // 3. Send attestation to server.
      const registered = await apiRequest(
        endpoints.passkeys.register,
        sendJson('POST', {
          clientDataJSON: bufferToBase64Url(attestationResponse.clientDataJSON),
          attestationObject: bufferToBase64Url(attestationResponse.attestationObject),
          name,
        }),
        'Registration failed.',
      )
      if (!registered.ok) {
        return { ok: false, error: registered.error }
      }

      // The reply describes the new key only in part (no credential id), so the
      // list is reloaded rather than patched; the user, to pick up has_passkeys.
      await Promise.all([listPasskeys(), fetchMe()])
      return { ok: true }
    } catch (e: unknown) {
      if (e instanceof DOMException && e.name === 'NotAllowedError') {
        return { ok: false, error: 'Passkey creation was cancelled or not allowed.' }
      }
      return { ok: false, error: 'An unexpected error occurred during passkey registration.' }
    }
  }

  async function loginWithPasskey(email?: string): Promise<{ ok: boolean; error?: string }> {
    try {
      // 1. Get assertion options.
      const options = await apiRequest<RequestOptionsJson>(
        endpoints.passkeys.loginOptions,
        sendJson('POST', { email: email ?? '' }),
        'Failed to get login options.',
      )
      if (!options.ok) {
        return { ok: false, error: options.error }
      }

      // 2. Call the WebAuthn browser API, with the base64url fields as bytes.
      const { publicKey } = options.data
      const assertion = (await navigator.credentials.get({
        publicKey: {
          ...publicKey,
          challenge: base64UrlToBuffer(publicKey.challenge),
          allowCredentials: publicKey.allowCredentials?.map(toDescriptor),
        },
      })) as PublicKeyCredential | null
      if (!assertion) {
        return { ok: false, error: 'Passkey authentication was cancelled.' }
      }

      const assertionResponse = assertion.response as AuthenticatorAssertionResponse

      // 3. Send assertion to server.
      const signedIn = await apiRequest<{ user: User }>(
        endpoints.passkeys.login,
        sendJson('POST', {
          id: bufferToBase64Url(assertion.rawId),
          clientDataJSON: bufferToBase64Url(assertionResponse.clientDataJSON),
          authenticatorData: bufferToBase64Url(assertionResponse.authenticatorData),
          signature: bufferToBase64Url(assertionResponse.signature),
        }),
        'Passkey login failed.',
      )
      if (!signedIn.ok) {
        return { ok: false, error: signedIn.error }
      }

      currentUser.value = signedIn.data.user
      return { ok: true }
    } catch (e: unknown) {
      if (e instanceof DOMException && e.name === 'NotAllowedError') {
        return { ok: false, error: 'Passkey authentication was cancelled or not allowed.' }
      }
      return { ok: false, error: 'An unexpected error occurred during passkey login.' }
    }
  }

  async function renamePasskey(id: number, name: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.passkeys.byId(id),
      sendJson('PATCH', { name }),
      'Could not rename passkey.',
    )
    if (!res.ok) {
      return { ok: false, error: res.error }
    }
    const pk = passkeys.value.find((p) => p.passkey_id === id)
    if (pk) {
      pk.name = name
    }
    return { ok: true }
  }

  async function deletePasskey(id: number): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.passkeys.byId(id),
      { method: 'DELETE' },
      'Could not delete passkey.',
    )
    if (!res.ok) {
      return { ok: false, error: res.error }
    }
    passkeys.value = passkeys.value.filter((p) => p.passkey_id !== id)
    await fetchMe()
    return { ok: true }
  }

  async function togglePasswordLogin(disable: boolean): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest<{ user: User }>(
      endpoints.passkeys.passwordLogin,
      sendJson('PATCH', { disable }),
      'Could not update setting.',
    )
    if (!res.ok) {
      return { ok: false, error: res.error }
    }
    currentUser.value = res.data.user
    return { ok: true }
  }

  /**
   * Check if the browser supports WebAuthn / passkeys.
   */
  function isSupported(): boolean {
    return 'PublicKeyCredential' in window && 'credentials' in navigator
  }

  return {
    passkeys,
    loading,
    isSupported,
    listPasskeys,
    registerPasskey,
    loginWithPasskey,
    renamePasskey,
    deletePasskey,
    togglePasswordLogin,
  }
}
