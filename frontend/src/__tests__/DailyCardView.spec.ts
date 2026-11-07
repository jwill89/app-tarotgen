import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import DailyCardView from '@/views/DailyCardView.vue'
import { useDecks } from '@/composables/useDecks'
import { useToasts } from '@/composables/useToasts'
import { STORAGE_KEYS } from '@/constants'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'
import type { DailyCard, Deck } from '@/types'

const TODAY = '2026-10-30'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)

function button(name: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find(
    (b) => text(b) === name || b.getAttribute('aria-label') === name,
  )
  if (!found) {
    throw new Error(`No "${name}" button`)
  }
  return found
}

function labelled(label: string): HTMLElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const control = el && document.getElementById(el.htmlFor)
  if (!control) {
    throw new Error(`Nothing is labelled "${label}"`)
  }
  return control
}

const deck = (deck_id: number, name: string): Deck => ({
  deck_id,
  deck_system_id: 1,
  name,
  artist: 'Artist',
  purchase_url: '',
  additional_cards: 0,
  card_aspect_w: 5,
  card_aspect_h: 8.6,
  approved: true,
  usable: true,
  system_short_name: 'RWS',
  system_total_cards: 78,
})

const card = (day: string, extra: Partial<DailyCard> = {}): DailyCard => ({
  day,
  reading_id: `r-${day}`,
  deck_id: 1,
  deck_name: 'Rider-Waite',
  card_id: 17,
  card_name: 'The Star',
  reversed: false,
  ...extra,
})

/** A day drawn whose reading was deleted since, as the API sends it. */
const deleted = (day: string): DailyCard => ({
  day,
  reading_id: null,
  deck_id: null,
  deck_name: null,
  card_id: null,
  card_name: null,
  reversed: null,
})

const DELETED_TODAY =
  "You drew today's card and later deleted its reading. A new card can be drawn tomorrow."

const monthName = (y: number, m: number) =>
  new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })

/** The calendar's day cells (not the padding), as their visible text. */
const days = () => [...document.body.querySelectorAll('.daily-calendar__day')].map((d) => text(d))
const drawnDay = (n: number) =>
  [...document.body.querySelectorAll<HTMLAnchorElement>('a.daily-calendar__day')].find(
    (a) => a.querySelector('.daily-calendar__num')?.textContent === String(n),
  )
/** The calendar's day `n`, whatever it shows. */
const dayCell = (n: number) =>
  [...document.body.querySelectorAll<HTMLElement>('.daily-calendar__day')].find(
    (el) => el.querySelector('.daily-calendar__num')?.textContent === String(n),
  )

/** Serve the decks, each month's cards (by ?month), and anything else asked. */
function serve(
  months: Record<string, DailyCard[] | { status: number; body: unknown }>,
  extra: Parameters<typeof stubApi>[0] = {},
) {
  const asked: string[] = []
  const api = stubApi({
    'GET /decks': { body: [deck(1, 'Rider-Waite'), deck(2, 'Thoth')] },
    'GET /account/favorite-decks': { body: [] },
    'GET /account/daily-cards': (_body, url) => {
      const month = url.searchParams.get('month') ?? ''
      asked.push(month)
      const answer = months[month] ?? []
      return Array.isArray(answer) ? { body: answer } : answer
    },
    ...extra,
  })
  return { api, asked }
}

async function mountPage() {
  return mountWithApp(DailyCardView, { path: '/daily' })
}

describe('DailyCardView', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 30, 9, 15))
    signIn(testUser())
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
    useToasts().toasts.value = []
    localStorage.clear()
  })
  afterEach(async () => {
    await cleanup()
    vi.useRealTimers()
  })

  describe('before the day is drawn', () => {
    it("offers the draw, prefilled with the last daily card's deck and reversals", async () => {
      localStorage.setItem(
        STORAGE_KEYS.dailyCardChoice,
        JSON.stringify({ deck_id: 2, use_reversals: true }),
      )
      const { asked } = serve({})

      await mountPage()

      expect(asked).toEqual(['2026-10'])
      expect(pageText()).toContain('Today ·')
      expect((labelled('Deck') as HTMLInputElement).value).toBe('Thoth')
      expect(labelled('Reversed Cards').getAttribute('aria-checked')).toBe('true')
      expect(button("Draw today's card").disabled).toBe(false)
    })

    it("falls back to the reading screens' deck, reversals off", async () => {
      localStorage.setItem(STORAGE_KEYS.lastDeck, '1')
      serve({})

      await mountPage()

      expect((labelled('Deck') as HTMLInputElement).value).toBe('Rider-Waite')
      expect(labelled('Reversed Cards').getAttribute('aria-checked')).toBe('false')
    })

    it("draws the day's card, shows it and remembers the choice", async () => {
      localStorage.setItem(
        STORAGE_KEYS.dailyCardChoice,
        JSON.stringify({ deck_id: 2, use_reversals: true }),
      )
      const sent: unknown[] = []
      serve(
        {},
        {
          'POST /account/daily-cards': (body) => {
            sent.push(body)
            return {
              status: 201,
              body: card(TODAY, {
                deck_id: 2,
                deck_name: 'Thoth',
                card_name: 'The Moon',
                reversed: true,
              }),
            }
          },
        },
      )
      await mountPage()

      button("Draw today's card").click()
      await flushPromises()

      expect(sent).toEqual([{ day: TODAY, deck_id: 2, use_reversals: true }])
      expect(document.body.querySelector('.daily-today__name')?.textContent).toBe('The Moon')
      expect(text(document.body.querySelector('.daily-today__meta') ?? document.body)).toBe(
        'Reversed · Thoth',
      )
      const open = [...document.body.querySelectorAll('a')].find(
        (a) => text(a) === 'Open the reading',
      )
      expect(open?.getAttribute('href')).toBe(`/reading/r-${TODAY}`)
      expect(JSON.parse(localStorage.getItem(STORAGE_KEYS.dailyCardChoice) ?? 'null')).toEqual({
        deck_id: 2,
        use_reversals: true,
      })
      // The calendar has it at once.
      expect(drawnDay(30)?.getAttribute('aria-label')).toContain('The Moon, reversed')
    })

    it('opens the card the day already has when it was drawn elsewhere', async () => {
      serve(
        {},
        {
          'POST /account/daily-cards': {
            status: 409,
            body: {
              error: 'You have already drawn your daily card for this day.',
              reading_id: 'abc123',
            },
          },
        },
      )
      const { router } = await mountPage()

      button("Draw today's card").click()
      await flushPromises()

      await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/reading/abc123'))
      expect(useToasts().toasts.value.map((t) => t.message)).toEqual([
        'You have already drawn your daily card for this day.',
      ])
    })

    it("says so, and opens nothing, when the day's reading was deleted elsewhere", async () => {
      serve(
        {},
        {
          'POST /account/daily-cards': {
            status: 409,
            body: {
              error:
                'You have already drawn your daily card for this day, and its reading was deleted.',
              reading_id: null,
            },
          },
        },
      )
      const { router } = await mountPage()

      button("Draw today's card").click()
      await flushPromises()

      expect(useToasts().toasts.value.map((t) => t.message)).toEqual([
        'You have already drawn your daily card for this day, and its reading was deleted.',
      ])
      expect(router.currentRoute.value.name).toBe('daily-card')
      // The day is shown as the drawn one it is: no draw on offer again.
      expect(pageText()).toContain(DELETED_TODAY)
      expect(document.body.querySelector('.daily-today__form')).toBeNull()
      expect(dayCell(30)?.classList.contains('is-deleted')).toBe(true)
    })

    it("shows the server's message when the draw is refused", async () => {
      serve(
        {},
        {
          'POST /account/daily-cards': {
            status: 400,
            body: { error: "A daily card can only be drawn for today. Check your device's date." },
          },
        },
      )
      const { router } = await mountPage()

      button("Draw today's card").click()
      await flushPromises()

      expect(pageText()).toContain(
        "A daily card can only be drawn for today. Check your device's date.",
      )
      expect(router.currentRoute.value.name).toBe('daily-card')
      expect(document.body.querySelector('.daily-today__name')).toBeNull()
    })
  })

  describe('once the day is drawn', () => {
    it('shows its card in place of the draw, with its picture', async () => {
      serve({ '2026-10': [card(TODAY, { card_id: 5 })] })

      await mountPage()

      expect(document.body.querySelector('.daily-today__name')?.textContent).toBe('The Star')
      expect(pageText()).not.toContain("Draw today's card")
      const img = document.body.querySelector<HTMLImageElement>('.daily-today__figure img')
      expect(img?.getAttribute('src')).toBe('/assets/decks/1/thumbs/Card_0005.webp')
      expect(img?.getAttribute('alt')).toBe('The Star')
      expect(img?.classList.contains('reversed')).toBe(false)
    })

    it('falls back to the master scan, then to no picture', async () => {
      serve({ '2026-10': [card(TODAY, { card_id: 5 })] })
      await mountPage()
      const img = () => document.body.querySelector<HTMLImageElement>('.daily-today__figure img')

      img()?.dispatchEvent(new Event('error'))
      await flushPromises()
      expect(img()?.getAttribute('src')).toBe('/assets/decks/1/Card_0005.png')

      img()?.dispatchEvent(new Event('error'))
      await flushPromises()
      expect(document.body.querySelector('.daily-today__figure')).toBeNull()
      expect(document.body.querySelector('.daily-today__name')?.textContent).toBe('The Star')
    })

    it('says plainly when its reading was deleted, and offers no second draw', async () => {
      serve({ '2026-10': [deleted(TODAY)] })

      await mountPage()

      expect(pageText()).toContain(DELETED_TODAY)
      expect(pageText()).not.toContain("Draw today's card")
      expect(document.body.querySelector('.daily-today__form')).toBeNull()
      expect(document.body.querySelector('.daily-today__name')).toBeNull()
      expect([...document.body.querySelectorAll('a')].map((a) => text(a))).not.toContain(
        'Open the reading',
      )
    })
  })

  describe('the calendar', () => {
    it("links each day drawn to its reading, and marks today's date", async () => {
      serve({
        '2026-10': [
          card('2026-10-03', { card_name: 'The Tower', reversed: true }),
          card('2026-10-21'),
        ],
      })

      await mountPage()

      expect(document.body.querySelector('.daily-calendar__title')?.textContent.trim()).toBe(
        monthName(2026, 10),
      )
      expect(days()).toHaveLength(31)
      // October 2026 starts on a Thursday: four empty cells lead the first week.
      expect(document.body.querySelector('tbody tr')?.querySelectorAll('td:empty')).toHaveLength(4)

      const third = drawnDay(3)
      expect(third?.getAttribute('href')).toBe('/reading/r-2026-10-03')
      expect(third?.getAttribute('aria-label')).toMatch(/3.*: The Tower, reversed$/)
      expect(third?.querySelector('.daily-calendar__card')?.textContent).toBe('The Tower')
      expect(third?.querySelector('.daily-calendar__rev')?.textContent).toBe('Rev')
      expect(drawnDay(21)?.querySelector('.daily-calendar__rev')).toBeNull()
      expect(drawnDay(21)?.getAttribute('aria-label')).toMatch(/: The Star$/)
      expect(document.body.querySelectorAll('a.daily-calendar__day')).toHaveLength(2)

      const today = document.body.querySelector('[aria-current="date"]')
      expect(today?.textContent.trim()).toBe('30')
      expect(today?.tagName).toBe('SPAN')
      expect(
        document.body.querySelector('.daily-calendar__day.is-future')?.textContent.trim(),
      ).toBe('31')
    })

    it('marks a day whose reading was deleted as drawn, with nothing to open', async () => {
      serve({ '2026-10': [card('2026-10-03'), deleted('2026-10-12'), deleted(TODAY)] })

      await mountPage()

      const twelfth = dayCell(12)
      expect(twelfth?.tagName).toBe('SPAN')
      expect(twelfth?.classList.contains('is-deleted')).toBe(true)
      expect(twelfth?.querySelector('.daily-calendar__card')?.textContent).toBe('Deleted')
      // What a screen reader hears: the date, drawn, and the reading gone.
      expect(twelfth?.querySelector('.visually-hidden')?.textContent).toMatch(
        /12.*: drawn, reading deleted$/,
      )
      expect(
        [...(twelfth?.querySelectorAll('[aria-hidden="true"]') ?? [])].map((el) => el.className),
      ).toEqual(['daily-calendar__num', 'daily-calendar__card', 'daily-calendar__mark'])
      // Only the day whose reading is there opens one.
      expect(
        [...document.body.querySelectorAll('a.daily-calendar__day')].map((a) =>
          a.getAttribute('href'),
        ),
      ).toEqual(['/reading/r-2026-10-03'])
      // Today, deleted too, is still today.
      expect(document.body.querySelector('[aria-current="date"]')).toBe(dayCell(30))
      expect(dayCell(30)?.classList.contains('is-deleted')).toBe(true)
    })

    it('pages back through the months, never past this one', async () => {
      const { asked } = serve({
        '2026-10': [card(TODAY, { card_name: 'The Sun' })],
        '2026-09': [card('2026-09-29', { card_name: 'The Fool' })],
      })
      await mountPage()

      expect(button('Next month').disabled).toBe(true)

      button('Previous month').click()
      await flushPromises()

      expect(asked).toEqual(['2026-10', '2026-09'])
      expect(document.body.querySelector('.daily-calendar__title')?.textContent.trim()).toBe(
        monthName(2026, 9),
      )
      expect(drawnDay(29)?.getAttribute('href')).toBe('/reading/r-2026-09-29')
      expect(document.body.querySelector('[aria-current="date"]')).toBeNull()
      // Today's card stays where it is.
      expect(document.body.querySelector('.daily-today__name')?.textContent).toBe('The Sun')

      expect(button('Next month').disabled).toBe(false)
      button('Next month').click()
      await flushPromises()
      expect(asked.at(-1)).toBe('2026-10')
      expect(button('Next month').disabled).toBe(true)
    })

    it('crosses into the year before', async () => {
      vi.setSystemTime(new Date(2026, 0, 12, 9, 0))
      const { asked } = serve({})
      await mountPage()

      button('Previous month').click()
      await flushPromises()

      expect(asked).toEqual(['2026-01', '2025-12'])
      expect(days()).toHaveLength(31)
    })

    it("names each weekday's column", async () => {
      serve({})
      await mountPage()

      const heads = [...document.body.querySelectorAll('thead th')]
      expect(heads).toHaveLength(7)
      expect(heads.every((th) => th.getAttribute('scope') === 'col')).toBe(true)
      expect(heads[0]?.getAttribute('abbr')).toBe(
        new Date(2023, 0, 1).toLocaleDateString(undefined, { weekday: 'long' }),
      )
      const table = document.body.querySelector('table')
      expect(document.getElementById(table?.getAttribute('aria-labelledby') ?? '')?.tagName).toBe(
        'H2',
      )
    })
  })

  describe('when loading fails', () => {
    it('says so in place of the draw, and tries again', async () => {
      const months: Parameters<typeof serve>[0] = {
        '2026-10': { status: 500, body: { error: 'The calendar is resting.' } },
      }
      serve(months)
      await mountPage()

      expect(pageText()).toContain("Couldn't load your daily cards. The calendar is resting.")
      expect(pageText()).not.toContain("Draw today's card")

      months['2026-10'] = []
      button('Try again').click()
      await flushPromises()

      expect(pageText()).not.toContain("Couldn't load")
      expect(button("Draw today's card").disabled).toBe(false)
    })

    it('says which month could not be loaded, and tries it again', async () => {
      const months: Parameters<typeof serve>[0] = {
        '2026-09': { status: 500, body: { error: 'Try later.' } },
      }
      const { asked } = serve(months)
      await mountPage()

      button('Previous month').click()
      await flushPromises()
      expect(pageText()).toContain(`Couldn't load ${monthName(2026, 9)}. Try later.`)
      // Today is still known: the draw stays on offer.
      expect(document.body.querySelector('.daily-today__form')).not.toBeNull()

      months['2026-09'] = [card('2026-09-29')]
      button('Try again').click()
      await flushPromises()

      expect(asked).toEqual(['2026-10', '2026-09', '2026-09'])
      expect(pageText()).not.toContain("Couldn't load")
      expect(drawnDay(29)).toBeDefined()
    })
  })
})
