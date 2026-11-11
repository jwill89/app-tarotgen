import { ref, computed, type Ref } from 'vue'
import type { Spread, SpreadOption } from '@/types'
import { apiRequest } from './apiClient'
import { useToasts } from './useToasts'
import { endpoints } from '@/api/endpoints'
import { useUserSpreads } from './useUserSpreads'
import { useFavoriteSpreads } from './useFavoriteSpreads'
import { useUser } from './useUser'

const spreads: Ref<Spread[]> = ref([])

export function useSpreads() {
  const { userSpreads, fetchUserSpreads } = useUserSpreads()
  const { fetchFavorites, isFavorite } = useFavoriteSpreads()
  const { isLoggedIn } = useUser()

  /**
   * Just the public spreads (the library), each with its category. False when
   * they couldn't be loaded, after a toast saying so: an empty list would read
   * as "there are none".
   */
  async function fetchPublicSpreads(): Promise<boolean> {
    const res = await apiRequest<Spread[]>(endpoints.spreads.list)
    if (res.ok) {
      spreads.value = res.data
    } else {
      useToasts().error(`Couldn't load the spreads. ${res.error}`, { detail: res })
    }
    return res.ok
  }

  async function fetchSpreads(): Promise<void> {
    // Fire the public list, personal spreads, and favorites concurrently. The
    // favorites/user-spreads calls don't depend on the public list (favorites are
    // reconciled by id in spreadOptions), so awaiting the public list first would
    // add a serial round trip to every spread-selector page load.
    await Promise.all([
      fetchPublicSpreads(),
      isLoggedIn.value ? fetchUserSpreads() : Promise.resolve(),
      isLoggedIn.value ? fetchFavorites() : Promise.resolve(),
    ])
  }

  /**
   * Combined list of public + personal spreads for the spread selector. A public
   * spread carries its category's name and place, so the picker can group by them.
   */
  const spreadOptions = computed<SpreadOption[]>(() => {
    const publicOpts: SpreadOption[] = spreads.value.map((s) => ({
      id: `public-${s.spread_id}`,
      spread_id: s.spread_id,
      name: s.name,
      description: s.description,
      card_count: s.card_count,
      positions: s.positions,
      type: 'public',
      isFavorite: isFavorite('public', s.spread_id),
      category: s.category_name,
      categoryOrder: s.category_sort_order,
    }))

    const userOpts: SpreadOption[] = userSpreads.value.map((s) => ({
      id: `user-${s.user_spread_id}`,
      user_spread_id: s.user_spread_id,
      name: s.name,
      description: s.description,
      card_count: s.card_count,
      positions: s.positions,
      type: 'personal',
      isFavorite: isFavorite('personal', s.user_spread_id),
    }))

    return [...userOpts, ...publicOpts]
  })

  return { spreads, spreadOptions, fetchSpreads, fetchPublicSpreads }
}
