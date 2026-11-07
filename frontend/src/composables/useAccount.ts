import { useUser } from './useUser'
import { endpoints } from '@/api/endpoints'
import { apiFetch, apiRequest, sendJson } from './apiClient'
import type { AccountReading, ReadingSpread, ReadingTag, User, Visibility } from '@/types'

export interface ReadingMetaUpdate {
  reading_name?: string
  reading_notes?: string
  hide_user?: boolean
  question?: string
  querent?: string
  tags?: string[]
  visibility?: Visibility
  password?: string
  remove_password?: boolean
}

/**
 * What My Readings narrows the list to. `from` and `to` are the reader's own
 * dates (YYYY-MM-DD, both included); a blank field is no filter.
 */
export interface ReadingFilters {
  q?: string
  deck_id?: number | null
  /** A spread's name as the readings recorded it ("Free Draw" for unplaced draws). */
  spread?: string
  tag?: string
  from?: string
  to?: string
  daily?: '' | 'only' | 'exclude'
}

/** The browser's time zone, which the API reads `from` and `to` in. */
function timeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone
}

/** Signed-in user's self-service API (/api/account/*). */
export function useAccount() {
  const { currentUser } = useUser()

  /**
   * One page of the user's readings, newest first, plus their total count. A
   * failure comes back as `{ ok: false, error }` rather than an empty list, so
   * the page can't mistake "couldn't load" for "no readings yet".
   */
  async function listReadingsPage(
    page: number,
    perPage: number,
    filters: ReadingFilters = {},
  ): Promise<
    { ok: true; readings: AccountReading[]; total: number } | { ok: false; error: string }
  > {
    const offset = (Math.max(1, page) - 1) * perPage
    const query = new URLSearchParams({ limit: String(perPage), offset: String(offset) })
    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== null && value !== '') {
        query.set(key, String(value))
      }
    }
    if (query.has('from') || query.has('to')) {
      query.set('tz', timeZone())
    }

    const res = await apiRequest<AccountReading[]>(`${endpoints.account.readings}?${query}`)
    if (!res.ok) {
      return { ok: false, error: res.error }
    }

    // get() returns null when absent, and Number(null) is 0 — parse only a real value.
    const header = res.headers?.get('X-Total-Count')
    const total = header ? Number(header) : NaN
    return {
      ok: true,
      readings: res.data,
      // A proxy that strips the header must not break paging: fall back to
      // "at least what we can see".
      total: Number.isFinite(total) ? total : offset + res.data.length,
    }
  }

  /**
   * The tags on the user's readings with how many carry each, alphabetically.
   * Empty on any failure: they only feed suggestions and a filter.
   */
  async function listTags(): Promise<ReadingTag[]> {
    return (await apiFetch<ReadingTag[]>(endpoints.account.tags)) ?? []
  }

  /**
   * The spreads the user's readings were laid out in with how many used each,
   * alphabetically: only names the spread filter finds readings for. Empty on
   * any failure: they only feed a filter.
   */
  async function listReadingSpreads(): Promise<ReadingSpread[]> {
    return (await apiFetch<ReadingSpread[]>(endpoints.account.readingSpreads)) ?? []
  }

  async function updateReading(
    id: string,
    payload: ReadingMetaUpdate,
  ): Promise<{ ok: boolean; reading?: AccountReading; error?: string }> {
    const res = await apiRequest<AccountReading>(
      endpoints.account.readingById(encodeURIComponent(id)),
      sendJson('PATCH', payload),
      'Update failed.',
    )
    return res.ok ? { ok: true, reading: res.data } : { ok: false, error: res.error }
  }

  async function deleteReading(id: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.readingById(encodeURIComponent(id)),
      { method: 'DELETE' },
      'Could not delete reading.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  async function changeDisplayName(displayName: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest<{ user?: User }>(
      endpoints.account.profile,
      sendJson('PATCH', { display_name: displayName }),
      'Could not update display name.',
    )
    if (!res.ok) {
      return { ok: false, error: res.error }
    }
    if (res.data.user) {
      currentUser.value = res.data.user
    }
    return { ok: true }
  }

  async function changePassword(
    currentPassword: string,
    newPassword: string,
  ): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.changePassword,
      sendJson('POST', { current_password: currentPassword, new_password: newPassword }),
      'Could not change password.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  /**
   * Sign the account out on every other device; this browser stays signed in.
   * `ended` is how many sessions that signed out.
   */
  async function endOtherSessions(): Promise<
    { ok: true; ended: number } | { ok: false; error: string }
  > {
    const res = await apiRequest<{ ended: number }>(
      endpoints.account.endOtherSessions,
      { method: 'POST' },
      'Could not sign out your other devices.',
    )
    return res.ok ? { ok: true, ended: res.data.ended } : { ok: false, error: res.error }
  }

  /**
   * Where the journal download lives, for a plain link: the browser sends the
   * session cookie and saves the attachment itself, no fetch in between.
   */
  function journalExportUrl(format: 'json' | 'csv'): string {
    return '/api' + endpoints.account.readingsExport(format)
  }

  async function deleteAccount(password: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.root,
      sendJson('DELETE', { password }),
      'Could not delete account.',
    )
    if (!res.ok) {
      return { ok: false, error: res.error }
    }
    currentUser.value = null
    return { ok: true }
  }

  /** Detach the linked Google account (refused while it's the account's only way to sign in). */
  async function unlinkGoogle(): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.authGoogle.unlink,
      { method: 'POST' },
      'Could not unlink Google account.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  return {
    listReadingsPage,
    listTags,
    listReadingSpreads,
    updateReading,
    deleteReading,
    changeDisplayName,
    changePassword,
    endOtherSessions,
    journalExportUrl,
    deleteAccount,
    unlinkGoogle,
  }
}
