import { ref, type Ref } from 'vue'
import { apiRequest } from './apiClient'
import { endpoints } from '@/api/endpoints'
import { useToasts } from './useToasts'
import type { FavoriteSpread } from '@/types'

const favorites: Ref<FavoriteSpread[]> = ref([])
// Dedupe concurrent callers: a single page load mounts several components that
// each call fetchFavorites — collapse them into one in-flight request instead of
// a burst of identical authenticated calls (each of which the server serializes).
let inFlight: Promise<void> | null = null

export function useFavoriteSpreads() {
  function fetchFavorites(): Promise<void> {
    if (inFlight) {
      return inFlight
    }
    inFlight = (async () => {
      const res = await apiRequest<FavoriteSpread[]>(endpoints.account.favorites)
      if (res.ok) {
        favorites.value = res.data
      } else if (res.status !== 401) {
        // A 401 is an expired session, which the router's revalidation handles.
        useToasts().error(`Couldn't load your favourite spreads. ${res.error}`, { detail: res })
      }
    })().finally(() => {
      inFlight = null
    })
    return inFlight
  }

  function isFavorite(spreadType: 'public' | 'personal', spreadId: number): boolean {
    return favorites.value.some((f) => f.spread_type === spreadType && f.spread_id === spreadId)
  }

  async function addFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
    const res = await apiRequest(endpoints.account.favorites, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ spread_type: spreadType, spread_id: spreadId }),
    })
    if (!res.ok) {
      return false
    }
    favorites.value = [...favorites.value, { spread_type: spreadType, spread_id: spreadId }]
    return true
  }

  async function removeFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
    const res = await apiRequest(endpoints.account.favoriteById(spreadType, spreadId), {
      method: 'DELETE',
    })
    if (!res.ok) {
      return false
    }
    favorites.value = favorites.value.filter(
      (f) => !(f.spread_type === spreadType && f.spread_id === spreadId),
    )
    return true
  }

  async function toggleFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
    const ok = isFavorite(spreadType, spreadId)
      ? await removeFavorite(spreadType, spreadId)
      : await addFavorite(spreadType, spreadId)
    // Callers fire-and-forget (the star just doesn't change), so say it here.
    if (!ok) {
      useToasts().error("Couldn't update your favourite spreads. Please try again.")
    }
    return ok
  }

  return {
    favorites,
    fetchFavorites,
    isFavorite,
    addFavorite,
    removeFavorite,
    toggleFavorite,
  }
}
