<script setup lang="ts">
/**
 * The public spread library: every published spread, filtered by the admins'
 * categories and searched by name and description. A spread opens to its
 * positions and what each asks; from there the reader draws with it (New Draw,
 * `?spread=`) or, signed in, copies it into My Spreads to make it their own.
 *
 * The list is small (dozens), so it's fetched whole and filtered here.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from 'reka-ui'
import { useSpreads } from '@/composables/useSpreads'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'
import { renderMarkdown, markdownExcerpt } from '@/utils/markdown'
import type { Spread } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import SpreadLayoutPreview from '@/components/SpreadLayoutPreview.vue'

const ALL = 'all'
const EXCERPT_LENGTH = 160

const { spreads, fetchPublicSpreads } = useSpreads()
const { copyPublicSpread } = useUserSpreads()
const { isLoggedIn } = useUser()
const toasts = useToasts()

const loading = ref(true)
const loadFailed = ref(false)
const search = ref('')
const category = ref<string>(ALL)
const open = ref<Spread | null>(null)
const copying = ref(false)
/** The name the open spread was just copied under, to point at it in My Spreads. */
const copiedAs = ref<string | null>(null)

onMounted(async () => {
  loadFailed.value = !(await fetchPublicSpreads())
  loading.value = false
})

/**
 * "All", then each category that holds a spread, in the admins' order: every
 * spread carries its category's name and place.
 */
const tabs = computed(() => {
  const held = new Map<number, { label: string; order: number; count: number }>()
  for (const s of spreads.value) {
    const tab = held.get(s.category_id)
    if (tab) {
      tab.count++
    } else {
      held.set(s.category_id, { label: s.category_name, order: s.category_sort_order, count: 1 })
    }
  }
  return [
    { value: ALL, label: 'All', count: spreads.value.length },
    ...[...held.entries()]
      .sort(([, a], [, b]) => a.order - b.order)
      .map(([id, { label, count }]) => ({ value: String(id), label, count })),
  ]
})

const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  return spreads.value.filter(
    (s) =>
      (category.value === ALL || String(s.category_id) === category.value) &&
      (!q || s.name.toLowerCase().includes(q) || s.description.toLowerCase().includes(q)),
  )
})

// Worked out once per list, not on every keystroke of a search.
const excerpts = computed(
  () =>
    new Map(
      spreads.value.map((s) => [s.spread_id, markdownExcerpt(s.description, EXCERPT_LENGTH)]),
    ),
)

const openDescriptionHtml = computed(() => renderMarkdown(open.value?.description))
const openPositions = computed(() =>
  open.value ? [...open.value.positions].sort((a, b) => a.order - b.order) : [],
)

function cards(count: number): string {
  return count === 1 ? '1 card' : `${count} cards`
}

function meta(spread: Spread): string {
  return `${spread.category_name} · ${cards(spread.card_count)}`
}

/** Only a search can empty a tab: each lists the category's spreads. */
function noMatch(tabLabel: string): string {
  const where = category.value === ALL ? '' : ` in ${tabLabel}`
  return `No spreads match “${search.value.trim()}”${where}.`
}

function openSpread(spread: Spread): void {
  copiedAs.value = null
  open.value = spread
}

function close(): void {
  open.value = null
}

async function copyOpen(): Promise<void> {
  if (!open.value || copying.value) {
    return
  }
  copying.value = true
  const res = await copyPublicSpread(open.value.spread_id)
  copying.value = false
  if (!res.ok) {
    toasts.error(res.error, { detail: res })
    return
  }
  copiedAs.value = res.data.name
  toasts.success(`Copied to My Spreads as “${res.data.name}”.`)
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader
            title="Spreads"
            subtitle="Layouts for every kind of question. Open one to see what each position asks, then draw with it or make it your own."
          >
            <router-link :to="{ name: 'submit-spread' }" class="button">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
              <span>Create a Spread</span>
            </router-link>
          </PageHeader>

          <div v-if="loading" class="has-text-centered py-6" role="status">
            <span class="icon is-large"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin size="2x"
            /></span>
            <span class="visually-hidden">Loading spreads…</span>
          </div>

          <div v-else-if="loadFailed" class="myst-callout">
            <p>The spreads couldn't be loaded just now. Please try again in a moment.</p>
          </div>

          <div v-else-if="spreads.length === 0" class="myst-callout">
            <p>There are no public spreads yet.</p>
            <p class="mt-2">
              <router-link :to="{ name: 'submit-spread' }">Create a spread</router-link> and submit
              it for everyone to use.
            </p>
          </div>

          <div v-else class="settings-panel library">
            <div class="field library-search">
              <label for="spread-library-search" class="visually-hidden">Search spreads</label>
              <div class="control has-icons-left">
                <input
                  id="spread-library-search"
                  v-model="search"
                  class="input"
                  type="search"
                  placeholder="Search by name or description"
                  autocomplete="off"
                />
                <span class="icon is-small is-left"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
                /></span>
              </div>
            </div>

            <TabsRoot v-model="category">
              <TabsList class="library-tabs" aria-label="Categories">
                <TabsTrigger
                  v-for="tab in tabs"
                  :key="tab.value"
                  :value="tab.value"
                  class="library-tab"
                >
                  {{ tab.label }}
                  <span class="library-tab__count" aria-hidden="true">{{ tab.count }}</span>
                </TabsTrigger>
              </TabsList>

              <TabsContent v-for="tab in tabs" :key="tab.value" :value="tab.value">
                <p v-if="visible.length === 0" class="library-empty">
                  {{ noMatch(tab.label) }}
                  <button type="button" class="button is-small is-text" @click="search = ''">
                    Clear the search
                  </button>
                </p>
                <ul v-else class="library-list">
                  <li v-for="spread in visible" :key="spread.spread_id" class="library-entry">
                    <SpreadLayoutPreview
                      :positions="spread.positions"
                      thumb
                      class="library-entry__shape"
                    />
                    <div class="library-entry__text">
                      <h2 class="library-entry__name">
                        <button
                          type="button"
                          class="library-entry__open"
                          @click="openSpread(spread)"
                        >
                          {{ spread.name }}
                        </button>
                      </h2>
                      <p class="library-entry__meta">{{ meta(spread) }}</p>
                      <p v-if="excerpts.get(spread.spread_id)" class="library-entry__excerpt">
                        {{ excerpts.get(spread.spread_id) }}
                      </p>
                    </div>
                  </li>
                </ul>
              </TabsContent>
            </TabsRoot>
          </div>
        </div>
      </div>
    </div>
  </section>

  <BaseModal :active="open !== null" :title="open?.name ?? ''" max-width="46rem" @close="close">
    <template v-if="open">
      <p class="library-detail__meta">{{ meta(open) }}</p>

      <div class="library-detail">
        <SpreadLayoutPreview :positions="open.positions" class="library-detail__shape" />
        <div class="library-detail__positions">
          <h3 class="library-detail__heading">Positions</h3>
          <ol class="library-positions">
            <li v-for="pos in openPositions" :key="pos.order" class="library-position">
              <span class="spread-detail-order" aria-hidden="true">{{ pos.order }}</span>
              <div>
                <p class="library-position__title">
                  <span class="visually-hidden">{{ pos.order }}. </span
                  >{{ pos.title || 'Position ' + pos.order }}
                </p>
                <p v-if="pos.description" class="library-position__about">
                  {{ pos.description }}
                </p>
              </div>
            </li>
          </ol>
        </div>
      </div>

      <!-- Sanitized by renderMarkdown() (marked + DOMPurify). -->
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div v-if="openDescriptionHtml" class="content mt-4" v-html="openDescriptionHtml"></div>

      <p v-if="copiedAs" class="myst-callout library-copied" role="status">
        Copied as &ldquo;{{ copiedAs }}&rdquo;.
        <router-link :to="{ name: 'account-spreads' }">Open My Spreads</router-link>
        to change it.
      </p>
      <p v-else-if="!isLoggedIn" class="help mt-4">
        <router-link :to="{ name: 'login', query: { redirect: '/spreads' } }">Sign in</router-link>
        to copy this spread into My Spreads and make it your own.
      </p>
    </template>

    <template v-if="open" #footer>
      <router-link
        class="button is-primary"
        :to="{ name: 'new-reading', query: { spread: String(open.spread_id) } }"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
        <span>Draw with this spread</span>
      </router-link>
      <button
        v-if="isLoggedIn"
        type="button"
        class="button"
        :class="{ 'is-loading': copying }"
        :disabled="copiedAs !== null"
        @click="copyOpen"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['copy']" /></span>
        <span>{{ copiedAs ? 'Copied' : 'Copy to My Spreads' }}</span>
      </button>
      <button type="button" class="button is-text" @click="close">Close</button>
    </template>
  </BaseModal>
</template>

<style scoped>
.library-search {
  max-width: 26rem;
}

/* Category filter: quiet text tabs on a hairline; the selected one takes the pop. */
.library-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1.25rem;
  margin: 0.5rem 0 0.25rem;
  border-bottom: 1px solid var(--myst-border);
}

.library-tab {
  display: inline-flex;
  align-items: baseline;
  gap: 0.4rem;
  padding: 0.55rem 0 0.5rem;
  margin-bottom: -1px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: none;
  color: var(--myst-text-muted);
  font: inherit;
  font-size: 0.92rem;
  cursor: pointer;
  transition:
    color 0.15s ease,
    border-color 0.15s ease;
}

.library-tab:hover {
  color: var(--myst-text-strong);
}

.library-tab:focus-visible {
  outline: 2px solid var(--myst-gold-bright);
  outline-offset: 2px;
  border-radius: 2px;
}

.library-tab[data-state='active'] {
  color: var(--myst-text-strong);
  border-bottom-color: var(--myst-aqua);
}

.library-tab__count {
  font-size: 0.75rem;
  color: var(--myst-text-dim);
}

/* One spread per row, hairline-divided: its shape, then its name and gist. */
.library-list {
  list-style: none;
  margin: 0;
}

.library-entry {
  position: relative;
  display: grid;
  grid-template-columns: 7.5rem 1fr;
  gap: 1.25rem;
  align-items: center;
  padding: 1.1rem 0.5rem;
  border-bottom: 1px solid var(--myst-border);
  border-radius: 10px;
  transition: background-color 0.15s ease;
}

.library-entry:last-child {
  border-bottom: 0;
}

.library-entry:hover {
  background-color: color-mix(in srgb, var(--myst-surface-3) 55%, transparent);
}

.library-entry:focus-within {
  outline: 2px solid var(--myst-gold-bright);
  outline-offset: -2px;
}

.library-entry__shape {
  width: 100%;
}

.library-entry__name {
  margin: 0;
  font-family: var(--myst-heading-font);
  font-size: 1.2rem;
  font-weight: 400;
  line-height: 1.25;
  color: var(--myst-text-strong);
}

/* The name is the control; it stretches over the whole row. */
.library-entry__open {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.library-entry__open::after {
  content: '';
  position: absolute;
  inset: 0;
}

.library-entry__open:focus-visible {
  outline: none;
}

.library-entry__meta {
  margin-top: 0.2rem;
  font-size: 0.8rem;
  letter-spacing: 0.02em;
  color: var(--myst-gold);
}

.library-entry__excerpt {
  margin-top: 0.4rem;
  font-size: 0.9rem;
  color: var(--myst-text-muted);
}

.library-empty {
  padding: 1.5rem 0.5rem;
  color: var(--myst-text-muted);
}

/* Detail dialog: the numbered layout beside its positions. */
.library-detail__meta {
  margin: -0.25rem 0 1rem;
  font-size: 0.85rem;
  color: var(--myst-gold);
}

.library-detail {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 1.5rem;
  align-items: start;
}

.library-detail__heading {
  margin-bottom: 0.6rem;
  font-family: var(--myst-heading-font);
  font-size: 1rem;
  font-weight: 400;
  color: var(--myst-text-strong);
}

.library-positions {
  list-style: none;
  margin: 0;
}

.library-position {
  display: flex;
  gap: 0.7rem;
  align-items: flex-start;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--myst-border);
}

.library-position:last-child {
  border-bottom: 0;
}

.library-position .spread-detail-order {
  margin-top: 0.05rem;
}

.library-position__title {
  color: var(--myst-text);
  font-weight: 600;
}

.library-position__about {
  margin-top: 0.15rem;
  font-size: 0.85rem;
  color: var(--myst-text-muted);
}

.library-copied {
  margin-top: 1rem;
}

@media screen and (width <= 600px) {
  .library-entry {
    grid-template-columns: 4.75rem 1fr;
    gap: 0.9rem;
    padding: 0.9rem 0.25rem;
  }

  .library-entry__name {
    font-size: 1.05rem;
  }

  .library-detail {
    grid-template-columns: 1fr;
  }

  .library-detail__shape {
    max-width: 16rem;
    margin: 0 auto;
  }
}
</style>
