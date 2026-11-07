<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDecks } from '@/composables/useDecks'
import { useUser } from '@/composables/useUser'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useReadings } from '@/composables/useReadings'
import { useSubmissions } from '@/composables/useSubmissions'
import { useToasts } from '@/composables/useToasts'
import { cardAspectStyle } from '@/utils/cardAspect'
import { cardImageUrls } from '@/utils/cardImages'
import { cardLabel } from '@/utils/deck'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import SpreadCanvasEditor from '@/components/SpreadCanvasEditor.vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'
import type { ReadingInfo, Deck } from '@/types'

const route = useRoute()
const router = useRouter()
const { decks, fetchDecks } = useDecks()
const { isLoggedIn } = useUser()
const { createUserSpread } = useUserSpreads()
const { get: getReading, savePlacement } = useReadings()
const { submitSpread } = useSubmissions()
const toasts = useToasts()

const readingId = computed(() => route.params.id as string)

// Loading state
const loading = ref(true)
const saving = ref(false)
const readingInfo = ref<ReadingInfo | null>(null)
const deck = ref<Deck | null>(null)

interface Slot extends SpreadSlotBase {
  cardId: number
  reversed: boolean
  cardName: string
}

// The drawn cards. Populated once in onMounted before the canvas mounts (the
// editor is gated behind v-if="!loading"), then mutated in place.
const slots = ref<Slot[]>([])

// True when the reading already had a placed spread (e.g. the owner drew more
// cards into it) — we then pre-place the existing cards, preserve the spread's
// name, and skip the "save as spread" prompt that the fresh free-draw flow shows.
const isExistingSpread = ref(false)
const existingSpreadName = ref('')

// Save-spread modal
const showSaveSpreadModal = ref(false)
const spreadName = ref('')
const spreadDescription = ref('')
const spreadSaveMode = ref<'public' | 'personal'>('personal')
const spreadSaveModeOptions = [
  { value: 'personal', label: 'Personal', icon: byPrefixAndName.fas.lock },
  { value: 'public', label: 'Public', icon: byPrefixAndName.fas.globe },
]
const spreadSaving = ref(false)

// Fetch the reading data
onMounted(async () => {
  // Cached module-wide; fired alongside the reading fetch rather than awaited.
  void fetchDecks()
  try {
    const res = await getReading(readingId.value)
    // Only the owner arranges a reading, and an owner never gets the locked shape.
    if (!res.ok || res.data.locked) {
      toasts.error('Could not load the reading.', { detail: res })
      void router.replace({ name: 'home' })
      return
    }

    // A finalized reading is locked — there is nothing to arrange.
    if (res.data.is_final) {
      toasts.warning('This reading is final and can no longer be edited.')
      void router.replace({ name: 'reading', params: { id: readingId.value } })
      return
    }

    const info = res.data.reading_info
    readingInfo.value = info

    // Find deck
    deck.value = decks.value.find((dk) => dk.deck_id === info.deck_id) ?? null

    // Existing placement (if any), keyed by position order, so cards already
    // arranged into a spread come back pre-placed and only the new draws sit
    // in the tray awaiting placement.
    const positions = info.spread?.positions ?? []
    isExistingSpread.value = positions.length > 0
    existingSpreadName.value = info.spread?.name ?? ''
    const byOrder = new Map(positions.map((p) => [p.order, p]))

    // Populate slots from draw data
    info.draw.forEach((card, i) => {
      const pos = byOrder.get(i + 1)
      slots.value.push({
        title: pos?.title ?? '',
        x: pos?.x ?? 50,
        y: pos?.y ?? 50,
        rotation: pos?.rotation ?? 0,
        placed: pos !== undefined,
        cardId: card.card_id,
        reversed: card.reversed,
        cardName: cardLabel(card),
      })
    })
  } finally {
    loading.value = false
  }
})

// If decks load after the reading, sync
watch(
  decks,
  (val) => {
    const info: ReadingInfo | null = readingInfo.value
    if (info && !deck.value) {
      deck.value = val.find((d) => d.deck_id === info.deck_id) ?? null
    }
  },
  { immediate: true },
)

const allPlaced = computed(() => slots.value.length > 0 && slots.value.every((s) => s.placed))

function cardThumb(cardId: number): string {
  if (!deck.value) {
    return ''
  }
  return cardImageUrls(deck.value.deck_id, cardId).thumb
}

// Submit placement
async function finalizePlacement() {
  if (!allPlaced.value) {
    toasts.warning('Please place all cards on the canvas first.')
    return
  }

  saving.value = true
  const positions = slots.value.map((s: Slot) => ({
    title: s.title,
    x: s.x,
    y: s.y,
    rotation: s.rotation,
  }))
  const res = await savePlacement(
    readingId.value,
    positions,
    existingSpreadName.value || 'Free Draw Placement',
  )
  saving.value = false

  if (!res.ok) {
    toasts.error(res.error, { detail: res })
    return
  }

  toasts.success('Placement saved!')

  // Editing an existing spread (drew more cards): no need to re-offer
  // saving it as a reusable spread — go straight back to the reading.
  if (isExistingSpread.value) {
    void router.push({ name: 'reading', params: { id: readingId.value } })
    return
  }

  // Fresh free draw: offer to save the layout as a reusable spread.
  showSaveSpreadModal.value = true
}

// Save the spread (optional step after placement is saved)
async function saveSpread() {
  if (!spreadName.value.trim()) {
    toasts.warning('Please enter a name for the spread.')
    return
  }

  spreadSaving.value = true
  try {
    const positions = slots.value.map((s: Slot, idx: number) => ({
      order: idx + 1,
      title: s.title,
      x: s.x,
      y: s.y,
      rotation: s.rotation,
    }))

    const payload = {
      name: spreadName.value.trim(),
      description: spreadDescription.value.trim(),
      card_count: slots.value.length,
      positions,
    }

    if (spreadSaveMode.value === 'personal' && isLoggedIn.value) {
      const saved = await createUserSpread(payload)
      if (saved.ok) {
        toasts.success('Spread saved to your personal collection!')
      } else {
        toasts.error(saved.error)
      }
    } else {
      const res = await submitSpread(payload)
      if (res.ok) {
        toasts.success('Spread submitted for review!')
      } else {
        toasts.error(res.error)
      }
    }
  } finally {
    spreadSaving.value = false
    showSaveSpreadModal.value = false
    void router.push({ name: 'reading', params: { id: readingId.value } })
  }
}

function skipSaveSpread() {
  showSaveSpreadModal.value = false
  void router.push({ name: 'reading', params: { id: readingId.value } })
}
</script>

<template>
  <section v-if="!loading" class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader
            title="Arrange Your Draw"
            subtitle="Place your drawn cards on the canvas, name each position, and finalize the layout."
          />

          <div class="settings-panel">
            <SpreadCanvasEditor v-model="slots" :viewport-style="cardAspectStyle(deck)">
              <template #card="{ item }">
                <img
                  :src="cardThumb(item.cardId)"
                  :class="{ reversed: item.reversed }"
                  class="fdp-card-img"
                  :alt="item.cardName"
                  draggable="false"
                  loading="lazy"
                />
              </template>

              <template #tray-token="{ item, index }">
                <span>{{ index + 1 }}</span>
                <span class="fdp-tray-name">{{
                  item.cardName.split(' ').slice(0, 2).join(' ')
                }}</span>
              </template>

              <template #edit-modal="{ item, index, close }">
                <BaseModal
                  :active="index !== null"
                  :title="
                    index !== null && item ? 'Position #' + (index + 1) + ' — ' + item.cardName : ''
                  "
                  max-width="26rem"
                  @close="close"
                >
                  <template v-if="item">
                    <div class="field">
                      <label for="freedraw-position-title" class="label"
                        >Position Title
                        <span class="has-text-grey is-size-7 has-text-weight-normal"
                          >(optional)</span
                        ></label
                      >
                      <input
                        id="freedraw-position-title"
                        v-model="item.title"
                        class="input"
                        placeholder="e.g. The Present"
                        autocomplete="off"
                      />
                    </div>
                  </template>
                  <template #footer>
                    <button type="button" class="button is-primary" @click="close">Done</button>
                  </template>
                </BaseModal>
              </template>
            </SpreadCanvasEditor>

            <!-- Finalize button -->
            <div class="field mt-4">
              <div class="buttons">
                <button
                  type="button"
                  class="button is-primary"
                  :class="{ 'is-loading': saving }"
                  :disabled="!allPlaced || saving"
                  @click="finalizePlacement"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
                  <span>Finalize Placement</span>
                </button>
                <button
                  type="button"
                  class="button"
                  @click="router.push({ name: 'reading', params: { id: readingId } })"
                >
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['forward']"
                  /></span>
                  <span>Skip Placement</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <LoadingOverlay v-if="loading" message="Loading your reading..." />
  <LoadingOverlay v-if="saving" message="Saving placement..." />

  <!-- Save spread modal (appears after placement is saved) -->
  <BaseModal
    :active="showSaveSpreadModal"
    title="Save This Layout as a Spread?"
    max-width="32rem"
    @close="skipSaveSpread"
  >
    <p class="mb-4">
      Your reading has been saved. Would you like to also save this card layout as a reusable
      spread?
    </p>

    <div class="field">
      <label for="freedraw-spread-name" class="label">Spread Name</label>
      <input
        id="freedraw-spread-name"
        v-model="spreadName"
        class="input"
        placeholder="e.g. My Three-Card Pull"
        maxlength="100"
        autocomplete="off"
      />
    </div>

    <div class="field">
      <label for="freedraw-description" class="label"
        >Description
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <textarea
        id="freedraw-description"
        v-model="spreadDescription"
        class="textarea"
        rows="3"
        placeholder="Describe the spread layout..."
      ></textarea>
    </div>

    <div v-if="isLoggedIn" class="field">
      <span id="freedraw-save-as" class="label">Save As</span>
      <SegmentedControl
        v-model="spreadSaveMode"
        :options="spreadSaveModeOptions"
        aria-labelledby="freedraw-save-as"
      />
      <p v-if="spreadSaveMode === 'personal'" class="help">Saved to your personal collection.</p>
      <p v-else class="help">Submitted for review to become a public spread.</p>
    </div>

    <template #footer>
      <button type="button" class="button" @click="skipSaveSpread">Skip</button>
      <button
        type="button"
        class="button is-primary"
        :class="{ 'is-loading': spreadSaving }"
        :disabled="spreadSaving || !spreadName.trim()"
        @click="saveSpread"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
        <span>Save Spread</span>
      </button>
    </template>
  </BaseModal>
</template>

<style scoped>
.fdp-card-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 8px;
}

.fdp-card-img.reversed {
  transform: rotate(180deg);
}

.fdp-tray-name {
  font-size: 0.6rem;
  color: var(--myst-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
</style>
