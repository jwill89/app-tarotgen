<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, defineAsyncComponent } from 'vue'
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { APP_VERSION } from '@/constants'
import PageHeader from '@/components/PageHeader.vue'
import Tooltip from '@/components/Tooltip.vue'
import type { UsageStats } from '@/types'

// The release notes come with it, so they load only when the version is clicked.
const ChangelogDialog = defineAsyncComponent(() => import('@/components/admin/ChangelogDialog.vue'))

const api = useAdminApi()
const changelogOpen = ref(false)

const counts = ref({
  deckSystems: 0,
  decks: 0,
  specialCards: 0,
  spreads: 0,
  readings: 0,
  users: 0,
  unreadContacts: 0,
  openCardReports: 0,
  pendingSpreads: 0,
  pendingDeckSystems: 0,
})
const stats = ref<UsageStats | null>(null)
const loading = ref(true)

interface NavItem {
  to: string
  label: string
  icon: IconDefinition
  count: number
  /** Render the count as the highlighted pop badge (e.g. unread contacts). */
  badge?: boolean
}
interface NavGroup {
  label: string
  icon: IconDefinition
  items: NavItem[]
}

// The dashboard's section launcher: a grouped side-menu whose item counts stay
// live off `counts`.
const navGroups = computed<NavGroup[]>(() => [
  {
    label: 'Catalog',
    icon: byPrefixAndName.fad['grid-2-plus'],
    items: [
      {
        to: 'admin-decks',
        label: 'Decks',
        icon: byPrefixAndName.fad['cards-blank'],
        count: counts.value.decks,
      },
      {
        to: 'admin-deck-systems',
        label: 'Deck Systems',
        icon: byPrefixAndName.fad['layer-group'],
        count: counts.value.deckSystems,
      },
      {
        to: 'admin-deck-system-submissions',
        label: 'Deck System Submissions',
        icon: byPrefixAndName.fad.inbox,
        // Waiting for review, so a badge like unread contacts.
        count: counts.value.pendingDeckSystems,
        badge: true,
      },
      {
        to: 'admin-special-cards',
        label: 'Special Cards',
        icon: byPrefixAndName.fad.sparkles,
        count: counts.value.specialCards,
      },
      {
        to: 'admin-spreads',
        label: 'Spreads',
        icon: byPrefixAndName.fad['table-cells'],
        count: counts.value.spreads,
      },
      {
        to: 'admin-spread-submissions',
        label: 'Spread Submissions',
        icon: byPrefixAndName.fad.inbox,
        // Waiting for review, so a badge like unread contacts.
        count: counts.value.pendingSpreads,
        badge: true,
      },
    ],
  },
  {
    label: 'Activity',
    icon: byPrefixAndName.fas['gauge-high'],
    items: [
      {
        to: 'admin-readings',
        label: 'Readings',
        icon: byPrefixAndName.fad.scroll,
        count: counts.value.readings,
      },
      {
        to: 'admin-users',
        label: 'Users',
        icon: byPrefixAndName.fad.users,
        count: counts.value.users,
      },
      {
        to: 'admin-contacts',
        label: 'Contacts',
        icon: byPrefixAndName.fad.envelope,
        count: counts.value.unreadContacts,
        badge: true,
      },
      {
        to: 'admin-card-reports',
        label: 'Errored Cards',
        icon: byPrefixAndName.fad['circle-exclamation'],
        // Open reports only: the ones still waiting for a new scan.
        count: counts.value.openCardReports,
      },
    ],
  },
])

// Largest bar values, used to scale the simple CSS bar charts.
const maxDeck = computed(() => Math.max(1, ...(stats.value?.topDecks ?? []).map((d) => d.count)))
const maxSpread = computed(() =>
  Math.max(1, ...(stats.value?.topSpreads ?? []).map((s) => s.count)),
)
const maxDaily = computed(() => Math.max(1, ...(stats.value?.daily ?? []).map((d) => d.count)))

function dayLabel(date: string): string {
  // date is 'YYYY-MM-DD' (UTC); show day-of-month for a compact axis.
  return date.slice(8, 10)
}

onMounted(async () => {
  const data = await api.get<{ counts: typeof counts.value; stats: UsageStats }>(
    endpoints.admin.dashboard.summary,
  )
  if (data) {
    counts.value = data.counts
    stats.value = data.stats
  }
  loading.value = false
})
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-11-desktop is-12-tablet">
          <PageHeader title="Admin Dashboard" subtitle="Manage your tarot data.">
            <Tooltip text="Application version: see the changelog">
              <button type="button" class="tag app-version" @click="changelogOpen = true">
                v{{ APP_VERSION }}
              </button>
            </Tooltip>
          </PageHeader>

          <div class="admin-console">
            <!-- Section launcher -->
            <nav class="admin-nav" aria-label="Admin sections">
              <template v-for="group in navGroups" :key="group.label">
                <p class="admin-nav-group">
                  <span class="icon"><FontAwesomeIcon :icon="group.icon" /></span>{{ group.label }}
                </p>
                <router-link
                  v-for="item in group.items"
                  :key="item.to"
                  :to="{ name: item.to }"
                  class="admin-nav-item"
                >
                  <span class="nav-icon"><FontAwesomeIcon :icon="item.icon" /></span>
                  <span class="nav-label">{{ item.label }}</span>
                  <span v-if="item.badge && item.count" class="nav-badge">{{ item.count }}</span>
                  <span v-else class="nav-count">{{
                    loading ? '·' : item.count.toLocaleString()
                  }}</span>
                </router-link>
              </template>
            </nav>

            <!-- Insights -->
            <div class="admin-insights">
              <p v-if="loading" class="has-text-grey">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin
                /></span>
                <span>Loading insights…</span>
              </p>

              <template v-if="stats">
                <h2 class="insights-title">Reading Insights</h2>

                <div class="columns is-multiline">
                  <div class="column is-4">
                    <div class="settings-panel stat-box">
                      <p class="stat-value">{{ stats.totals.readings.toLocaleString() }}</p>
                      <p class="stat-label">Total readings</p>
                    </div>
                  </div>
                  <div class="column is-4">
                    <div class="settings-panel stat-box">
                      <p class="stat-value">{{ stats.totals.last7.toLocaleString() }}</p>
                      <p class="stat-label">Last 7 days</p>
                    </div>
                  </div>
                  <div class="column is-4">
                    <div class="settings-panel stat-box">
                      <p class="stat-value">{{ stats.totals.last30.toLocaleString() }}</p>
                      <p class="stat-label">Last 30 days</p>
                    </div>
                  </div>
                </div>

                <div class="columns">
                  <!-- Readings per day -->
                  <div class="column is-7">
                    <div class="settings-panel">
                      <h3 class="title is-6">Readings per day (last 14)</h3>
                      <div v-if="stats.daily.length" class="daily-chart">
                        <div
                          v-for="d in stats.daily"
                          :key="d.date"
                          class="daily-col"
                          :title="d.date + ': ' + d.count"
                        >
                          <div
                            class="daily-bar"
                            :style="{ height: Math.round((d.count / maxDaily) * 100) + '%' }"
                          ></div>
                          <span class="daily-axis">{{ dayLabel(d.date) }}</span>
                        </div>
                      </div>
                      <p v-else class="has-text-grey">No readings yet.</p>
                    </div>
                  </div>

                  <!-- Reading types -->
                  <div class="column is-5">
                    <div class="settings-panel">
                      <h3 class="title is-6">Reading types</h3>
                      <div class="type-row">
                        <span>Free draw</span
                        ><strong>{{ stats.byType.freeDraw.toLocaleString() }}</strong>
                      </div>
                      <div class="type-row">
                        <span>Spread</span
                        ><strong>{{ stats.byType.spread.toLocaleString() }}</strong>
                      </div>
                      <div class="type-row">
                        <span>Custom</span
                        ><strong>{{ stats.byType.custom.toLocaleString() }}</strong>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="columns">
                  <!-- Top decks -->
                  <div class="column is-6">
                    <div class="settings-panel">
                      <h3 class="title is-6">Most-used decks</h3>
                      <div v-for="d in stats.topDecks" :key="d.deck_id" class="rank-row">
                        <span class="rank-name">{{ d.name }}</span>
                        <span class="rank-track"
                          ><span
                            class="rank-fill"
                            :style="{ width: Math.round((d.count / maxDeck) * 100) + '%' }"
                          ></span
                        ></span>
                        <span class="rank-count">{{ d.count }}</span>
                      </div>
                      <p v-if="stats.topDecks.length === 0" class="has-text-grey">No data yet.</p>
                    </div>
                  </div>

                  <!-- Top spreads -->
                  <div class="column is-6">
                    <div class="settings-panel">
                      <h3 class="title is-6">Most-used spreads</h3>
                      <div v-for="s in stats.topSpreads" :key="s.name" class="rank-row">
                        <span class="rank-name">{{ s.name }}</span>
                        <span class="rank-track"
                          ><span
                            class="rank-fill"
                            :style="{ width: Math.round((s.count / maxSpread) * 100) + '%' }"
                          ></span
                        ></span>
                        <span class="rank-count">{{ s.count }}</span>
                      </div>
                      <p v-if="stats.topSpreads.length === 0" class="has-text-grey">
                        No spread readings yet.
                      </p>
                    </div>
                  </div>
                </div>
              </template>

              <p v-else-if="!loading" class="has-text-grey">No insights available yet.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <ChangelogDialog v-if="changelogOpen" @close="changelogOpen = false" />
  </section>
</template>

<style scoped>
.app-version {
  vertical-align: middle;
  font-size: 0.6em;
  font-weight: 600;

  /* A button (it opens the changelog) that still reads as the version tag. */
  border: 0;
  font-family: inherit;
  cursor: pointer;
  transition: color 0.15s ease;
}

.app-version:hover {
  color: var(--myst-gold);
}

.app-version:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
}

/* ── Console layout: side-menu launcher + insights ─────────── */
.admin-console {
  display: flex;
  gap: 1.5rem;
  align-items: flex-start;
}

.admin-nav {
  flex: 0 0 240px;
  position: sticky;
  top: 5rem;
  padding: 0.5rem;
  background: linear-gradient(180deg, var(--myst-surface-2), var(--myst-surface));
  border: 1px solid var(--myst-hair-gold);
  border-radius: 14px;
}

.admin-nav-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.85rem 0.7rem 0.4rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--myst-text-dim);
}

.admin-nav-group .icon {
  color: var(--myst-gold);
  font-size: 0.8rem;
}

.admin-nav-group:first-child {
  padding-top: 0.35rem;
}

.admin-nav-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 0.7rem;
  border-radius: 8px;
  color: var(--myst-text-muted);
  font-size: 0.9rem;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}

.admin-nav-item .nav-icon {
  flex: none;
  width: 1.25em;
  display: inline-flex;
  justify-content: center;
  color: var(--myst-gold);
}

.admin-nav-item .nav-label {
  flex: 1 1 auto;
}

.admin-nav-item .nav-count {
  flex: none;
  color: var(--myst-text-dim);
  font-variant-numeric: tabular-nums;
  font-size: 0.85rem;
}

.admin-nav-item .nav-badge {
  flex: none;
  background: var(--myst-aqua);
  color: var(--myst-on-aqua);
  font-weight: 600;
  padding: 0.05rem 0.5rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-variant-numeric: tabular-nums;
}

.admin-nav-item:hover {
  background: color-mix(in srgb, var(--myst-gold) 10%, transparent);
  color: var(--myst-text-strong);
}

.admin-nav-item:hover .nav-label {
  color: var(--myst-text-strong);
}

.admin-nav-item:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
}

.admin-insights {
  flex: 1 1 auto;
  min-width: 0;
}

.insights-title {
  font-family: var(--myst-heading-font);
  letter-spacing: 0.02em;
  color: var(--myst-text);
  font-size: 1.35rem;
  margin-bottom: 1rem;
}

/* Stack the console on narrow screens. */
@media screen and (width <= 768px) {
  .admin-console {
    flex-direction: column;
  }

  .admin-nav {
    position: static;
    flex-basis: auto;
    width: 100%;
  }
}

/* ── Insight panels ────────────────────────────────────────── */
.stat-box {
  text-align: center;
  border: 1px solid var(--myst-border-strong);
}

.stat-value {
  font-size: 2.25rem;
  font-weight: 800;
  line-height: 1;
}

.stat-label {
  margin-top: 0.35rem;
  opacity: 0.7;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-size: 0.8rem;
}

.daily-chart {
  display: flex;
  align-items: flex-end;
  gap: 0.35rem;
  height: 160px;
  padding-top: 0.5rem;
}

.daily-col {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  justify-content: flex-end;
  gap: 0.25rem;
}

.daily-bar {
  width: 100%;
  min-height: 2px;
  border-radius: 4px 4px 0 0;
  background: linear-gradient(to top, var(--myst-aqua-deep), var(--myst-aqua));
}

.daily-axis {
  font-size: 0.7rem;
  opacity: 0.6;
}

.rank-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.5rem;
}

.rank-name {
  flex: 0 0 38%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.9rem;
}

.rank-track {
  flex: 1 1 auto;
  height: 0.6rem;
  background: var(--myst-surface-3);
  border-radius: 999px;
  overflow: hidden;
}

.rank-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(to right, var(--myst-aqua-deep), var(--myst-aqua));
}

.rank-count {
  flex: none;
  min-width: 2rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.type-row {
  display: flex;
  justify-content: space-between;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--myst-border);
}

.type-row:last-child {
  border-bottom: none;
}
</style>
