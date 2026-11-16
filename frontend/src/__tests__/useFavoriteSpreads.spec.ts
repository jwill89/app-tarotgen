import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useToasts } from '@/composables/useToasts'
import { stubApi, problem } from './helpers/app'

const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

describe('useFavoriteSpreads', () => {
  beforeEach(() => {
    useFavoriteSpreads().favorites.value = []
    useToasts().toasts.value = []
  })

  it('loads the favourites and tells public and personal spreads apart', async () => {
    stubApi({
      'GET /account/favorites': {
        body: [
          { spread_type: 'public', spread_id: 3 },
          { spread_type: 'personal', spread_id: 8 },
        ],
      },
    })
    const { fetchFavorites, isFavorite } = useFavoriteSpreads()

    await fetchFavorites()

    expect(isFavorite('public', 3)).toBe(true)
    expect(isFavorite('personal', 8)).toBe(true)
    // Same id, other kind: a personal spread 3 is a different spread.
    expect(isFavorite('personal', 3)).toBe(false)
  })

  it('collapses callers that ask at the same time into one request', async () => {
    const api = stubApi({ 'GET /account/favorites': { body: [] } })
    const { fetchFavorites } = useFavoriteSpreads()

    await Promise.all([fetchFavorites(), fetchFavorites(), fetchFavorites()])
    expect(api).toHaveBeenCalledTimes(1)

    // Once it has settled, the next call asks again.
    await fetchFavorites()
    expect(api).toHaveBeenCalledTimes(2)
  })

  it('stays quiet about an expired session (the router handles it)', async () => {
    stubApi({ 'GET /account/favorites': { status: 401, body: problem('Not authenticated') } })

    await useFavoriteSpreads().fetchFavorites()

    expect(toastMessages()).toEqual([])
  })

  it("says why the favourites couldn't load", async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    stubApi({ 'GET /account/favorites': { status: 500, body: problem('Database is locked.') } })

    await useFavoriteSpreads().fetchFavorites()

    expect(toastMessages()).toEqual(["Couldn't load your favourite spreads. Database is locked."])
  })

  it('stars a spread by POSTing its kind and id', async () => {
    const api = stubApi({ 'POST /account/favorites': { status: 201 } })
    const { toggleFavorite, isFavorite } = useFavoriteSpreads()

    expect(await toggleFavorite('personal', 8)).toBe(true)

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      spread_type: 'personal',
      spread_id: 8,
    })
    expect(isFavorite('personal', 8)).toBe(true)
  })

  it('unstars a starred spread through its own URL', async () => {
    const api = stubApi({ 'DELETE /account/favorites/public/3': { status: 204 } })
    const { favorites, toggleFavorite } = useFavoriteSpreads()
    favorites.value = [
      { spread_type: 'public', spread_id: 3 },
      { spread_type: 'public', spread_id: 4 },
    ]

    expect(await toggleFavorite('public', 3)).toBe(true)

    expect(api).toHaveBeenCalledTimes(1)
    expect(favorites.value).toEqual([{ spread_type: 'public', spread_id: 4 }])
  })

  it('leaves the star as it was and says so when the server refuses', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    stubApi({ 'DELETE /account/favorites/public/3': { status: 500 } })
    const { favorites, toggleFavorite } = useFavoriteSpreads()
    favorites.value = [{ spread_type: 'public', spread_id: 3 }]

    expect(await toggleFavorite('public', 3)).toBe(false)

    expect(favorites.value).toEqual([{ spread_type: 'public', spread_id: 3 }])
    expect(toastMessages()).toEqual(["Couldn't update your favourite spreads. Please try again."])
  })

  it('does not add a star the server never saved', async () => {
    stubApi({ 'POST /account/favorites': { status: 422, body: problem('Unknown spread.') } })
    const { addFavorite, favorites } = useFavoriteSpreads()

    expect(await addFavorite('public', 99)).toBe(false)
    expect(favorites.value).toEqual([])
  })
})
