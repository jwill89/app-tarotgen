<script setup lang="ts">
/**
 * The public spreads: search, sort, and each one's way into the editor or out
 * of the library. Adding and editing, the categories and the submission queue
 * are pages of their own, linked from the header.
 */
import { byPrefixAndName } from '@/fontawesome'
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminApi } from '@/composables/useApi'
import { useAdminSpreads } from '@/composables/useAdminSpreads'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import IconButton from '@/components/IconButton.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { Spread } from '@/types'

const api = useAdminApi()
const router = useRouter()
const { confirm } = useConfirm()
// The queue only for its count, on the Submissions link.
const { spreads, pending, loadSpreads, loadPending } = useAdminSpreads()

const {
  search,
  sortKey,
  sortDir,
  rows: visibleSpreads,
  toggleSort,
} = useDataTable(spreads, {
  searchText: (s) => `${s.name} ${s.spread_id} ${s.category_name}`,
  sortAccessors: {
    spread_id: (s) => s.spread_id,
    name: (s) => s.name,
    // By the categories' own order, as the library lists them.
    category: (s) => s.category_sort_order,
    card_count: (s) => s.card_count,
  },
  initialSort: 'spread_id',
})

function openEdit(spread: Spread): void {
  void router.push({ name: 'admin-spread-edit', params: { id: spread.spread_id } })
}

async function deleteSpread(spread: Spread): Promise<void> {
  const ok = await confirm({
    title: 'Delete spread',
    message: `Delete "${spread.name}"? Existing readings that used it keep their own saved copy and are unaffected.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }
  const result = await api.del(endpoints.admin.spreads.byId(spread.spread_id), 'Spread deleted.')
  if (result) {
    await loadSpreads()
  }
}

onMounted(() => {
  void loadSpreads()
  void loadPending()
})
</script>

<template>
  <AdminListPage title="Manage Spreads" subtitle="Create and arrange tarot spreads.">
    <template #actions>
      <router-link :to="{ name: 'admin-spread-categories' }" class="button">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['tags']" /></span>
        <span>Categories</span>
      </router-link>
      <router-link
        :to="{ name: 'admin-spread-submissions' }"
        class="button"
        :aria-label="pending.length ? `Submissions, ${pending.length} waiting` : undefined"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['inbox']" /></span>
        <span>Submissions</span>
        <span v-if="pending.length" class="tag is-warning is-rounded ml-2">{{
          pending.length
        }}</span>
      </router-link>
      <router-link :to="{ name: 'admin-spread-new' }" class="button is-primary">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
        <span>Add Spread</span>
      </router-link>
    </template>

    <AdminSearchField v-model="search" placeholder="Search spreads by name, category or ID..." />

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="ID"
                sort-key="spread_id"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Name"
                sort-key="name"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Category"
                sort-key="category"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Cards"
                sort-key="card_count"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="spread in visibleSpreads" :key="spread.spread_id">
              <td>{{ spread.spread_id }}</td>
              <td>{{ spread.name }}</td>
              <td>{{ spread.category_name }}</td>
              <td>{{ spread.card_count }}</td>
              <td>
                <div class="row-actions">
                  <IconButton
                    :icon="byPrefixAndName.fas['pen-to-square']"
                    label="Edit"
                    @click="openEdit(spread)"
                  />
                  <IconButton
                    :icon="byPrefixAndName.fas['trash']"
                    label="Delete"
                    intent="danger"
                    @click="deleteSpread(spread)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="spreads.length === 0" class="has-text-grey">
      No spreads yet. Click "Add Spread" to create one.
    </p>
  </AdminListPage>
</template>
