import { ref } from 'vue'
import { useAdminApi } from './useApi'
import { endpoints } from '@/api/endpoints'
import type { DeckSystem } from '@/types'

/**
 * The lists behind the admin Deck Systems pages: the approved systems and the
 * submissions waiting for review. Each page loads the ones it shows; a failed
 * load keeps the last list (useAdminApi has toasted why).
 */
export function useAdminDeckSystems() {
  const api = useAdminApi()

  const systems = ref<DeckSystem[]>([])
  const pending = ref<DeckSystem[]>([])

  async function loadSystems(): Promise<void> {
    const data = await api.get<DeckSystem[]>(endpoints.admin.deckSystems.list)
    if (data) {
      systems.value = data
    }
  }

  async function loadPending(): Promise<void> {
    const data = await api.get<DeckSystem[]>(endpoints.admin.deckSystems.pending)
    if (data) {
      pending.value = data
    }
  }

  return { systems, pending, loadSystems, loadPending }
}
