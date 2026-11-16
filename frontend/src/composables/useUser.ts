import { ref, computed, watch } from 'vue'
import { session } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import { apiRequest, fieldErrors, sendJson } from './apiClient'
import { endpoints } from '@/api/endpoints'
import type { User } from '@/types'

const USER_KEY = STORAGE_KEYS.currentUser

// Seed from session storage so nav/guards reflect the logged-in user instantly
// on reload, before the async /me revalidation completes.
const currentUser = ref<User | null>(session.get<User | null>(USER_KEY, null))

// Mirror changes back to session storage.
watch(
  currentUser,
  (val) => {
    if (val) {
      session.set(USER_KEY, val)
    } else {
      session.remove(USER_KEY)
    }
  },
  { deep: true },
)

export interface RegisterResult {
  ok: boolean
  errors?: string[]
  message?: string
  /** Present only in non-production when SMTP is unconfigured. */
  activationLink?: string
}

interface MeResponse {
  user?: User | null
}
interface RegisterResponse {
  message?: string
  activation_link?: string
}
interface MessageResponse {
  message?: string
}
interface ForgotResponse {
  message?: string
  reset_link?: string
}
interface LoginResponse {
  user?: User | null
}

export function useUser() {
  const isLoggedIn = computed(() => currentUser.value !== null)

  /** Revalidate the session against the server. */
  async function fetchMe(): Promise<void> {
    const res = await apiRequest<MeResponse>(endpoints.auth.me)
    currentUser.value = res.ok ? (res.data.user ?? null) : null
  }

  async function register(
    email: string,
    displayName: string,
    password: string,
  ): Promise<RegisterResult> {
    const res = await apiRequest<RegisterResponse>(
      endpoints.auth.register,
      sendJson('POST', { email, display_name: displayName, password }),
      'Registration failed.',
    )

    if (res.ok) {
      return {
        ok: true,
        message: typeof res.data.message === 'string' ? res.data.message : undefined,
        activationLink:
          typeof res.data.activation_link === 'string' ? res.data.activation_link : undefined,
      }
    }

    // A failed validation lists each field the server refused; anything else
    // (a taken display name, a rate limit) is the one message.
    const errors = fieldErrors(res.data).map((error) => error.message)
    return { ok: false, errors: errors.length > 0 ? errors : [res.error] }
  }

  async function activate(
    token: string,
  ): Promise<{ ok: boolean; message?: string; error?: string }> {
    const res = await apiRequest<MessageResponse>(
      endpoints.auth.activate,
      sendJson('POST', { token }),
      'Activation failed.',
    )
    return res.ok
      ? { ok: true, message: typeof res.data.message === 'string' ? res.data.message : undefined }
      : { ok: false, error: res.error }
  }

  async function requestPasswordReset(
    email: string,
  ): Promise<{ ok: boolean; message?: string; resetLink?: string }> {
    const res = await apiRequest<ForgotResponse>(
      endpoints.auth.forgotPassword,
      sendJson('POST', { email }),
      'Request failed.',
    )
    return res.ok
      ? {
          ok: true,
          message: typeof res.data.message === 'string' ? res.data.message : undefined,
          resetLink: typeof res.data.reset_link === 'string' ? res.data.reset_link : undefined,
        }
      : { ok: false, message: res.error }
  }

  async function resetPassword(
    token: string,
    password: string,
  ): Promise<{ ok: boolean; message?: string; error?: string }> {
    const res = await apiRequest<MessageResponse>(
      endpoints.auth.resetPassword,
      sendJson('POST', { token, password }),
      'Reset failed.',
    )
    return res.ok
      ? { ok: true, message: typeof res.data.message === 'string' ? res.data.message : undefined }
      : { ok: false, error: res.error }
  }

  async function login(
    email: string,
    password: string,
    rememberMe = false,
    turnstileToken = '',
  ): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest<LoginResponse>(
      endpoints.auth.login,
      sendJson('POST', {
        email,
        password,
        remember_me: rememberMe,
        turnstile_token: turnstileToken,
      }),
      'Login failed.',
    )

    if (res.ok) {
      currentUser.value = res.data.user ?? null
      return { ok: true }
    }
    return { ok: false, error: res.error }
  }

  async function logout(): Promise<void> {
    // Best-effort: clear local state regardless of the server's response.
    await apiRequest(endpoints.auth.logout, { method: 'POST' })
    currentUser.value = null
  }

  return {
    currentUser,
    isLoggedIn,
    fetchMe,
    register,
    activate,
    requestPasswordReset,
    resetPassword,
    login,
    logout,
  }
}
