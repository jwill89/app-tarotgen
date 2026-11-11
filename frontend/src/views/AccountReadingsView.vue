<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import {
  PaginationRoot,
  PaginationList,
  PaginationListItem,
  PaginationPrev,
  PaginationNext,
  PaginationEllipsis,
} from 'reka-ui'
import { useAccount, type ReadingFilters } from '@/composables/useAccount'
import { useDecks } from '@/composables/useDecks'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { formatDateTime } from '@/utils/datetime'
import { visibilityLabels } from '@/utils/visibility'
import BaseSelect from '@/components/BaseSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import ReadingEditDialog from '@/components/ReadingEditDialog.vue'
import type { AccountReading, DrawCard, ReadingSpread, ReadingTag } from '@/types'

const route = useRoute()
const router = useRouter()
const { listReadingsPage, listTags, listReadingSpreads, deleteReading } = useAccount()
const { decks, fetchDecks } = useDecks()
const toasts = useToasts()
const { confirm } = useConfirm()
const deletingId = ref<string | null>(null)

const readings = ref<AccountReading[]>([])
const loading = ref(true)
const editing = ref<AccountReading | null>(null)
const myTags = ref<ReadingTag[]>([])
const mySpreads = ref<ReadingSpread[]>([])

function summarize(r: AccountReading): string {
  if (r.reading_name) {
    return r.reading_name
  }
  try {
    const info = r.reading_info
    if (info.spread?.name) {
      return info.spread.name
    }
    // Stored reading_info may be malformed (hence the try/catch) — draw can be absent.
    const draw = info.draw as DrawCard[] | undefined
    const n = draw?.length ?? 0
    return String(n) + (n === 1 ? ' card' : ' cards')
  } catch {
    return 'Reading'
  }
}

// ── Filters ──────────────────────────────────────────────────────────────────
// They live in the URL (?q=&deck=&spread=&tag=&from=&to=&daily=&page=): a
// filtered list is a link, and Back steps through the filters tried.

function fromQuery(name: string): string {
  const value = route.query[name]
  return typeof value === 'string' ? value : ''
}

const filters = computed<ReadingFilters>(() => {
  const daily = fromQuery('daily')
  return {
    q: fromQuery('q'),
    deck_id: Number(fromQuery('deck')) || null,
    spread: fromQuery('spread'),
    tag: fromQuery('tag'),
    from: fromQuery('from'),
    to: fromQuery('to'),
    daily: daily === 'only' || daily === 'exclude' ? daily : '',
  }
})
const isFiltered = computed(() =>
  Object.values(filters.value).some((value) => value !== '' && value !== null),
)

/** Change some of the URL's filters; any change but paging starts again at page 1. */
function setQuery(changes: Record<string, string | null>): void {
  const merged: LocationQueryRaw = { ...route.query, ...changes }
  if (!('page' in changes)) {
    merged.page = null
  }
  // A cleared filter leaves the address rather than staying as `?tag=`.
  const query = Object.fromEntries(
    Object.entries(merged).filter(([, value]) => value !== null && value !== ''),
  )
  void router.push({ query })
}

function clearFilters(): void {
  search.value = ''
  void router.push({ query: {} })
}

// Search: typed into a field, put in the URL once the reader pauses.
const search = ref(fromQuery('q'))
let searchTimer: ReturnType<typeof setTimeout> | undefined
function onSearch(): void {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    setQuery({ q: search.value.trim() || null })
  }, 400)
}
watch(
  () => fromQuery('q'),
  (q) => {
    if (q !== search.value.trim()) {
      search.value = q
    }
  },
)
onBeforeUnmount(() => {
  clearTimeout(searchTimer)
})

const deckChoice = computed<string | number | null>({
  get: () => filters.value.deck_id ?? 'all',
  set: (value) => {
    setQuery({ deck: value === 'all' || value === null ? null : String(value) })
  },
})
const deckOptions = computed(() => [
  { value: 'all', label: 'All decks' },
  ...decks.value.map((d) => ({ value: d.deck_id, label: d.name })),
])

// The spreads the reader's own readings were laid out in, by the name they
// recorded: the server lists only names the filter finds readings for.
const spreadChoice = computed<string | number | null>({
  get: () => filters.value.spread || 'all',
  set: (value) => {
    setQuery({ spread: value === 'all' || value === null ? null : String(value) })
  },
})
const spreadOptions = computed(() => {
  const options = [
    { value: 'all', label: 'All spreads' },
    ...mySpreads.value.map((s) => ({ value: s.name, label: `${s.name} (${String(s.count)})` })),
  ]
  // A spread from a link that no reading uses any more still shows as chosen.
  const chosen = filters.value.spread
  if (chosen && !mySpreads.value.some((s) => s.name === chosen)) {
    options.push({ value: chosen, label: chosen })
  }
  return options
})

const tagChoice = computed<string | number | null>({
  get: () => filters.value.tag || 'all',
  set: (value) => {
    setQuery({ tag: value === 'all' || value === null ? null : String(value) })
  },
})
const tagOptions = computed(() => {
  const options = [
    { value: 'all', label: 'All tags' },
    ...myTags.value.map((t) => ({ value: t.tag, label: `${t.tag} (${String(t.count)})` })),
  ]
  // A tag from a link that's no longer on any reading still shows as chosen.
  const chosen = filters.value.tag
  if (chosen && !myTags.value.some((t) => t.tag === chosen)) {
    options.push({ value: chosen, label: chosen })
  }
  return options
})
// A select shows the chosen option's label as it first rendered: each is
// remounted when its options change (the decks and the spread and tag counts
// arrive after a filtered page opens).
const optionsKey = (options: { label: string }[]) => options.map((o) => o.label).join('\n')
const spreadSelectKey = computed(() => optionsKey(spreadOptions.value))
const tagSelectKey = computed(() => optionsKey(tagOptions.value))

const dailyChoice = computed<string | number | null>({
  get: () => filters.value.daily || 'all',
  set: (value) => {
    setQuery({ daily: value === 'only' || value === 'exclude' ? value : null })
  },
})
const dailyOptions = [
  { value: 'all', label: 'Included' },
  { value: 'exclude', label: 'Left out' },
  { value: 'only', label: 'Only daily cards' },
]

function onDate(name: 'from' | 'to', event: Event): void {
  setQuery({ [name]: (event.target as HTMLInputElement).value || null })
}

// ── The list ─────────────────────────────────────────────────────────────────
// One page at a time: fetching every reading with its full body would grow with
// the reader's whole history.
const PER_PAGE = 25
const page = computed(() => Math.max(1, Math.floor(Number(fromQuery('page'))) || 1))
const total = ref(0)
const loadError = ref('')

// Only the latest request may land: a slow answer for an earlier filter must
// not replace the one the reader is looking at.
let latest = 0

async function load() {
  const request = ++latest
  loading.value = true
  const res = await listReadingsPage(page.value, PER_PAGE, filters.value)
  if (request !== latest) {
    return
  }
  loading.value = false
  if (!res.ok) {
    // Shown in place of the list — an empty list would read as "no readings yet".
    loadError.value = res.error
    return
  }
  loadError.value = ''
  readings.value = res.readings
  total.value = res.total
  // Deleting the only reading on the last page leaves it empty: step back.
  if (res.readings.length === 0 && page.value > 1) {
    setQuery({ page: page.value > 2 ? String(page.value - 1) : null })
  }
}

function onPage(next: number): void {
  setQuery({ page: next > 1 ? String(next) : null })
}

async function loadTags(): Promise<void> {
  myTags.value = await listTags()
}

async function loadSpreads(): Promise<void> {
  mySpreads.value = await listReadingSpreads()
}

// Any change to the URL's query is a new filter or page. (Not when the route
// itself changes: that's the reader leaving.)
watch(
  () => route.query,
  () => {
    if (route.name === 'account-readings') {
      void load()
    }
  },
)

function onSaved(): void {
  toasts.success('Reading updated.')
  editing.value = null
  void load()
  void loadTags()
}

async function removeReading(r: AccountReading) {
  const ok = await confirm({
    title: 'Delete reading',
    message: `Permanently delete "${summarize(r)}"? Anyone with its link will no longer be able to view it. This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }

  deletingId.value = r.reading_id
  try {
    const res = await deleteReading(r.reading_id)
    if (res.ok) {
      toasts.success('Reading deleted.')
      // Reload the page so the next reading moves up and the total stays right;
      // the spread and tag counts lose the reading too.
      await load()
      void loadTags()
      void loadSpreads()
    } else {
      toasts.error(res.error ?? 'Could not delete the reading.')
    }
  } finally {
    deletingId.value = null
  }
}

onMounted(() => {
  void fetchDecks()
  void loadSpreads()
  void loadTags()
  void load()
})
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader
            title="My Readings"
            subtitle="Find a reading, and manage its title, journal, and who can open it."
          />

          <div
            class="settings-panel readings-filters"
            role="search"
            aria-label="Filter your readings"
          >
            <div class="field readings-filters__search">
              <label class="label" for="my-readings-search">Search</label>
              <div class="control has-icons-left">
                <input
                  id="my-readings-search"
                  v-model="search"
                  class="input"
                  type="search"
                  placeholder="Title, question, who it was for, notes…"
                  @input="onSearch"
                />
                <span class="icon is-small is-left"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
                /></span>
              </div>
            </div>
            <div class="field">
              <label class="label" for="my-readings-deck">Deck</label>
              <BaseSelect
                id="my-readings-deck"
                :key="deckOptions.length"
                v-model="deckChoice"
                :options="deckOptions"
              />
            </div>
            <div class="field">
              <label class="label" for="my-readings-spread">Spread</label>
              <BaseSelect
                id="my-readings-spread"
                :key="spreadSelectKey"
                v-model="spreadChoice"
                :options="spreadOptions"
              />
            </div>
            <div class="field">
              <label class="label" for="my-readings-tag">Tag</label>
              <BaseSelect
                id="my-readings-tag"
                :key="tagSelectKey"
                v-model="tagChoice"
                :options="tagOptions"
              />
            </div>
            <div class="field">
              <label class="label" for="my-readings-from">From</label>
              <input
                id="my-readings-from"
                class="input"
                type="date"
                :value="filters.from"
                :max="filters.to || undefined"
                @change="onDate('from', $event)"
              />
            </div>
            <div class="field">
              <label class="label" for="my-readings-to">To</label>
              <input
                id="my-readings-to"
                class="input"
                type="date"
                :value="filters.to"
                :min="filters.from || undefined"
                @change="onDate('to', $event)"
              />
            </div>
            <div class="field">
              <label class="label" for="my-readings-daily">Daily Cards</label>
              <BaseSelect id="my-readings-daily" v-model="dailyChoice" :options="dailyOptions" />
            </div>
            <div v-if="isFiltered" class="field readings-filters__clear">
              <button type="button" class="button is-ghost" @click="clearFilters">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" /></span>
                <span>Clear filters</span>
              </button>
            </div>
          </div>

          <p v-if="loading && readings.length === 0 && !loadError" class="has-text-grey">
            <span class="icon"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin
            /></span>
            <span>Loading your readings…</span>
          </p>

          <div v-else-if="loadError" class="notification is-danger is-light">
            <p class="mb-3">Couldn't load your readings. {{ loadError }}</p>
            <button
              type="button"
              class="button is-small"
              :class="{ 'is-loading': loading }"
              @click="load"
            >
              Try again
            </button>
          </div>

          <p v-else-if="total === 0 && isFiltered" class="has-text-grey">
            No readings match these filters.
          </p>

          <p v-else-if="total === 0" class="has-text-grey">
            You haven't saved any readings yet. New draws and recreations you make while signed in
            appear here.
          </p>

          <div v-else class="settings-panel">
            <p class="has-text-grey is-size-7 mb-2" role="status">
              {{ total }} reading{{ total === 1 ? '' : 's'
              }}{{ isFiltered ? (total === 1 ? ' matches' : ' match') : '' }}
            </p>
            <div class="table-container">
              <table class="table is-fullwidth is-hoverable is-striped">
                <thead>
                  <tr>
                    <th>Reading</th>
                    <th>Date</th>
                    <th>Visibility</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in readings" :key="r.reading_id">
                    <td>
                      {{ summarize(r) }}
                      <!-- A spoken space between the title and the tag, which the
                           template compiler would otherwise drop. -->
                      <!-- eslint-disable vue/no-useless-mustaches -- the space is text the template compiler would drop as the first child of the <template>, and Prettier would move -->
                      <template v-if="r.daily_card_day"
                        >{{ ' '
                        }}<span class="tag is-link is-light ml-1">Daily card</span></template
                      >
                      <!-- eslint-enable vue/no-useless-mustaches -->
                      <p v-if="r.question" class="reading-question">“{{ r.question }}”</p>
                      <p v-if="r.querent" class="reading-querent">For {{ r.querent }}</p>
                      <p v-if="r.tags.length" class="reading-tags">
                        <router-link
                          v-for="tag in r.tags"
                          :key="tag"
                          class="data-chip"
                          :to="{ query: { ...route.query, tag, page: undefined } }"
                          :aria-label="`Readings tagged ${tag}`"
                          >{{ tag }}</router-link
                        >
                      </p>
                    </td>
                    <td>{{ formatDateTime(r.reading_time) }}</td>
                    <td>
                      <span v-if="r.visibility === 'private'" class="tag is-dark mr-1">
                        <span class="icon is-small"
                          ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
                        /></span>
                        <span>{{ visibilityLabels.private }}</span>
                      </span>
                      <span
                        v-else-if="r.visibility === 'password'"
                        class="tag is-warning is-light mr-1"
                      >
                        <span class="icon is-small"
                          ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock-keyhole']"
                        /></span>
                        <span>{{ visibilityLabels.password }}</span>
                      </span>
                      <span v-else class="has-text-grey mr-1">{{ visibilityLabels.link }}</span>
                      <!-- A spoken space before the tag, as for the daily card's. -->
                      <!-- eslint-disable vue/no-useless-mustaches -- the space is text the template compiler would drop as the first child of the <template>, and Prettier would move -->
                      <template v-if="r.hide_user"
                        >{{ ' ' }}<span class="tag is-info is-light">Name hidden</span></template
                      >
                      <!-- eslint-enable vue/no-useless-mustaches -->
                    </td>
                    <td>
                      <div class="buttons are-small">
                        <router-link
                          class="button is-info"
                          :to="{ name: 'reading', params: { id: r.reading_id } }"
                        >
                          <span class="icon"
                            ><FontAwesomeIcon :icon="byPrefixAndName.fas['eye']"
                          /></span>
                          <span>View</span>
                        </router-link>
                        <button type="button" class="button" @click="editing = r">
                          <span class="icon"
                            ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                          /></span>
                          <span>Edit</span>
                        </button>
                        <button
                          type="button"
                          class="button is-danger"
                          :class="{ 'is-loading': deletingId === r.reading_id }"
                          @click="removeReading(r)"
                        >
                          <span class="icon"
                            ><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']"
                          /></span>
                          <span>Delete</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <PaginationRoot
              v-if="total > PER_PAGE"
              :page="page"
              :total="total"
              :items-per-page="PER_PAGE"
              :sibling-count="1"
              show-edges
              class="mt-4"
              @update:page="onPage"
            >
              <PaginationList v-slot="{ items }" class="pagination-list-reka">
                <PaginationPrev class="button is-small">‹</PaginationPrev>
                <template v-for="(item, i) in items" :key="i">
                  <PaginationListItem
                    v-if="item.type === 'page'"
                    :value="item.value"
                    class="button is-small"
                  >
                    {{ item.value }}
                  </PaginationListItem>
                  <PaginationEllipsis v-else :index="i" class="pagination-ellipsis"
                    >&hellip;</PaginationEllipsis
                  >
                </template>
                <PaginationNext class="button is-small">›</PaginationNext>
              </PaginationList>
            </PaginationRoot>
          </div>
        </div>
      </div>
    </div>
  </section>

  <ReadingEditDialog :reading="editing" @close="editing = null" @saved="onSaved" />
</template>

<style scoped>
.readings-filters {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr));
  gap: 0.75rem 1rem;
  align-items: end;
  margin-bottom: 1.25rem;
}

.readings-filters .field {
  margin-bottom: 0;
}

.readings-filters__search {
  grid-column: 1 / -1;
}

.reading-question,
.reading-querent {
  margin-top: 0.2rem;
  color: var(--myst-text-muted);
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}

.reading-question {
  font-style: italic;
}

.reading-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.35rem;
}

.reading-tags .data-chip:hover {
  border-color: var(--myst-gold);
}
</style>
