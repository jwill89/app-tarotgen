<script setup lang="ts">
/**
 * Add a deck system (/admin/deck-systems/new) or edit one (/admin/deck-systems/:id/edit),
 * on a page of its own: the editor is in the URL, and Save, Cancel and the link
 * back all return to the page the system is listed on the way the admin came
 * (useReturnTo): the list, or the submission queue for one not yet approved.
 * Leaving with unsaved changes asks first: a 78-card list is a lot to lose.
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAdminApi } from '@/composables/useApi'
import { useReturnTo } from '@/composables/useReturnTo'
import { useSubmissions } from '@/composables/useSubmissions'
import { useToasts } from '@/composables/useToasts'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { endpoints } from '@/api/endpoints'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import DeckCardListEditor from '@/components/DeckCardListEditor.vue'
import NumberField from '@/components/NumberField.vue'
import { allCardsNamed, resizeCards } from '@/components/deckCards'
import type { DeckSystemCard, DeckSystemWithCards } from '@/types'

const LIST = { name: 'admin-deck-systems' }
const QUEUE = { name: 'admin-deck-system-submissions' }

/** What the editor changes; the rest of a system is the server's. */
interface DeckSystemForm {
  name: string
  short_name: string
  /** Undefined while the field is cleared. */
  total_cards: number | undefined
  cards: DeckSystemCard[]
}

const route = useRoute()
const api = useAdminApi()
const returnTo = useReturnTo()
const { submitDeckSystem } = useSubmissions()
const toasts = useToasts()

/**
 * The id in the URL; none when adding a system. Read once: no link leads from
 * one editor to another, so the page is never reused for a different system.
 */
const id = typeof route.params.id === 'string' ? route.params.id : null
/** The system being edited, as loaded; null when adding one. */
const system = ref<DeckSystemWithCards | null>(null)
const form = ref<DeckSystemForm | null>(id === null ? blankForm() : null)
const state = ref<'loading' | 'ready' | 'missing' | 'failed'>(id === null ? 'ready' : 'loading')
const loadError = ref('')
const saving = ref(false)

/** The form as last loaded or saved: anything else is unsaved. */
let savedForm = JSON.stringify(form.value)

useUnsavedChanges(
  () => form.value !== null && JSON.stringify(form.value) !== savedForm,
  "Leave this page? Your changes to this deck system haven't been saved and will be lost.",
)

/** A submission is listed in the queue until it is approved; every other system in the list. */
const pendingSubmission = computed(() => system.value?.approved === false)
const home = computed(() => (pendingSubmission.value ? QUEUE : LIST))
const back = computed(() => ({
  to: home.value,
  label: pendingSubmission.value ? 'Back to Submissions' : 'Back to Deck Systems',
  returning: true,
}))

const title = computed(() => {
  if (id === null) {
    return 'Add Deck System'
  }
  return state.value === 'missing' ? 'Deck System Not Found' : 'Edit Deck System'
})
const subtitle = computed(() =>
  pendingSubmission.value ? 'A submission waiting for review: not yet approved.' : undefined,
)

const allCardTitlesValid = computed(() => allCardsNamed(form.value?.cards ?? []))

function blankForm(): DeckSystemForm {
  return { name: '', short_name: '', total_cards: 78, cards: resizeCards([], 78) }
}

async function loadSystem(systemId: string): Promise<void> {
  // Only a whole number names a system; anything else can't be one.
  if (!/^\d+$/.test(systemId)) {
    state.value = 'missing'
    return
  }
  const result = await api.getResult<DeckSystemWithCards>(
    endpoints.admin.deckSystems.byId(systemId),
  )
  if (result.ok) {
    const loaded = result.data
    system.value = loaded
    // One row per card the total names, so the form starts as it would show.
    form.value = {
      name: loaded.name,
      short_name: loaded.short_name,
      total_cards: loaded.total_cards,
      cards: resizeCards(loaded.cards, loaded.total_cards),
    }
    savedForm = JSON.stringify(form.value)
    state.value = 'ready'
  } else if (result.status === 404) {
    state.value = 'missing'
  } else if (result.status !== 401) {
    // A 401 has already gone to the login page.
    loadError.value = result.error
    state.value = 'failed'
  }
}

// Watch rather than a native @change listener: Reka's NumberField commits the
// model on blur/Enter AND on the −/+ steppers, but the steppers set it
// programmatically, which fires no native `change` — so an @change handler
// would silently miss every stepper click. Typing is safe here because the model is
// only committed on blur, never per keystroke (NumberFieldInput's onInput
// updates the displayed text only).
watch(
  () => form.value?.total_cards,
  (total) => {
    if (form.value) {
      form.value.cards = resizeCards(form.value.cards, total ?? 78)
    }
  },
)

/** Save the form (Save waits until every card is named), then return. */
async function save(edited: DeckSystemForm): Promise<void> {
  const payload = {
    name: edited.name,
    short_name: edited.short_name,
    total_cards: edited.total_cards ?? edited.cards.length,
    cards: edited.cards,
  }
  saving.value = true
  try {
    if (system.value === null) {
      // There is no admin create route: an admin's submission is approved on arrival.
      const res = await submitDeckSystem(payload)
      if (!res.ok) {
        toasts.error(res.error)
        return
      }
      toasts.success('Deck system created.')
    } else {
      const updated = await api.put(
        endpoints.admin.deckSystems.byId(system.value.deck_system_id),
        payload,
        'Deck system updated.',
      )
      if (updated === null) {
        return
      }
    }
    // Saved: nothing left to lose, so leaving doesn't ask.
    savedForm = JSON.stringify(edited)
    returnTo(home.value)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (id !== null) {
    void loadSystem(id)
  }
})
</script>

<template>
  <AdminListPage :title="title" :subtitle="subtitle" :back="back">
    <p v-if="state === 'loading'" class="has-text-grey">
      <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
      <span>Loading the deck system…</span>
    </p>

    <div v-else-if="state === 'missing'" class="settings-panel">
      <p>There is no deck system #{{ id }}. It may have been deleted.</p>
    </div>

    <div v-else-if="state === 'failed'" class="notification is-danger is-light">
      The deck system couldn't be loaded: {{ loadError }}
    </div>

    <div v-else-if="form" class="settings-panel">
      <div class="columns">
        <div class="column is-4">
          <div class="field">
            <label for="admin-system-name" class="label"
              >Name <span class="has-text-danger">*</span></label
            >
            <input
              id="admin-system-name"
              v-model="form.name"
              class="input"
              placeholder="e.g. Rider-Waite-Smith"
            />
          </div>
        </div>
        <div class="column is-4">
          <div class="field">
            <label for="admin-system-short-name" class="label"
              >Short Name <span class="has-text-danger">*</span></label
            >
            <input
              id="admin-system-short-name"
              v-model="form.short_name"
              class="input"
              placeholder="e.g. RWS"
            />
          </div>
        </div>
        <div class="column is-4">
          <div class="field">
            <label for="admin-system-total-cards" class="label"
              >Total Cards <span class="has-text-danger">*</span></label
            >
            <NumberField id="admin-system-total-cards" v-model="form.total_cards" :min="1" />
          </div>
        </div>
      </div>

      <DeckCardListEditor
        v-model="form.cards"
        heading-tag="h5"
        max-height="600px"
        :initial-open="system === null ? ['0'] : []"
      >
        <template #hint>
          <p class="mb-4 has-text-grey is-size-7">
            Each card needs a name at minimum. Other fields are optional.
          </p>
        </template>
      </DeckCardListEditor>

      <div class="editor-footer mt-5">
        <p v-if="!allCardTitlesValid" class="help is-danger">
          All cards must have a title before saving.
        </p>
        <div class="buttons">
          <button type="button" class="button" @click="returnTo(home)">Cancel</button>
          <button
            type="button"
            class="button is-success"
            :class="{ 'is-loading': saving }"
            :disabled="saving || !allCardTitlesValid"
            @click="save(form)"
          >
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
            <span>{{ system === null ? 'Create' : 'Save' }}</span>
          </button>
        </div>
      </div>
    </div>
  </AdminListPage>
</template>

<style scoped>
/* The hint and the buttons share a row while they fit, and stack on a phone. */
.editor-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.editor-footer .buttons {
  margin-left: auto;
}
</style>
