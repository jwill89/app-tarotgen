<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { reactive, computed, watch, ref, onMounted, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import SpreadComboBox from '@/components/SpreadComboBox.vue'
import SpreadLayoutPreview from '@/components/SpreadLayoutPreview.vue'
import DeckComboBox from '@/components/DeckComboBox.vue'
import { useDecks } from '@/composables/useDecks'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useToasts } from '@/composables/useToasts'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useReadings } from '@/composables/useReadings'
import { useUser } from '@/composables/useUser'
import { renderMarkdown } from '@/utils/markdown'
import { defaultDeckId } from '@/utils/deck'
import { cardAspectStyle } from '@/utils/cardAspect'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import NumberField from '@/components/NumberField.vue'
import PageHeader from '@/components/PageHeader.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import TurnstileWidget from '@/components/TurnstileWidget.vue'
import type { Deck, SpreadOption } from '@/types'

const LAST_DECK_KEY = STORAGE_KEYS.lastDeck

const router = useRouter()
const route = useRoute()
const { decks, fetchDecks } = useDecks()
const { fetchFavoriteDecks } = useFavoriteDecks()
const { spreadOptions, fetchSpreads } = useSpreads()
const toasts = useToasts()
const { record: recordReading } = useRecentReadings()
const { generate: generateReading } = useReadings()
const { isLoggedIn } = useUser()

// A signed-out visitor passes a Turnstile challenge to draw, where the site has
// Turnstile on; a signed-in reader never sees one.
const turnstileToken = ref('')
const turnstileEnabled = ref(false)
const turnstile = useTemplateRef('turnstile')
const challengePending = computed(
  () => !isLoggedIn.value && turnstileEnabled.value && !turnstileToken.value,
)

const isLoading = ref(false)
const ownerOptions = useTemplateRef<InstanceType<typeof ReadingOwnerOptions>>('ownerOptions')
const selectedSpreadOption = ref<SpreadOption | null>(null)
const freeDrawChosen = ref(true)

const freeDrawModeOptions = [
  { value: 'standard', label: 'Standard', icon: byPrefixAndName.fas.shuffle },
  { value: 'placement', label: 'With Placement', icon: byPrefixAndName.fas['hand-pointer'] },
]

interface DrawForm {
  deckId: number | null
  spreadId: number | null
  userSpreadId: number | null
  numberOfCards: number
  useReversals: boolean
  useAdditionalCards: boolean
  freeDrawMode: 'standard' | 'placement'
}

const form = reactive<DrawForm>({
  deckId: null,
  spreadId: null,
  userSpreadId: null,
  numberOfCards: 1,
  useReversals: false,
  useAdditionalCards: false,
  freeDrawMode: 'standard',
})

onMounted(() => {
  void fetchDecks()
  void fetchSpreads()
  void fetchFavoriteDecks()
})

// `?spread=<id>` (the spread library's "Draw with this spread") picks that public
// spread once the list arrives. Only once: after that the picker is the user's.
const requestedSpread = typeof route.query.spread === 'string' ? Number(route.query.spread) : NaN
let spreadRequestOpen = Number.isInteger(requestedSpread) && requestedSpread > 0
watch(
  spreadOptions,
  (options) => {
    if (!spreadRequestOpen) {
      return
    }
    const match = options.find((o) => o.type === 'public' && o.spread_id === requestedSpread)
    if (match) {
      spreadRequestOpen = false
      selectedSpreadOption.value = match
    }
  },
  { immediate: true },
)

// Set the deck once loaded: prefer the user's last pick (if still available),
// otherwise default to Rider-Waite.
watch(
  decks,
  (val) => {
    if (val.length > 0 && form.deckId === null) {
      form.deckId = defaultDeckId(val, local.get<number | null>(LAST_DECK_KEY, null))
    }
  },
  { immediate: true },
)

// Remember the chosen deck across visits/sessions.
watch(
  () => form.deckId,
  (val) => {
    if (val !== null) {
      local.set(LAST_DECK_KEY, val)
    }
  },
)

// Reset additional cards when deck changes
watch(
  () => form.deckId,
  () => {
    form.useAdditionalCards = false
  },
)

const selectedDeck = computed(() => {
  if (form.deckId === null) {
    return null
  }
  return decks.value.find((d) => d.deck_id === form.deckId) ?? null
})

// Free-draw max: the deck's system total, plus its extras only when that toggle is on.
const maxCards = computed(() => {
  const d: Deck | null = selectedDeck.value
  if (!d) {
    return 78
  }
  const baseCards = d.system_total_cards || 78
  return baseCards + (form.useAdditionalCards ? d.additional_cards : 0)
})

// Keep the requested count within the deck's available range (free draw only).
watch(maxCards, (max) => {
  if (!selectedSpreadOption.value && form.numberOfCards > max) {
    form.numberOfCards = max
  }
})

const selectedSpread = computed(() => {
  if (!selectedSpreadOption.value) {
    return null
  }
  return {
    spread_id: selectedSpreadOption.value.spread_id ?? 0,
    user_spread_id: selectedSpreadOption.value.user_spread_id,
    name: selectedSpreadOption.value.name,
    description: selectedSpreadOption.value.description,
    card_count: selectedSpreadOption.value.card_count,
    positions: selectedSpreadOption.value.positions,
    type: selectedSpreadOption.value.type,
  }
})

// Sync combo selection back to form IDs.
watch(selectedSpreadOption, (val) => {
  if (val) {
    form.spreadId = val.spread_id ?? null
    form.userSpreadId = val.user_spread_id ?? null
    freeDrawChosen.value = false
  } else {
    form.spreadId = null
    form.userSpreadId = null
  }
})

function onFreeDrawSelected() {
  freeDrawChosen.value = true
}

// A spread is chosen (either explicit or free draw default), so the rest of the form can render.
const spreadReady = computed(() => selectedSpreadOption.value !== null || freeDrawChosen.value)

const spreadDescriptionHtml = computed(() =>
  selectedSpread.value ? renderMarkdown(selectedSpread.value.description) : '',
)

const selectedSpreadPositions = computed(() => {
  if (!selectedSpread.value) {
    return []
  }
  return [...selectedSpread.value.positions].sort((a, b) => a.order - b.order)
})

// Custom titles the user can enter per position (aligned with sorted positions).
const positionTitles = ref<string[]>([])

// A spread dictates its own card count and resets the custom titles.
watch(selectedSpread, (val) => {
  if (val) {
    form.numberOfCards = val.card_count
    positionTitles.value = selectedSpreadPositions.value.map(() => '')
  } else {
    positionTitles.value = []
  }
})

function resetForm() {
  form.deckId = defaultDeckId(decks.value)
  form.spreadId = null
  form.userSpreadId = null
  form.useAdditionalCards = false
  form.numberOfCards = 1
  form.useReversals = false
  form.freeDrawMode = 'standard'
  selectedSpreadOption.value = null
  freeDrawChosen.value = true
  positionTitles.value = []
  // Or a password from before the reset would still lock the next reading.
  ownerOptions.value?.reset()
}

async function submitNewReading() {
  const body = new URLSearchParams({
    deck_id: String(form.deckId),
    number_of_cards: String(form.numberOfCards),
    use_reversals: String(form.useReversals),
    use_additional_cards: String(form.useAdditionalCards),
  })

  if (form.spreadId !== null) {
    body.set('spread_id', String(form.spreadId))
    body.set('position_titles', JSON.stringify(positionTitles.value))
  } else if (form.userSpreadId !== null) {
    body.set('user_spread_id', String(form.userSpreadId))
    body.set('position_titles', JSON.stringify(positionTitles.value))
  }

  // A form post: a list (the tags) goes as one comma-separated value.
  for (const [key, value] of Object.entries(ownerOptions.value?.collect() ?? {})) {
    body.set(key, Array.isArray(value) ? value.join(',') : String(value))
  }

  if (!isLoggedIn.value && turnstileToken.value) {
    body.set('turnstile_token', turnstileToken.value)
  }

  isLoading.value = true
  const res = await generateReading(body)
  isLoading.value = false

  if (!res.ok) {
    toasts.error(res.error, { detail: res })
    // The challenge token is single-use; reset so the visitor can try again.
    turnstile.value?.reset()
    return
  }

  recordReading({
    id: res.data.reading_id,
    deckName: selectedDeck.value?.name ?? 'Tarot',
    summary: selectedSpread.value
      ? selectedSpread.value.name
      : String(form.numberOfCards) + (form.numberOfCards === 1 ? ' card' : ' cards'),
    at: res.data.reading_time,
  })

  // Free Draw With Placement: redirect to the placement editor instead.
  if (!selectedSpreadOption.value && form.freeDrawMode === 'placement') {
    toasts.success('Cards drawn! Now arrange your spread.')
    void router.push({ name: 'free-draw-placement', params: { id: res.data.reading_id } })
  } else {
    toasts.success('Your reading is ready!')
    void router.push({ name: 'reading', params: { id: res.data.reading_id } })
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader title="New Draw" subtitle="Choose the settings for your draw." />

          <div class="settings-panel">
            <div class="field">
              <label class="label" for="deck_id">Deck</label>
              <DeckComboBox id="deck_id" v-model="form.deckId" :decks="decks" />
            </div>

            <div
              v-if="selectedDeck && selectedDeck.additional_cards > 0"
              class="notification is-warning is-light"
            >
              <p class="mb-2">
                <strong
                  >This deck has {{ selectedDeck.additional_cards }} extra card{{
                    selectedDeck.additional_cards === 1 ? '' : 's'
                  }}.</strong
                >
                Turn this on to allow them to be drawn in your reading.
              </p>
              <div class="control">
                <ToggleSwitch v-model="form.useAdditionalCards">{{
                  form.useAdditionalCards ? 'On' : 'Off'
                }}</ToggleSwitch>
              </div>
            </div>

            <div class="field">
              <label for="new-reading-spread" class="label">Spread</label>
              <SpreadComboBox
                id="new-reading-spread"
                v-model="selectedSpreadOption"
                :options="spreadOptions"
                @free-draw="onFreeDrawSelected"
              />
            </div>

            <div v-if="selectedSpread" class="columns">
              <div v-if="selectedSpread.description" class="column">
                <div class="notification spread-description">
                  <!-- Sanitized by renderMarkdown() (marked + DOMPurify). -->
                  <!-- eslint-disable-next-line vue/no-v-html -->
                  <div class="content" v-html="spreadDescriptionHtml"></div>
                </div>
              </div>
              <div class="column is-half">
                <SpreadLayoutPreview
                  :positions="selectedSpreadPositions"
                  class="mx-auto"
                  :style="cardAspectStyle(selectedDeck)"
                />
              </div>
            </div>

            <div v-if="selectedSpread" class="field">
              <span id="new-reading-position-titles" class="label">
                Position Titles
                <span class="has-text-grey is-size-7 has-text-weight-normal"
                  >(optional — leave blank to use the spread's defaults)</span
                >
              </span>
              <div
                class="columns is-multiline"
                role="group"
                aria-labelledby="new-reading-position-titles"
              >
                <div
                  v-for="(pos, idx) in selectedSpreadPositions"
                  :key="'title-' + pos.order"
                  class="column is-6"
                >
                  <div class="field has-addons mb-0">
                    <p class="control">
                      <span class="button is-static">#{{ pos.order }}</span>
                    </p>
                    <p class="control is-expanded">
                      <input
                        v-model="positionTitles[idx]"
                        class="input"
                        :aria-label="'Position ' + pos.order + ' title'"
                        :placeholder="pos.title || 'Position ' + pos.order"
                        autocomplete="off"
                      />
                    </p>
                  </div>
                  <p v-if="pos.description" class="help">{{ pos.description }}</p>
                </div>
              </div>
            </div>

            <div v-if="freeDrawChosen && !selectedSpread" class="field">
              <span id="new-reading-free-draw-mode" class="label">Free Draw Mode</span>
              <SegmentedControl
                v-model="form.freeDrawMode"
                :options="freeDrawModeOptions"
                aria-labelledby="new-reading-free-draw-mode"
              />
              <p v-if="form.freeDrawMode === 'standard'" class="help">
                Cards are drawn and displayed in order.
              </p>
              <p v-else class="help">
                After drawing, you'll arrange the cards on a spread canvas and name each position.
              </p>
            </div>

            <div v-if="spreadReady" class="columns is-multiline">
              <div class="column is-4-desktop is-6-tablet">
                <div class="field">
                  <label for="new-reading-number-of-cards" class="label">Number of Cards</label>
                  <NumberField
                    id="new-reading-number-of-cards"
                    v-model="form.numberOfCards"
                    :min="1"
                    :max="maxCards"
                    :disabled="selectedSpread !== null"
                  />
                  <p v-if="selectedSpread" class="help">Set by the selected spread.</p>
                  <p v-else class="help">Up to {{ maxCards }} cards for this deck.</p>
                </div>
              </div>
              <div class="column is-4-desktop is-6-tablet">
                <div class="field">
                  <label for="new-reading-reversed-cards" class="label">Reversed Cards</label>
                  <div class="control">
                    <ToggleSwitch id="new-reading-reversed-cards" v-model="form.useReversals">{{
                      form.useReversals ? 'On' : 'Off'
                    }}</ToggleSwitch>
                  </div>
                </div>
              </div>
            </div>

            <ReadingOwnerOptions v-if="spreadReady" ref="ownerOptions" />

            <TurnstileWidget
              v-if="!isLoggedIn"
              ref="turnstile"
              v-model="turnstileToken"
              v-model:enabled="turnstileEnabled"
            />

            <div class="field">
              <div class="buttons">
                <button
                  type="button"
                  class="button is-primary"
                  :disabled="challengePending"
                  @click="submitNewReading"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
                  <span>Generate New Draw</span>
                </button>
                <button type="button" class="button" @click="resetForm">
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
                  /></span>
                  <span>Reset</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <LoadingOverlay v-if="isLoading" message="Drawing your cards..." />
</template>
