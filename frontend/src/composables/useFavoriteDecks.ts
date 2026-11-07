import { ref, type Ref } from 'vue'
import { apiRequest } from './apiClient'
import { endpoints } from '@/api/endpoints'
import { useUser } from './useUser'
import { useToasts } from './useToasts'

const favoriteDecks: Ref<number[]> = ref([])
// Dedupe concurrent callers into a single in-flight request (see useFavoriteSpreads).
let inFlight: Promise<void> | null = null

export function useFavoriteDecks() {
  const { isLoggedIn } = useUser()

  function fetchFavoriteDecks(): Promise<void> {
    // Favourites are account data: for a signed-out visitor this endpoint can
    // only ever answer 401, and both deck-picker pages call it on mount. Guard
    // here rather than at each call site so every caller is covered (mirrors
    // the isLoggedIn checks in useSpreads).
    if (!isLoggedIn.value) {
      return Promise.resolve()
    }
    if (inFlight) {
      return inFlight
    }
    inFlight = (async () => {
      const res = await apiRequest<number[]>(endpoints.account.favoriteDecks)
      if (res.ok) {
        favoriteDecks.value = res.data
      } else if (res.status !== 401) {
        // A 401 is an expired session, which the router's revalidation handles.
        useToasts().error(`Couldn't load your favourite decks. ${res.error}`, { detail: res })
      }
    })().finally(() => {
      inFlight = null
    })
    return inFlight
  }

  function isFavorite(deckId: number): boolean {
    return favoriteDecks.value.includes(deckId)
  }

  async function addFavorite(deckId: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.favoriteDecks, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ deck_id: deckId }),
    })
    if (!res.ok) {
      return false
    }
    favoriteDecks.value = [...favoriteDecks.value, deckId]
    return true
  }

  async function removeFavorite(deckId: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.favoriteDeckById(deckId), {
      method: 'DELETE',
    })
    if (!res.ok) {
      return false
    }
    favoriteDecks.value = favoriteDecks.value.filter((id) => id !== deckId)
    return true
  }

  async function toggleFavorite(deckId: number): Promise<boolean> {
    const ok = isFavorite(deckId) ? await removeFavorite(deckId) : await addFavorite(deckId)
    // Callers fire-and-forget (the star just doesn't change), so say it here.
    if (!ok) {
      useToasts().error("Couldn't update your favourite decks. Please try again.")
    }
    return ok
  }

  return {
    favoriteDecks,
    fetchFavoriteDecks,
    isFavorite,
    addFavorite,
    removeFavorite,
    toggleFavorite,
  }
}
