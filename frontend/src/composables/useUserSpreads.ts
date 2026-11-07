import { ref, type Ref } from 'vue'
import type { UserSpread } from '@/types'
import { apiFetch, apiRequest, sendJson, type ApiResult } from './apiClient'
import { endpoints } from '@/api/endpoints'

const userSpreads: Ref<UserSpread[]> = ref([])

export function useUserSpreads() {
  async function fetchUserSpreads(): Promise<void> {
    const data = await apiFetch<UserSpread[]>(endpoints.account.spreads)
    if (data) {
      userSpreads.value = data
    }
  }

  /** The whole result, so a refused save can say why (the server's message). */
  async function createUserSpread(payload: {
    name: string
    description: string
    card_count: number
    positions: unknown[]
  }): Promise<ApiResult<UserSpread>> {
    const res = await apiRequest<UserSpread>(
      endpoints.account.spreads,
      sendJson('POST', payload),
      'Failed to save the spread. Please try again.',
    )
    if (res.ok) {
      userSpreads.value = [res.data, ...userSpreads.value]
    }
    return res
  }

  /** The whole result, so a refused edit can say why (the server's message). */
  async function updateUserSpread(
    id: number,
    payload: { name?: string; description?: string; card_count?: number; positions?: unknown[] },
  ): Promise<ApiResult<UserSpread>> {
    const res = await apiRequest<UserSpread>(
      endpoints.account.spreadById(id),
      sendJson('PUT', payload),
      'Failed to save changes. Please try again.',
    )
    if (res.ok) {
      userSpreads.value = userSpreads.value.map((s) => (s.user_spread_id === id ? res.data : s))
    }
    return res
  }

  async function deleteUserSpread(id: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.spreadById(id), { method: 'DELETE' })
    if (!res.ok) {
      return false
    }
    userSpreads.value = userSpreads.value.filter((s) => s.user_spread_id !== id)
    return true
  }

  async function submitAsPublic(id: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.spreadSubmit(id), { method: 'POST' })
    return res.ok
  }

  /**
   * Copy a public spread into the user's own (named "… (copy)" by the server).
   * The whole result, so a refusal can say why.
   */
  async function copyPublicSpread(spreadId: number): Promise<ApiResult<UserSpread>> {
    const res = await apiRequest<UserSpread>(
      endpoints.spreads.copy(spreadId),
      { method: 'POST' },
      "Couldn't copy the spread. Please try again.",
    )
    if (res.ok) {
      userSpreads.value = [res.data, ...userSpreads.value]
    }
    return res
  }

  return {
    userSpreads,
    fetchUserSpreads,
    createUserSpread,
    updateUserSpread,
    deleteUserSpread,
    submitAsPublic,
    copyPublicSpread,
  }
}
