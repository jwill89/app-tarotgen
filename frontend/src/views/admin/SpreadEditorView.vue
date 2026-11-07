<script setup lang="ts">
/**
 * Add a spread (/admin/spreads/new) or edit one (/admin/spreads/:id/edit), on a
 * page of its own: the editor is in the URL, and Save, Cancel and the link back
 * all return to the list the way the admin came (useReturnTo).
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminApi } from '@/composables/useApi'
import { useAdminSpreads } from '@/composables/useAdminSpreads'
import { useReturnTo } from '@/composables/useReturnTo'
import { endpoints } from '@/api/endpoints'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import type { Spread, SpreadPosition } from '@/types'

const LIST = { name: 'admin-spreads' }
const BACK = { to: LIST, label: 'Back to Spreads', returning: true }

const route = useRoute()
const api = useAdminApi()
const returnTo = useReturnTo()
const { categories, loadCategories } = useAdminSpreads()
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')

/**
 * The id in the URL; none when adding a spread. Read once: no link leads from
 * one editor to another, so the page is never reused for a different spread.
 */
const id = typeof route.params.id === 'string' ? route.params.id : null
/** The spread being edited, once loaded; null when adding one. */
const spread = ref<Spread | null>(null)
const state = ref<'loading' | 'ready' | 'missing' | 'failed'>(id === null ? 'ready' : 'loading')
const loadError = ref('')

const title = computed(() => {
  if (id === null) {
    return 'Add Spread'
  }
  return state.value === 'missing' ? 'Spread Not Found' : 'Edit Spread'
})

async function loadSpread(spreadId: string): Promise<void> {
  // Only a whole number names a spread; anything else can't be one.
  if (!/^\d+$/.test(spreadId)) {
    state.value = 'missing'
    return
  }
  const result = await api.getResult<Spread>(endpoints.admin.spreads.byId(spreadId))
  if (result.ok) {
    spread.value = result.data
    state.value = 'ready'
  } else if (result.status === 404) {
    state.value = 'missing'
  } else if (result.status !== 401) {
    // A 401 has already gone to the login page.
    loadError.value = result.error
    state.value = 'failed'
  }
}

async function save(payload: {
  name: string
  description: string
  card_count: number
  positions: SpreadPosition[]
  category_id?: number
}): Promise<void> {
  const result = spread.value
    ? await api.put<Spread>(
        endpoints.admin.spreads.byId(spread.value.spread_id),
        payload,
        'Spread updated.',
      )
    : await api.post<Spread>(endpoints.admin.spreads.list, payload, 'Spread created.')

  if (result) {
    returnTo(LIST)
  } else {
    editorRef.value?.setError('Failed to save the spread. Please try again.')
  }
}

onMounted(() => {
  void loadCategories()
  if (id !== null) {
    void loadSpread(id)
  }
})
</script>

<template>
  <AdminListPage :title="title" :back="BACK">
    <p v-if="state === 'loading'" class="has-text-grey">
      <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
      <span>Loading the spread…</span>
    </p>

    <div v-else-if="state === 'missing'" class="settings-panel">
      <p>There is no spread #{{ id }}. It may have been deleted.</p>
    </div>

    <div v-else-if="state === 'failed'" class="notification is-danger is-light">
      The spread couldn't be loaded: {{ loadError }}
    </div>

    <div v-else class="settings-panel">
      <SpreadEditor
        ref="editorRef"
        :spread="spread"
        :categories="categories"
        @save="save"
        @cancel="returnTo(LIST)"
      />
    </div>
  </AdminListPage>
</template>
