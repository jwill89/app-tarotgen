import { useUser } from './useUser'
import { useToasts } from './useToasts'
import { useRouter } from 'vue-router'
import { apiRequest, type ApiResult } from './apiClient'

// Re-export the framework-free client, so '@/composables/useApi' also serves
// apiFetch, apiRequest, readApiError, types, …
export * from './apiClient'

const JSON_HEADERS = { 'Content-Type': 'application/json' }

/**
 * Composable for admin API calls: auto-handles 401 (drop to login), toasts the
 * server's error message on other failures, and toasts a success message when
 * one is provided. All paths are relative to `/api/admin`.
 */
export function useAdminApi() {
  const { currentUser } = useUser()
  const { error: toastError, success: toastSuccess } = useToasts()
  const router = useRouter()

  async function requestResult<T>(
    path: string,
    options: RequestInit = {},
    successMessage?: string,
    { quiet = false }: { quiet?: boolean } = {},
  ): Promise<ApiResult<T>> {
    const res = await apiRequest<T>('/admin' + path, { headers: JSON_HEADERS, ...options })

    if (!res.ok) {
      if (res.status === 401) {
        // Session expired or admin was revoked — drop to the login screen.
        currentUser.value = null
        void router.push({ name: 'login' })
      } else if (!quiet) {
        // Surface the server's message (or the transport error) so admins see
        // why an action failed; log the body/status for post-mortem.
        toastError(res.error, { detail: { status: res.status, body: res.data } })
      }
      return res
    }

    if (successMessage) {
      toastSuccess(successMessage)
    }
    return res
  }

  async function request<T>(
    path: string,
    options: RequestInit = {},
    successMessage?: string,
  ): Promise<T | null> {
    const res = await requestResult<T>(path, options, successMessage)
    return res.ok ? res.data : null
  }

  function get<T>(path: string) {
    return request<T>(path)
  }

  /**
   * A GET for a page that shows its own failure (a "not found" state): the whole
   * result, status and message included, with nothing toasted. A 401 still
   * drops to the login page.
   */
  function getResult<T>(path: string): Promise<ApiResult<T>> {
    return requestResult<T>(path, {}, undefined, { quiet: true })
  }

  function post<T>(path: string, data: unknown, successMessage?: string) {
    return request<T>(path, { method: 'POST', body: JSON.stringify(data) }, successMessage)
  }

  function put<T>(path: string, data: unknown, successMessage?: string) {
    return request<T>(path, { method: 'PUT', body: JSON.stringify(data) }, successMessage)
  }

  function patch<T>(path: string, data: unknown, successMessage?: string) {
    return request<T>(path, { method: 'PATCH', body: JSON.stringify(data) }, successMessage)
  }

  // A successful DELETE may answer 204 No Content (no body) or 200 with a small
  // JSON summary (e.g. { deleted: N }). Return the body when there is one,
  // otherwise `true`, so callers can reliably gate a refresh on the result — a
  // 204 is a success, not the falsy `null` that a failure returns.
  async function del<T = unknown>(path: string, successMessage?: string): Promise<T | null> {
    const res = await requestResult<T>(path, { method: 'DELETE' }, successMessage)
    if (!res.ok) {
      return null
    }
    return (res.data ?? true) as T
  }

  return { get, getResult, post, put, patch, del }
}
