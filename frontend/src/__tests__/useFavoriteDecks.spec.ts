import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useToasts } from '@/composables/useToasts'
import { stubApi, signIn, testUser, problem, problemBody } from './helpers/app'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

describe('useFavoriteDecks.toggleFavorite', () => {
  beforeEach(() => {
    useFavoriteDecks().favoriteDecks.value = []
    useToasts().toasts.value = []
  })

  it('adds a favourite on success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { status: 201 })))

    const { favoriteDecks, toggleFavorite } = useFavoriteDecks()

    expect(await toggleFavorite(3)).toBe(true)
    expect(favoriteDecks.value).toEqual([3])
    expect(useToasts().toasts.value).toEqual([])
  })

  it('keeps the star as it was and says why when the server refuses', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(problemBody(500, 'x'), { ok: false, status: 500 })),
    )

    const { favoriteDecks, toggleFavorite } = useFavoriteDecks()

    expect(await toggleFavorite(3)).toBe(false)
    expect(favoriteDecks.value).toEqual([])
    expect(useToasts().toasts.value[0]?.message).toMatch(/Couldn't update your favourite decks/)
  })

  it('does not reject on a network error (callers fire and forget)', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const { toggleFavorite } = useFavoriteDecks()

    await expect(toggleFavorite(3)).resolves.toBe(false)
    expect(useToasts().toasts.value).toHaveLength(1)
  })
})

describe('useFavoriteDecks.fetchFavoriteDecks', () => {
  beforeEach(() => {
    signIn(testUser())
    useFavoriteDecks().favoriteDecks.value = []
    useToasts().toasts.value = []
  })

  it("doesn't ask for a signed-out visitor's favourites (it could only be refused)", async () => {
    signIn(null)
    const api = stubApi({})

    await useFavoriteDecks().fetchFavoriteDecks()

    expect(api).not.toHaveBeenCalled()
  })

  it('loads the starred deck ids', async () => {
    stubApi({ 'GET /account/favorite-decks': { body: [2, 5] } })
    const { fetchFavoriteDecks, isFavorite } = useFavoriteDecks()

    await fetchFavoriteDecks()

    expect([isFavorite(2), isFavorite(5), isFavorite(3)]).toEqual([true, true, false])
  })

  it('collapses the deck pickers asking at once into one request', async () => {
    const api = stubApi({ 'GET /account/favorite-decks': { body: [] } })
    const { fetchFavoriteDecks } = useFavoriteDecks()

    await Promise.all([fetchFavoriteDecks(), fetchFavoriteDecks()])

    expect(api).toHaveBeenCalledTimes(1)
  })

  it('stays quiet about an expired session but explains other failures', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { fetchFavoriteDecks } = useFavoriteDecks()

    stubApi({ 'GET /account/favorite-decks': { status: 401 } })
    await fetchFavoriteDecks()
    expect(useToasts().toasts.value).toEqual([])

    stubApi({
      'GET /account/favorite-decks': { status: 500, body: problem('Database is locked.') },
    })
    await fetchFavoriteDecks()
    expect(useToasts().toasts.value.map((t) => t.message)).toEqual([
      "Couldn't load your favourite decks. Database is locked.",
    ])
  })

  it('unstars a starred deck through its own URL', async () => {
    const api = stubApi({ 'DELETE /account/favorite-decks/3': { status: 204 } })
    const { favoriteDecks, toggleFavorite } = useFavoriteDecks()
    favoriteDecks.value = [3, 4]

    expect(await toggleFavorite(3)).toBe(true)

    expect(api).toHaveBeenCalledTimes(1)
    expect(favoriteDecks.value).toEqual([4])
  })

  it('sends the deck id when starring', async () => {
    const api = stubApi({ 'POST /account/favorite-decks': { status: 201 } })

    await useFavoriteDecks().addFavorite(6)

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({ deck_id: 6 })
  })
})
