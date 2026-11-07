<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, onMounted, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import DeckComboBox from '@/components/DeckComboBox.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import NumberField from '@/components/NumberField.vue'
import { ContextMenuItem } from 'reka-ui'
import PageHeader from '@/components/PageHeader.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import Tooltip from '@/components/Tooltip.vue'
import SpreadCanvasEditor from '@/components/SpreadCanvasEditor.vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'
import { useDecks } from '@/composables/useDecks'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useConfirm } from '@/composables/useConfirm'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import { useToasts } from '@/composables/useToasts'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useReadings } from '@/composables/useReadings'
import { useUser } from '@/composables/useUser'
import { defaultDeckId } from '@/utils/deck'
import { cardAspectStyle } from '@/utils/cardAspect'
import { cardImageUrls } from '@/utils/cardImages'
import type { DeckCard } from '@/types'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'
import TurnstileWidget from '@/components/TurnstileWidget.vue'

const LAST_DECK_KEY = STORAGE_KEYS.lastDeck

const router = useRouter()
const { decks, fetchDecks, fetchDeckCards } = useDecks()
const { createCustom } = useReadings()
const { fetchFavoriteDecks } = useFavoriteDecks()
const { spreads, fetchSpreads } = useSpreads()
const { confirm } = useConfirm()

interface Slot extends SpreadSlotBase {
  cardId: number | null
  reversed: boolean
}

function newSlot(): Slot {
  return { title: '', x: 50, y: 50, rotation: 0, placed: false, cardId: null, reversed: false }
}

const deckId = ref<number | null>(null)
const readingName = ref('')
const deckCards = ref<DeckCard[]>([])
const loadingCards = ref(false)
const cardCount = ref(1)
const baseSpreadId = ref<number | null>(null)
// A stable reactive array, seeded before the canvas mounts and mutated in place;
// loads (applyTemplate / reset) bump editorKey to remount with a fresh history.
const slots = ref<Slot[]>([newSlot()])
const editorKey = ref(0)
const isLoading = ref(false)
const ownerOptions = useTemplateRef<InstanceType<typeof ReadingOwnerOptions>>('ownerOptions')
const toasts = useToasts()
const { record: recordReading } = useRecentReadings()
const { isLoggedIn } = useUser()

// A signed-out visitor passes a Turnstile challenge to save, where the site has
// Turnstile on (AUDIT §9.14); a signed-in reader never sees one.
const turnstileToken = ref('')
const turnstileEnabled = ref(false)
const turnstile = useTemplateRef('turnstile')
const challengePending = computed(
  () => !isLoggedIn.value && turnstileEnabled.value && !turnstileToken.value,
)

onMounted(() => {
  void fetchDecks()
  void fetchSpreads()
  void fetchFavoriteDecks()
})

// Spreads offered as base templates, alphabetised.
const sortedSpreads = computed(() =>
  [...spreads.value].sort((a, b) => a.name.localeCompare(b.name)),
)

// Base-on-a-spread picker (Reka Select): 0 is the "start from scratch" sentinel
// (baseSpreadId stays number | null); selecting an option runs applyTemplate.
const baseSpreadOptions = computed(() => [
  { value: 0, label: 'Start from scratch' },
  ...sortedSpreads.value.map((s) => ({
    value: s.spread_id,
    label: `${s.name} (${s.card_count} cards)`,
  })),
])

const baseSpreadModel = computed<number>({
  get: () => baseSpreadId.value ?? 0,
  set: (v) => {
    baseSpreadId.value = v === 0 ? null : v
    void applyTemplate()
  },
})

// Apply a saved spread as a starting layout: copy its positions into the slots,
// leaving each card unassigned so the user still chooses what sat where.
async function applyTemplate() {
  const spread = spreads.value.find((s) => s.spread_id === baseSpreadId.value)
  if (!spread) {
    return
  }

  const hasWork = slots.value.some((s) => s.placed || s.cardId !== null)
  if (hasWork) {
    const ok = await confirm({
      title: 'Apply spread template',
      message: `Replace your current layout with the "${spread.name}" template? The positions will be filled in for you — you'll still pick which card goes in each spot.`,
      confirmLabel: 'Apply template',
    })
    if (!ok) {
      baseSpreadId.value = null
      return
    }
  }

  const positions = [...spread.positions].sort((a, b) => a.order - b.order)
  slots.value.length = 0
  for (const p of positions) {
    slots.value.push({
      title: p.title,
      x: p.x,
      y: p.y,
      rotation: p.rotation,
      placed: true,
      cardId: null,
      reversed: false,
    })
  }
  if (slots.value.length === 0) {
    slots.value.push(newSlot())
  }
  cardCount.value = slots.value.length
  editorKey.value++ // remount the canvas → fresh history / selection / zoom
  toasts.success(`Applied the "${spread.name}" template.`)
}

// Pick a default deck once decks load: prefer the user's remembered choice.
watch(
  decks,
  (val) => {
    if (val.length > 0 && deckId.value === null) {
      deckId.value = defaultDeckId(val, local.get<number | null>(LAST_DECK_KEY, null))
    }
  },
  { immediate: true },
)

// Load the deck's cards whenever the deck changes; clear stale selections.
watch(
  deckId,
  async (val) => {
    if (val === null) {
      return
    }
    local.set(LAST_DECK_KEY, val)
    slots.value.forEach((s) => {
      s.cardId = null
    })
    loadingCards.value = true
    const cards = await fetchDeckCards(val)
    loadingCards.value = false
    if (!cards) {
      toasts.error("Couldn't load this deck's cards. Please try again.")
    }
    deckCards.value = cards ?? []
  },
  { immediate: true },
)

// Resize the slot list when the card count changes (in place).
watch(cardCount, (val) => {
  const target = Math.max(1, Math.min(78, Math.floor(val || 1)))
  while (slots.value.length < target) {
    slots.value.push(newSlot())
  }
  while (slots.value.length > target) {
    slots.value.pop()
  }
})

// Keep the card-count field in sync when an undo/redo changes the slot count.
function onRestore(length: number) {
  cardCount.value = length
}

const selectedDeck = computed(() =>
  deckId.value === null ? null : (decks.value.find((d) => d.deck_id === deckId.value) ?? null),
)

const allReady = computed(
  () => slots.value.length > 0 && slots.value.every((s) => s.placed && s.cardId !== null),
)

// A physical deck holds each card once, so track which cards are already chosen.
const usedCardIds = computed(
  () => new Set(slots.value.filter((s) => s.cardId !== null).map((s) => s.cardId)),
)

// Card-picker options for the edit modal (Reka Select). A card is disabled once
// it's used by another slot; the slot's own current card stays enabled.
function cardOptionsFor(currentCardId: number | null) {
  return deckCards.value.map((c) => ({
    value: c.card_id,
    label: c.name,
    disabled: currentCardId !== c.card_id && usedCardIds.value.has(c.card_id),
  }))
}

function cardThumb(cardId: number | null): string {
  if (cardId === null || deckId.value === null) {
    return ''
  }
  return cardImageUrls(deckId.value, cardId).thumb
}

function resetForm() {
  readingName.value = ''
  baseSpreadId.value = null
  cardCount.value = 1
  slots.value.length = 0
  slots.value.push(newSlot())
  editorKey.value++
  // Or a password from before the reset would still lock the next reading.
  ownerOptions.value?.reset()
}

async function submit() {
  if (deckId.value === null) {
    toasts.warning('Please choose a deck.')
    return
  }
  if (!allReady.value) {
    toasts.warning('Every card must be placed on the layout and have a card selected.')
    return
  }

  const chosenIds = slots.value.map((s) => s.cardId)
  if (new Set(chosenIds).size !== chosenIds.length) {
    toasts.warning('Each card can only be used once in a reading.')
    return
  }

  const cards = slots.value.map((s) => ({
    card_id: s.cardId,
    reversed: s.reversed,
    title: s.title,
    x: s.x,
    y: s.y,
    rotation: s.rotation,
  }))

  const payload: Record<string, unknown> = { deck_id: deckId.value, name: readingName.value, cards }
  Object.assign(payload, ownerOptions.value?.collect())
  if (!isLoggedIn.value && turnstileToken.value) {
    payload.turnstile_token = turnstileToken.value
  }

  isLoading.value = true
  const res = await createCustom(payload)
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
    summary:
      readingName.value.trim() ||
      String(slots.value.length) + (slots.value.length === 1 ? ' card' : ' cards'),
    at: res.data.reading_time,
  })
  toasts.success('Your custom reading has been saved!')
  void router.push({ name: 'reading', params: { id: res.data.reading_id } })
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader
            title="Recreate Draw"
            subtitle="Recreate a real spread by placing specific cards in the positions you want: choose the deck, place each card where it sat, name the positions, and pick the exact card (and orientation) for each spot."
          />

          <div class="settings-panel">
            <div class="columns">
              <div class="column is-6">
                <div class="field">
                  <label class="label" for="cr-deck">Deck</label>
                  <DeckComboBox id="cr-deck" v-model="deckId" :decks="decks" />
                </div>
              </div>
              <div class="column is-6">
                <div class="field">
                  <label class="label" for="cr-name"
                    >Spread Name
                    <span class="has-text-grey is-size-7 has-text-weight-normal"
                      >(optional)</span
                    ></label
                  >
                  <div class="control">
                    <input
                      id="cr-name"
                      v-model="readingName"
                      class="input"
                      maxlength="100"
                      autocomplete="off"
                      placeholder="e.g. My Morning Three-Card Pull"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div class="columns">
              <div class="column is-4">
                <div class="field">
                  <label for="custom-number-of-cards" class="label">Number of Cards</label>
                  <NumberField id="custom-number-of-cards" v-model="cardCount" :min="1" :max="78" />
                </div>
              </div>
              <div class="column is-8">
                <div class="field">
                  <label for="custom-base-on-a-spread" class="label"
                    >Base on a Spread
                    <span class="has-text-grey is-size-7 has-text-weight-normal"
                      >(optional)</span
                    ></label
                  >
                  <BaseSelect
                    id="custom-base-on-a-spread"
                    v-model="baseSpreadModel"
                    :options="baseSpreadOptions"
                  />
                  <p class="help">
                    Pre-fills the card count and positions; you still choose which card goes in each
                    spot.
                  </p>
                </div>
              </div>
            </div>

            <SpreadCanvasEditor
              :key="editorKey"
              v-model="slots"
              :viewport-style="cardAspectStyle(selectedDeck)"
              edit-label="Choose card / title…"
              @restore="onRestore"
            >
              <template #card="{ item }">
                <img
                  v-if="item.cardId !== null"
                  :src="cardThumb(item.cardId)"
                  :class="{ reversed: item.reversed }"
                  class="cr-card-img"
                  alt=""
                  draggable="false"
                  loading="lazy"
                />
              </template>

              <template #context-menu-extra="{ item }">
                <ContextMenuItem class="myst-menu-item" @select="item.reversed = !item.reversed">
                  <span class="mi-icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']"
                  /></span>
                  <span>Toggle reversed</span>
                </ContextMenuItem>
              </template>

              <template #card-controls-extra="{ item }">
                <Tooltip text="Toggle reversed">
                  <button
                    type="button"
                    class="button is-small"
                    :class="{ 'is-warning': item.reversed }"
                    tabindex="-1"
                    aria-label="Toggle reversed"
                    @click.stop="item.reversed = !item.reversed"
                  >
                    <span class="icon is-small"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']"
                    /></span>
                  </button>
                </Tooltip>
              </template>

              <template #edit-modal="{ item, index, close }">
                <BaseModal
                  :active="index !== null"
                  :title="index !== null ? 'Position #' + (index + 1) : ''"
                  max-width="30rem"
                  @close="close"
                >
                  <template v-if="item">
                    <div class="field">
                      <label for="custom-position-title" class="label"
                        >Position Title
                        <span class="has-text-grey is-size-7 has-text-weight-normal"
                          >(optional)</span
                        ></label
                      >
                      <input
                        id="custom-position-title"
                        v-model="item.title"
                        class="input"
                        placeholder="e.g. The Present"
                      />
                    </div>
                    <div class="field">
                      <label for="custom-card" class="label">Card</label>
                      <BaseSelect
                        id="custom-card"
                        v-model="item.cardId"
                        :options="cardOptionsFor(item.cardId)"
                        :disabled="loadingCards"
                        :placeholder="loadingCards ? 'Loading cards…' : 'Select a card…'"
                      />
                    </div>
                    <div class="field">
                      <ToggleSwitch v-model="item.reversed">Reversed</ToggleSwitch>
                    </div>
                  </template>
                  <template #footer>
                    <button type="button" class="button is-primary" @click="close">Done</button>
                  </template>
                </BaseModal>
              </template>
            </SpreadCanvasEditor>

            <ReadingOwnerOptions ref="ownerOptions" notes class="mt-4" />

            <TurnstileWidget
              v-if="!isLoggedIn"
              ref="turnstile"
              v-model="turnstileToken"
              v-model:enabled="turnstileEnabled"
              class="mt-4"
            />

            <div class="field is-grouped mt-4">
              <div class="control">
                <button
                  type="button"
                  class="button is-primary"
                  :disabled="challengePending"
                  @click="submit"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
                  <span>Recreate Draw</span>
                </button>
              </div>
              <div class="control">
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

    <LoadingOverlay v-if="isLoading" message="Saving your reading..." />
  </section>
</template>

<style scoped>
.cr-card-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 8px;
}

.cr-card-img.reversed {
  transform: rotate(180deg);
}
</style>
