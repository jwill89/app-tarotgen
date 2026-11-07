<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, useTemplateRef } from 'vue'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { formatDateTime } from '@/utils/datetime'
import type { UserSpread, SpreadDraft, SpreadPosition } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import PageHeader from '@/components/PageHeader.vue'
import Tooltip from '@/components/Tooltip.vue'

const { userSpreads, fetchUserSpreads, updateUserSpread, deleteUserSpread, submitAsPublic } =
  useUserSpreads()
const toasts = useToasts()
const { confirm } = useConfirm()

const loading = ref(true)
const showEditor = ref(false)
const editingSpread = ref<UserSpread | null>(null)
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')

onMounted(async () => {
  await fetchUserSpreads()
  loading.value = false
})

function openEdit(spread: UserSpread) {
  editingSpread.value = spread
  showEditor.value = true
}

/** The fields of the UserSpread being edited that the SpreadEditor loads. */
const editorSpread = computed<SpreadDraft | null>(() => {
  if (!editingSpread.value) {
    return null
  }
  return {
    name: editingSpread.value.name,
    description: editingSpread.value.description,
    positions: editingSpread.value.positions,
  }
})

function closeEditor() {
  showEditor.value = false
  editingSpread.value = null
}

async function saveEdit(payload: {
  name: string
  description: string
  card_count: number
  positions: SpreadPosition[]
}) {
  if (!editingSpread.value) {
    return
  }
  const res = await updateUserSpread(editingSpread.value.user_spread_id, payload)
  if (res.ok) {
    toasts.success('Spread updated successfully.')
    closeEditor()
  } else {
    editorRef.value?.setError(res.error)
  }
}

async function handleDelete(spread: UserSpread) {
  const ok = await confirm({
    title: 'Delete Spread',
    message: `Are you sure you want to delete "${spread.name}"? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }
  const success = await deleteUserSpread(spread.user_spread_id)
  if (success) {
    toasts.success('Spread deleted.')
  } else {
    toasts.error('Failed to delete the spread.')
  }
}

async function handleSubmitPublic(spread: UserSpread) {
  const ok = await confirm({
    title: 'Submit as Public Spread',
    message: `Submit "${spread.name}" for admin review? If approved, it will become available to all users. Your personal copy will remain unchanged.`,
    confirmLabel: 'Submit for Review',
  })
  if (!ok) {
    return
  }
  const success = await submitAsPublic(spread.user_spread_id)
  if (success) {
    toasts.success('Spread submitted for review!')
  } else {
    toasts.error('Failed to submit the spread. Please try again.')
  }
}
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

          <div class="level">
            <div class="level-left">
              <div>
                <PageHeader title="My Spreads" subtitle="Manage your personal tarot spreads." />
              </div>
            </div>
            <div class="level-right">
              <router-link :to="{ name: 'submit-spread' }" class="button is-primary">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                <span>Create Spread</span>
              </router-link>
            </div>
          </div>

          <div v-if="loading" class="has-text-centered py-6">
            <span class="icon is-large"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin size="2x"
            /></span>
          </div>

          <div v-else-if="userSpreads.length === 0" class="myst-callout">
            <p>You haven't created any personal spreads yet.</p>
            <p class="mt-2">
              <router-link :to="{ name: 'submit-spread' }">Create your first spread</router-link>
              and choose "Save to my personal spreads" to keep it private, or copy one from the
              <router-link :to="{ name: 'spread-library' }">spread library</router-link> to make it
              your own.
            </p>
          </div>

          <div v-else class="settings-panel">
            <div class="table-container">
              <table class="table is-fullwidth is-hoverable">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Cards</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="spread in userSpreads" :key="spread.user_spread_id">
                    <td>
                      <strong>{{ spread.name }}</strong>
                      <p
                        v-if="spread.description"
                        class="is-size-7 has-text-grey mt-1 spread-description"
                      >
                        {{ spread.description }}
                      </p>
                    </td>
                    <td>{{ spread.card_count }}</td>
                    <td class="is-size-7">{{ formatDateTime(spread.created_at) }}</td>
                    <td class="is-size-7">{{ formatDateTime(spread.updated_at) }}</td>
                    <td class="has-text-right">
                      <div class="buttons are-small is-justify-content-flex-end">
                        <Tooltip text="Edit">
                          <button
                            type="button"
                            class="button is-info is-outlined"
                            aria-label="Edit"
                            @click="openEdit(spread)"
                          >
                            <span class="icon"
                              ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                            /></span>
                          </button>
                        </Tooltip>
                        <Tooltip text="Submit as public spread">
                          <button
                            type="button"
                            class="button is-link is-outlined"
                            aria-label="Submit as public spread"
                            @click="handleSubmitPublic(spread)"
                          >
                            <span class="icon"
                              ><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']"
                            /></span>
                          </button>
                        </Tooltip>
                        <Tooltip text="Delete">
                          <button
                            type="button"
                            class="button is-danger is-outlined"
                            aria-label="Delete"
                            @click="handleDelete(spread)"
                          >
                            <span class="icon"
                              ><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']"
                            /></span>
                          </button>
                        </Tooltip>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Edit spread modal -->
  <BaseModal :active="showEditor" title="Edit Spread" max-width="60rem" @close="closeEditor">
    <SpreadEditor
      v-if="editorSpread"
      ref="editorRef"
      :spread="editorSpread"
      save-label="Save Changes"
      @save="saveEdit"
      @cancel="closeEditor"
    />
  </BaseModal>
</template>

<style scoped>
.spread-description {
  max-width: 20rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
