<script setup lang="ts">
/**
 * Spreads visitors submitted, waiting for review on a page of their own under
 * the spread list: preview one, approve it into a library category (the first
 * unless another is chosen in the preview), or reject it.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, useId } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useAdminSpreads } from '@/composables/useAdminSpreads'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import BaseModal from '@/components/BaseModal.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import IconButton from '@/components/IconButton.vue'
import SpreadLayoutPreview from '@/components/SpreadLayoutPreview.vue'
import type { PendingSpread, Spread, SpreadPosition } from '@/types'
import { formatDateTime } from '@/utils/datetime'

const BACK = { to: { name: 'admin-spreads' }, label: 'Back to Spreads' }

const api = useAdminApi()
const { confirm } = useConfirm()
const { categories, pending, loadCategories, loadPending } = useAdminSpreads()

const uid = useId()
/** The category the submission being previewed is published in. */
const approveCategoryId = ref<number | null>(null)
const previewing = ref<PendingSpread | null>(null)
const busyPendingId = ref<number | null>(null)

const categoryOptions = computed(() =>
  categories.value.map((c) => ({ value: c.category_id, label: c.name })),
)
const waiting = computed(() =>
  pending.value.length === 1 ? '1 submission' : `${pending.value.length} submissions`,
)

function sortedPositions(p: PendingSpread): SpreadPosition[] {
  return p.positions.slice().sort((a, b) => a.order - b.order)
}

/** Publish a submission: in the category chosen, or (none given) the first. */
async function approvePending(p: PendingSpread, categoryId: number | null = null) {
  busyPendingId.value = p.pending_id
  const result = await api.post<Spread>(
    endpoints.admin.pendingSpreads.approve(p.pending_id),
    categoryId === null ? {} : { category_id: categoryId },
    'Spread approved and published.',
  )
  busyPendingId.value = null
  if (result) {
    if (previewing.value?.pending_id === p.pending_id) {
      previewing.value = null
    }
    await loadPending()
  }
}

function openPreview(p: PendingSpread) {
  approveCategoryId.value = categories.value[0]?.category_id ?? null
  previewing.value = p
}

async function rejectPending(p: PendingSpread) {
  const ok = await confirm({
    title: 'Reject submission',
    message: `Reject and permanently delete the submission "${p.name}"?`,
    confirmLabel: 'Reject',
    danger: true,
  })
  if (!ok) {
    return
  }
  busyPendingId.value = p.pending_id
  const result = await api.del(
    endpoints.admin.pendingSpreads.byId(p.pending_id),
    'Submission rejected.',
  )
  busyPendingId.value = null
  if (!result) {
    return
  }
  if (previewing.value?.pending_id === p.pending_id) {
    previewing.value = null
  }
  await loadPending()
}

onMounted(() => {
  void loadPending()
  void loadCategories()
})
</script>

<template>
  <AdminListPage
    title="Spread Submissions"
    subtitle="Review the spreads visitors submit. Approving one publishes it in the spread library."
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
                <th>Submitted</th>
                <th>Name</th>
                <th>By</th>
                <th>Cards</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in pending" :key="p.pending_id">
                <td>{{ formatDateTime(p.submitted_at) }}</td>
                <td>{{ p.name }}</td>
                <td>{{ p.submitter || '—' }}</td>
                <td>{{ p.card_count }}</td>
                <td>
                  <div class="row-actions">
                    <IconButton
                      :icon="byPrefixAndName.fas['eye']"
                      label="Preview"
                      @click="openPreview(p)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['check']"
                      :label="categories[0] ? `Approve into ${categories[0].name}` : 'Approve'"
                      intent="success"
                      :loading="busyPendingId === p.pending_id"
                      @click="approvePending(p)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['xmark']"
                      label="Reject"
                      intent="danger"
                      @click="rejectPending(p)"
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
      <p v-if="previewing.submitter" class="has-text-grey mb-3">
        Submitted by {{ previewing.submitter }}
      </p>
      <SpreadLayoutPreview :positions="previewing.positions" class="pending-preview-shape" />
      <div class="mt-4">
        <p class="label mb-2">Card Positions</p>
        <ol class="pending-position-list">
          <li v-for="pos in sortedPositions(previewing)" :key="'title-' + pos.order">
            {{ pos.title || '(untitled)' }}
            <span v-if="pos.description" class="pending-position-about">{{ pos.description }}</span>
          </li>
        </ol>
      </div>

      <div v-if="previewing.description" class="content mt-4 submission-description">
        {{ previewing.description }}
      </div>

      <div class="field mt-4">
        <label :for="uid + '-approve-category'" class="label">Publish in</label>
        <BaseSelect
          :id="uid + '-approve-category'"
          v-model="approveCategoryId"
          :options="categoryOptions"
          placeholder="Choose a category"
        />
      </div>
    </template>

    <template v-if="previewing" #footer>
      <button
        type="button"
        class="button is-success"
        :class="{ 'is-loading': busyPendingId === previewing.pending_id }"
        @click="approvePending(previewing, approveCategoryId)"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
        <span>Approve</span>
      </button>
      <button type="button" class="button is-danger" @click="rejectPending(previewing)">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" /></span>
        <span>Reject</span>
      </button>
      <button type="button" class="button" @click="previewing = null">Close</button>
    </template>
  </BaseModal>
</template>

<style scoped>
.submission-description {
  white-space: pre-wrap;
}

.pending-position-list {
  list-style: decimal;
  margin-left: 1.5rem;
}

.pending-position-list li {
  margin-bottom: 0.25rem;
}

/* The layout as New Draw and the library show it, small enough to keep the
   positions and the category in view. */
.pending-preview-shape {
  max-width: 22rem;
  margin: 0 auto;
}

.pending-position-about {
  display: block;
  font-size: 0.85rem;
  color: var(--myst-text-muted);
}
</style>
