<script setup lang="ts">
/**
 * The daily card: one card a day, from the deck the reader picks that day, kept
 * as an ordinary reading (it shows in My Readings too), and a month calendar of
 * the days drawn. "Today" is the browser's local date; the server only checks
 * that it is within a day of its own. Deleting a daily card's reading doesn't
 * free its day: it stays drawn, with no card to show.
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, onMounted, ref, useId, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import DeckComboBox from '@/components/DeckComboBox.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import { useDecks } from '@/composables/useDecks'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useDailyCards } from '@/composables/useDailyCards'
import { useToasts } from '@/composables/useToasts'
import { cardImageUrls } from '@/utils/cardImages'
import { cardAspectStyle } from '@/utils/cardAspect'
import { dateOf, isKept, localDay, monthCalendar, shiftMonth } from '@/utils/calendar'
import { defaultDeckId } from '@/utils/deck'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import type { DailyCard, KeptDailyCard } from '@/types'

/** What the last draw was made with, kept to prefill the next day's choice. */
interface StoredChoice {
  deck_id: number
  use_reversals: boolean
}

const router = useRouter()
const toasts = useToasts()
const { decks, deckLookup, fetchDecks } = useDecks()
const { fetchFavoriteDecks } = useFavoriteDecks()
const { fetchMonth, drawDailyCard } = useDailyCards()

const deckFieldId = useId()
const reversalsFieldId = useId()
const calendarTitleId = useId()

const today = localDay()
const thisMonth = today.slice(0, 7)

// ── Today ──────────────────────────────────────────────────────────────────
/** Today's daily card: null until it's drawn, its card null once its reading is deleted. */
const todayCard = ref<DailyCard | null>(null)
const todayKept = computed(() => {
  const card = todayCard.value
  return card && isKept(card) ? card : null
})
/** Whether this month's cards have loaded, so today's state is known. */
const todayKnown = ref(false)
const deckId = ref<number | null>(null)
const useReversals = ref(false)
const drawing = ref(false)
const drawError = ref('')

const stored = local.get<Partial<StoredChoice> | null>(STORAGE_KEYS.dailyCardChoice, null)
useReversals.value = stored?.use_reversals === true
const storedDeck = typeof stored?.deck_id === 'number' ? stored.deck_id : null

// The last daily card's deck while it's still offered; else the reading screens' deck.
watch(
  decks,
  (list) => {
    if (list.length > 0 && deckId.value === null) {
      deckId.value = defaultDeckId(
        list,
        storedDeck ?? local.get<number | null>(STORAGE_KEYS.lastDeck, null),
      )
    }
  },
  { immediate: true },
)

/** A day as its heading and screen readers give it: "Friday 30 October". */
function longDate(day: string): string {
  return dateOf(day).toLocaleDateString(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })
}

const todayLabel = longDate(today)

async function draw() {
  if (deckId.value === null) {
    drawError.value = 'Choose a deck to draw from.'
    return
  }
  const choice = { day: today, deckId: deckId.value, useReversals: useReversals.value }
  drawing.value = true
  drawError.value = ''
  const res = await drawDailyCard(choice)
  drawing.value = false

  if (res.ok) {
    showToday(res.card)
    local.set(STORAGE_KEYS.dailyCardChoice, {
      deck_id: choice.deckId,
      use_reversals: choice.useReversals,
    } satisfies StoredChoice)
    return
  }

  if (!res.taken) {
    drawError.value = res.error
    return
  }
  // Already drawn (in another tab, say): open the card the day already has…
  toasts.info(res.error)
  if (res.existingReadingId !== null) {
    void router.push({ name: 'reading', params: { id: res.existingReadingId } })
    return
  }
  // …or, its reading deleted since, show the day as the drawn one it still is.
  showToday({
    day: today,
    reading_id: null,
    deck_id: null,
    deck_name: null,
    card_id: null,
    card_name: null,
    reversed: null,
  })
}

/** Make `card` today's, in the calendar too while it shows this month. */
function showToday(card: DailyCard) {
  todayCard.value = card
  if (month.value === thisMonth) {
    monthCards.value = [...monthCards.value.filter((c) => c.day !== today), card]
  }
}

// The card's thumbnail, falling back to the master scan while a deck's web
// images aren't generated, and to no picture at all if that's missing too.
const imageStep = ref<'thumb' | 'png' | 'none'>('thumb')
watch(todayCard, () => {
  imageStep.value = 'thumb'
})
const todayImage = computed(() => {
  const card = todayKept.value
  if (!card || imageStep.value === 'none') {
    return null
  }
  const urls = cardImageUrls(card.deck_id, card.card_id)
  return imageStep.value === 'thumb' ? urls.thumb : urls.png
})
function onImageError() {
  imageStep.value = imageStep.value === 'thumb' ? 'png' : 'none'
}

// ── Calendar ───────────────────────────────────────────────────────────────
const month = ref(thisMonth)
const monthCards = ref<DailyCard[]>([])
const monthLoading = ref(true)
const monthError = ref('')

// The month's weeks, each day with its card (if drawn); null pads the weeks out.
const weeks = computed(() => monthCalendar(month.value, monthCards.value))
const monthLabel = computed(() =>
  dateOf(month.value).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
)

// Column heads, Sunday first: 1 January 2023 was a Sunday.
const weekdays = Array.from({ length: 7 }, (_, i) => {
  const date = new Date(2023, 0, 1 + i)
  return {
    short: date.toLocaleDateString(undefined, { weekday: 'short' }),
    long: date.toLocaleDateString(undefined, { weekday: 'long' }),
  }
})

function dayNumber(day: string): number {
  return dateOf(day).getDate()
}

/** What a screen reader hears for a drawn day: its date, the card, and whether reversed. */
function drawnLabel(card: KeptDailyCard): string {
  return `${longDate(card.day)}: ${card.card_name}${card.reversed ? ', reversed' : ''}`
}

// Only the latest month asked for may land, however quickly someone pages.
let latest = 0

async function loadMonth(target: string) {
  const request = ++latest
  month.value = target
  monthLoading.value = true
  const res = await fetchMonth(target)
  if (request !== latest) {
    return
  }
  monthLoading.value = false
  if (!res.ok) {
    monthError.value = res.error
    monthCards.value = []
    return
  }
  monthError.value = ''
  monthCards.value = res.data
  if (target === thisMonth) {
    todayCard.value = res.data.find((c) => c.day === today) ?? null
    todayKnown.value = true
  }
}

onMounted(() => {
  void fetchDecks()
  void fetchFavoriteDecks()
  void loadMonth(thisMonth)
})
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader
            title="Daily Card"
            subtitle="One card each day, from the deck you choose, kept with your readings."
          />

          <!-- Today -->
          <section class="settings-panel daily-today mb-5" aria-labelledby="daily-today-title">
            <p id="daily-today-title" class="daily-eyebrow">Today · {{ todayLabel }}</p>

            <div aria-live="polite">
              <p v-if="!todayKnown && monthLoading && !monthError" class="has-text-grey mt-3">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin
                /></span>
                <span>Loading…</span>
              </p>

              <div v-else-if="!todayKnown" class="notification is-danger is-light mt-3">
                <p class="mb-3">Couldn't load your daily cards. {{ monthError }}</p>
                <button
                  type="button"
                  class="button is-small"
                  :class="{ 'is-loading': monthLoading }"
                  @click="loadMonth(thisMonth)"
                >
                  Try again
                </button>
              </div>

              <div v-else-if="todayKept" class="daily-today__drawn">
                <!-- No frame at all when the deck has no picture of the card. -->
                <div
                  v-if="todayImage"
                  class="daily-today__figure"
                  :style="cardAspectStyle(deckLookup[todayKept.deck_id])"
                >
                  <img
                    :src="todayImage"
                    :class="{ reversed: todayKept.reversed }"
                    :alt="todayKept.card_name"
                    @error="onImageError"
                  />
                </div>
                <div class="daily-today__text">
                  <p class="daily-today__name">{{ todayKept.card_name }}</p>
                  <p class="daily-today__meta">
                    <!-- eslint-disable vue/no-useless-mustaches -- the spaces around the dot are text Prettier would move out of the <template> -->
                    <template v-if="todayKept.reversed"
                      ><span class="daily-today__reversed">Reversed</span>{{ ' · ' }}</template
                    ><span>{{ todayKept.deck_name }}</span>
                    <!-- eslint-enable vue/no-useless-mustaches -->
                  </p>
                  <router-link
                    class="button is-link is-outlined mt-4"
                    :to="{ name: 'reading', params: { id: todayKept.reading_id } }"
                  >
                    <span>Open the reading</span>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-right']"
                    /></span>
                  </router-link>
                </div>
              </div>

              <!-- Drawn, and its reading deleted since: the day is still taken. -->
              <p v-else-if="todayCard" class="daily-today__deleted">
                You drew today's card and later deleted its reading. A new card can be drawn
                tomorrow.
              </p>

              <div v-else class="daily-today__form">
                <div class="field">
                  <label class="label" :for="deckFieldId">Deck</label>
                  <DeckComboBox :id="deckFieldId" v-model="deckId" :decks="decks" />
                </div>
                <div class="field">
                  <label class="label" :for="reversalsFieldId">Reversed Cards</label>
                  <ToggleSwitch :id="reversalsFieldId" v-model="useReversals">{{
                    useReversals ? 'On' : 'Off'
                  }}</ToggleSwitch>
                </div>
                <div v-if="drawError" class="notification is-danger is-light">{{ drawError }}</div>
                <button
                  type="button"
                  class="button is-primary"
                  :class="{ 'is-loading': drawing }"
                  :disabled="drawing || deckId === null"
                  @click="draw"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
                  <span>Draw today's card</span>
                </button>
              </div>
            </div>
          </section>

          <!-- Past days -->
          <section class="settings-panel daily-calendar" :aria-labelledby="calendarTitleId">
            <div class="daily-calendar__head">
              <button
                type="button"
                class="daily-calendar__nav"
                aria-label="Previous month"
                @click="loadMonth(shiftMonth(month, -1))"
              >
                <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-left']" />
              </button>
              <h2 :id="calendarTitleId" class="daily-calendar__title" aria-live="polite">
                {{ monthLabel }}
              </h2>
              <button
                type="button"
                class="daily-calendar__nav"
                aria-label="Next month"
                :disabled="month >= thisMonth"
                @click="loadMonth(shiftMonth(month, 1))"
              >
                <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-right']" />
              </button>
            </div>

            <div v-if="monthError && todayKnown" class="notification is-danger is-light">
              <p class="mb-3">Couldn't load {{ monthLabel }}. {{ monthError }}</p>
              <button
                type="button"
                class="button is-small"
                :class="{ 'is-loading': monthLoading }"
                @click="loadMonth(month)"
              >
                Try again
              </button>
            </div>

            <table
              class="daily-calendar__grid"
              :aria-labelledby="calendarTitleId"
              :aria-busy="monthLoading"
            >
              <thead>
                <tr>
                  <th v-for="w in weekdays" :key="w.long" scope="col" :abbr="w.long">
                    {{ w.short }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(week, w) in weeks" :key="w">
                  <td v-for="(cell, d) in week" :key="cell?.day ?? `pad-${w}-${d}`">
                    <template v-if="cell">
                      <router-link
                        v-if="cell.status === 'drawn'"
                        class="daily-calendar__day is-drawn"
                        :class="{ 'is-today': cell.day === today }"
                        :to="{ name: 'reading', params: { id: cell.card.reading_id } }"
                        :aria-current="cell.day === today ? 'date' : undefined"
                        :aria-label="drawnLabel(cell.card)"
                      >
                        <span class="daily-calendar__top">
                          <span class="daily-calendar__num">{{ dayNumber(cell.day) }}</span>
                          <span v-if="cell.card.reversed" class="daily-calendar__rev">Rev</span>
                        </span>
                        <span class="daily-calendar__card">{{ cell.card.card_name }}</span>
                        <span class="daily-calendar__mark" aria-hidden="true"></span>
                      </router-link>
                      <!-- Drawn, its reading deleted since: still taken, nothing to open. -->
                      <span
                        v-else-if="cell.status === 'deleted'"
                        class="daily-calendar__day is-deleted"
                        :class="{ 'is-today': cell.day === today }"
                        :aria-current="cell.day === today ? 'date' : undefined"
                      >
                        <span class="daily-calendar__num" aria-hidden="true">{{
                          dayNumber(cell.day)
                        }}</span>
                        <span class="daily-calendar__card" aria-hidden="true">Deleted</span>
                        <span class="visually-hidden"
                          >{{ longDate(cell.day) }}: drawn, reading deleted</span
                        >
                        <span class="daily-calendar__mark" aria-hidden="true"></span>
                      </span>
                      <span
                        v-else
                        class="daily-calendar__day"
                        :class="{ 'is-today': cell.day === today, 'is-future': cell.day > today }"
                        :aria-current="cell.day === today ? 'date' : undefined"
                      >
                        <span class="daily-calendar__num">{{ dayNumber(cell.day) }}</span>
                      </span>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.daily-eyebrow {
  color: var(--myst-gold);
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

/* ── Today ──────────────────────────────────────────────── */
.daily-today__form {
  margin-top: 1rem;
  max-width: 26rem;
}

.daily-today__drawn {
  display: flex;
  align-items: center;
  gap: 1.75rem;
  margin-top: 1rem;
}

.daily-today__figure {
  flex: none;
  width: 9.5rem;
  aspect-ratio: var(--card-aspect);
  border-radius: 10px;
  border: 1px solid var(--myst-hair-gold);
  background: var(--myst-surface);
  overflow: hidden;
}

.daily-today__figure img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.daily-today__name {
  font-family: var(--myst-heading-font);
  font-size: 1.9rem;
  line-height: 1.15;
  color: var(--myst-text-strong);
}

.daily-today__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  margin-top: 0.35rem;
  color: var(--myst-text-muted);
}

.daily-today__reversed {
  color: var(--myst-silver);
}

.daily-today__deleted {
  max-width: 34rem;
  margin-top: 1rem;
  color: var(--myst-text-muted);
}

@media screen and (width <= 600px) {
  .daily-today__drawn {
    flex-direction: column;
    align-items: flex-start;
    gap: 1.1rem;
  }
}

/* ── Calendar ───────────────────────────────────────────── */
.daily-calendar__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.daily-calendar__title {
  font-family: var(--myst-heading-font);
  font-size: 1.45rem;
  letter-spacing: 0.01em;
  color: var(--myst-text-strong);
  text-align: center;
}

.daily-calendar__nav {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border: 1px solid var(--myst-border-strong);
  border-radius: 999px;
  background: transparent;
  color: var(--myst-text-muted);
  cursor: pointer;
  transition:
    color 0.15s ease,
    border-color 0.15s ease;
}

.daily-calendar__nav:hover:not(:disabled) {
  color: var(--myst-gold-bright);
  border-color: var(--myst-hair-gold);
}

.daily-calendar__nav:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 35%, transparent);
}

.daily-calendar__nav:disabled {
  opacity: 0.35;
  cursor: default;
}

.daily-calendar__grid {
  width: 100%;
  table-layout: fixed;
  border-collapse: separate;
  border-spacing: 4px;
}

.daily-calendar__grid[aria-busy='true'] {
  opacity: 0.6;
}

.daily-calendar__grid th {
  padding: 0 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--myst-text-dim);
  text-align: center;
}

.daily-calendar__grid td {
  padding: 0;
  vertical-align: top;
}

.daily-calendar__day {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  height: 4.75rem;
  padding: 0.4rem 0.45rem;
  border: 1px solid var(--myst-border);
  border-radius: 8px;
  color: var(--myst-text-muted);
  overflow: hidden;
}

.daily-calendar__day.is-future {
  color: var(--myst-text-dim);
  border-color: transparent;
}

.daily-calendar__day.is-drawn {
  border-color: var(--myst-hair-gold);
  background: color-mix(in srgb, var(--myst-gold) 6%, transparent);
  color: var(--myst-text);
  text-decoration: none;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}

.daily-calendar__day.is-drawn:hover {
  background: color-mix(in srgb, var(--myst-gold) 12%, transparent);
  border-color: var(--myst-gold);
}

.daily-calendar__day.is-drawn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 35%, transparent);
}

/* Drawn, but its reading is gone: the gold edge of a drawn day, broken, and
   no hover, as there is nothing to open. */
.daily-calendar__day.is-deleted {
  border-style: dashed;
  border-color: var(--myst-hair-gold);
}

.daily-calendar__day.is-deleted .daily-calendar__card {
  color: var(--myst-text-dim);
}

.daily-calendar__day.is-today {
  border-color: var(--myst-gold);
  box-shadow: inset 0 0 0 1px var(--myst-gold);
}

.daily-calendar__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.25rem;
}

.daily-calendar__num {
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
}

/* Reversed, said quietly; the link's label says it in full. */
.daily-calendar__rev {
  font-size: 0.6rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--myst-silver);
}

.is-today .daily-calendar__num {
  color: var(--myst-gold-bright);
  font-weight: 700;
}

.daily-calendar__card {
  font-size: 0.75rem;
  line-height: 1.25;
  color: var(--myst-text);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.daily-calendar__mark {
  display: none;
}

/* A phone has no room for card names: a drawn day keeps a gold mark instead
   (its name is still in the link's label). */
@media screen and (width <= 600px) {
  .daily-calendar__grid {
    border-spacing: 2px;
  }

  .daily-calendar__day {
    height: 3rem;
    padding: 0.3rem;
    align-items: center;
  }

  .daily-calendar__card,
  .daily-calendar__rev {
    display: none;
  }

  .daily-calendar__mark {
    display: block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--myst-gold);
  }

  .daily-calendar__day.is-deleted .daily-calendar__mark {
    background: transparent;
    border: 1px solid var(--myst-gold);
  }
}
</style>
