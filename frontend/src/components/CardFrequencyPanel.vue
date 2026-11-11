<script setup lang="ts">
/**
 * "Which cards keep turning up": a tally of the cards in the signed-in user's
 * own readings, over every deck or one, all time or a recent window. Counts and
 * proportions only — the site never interprets a card.
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, onMounted, ref, useId, watch } from 'vue'
import BaseSelect from '@/components/BaseSelect.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { useCardFrequency } from '@/composables/useCardFrequency'
import { useDecks } from '@/composables/useDecks'
import type { CardCount, CardFrequency } from '@/types'

/** How many cards show before "Show all". */
const TOP = 10

type Period = 'all' | '30' | '90' | '365'
const periodOptions: { value: Period; label: string }[] = [
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: '365', label: '1 year' },
  { value: 'all', label: 'All time' },
]

const { fetchCardFrequency } = useCardFrequency()
const { decks, deckLookup, fetchDecks } = useDecks()

const deckFieldId = useId()
const periodLabelId = useId()
const titleId = useId()

const deckChoice = ref<string | number | null>('all')
const period = ref<Period>('all')
const result = ref<CardFrequency | null>(null)
const loading = ref(true)
const error = ref('')
const showAll = ref(false)

const deckOptions = computed(() => [
  { value: 'all', label: 'All decks' },
  ...decks.value.map((d) => ({ value: d.deck_id, label: d.name })),
])

const filtered = computed(() => deckChoice.value !== 'all' || period.value !== 'all')
const cards = computed(() => result.value?.cards ?? [])
const shown = computed(() => (showAll.value ? cards.value : cards.value.slice(0, TOP)))
const most = computed(() => Math.max(1, ...cards.value.map((c) => c.count)))
const drawn = computed(() => cards.value.reduce((sum, c) => sum + c.count, 0))

// Only the latest request may land: a slow answer for an earlier filter must
// not replace the one the user is looking at.
let latest = 0

async function load() {
  const request = ++latest
  loading.value = true
  const res = await fetchCardFrequency({
    deckId: typeof deckChoice.value === 'number' ? deckChoice.value : null,
    days: period.value === 'all' ? null : Number(period.value),
  })
  if (request !== latest) {
    return
  }
  loading.value = false
  if (!res.ok) {
    error.value = res.error
    return
  }
  error.value = ''
  result.value = res.data
}

watch([deckChoice, period], () => {
  showAll.value = false
  void load()
})

onMounted(() => {
  void fetchDecks()
  void load()
})

function key(c: CardCount): string {
  return `${String(c.deck_id)}:${String(c.card_id)}:${c.card_name}`
}

/** The deck a card is told apart by: only an unnamed card counted across decks has one. */
function deckOf(c: CardCount): string | null {
  if (c.deck_id === null || typeof deckChoice.value === 'number') {
    return null
  }
  return deckLookup.value[c.deck_id]?.name ?? `Deck #${String(c.deck_id)}`
}

function times(n: number): string {
  return n === 1 ? '1 time' : `${String(n)} times`
}
</script>

<template>
  <section class="settings-panel frequency" :aria-labelledby="titleId">
    <div class="frequency__head">
      <div>
        <h2 :id="titleId" class="frequency__title">Card Frequency</h2>
        <p class="frequency__sub">Which cards keep turning up in your readings.</p>
      </div>
      <span v-if="loading && result" class="frequency__busy" role="status">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin />
        <span class="visually-hidden">Counting…</span>
      </span>
    </div>

    <div class="frequency__filters">
      <div class="field">
        <label class="label" :for="deckFieldId">Deck</label>
        <BaseSelect :id="deckFieldId" v-model="deckChoice" :options="deckOptions" />
      </div>
      <div class="field">
        <span :id="periodLabelId" class="label">Period</span>
        <SegmentedControl
          v-model="period"
          :options="periodOptions"
          :aria-labelledby="periodLabelId"
        />
      </div>
    </div>

    <p v-if="loading && !result && !error" class="has-text-grey">
      <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
      <span>Counting your cards…</span>
    </p>

    <div v-else-if="error" class="notification is-danger is-light">
      <p class="mb-3">Couldn't count your cards. {{ error }}</p>
      <button
        type="button"
        class="button is-small"
        :class="{ 'is-loading': loading }"
        @click="load"
      >
        Try again
      </button>
    </div>

    <p v-else-if="cards.length === 0" class="frequency__empty">
      <template v-if="filtered">
        None of your readings match. Try another deck or a longer period.
      </template>
      <template v-else>Draw a few readings and your most frequent cards will show here.</template>
    </p>

    <template v-else-if="result">
      <p class="frequency__summary">
        {{ drawn }} {{ drawn === 1 ? 'card' : 'cards' }} across {{ result.readings }}
        {{ result.readings === 1 ? 'reading' : 'readings' }}
      </p>

      <ol class="frequency__list" :aria-busy="loading">
        <li v-for="c in shown" :key="key(c)" class="frequency__row">
          <div class="frequency__name">
            <span class="frequency__card">{{ c.card_name }}</span>
            <span v-if="deckOf(c)" class="frequency__deck">{{ deckOf(c) }}</span>
          </div>
          <div class="frequency__bar" aria-hidden="true">
            <span class="frequency__fill" :style="{ width: `${(c.count / most) * 100}%` }">
              <span class="frequency__upright" :style="{ flexGrow: c.upright }"></span>
              <span class="frequency__reversed" :style="{ flexGrow: c.reversed }"></span>
            </span>
          </div>
          <div class="frequency__count">
            <strong>{{ c.count }}</strong>
            <span class="visually-hidden">{{ times(c.count) }}:</span>
            <span class="frequency__split"
              >{{ c.upright }} upright · {{ c.reversed }} reversed</span
            >
          </div>
        </li>
      </ol>

      <button
        v-if="cards.length > TOP"
        type="button"
        class="button is-small is-ghost frequency__more"
        :aria-expanded="showAll"
        @click="showAll = !showAll"
      >
        {{ showAll ? `Show the top ${String(TOP)}` : `Show all ${String(cards.length)} cards` }}
      </button>
    </template>
  </section>
</template>

<style scoped>
.frequency__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.1rem;
}

.frequency__title {
  font-family: var(--myst-heading-font);
  font-size: 1.45rem;
  letter-spacing: 0.01em;
  color: var(--myst-text-strong);
  line-height: 1.2;
}

.frequency__sub {
  color: var(--myst-text-muted);
  margin-top: 0.2rem;
}

.frequency__busy {
  color: var(--myst-text-dim);
}

.frequency__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 1rem 1.5rem;
  padding-bottom: 1.1rem;
  margin-bottom: 1.1rem;
  border-bottom: 1px solid var(--myst-border);
}

.frequency__filters .field {
  margin-bottom: 0;
  min-width: min(16rem, 100%);
}

.frequency__empty {
  color: var(--myst-text-muted);
}

.frequency__summary {
  color: var(--myst-text-dim);
  font-size: 0.85rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  margin-bottom: 0.6rem;
}

.frequency__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.frequency__list[aria-busy='true'] {
  opacity: 0.6;
}

.frequency__row {
  display: grid;
  grid-template-columns: minmax(9rem, 14rem) 1fr minmax(8.5rem, auto);
  align-items: center;
  gap: 0.35rem 1.1rem;
  padding: 0.55rem 0;
  border-top: 1px solid var(--myst-border);
}

.frequency__row:first-child {
  border-top: none;
}

.frequency__name {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.frequency__card {
  color: var(--myst-text-strong);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.frequency__deck {
  color: var(--myst-text-dim);
  font-size: 0.8rem;
}

/* The bar: a faint track, filled in proportion to the most-drawn card, the fill
   split between upright (gold) and reversed (silver). */
.frequency__bar {
  height: 6px;
  border-radius: 3px;
  background: color-mix(in srgb, var(--myst-text) 6%, transparent);
  overflow: hidden;
}

.frequency__fill {
  display: flex;
  height: 100%;
  min-width: 4px;
}

.frequency__upright {
  background: var(--myst-gold);
}

.frequency__reversed {
  background: color-mix(in srgb, var(--myst-silver) 55%, transparent);
}

.frequency__count {
  display: flex;
  align-items: baseline;
  justify-content: flex-end;
  gap: 0.6rem;
  white-space: nowrap;
}

.frequency__count strong {
  color: var(--myst-gold-bright);
  font-variant-numeric: tabular-nums;
  min-width: 1.5ch;
  text-align: right;
}

.frequency__split {
  color: var(--myst-text-dim);
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
}

.frequency__more {
  margin-top: 0.6rem;
  padding-left: 0;
}

@media screen and (width <= 600px) {
  .frequency__row {
    grid-template-columns: 1fr auto;
  }

  .frequency__bar {
    grid-column: 1 / -1;
    grid-row: 2;
  }
}
</style>
