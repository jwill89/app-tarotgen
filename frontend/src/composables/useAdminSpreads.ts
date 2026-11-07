import { ref } from 'vue'
import { useAdminApi } from './useApi'
import { endpoints } from '@/api/endpoints'
import type { PendingSpread, Spread, SpreadCategory } from '@/types'

/**
 * The lists behind the admin Spreads pages: the public spreads, the library's
 * categories and the submission queue. Each page loads the ones it shows; a
 * failed load keeps the last list (useAdminApi has toasted why).
 */
export function useAdminSpreads() {
  const api = useAdminApi()

  const spreads = ref<Spread[]>([])
  const categories = ref<SpreadCategory[]>([])
  const pending = ref<PendingSpread[]>([])

  async function loadSpreads(): Promise<void> {
    const data = await api.get<Spread[]>(endpoints.admin.spreads.list)
    if (data) {
      spreads.value = data
    }
  }

  async function loadCategories(): Promise<void> {
    const data = await api.get<SpreadCategory[]>(endpoints.admin.spreadCategories.list)
    if (data) {
      categories.value = data
    }
  }

  async function loadPending(): Promise<void> {
    const data = await api.get<PendingSpread[]>(endpoints.admin.pendingSpreads.list)
    if (data) {
      pending.value = data
    }
  }

  return { spreads, categories, pending, loadSpreads, loadCategories, loadPending }
}
