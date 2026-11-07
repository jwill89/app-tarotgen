<script setup lang="ts">
/**
 * Deck systems visitors submitted, waiting for review on a page of their own
 * under the deck-system list: preview one's card list, open it in the editor to
 * fix it up, approve it into the catalog, or reject it.
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminApi } from '@/composables/useApi'
import { useAdminDeckSystems } from '@/composables/useAdminDeckSystems'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import BaseModal from '@/components/BaseModal.vue'
import IconButton from '@/components/IconButton.vue'
import type { DeckSystem, DeckSystemCard, DeckSystemWithCards } from '@/types'

const BACK = { to: { name: 'admin-deck-systems' }, label: 'Back to Deck Systems' }

const api = useAdminApi()
const router = useRouter()
const { confirm } = useConfirm()
const { pending, loadPending } = useAdminDeckSystems()

const previewing = ref<DeckSystem | null>(null)
/** The previewed system's cards, once they have arrived. */
const previewCards = ref<DeckSystemCard[] | null>(null)
const busyId = ref<number | null>(null)

const waiting = computed(() =>
  pending.value.length === 1 ? '1 submission' : `${pending.value.length} submissions`,
)

/** The preview's line under its title: short name, size, and who sent it when known. */
function summary(system: DeckSystem): string {
  const parts = [system.short_name, `${system.total_cards} cards`]
  if (system.submitted_by !== null) {
    parts.push(`submitted by user #${system.submitted_by}`)
  }
  return parts.join(' · ')
}

async function openPreview(system: DeckSystem): Promise<void> {
  previewing.value = system
  previewCards.value = null
  const full = await api.get<DeckSystemWithCards>(
    endpoints.admin.deckSystems.byId(system.deck_system_id),
  )
  // Closed, or another opened, while the cards were on their way.
  if (!isPreviewing(system)) {
    return
  }
  if (full) {
    previewCards.value = full.cards
  } else {
    previewing.value = null
  } // useAdminApi has toasted why
}

function isPreviewing(system: DeckSystem): boolean {
  return previewing.value?.deck_system_id === system.deck_system_id
}

function closePreview(system: DeckSystem): void {
  if (isPreviewing(system)) {
    previewing.value = null
  }
}

function edit(system: DeckSystem): void {
  void router.push({ name: 'admin-deck-system-edit', params: { id: system.deck_system_id } })
}

async function approve(system: DeckSystem): Promise<void> {
  busyId.value = system.deck_system_id
  const result = await api.post<DeckSystem>(
    endpoints.admin.deckSystems.approve(system.deck_system_id),
    {},
    'Deck system approved.',
  )
  busyId.value = null
  if (!result) {
    return
  }
  closePreview(system)
  await loadPending()
}

async function reject(system: DeckSystem): Promise<void> {
  const ok = await confirm({
    title: 'Reject submission',
    message: `Reject and permanently delete the submission "${system.name}"? Its card list is deleted with it.`,
    confirmLabel: 'Reject',
    danger: true,
  })
  if (!ok) {
    return
  }
  busyId.value = system.deck_system_id
  const result = await api.del(
    endpoints.admin.deckSystems.byId(system.deck_system_id),
    'Submission rejected.',
  )
  busyId.value = null
  if (!result) {
    return
  }
  closePreview(system)
  await loadPending()
}

onMounted(() => {
  void loadPending()
})
</script>

<template>
  <AdminListPage
    title="Deck System Submissions"
    subtitle="Review the deck systems visitors submit. Approving one adds it to the catalog."
    :back="BACK"
  >
    <p v-if="pending.length === 0" class="has-text-grey">No pending submissions right now.</p>
    <template v-else>
      <p class="has-text-grey mb-3">{{ waiting }} waiting for review.</p>
      <div class="settings-panel">
        <div class="table-container">
          <table class="table is-fullwidth is-hoverable is-striped">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Short Name</th>
                <th>Cards</th>
                <th>Submitted By</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sys in pending" :key="sys.deck_system_id">
                <td>{{ sys.deck_system_id }}</td>
                <td>{{ sys.name }}</td>
                <td>{{ sys.short_name }}</td>
                <td>{{ sys.total_cards }}</td>
                <td>{{ sys.submitted_by ?? '—' }}</td>
                <td>
                  <div class="row-actions">
                    <IconButton
                      :icon="byPrefixAndName.fas['eye']"
                      label="Preview"
                      @click="openPreview(sys)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['check']"
                      label="Approve"
                      intent="success"
                      :loading="busyId === sys.deck_system_id"
                      @click="approve(sys)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['pen-to-square']"
                      label="Edit"
                      @click="edit(sys)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['xmark']"
                      label="Reject"
                      intent="danger"
                      @click="reject(sys)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </AdminListPage>

  <BaseModal
    :active="previewing !== null"
    :title="previewing?.name ?? ''"
    max-width="640px"
    @close="previewing = null"
  >
    <template v-if="previewing">
      <p class="has-text-grey mb-3">{{ summary(previewing) }}</p>
      <p v-if="previewCards === null" class="has-text-grey">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
        <span>Loading the cards…</span>
      </p>
      <p v-else-if="previewCards.length === 0" class="has-text-grey">No cards were submitted.</p>
      <ol v-else class="preview-card-list">
        <li v-for="card in previewCards" :key="card.card_id">
          {{ card.name || '(unnamed)' }}
          <span v-if="card.keywords" class="preview-card-keywords">{{ card.keywords }}</span>
        </li>
      </ol>
    </template>

    <template v-if="previewing" #footer>
      <button
        type="button"
        class="button is-success"
        :class="{ 'is-loading': busyId === previewing.deck_system_id }"
        @click="approve(previewing)"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
        <span>Approve</span>
      </button>
      <button type="button" class="button" @click="edit(previewing)">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['pen-to-square']" /></span>
        <span>Edit</span>
      </button>
      <button type="button" class="button is-danger" @click="reject(previewing)">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" /></span>
        <span>Reject</span>
      </button>
      <button type="button" class="button" @click="previewing = null">Close</button>
    </template>
  </BaseModal>
</template>

<style scoped>
/* A 78-card list reads in two columns where there is room for them. */
.preview-card-list {
  list-style: decimal;
  margin-left: 1.5rem;
  column-gap: 2.5rem;
}

@media screen and (width >= 769px) {
  .preview-card-list {
    columns: 2;
  }
}

.preview-card-list li {
  break-inside: avoid;
  margin-bottom: 0.25rem;
}

.preview-card-keywords {
  display: block;
  font-size: 0.85rem;
  color: var(--myst-text-muted);
}
</style>
