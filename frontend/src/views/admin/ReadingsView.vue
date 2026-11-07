<script setup lang="ts">
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import BaseModal from '@/components/BaseModal.vue'
import IconButton from '@/components/IconButton.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import { formatDateTime } from '@/utils/datetime'
import type { ReadingSummary } from '@/types'
import {
  PaginationRoot,
  PaginationList,
  PaginationListItem,
  PaginationPrev,
  PaginationNext,
  PaginationEllipsis,
} from 'reka-ui'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const allReadings = ref<ReadingSummary[]>([])
const loading = ref(true)
const page = ref(1)
const perPage = ref(50)
const perPageOptions = [25, 50, 100, 200]

const {
  search,
  sortKey,
  sortDir,
  rows: sortedReadings,
  toggleSort,
} = useDataTable(allReadings, {
  searchText: (r) => `${r.reading_id} ${r.deck_name} ${r.spread_name} ${r.display_name}`,
  sortAccessors: {
    reading_time: (r) => r.reading_time,
    reading_id: (r) => r.reading_id,
    spread_name: (r) => r.spread_name,
    deck_name: (r) => r.deck_name,
    display_name: (r) => r.display_name,
  },
  initialSort: 'reading_time',
  initialDir: 'desc',
})

const totalFiltered = computed(() => sortedReadings.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalFiltered.value / perPage.value)))

// Clamp page when filtering reduces the total
const safePage = computed(() => Math.min(page.value, totalPages.value))

// Reka's Pagination doesn't re-clamp an out-of-range page when a filter or
// per-page change shrinks the total, so keep the bound page in range ourselves
// (otherwise the controls desync from the displayed page).
watch(totalPages, (tp) => {
  if (page.value > tp) {
    page.value = tp
  }
})

const pagedReadings = computed(() => {
  const start = (safePage.value - 1) * perPage.value
  return sortedReadings.value.slice(start, start + perPage.value)
})

// Clean readings modal
const cleanModalActive = ref(false)
const cleanDays = ref(30)
const cleanBusy = ref(false)
const cleanOptions = [7, 14, 30, 60, 90, 180, 365]
const perPageSelectOptions = perPageOptions.map((n) => ({ value: n, label: String(n) }))
const cleanDaySelectOptions = cleanOptions.map((d) => ({ value: d, label: `${d} days` }))

async function fetchReadings() {
  loading.value = true
  const data = await api.get<{ rows: ReadingSummary[]; total: number }>(
    endpoints.admin.readings.list + '?limit=100000&offset=0',
  )
  if (data) {
    allReadings.value = data.rows
  }
  loading.value = false
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function viewReading(reading: ReadingSummary) {
  window.open(`/reading/${reading.reading_id}`, '_blank')
}

async function deleteReading(reading: ReadingSummary) {
  const ok = await confirm({
    title: 'Delete Reading',
    message: `Permanently delete reading "${reading.reading_id}"? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }

  const result = await api.del(
    endpoints.admin.readings.byId(reading.reading_id),
    'Reading deleted.',
  )
  if (result) {
    allReadings.value = allReadings.value.filter((r) => r.reading_id !== reading.reading_id)
  }
}

async function cleanOldReadings() {
  cleanBusy.value = true
  const result = await api.del<{ success: boolean; deleted: number }>(
    endpoints.admin.readings.clean(cleanDays.value),
  )
  cleanBusy.value = false

  if (result) {
    toasts.success(`Cleaned ${result.deleted} guest reading(s) older than ${cleanDays.value} days.`)
    cleanModalActive.value = false
    await fetchReadings()
  }
}

onMounted(fetchReadings)
</script>

<template>
  <AdminListPage title="Manage Readings" subtitle="Browse and manage all saved readings.">
    <template #actions>
      <button type="button" class="button is-danger" @click="cleanModalActive = true">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['broom']" /></span>
        <span>Clean Readings</span>
      </button>
    </template>

    <AdminSearchField
      v-model="search"
      placeholder="Search by reading ID, deck, spread, or user..."
    />

    <p v-if="loading" class="has-text-grey">
      <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
      <span>Loading readings…</span>
    </p>

    <template v-else>
      <div class="level mb-4">
        <div class="level-left">
          <div class="level-item">
            <div class="field has-addons">
              <p class="control">
                <span class="button is-static">Per page</span>
              </p>
              <p class="control">
                <BaseSelect
                  v-model="perPage"
                  :options="perPageSelectOptions"
                  aria-label="Per page"
                />
              </p>
            </div>
          </div>
        </div>
      </div>

      <PaginationRoot
        v-if="totalPages > 1"
        v-model:page="page"
        :total="totalFiltered"
        :items-per-page="perPage"
        :sibling-count="1"
        show-edges
        :default-page="1"
        class="mb-4"
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

      <div class="settings-panel">
        <div class="table-container">
          <table class="table is-fullwidth is-hoverable is-striped">
            <thead>
              <tr>
                <SortableTh
                  label="Date"
                  sort-key="reading_time"
                  :active-key="sortKey"
                  :dir="sortDir"
                  @sort="toggleSort"
                />
                <SortableTh
                  label="Reading ID"
                  sort-key="reading_id"
                  :active-key="sortKey"
                  :dir="sortDir"
                  @sort="toggleSort"
                />
                <SortableTh
                  label="User"
                  sort-key="display_name"
                  :active-key="sortKey"
                  :dir="sortDir"
                  @sort="toggleSort"
                />
                <SortableTh
                  label="Spread"
                  sort-key="spread_name"
                  :active-key="sortKey"
                  :dir="sortDir"
                  @sort="toggleSort"
                />
                <SortableTh
                  label="Deck"
                  sort-key="deck_name"
                  :active-key="sortKey"
                  :dir="sortDir"
                  @sort="toggleSort"
                />
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="reading in pagedReadings" :key="reading.reading_id">
                <td>{{ formatDateTime(reading.reading_time) }}</td>
                <td>
                  <code>{{ reading.reading_id }}</code>
                </td>
                <td>{{ reading.display_name }}</td>
                <td>{{ reading.spread_name || '—' }}</td>
                <td>{{ reading.deck_name }}</td>
                <td>
                  <div class="row-actions">
                    <IconButton
                      :icon="byPrefixAndName.fas['eye']"
                      label="View"
                      @click="viewReading(reading)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['trash']"
                      label="Delete"
                      intent="danger"
                      @click="deleteReading(reading)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <PaginationRoot
        v-if="totalPages > 1"
        v-model:page="page"
        :total="totalFiltered"
        :items-per-page="perPage"
        :sibling-count="1"
        show-edges
        :default-page="1"
        class="mt-4"
        @update:page="scrollToTop"
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

      <p class="has-text-grey mt-3">
        Showing {{ pagedReadings.length }} of {{ totalFiltered.toLocaleString() }} readings (page
        {{ safePage }} of {{ totalPages }}).
      </p>
    </template>
  </AdminListPage>

  <BaseModal
    :active="cleanModalActive"
    title="Clean Guest Readings"
    max-width="32rem"
    @close="cleanModalActive = false"
  >
    <p class="mb-4">
      Delete all readings that <strong>do not belong to a registered user</strong> and are older
      than the selected period.
    </p>
    <div class="field">
      <label for="admin-readings-older-than" class="label">Older than</label>
      <div class="control">
        <BaseSelect
          id="admin-readings-older-than"
          v-model="cleanDays"
          :options="cleanDaySelectOptions"
        />
      </div>
    </div>
    <template #footer>
      <button type="button" class="button" @click="cleanModalActive = false">Cancel</button>
      <button
        type="button"
        class="button is-danger"
        :class="{ 'is-loading': cleanBusy }"
        :disabled="cleanBusy"
        @click="cleanOldReadings"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['broom']" /></span>
        <span>Clean</span>
      </button>
    </template>
  </BaseModal>
</template>
