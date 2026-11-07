<script setup lang="ts">
/**
 * The approved deck systems: search, sort, and each one's way into the editor
 * or out of the catalog. Adding and editing, and the submission queue, are
 * pages of their own, linked from the header.
 */
import { byPrefixAndName } from '@/fontawesome'
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminApi } from '@/composables/useApi'
import { useAdminDeckSystems } from '@/composables/useAdminDeckSystems'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import IconButton from '@/components/IconButton.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import Tooltip from '@/components/Tooltip.vue'
import type { DeckSystem } from '@/types'

const api = useAdminApi()
const router = useRouter()
const { confirm } = useConfirm()
// The queue only for its count, on the Submissions link.
const { systems, pending, loadSystems, loadPending } = useAdminDeckSystems()

const {
  search,
  sortKey,
  sortDir,
  rows: visibleSystems,
  toggleSort,
} = useDataTable(systems, {
  searchText: (s) => `${s.name} ${s.short_name} ${s.deck_system_id}`,
  sortAccessors: {
    deck_system_id: (s) => s.deck_system_id,
    name: (s) => s.name,
    short_name: (s) => s.short_name,
    total_cards: (s) => s.total_cards,
  },
  initialSort: 'name',
})

function openEdit(system: DeckSystem): void {
  void router.push({ name: 'admin-deck-system-edit', params: { id: system.deck_system_id } })
}

async function deleteSystem(system: DeckSystem): Promise<void> {
  const ok = await confirm({
    title: 'Delete Deck System',
    message: `Delete "${system.name}"? This will also delete all card data for this system. Decks using this system will need to be reassigned.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }
  const result = await api.del(
    endpoints.admin.deckSystems.byId(system.deck_system_id),
    'Deck system deleted.',
  )
  if (result) {
    await loadSystems()
  }
}

onMounted(() => {
  void loadSystems()
  void loadPending()
})
</script>

<template>
  <AdminListPage
    title="Manage Deck Systems"
    subtitle="Manage card naming systems (e.g. Rider-Waite-Smith, Thoth)."
  >
    <template #actions>
      <router-link
        :to="{ name: 'admin-deck-system-submissions' }"
        class="button"
        :aria-label="pending.length ? `Submissions, ${pending.length} waiting` : undefined"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['inbox']" /></span>
        <span>Submissions</span>
        <span v-if="pending.length" class="tag is-warning is-rounded ml-2">{{
          pending.length
        }}</span>
      </router-link>
      <router-link :to="{ name: 'admin-deck-system-new' }" class="button is-primary">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
        <span>Add Deck System</span>
      </router-link>
    </template>

    <AdminSearchField v-model="search" placeholder="Search by name or short name..." />

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="ID"
                sort-key="deck_system_id"
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
                label="Short Name"
                sort-key="short_name"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Total Cards"
                sort-key="total_cards"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sys in visibleSystems" :key="sys.deck_system_id">
              <td>{{ sys.deck_system_id }}</td>
              <td>{{ sys.name }}</td>
              <td>
                <Tooltip :text="sys.name">
                  <span class="data-chip">{{ sys.short_name }}</span>
                </Tooltip>
              </td>
              <td>{{ sys.total_cards }}</td>
              <td>
                <div class="row-actions">
                  <IconButton
                    :icon="byPrefixAndName.fas['pen-to-square']"
                    label="Edit"
                    @click="openEdit(sys)"
                  />
                  <IconButton
                    :icon="byPrefixAndName.fas['trash']"
                    label="Delete"
                    intent="danger"
                    @click="deleteSystem(sys)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="visibleSystems.length === 0" class="has-text-grey">
      No deck systems match your search.
    </p>
  </AdminListPage>
</template>
