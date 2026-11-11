<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { CollapsibleRoot, CollapsibleTrigger, CollapsibleContent } from 'reka-ui'
import { ref, reactive, computed, watch, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDecks } from '@/composables/useDecks'
import { useUser } from '@/composables/useUser'
import { useSpreadLayout } from '@/composables/useSpreadLayout'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useReadingExport } from '@/composables/useReadingExport'
import { useReadings } from '@/composables/useReadings'
import { renderMarkdown } from '@/utils/markdown'
import { formatDateTime } from '@/utils/datetime'
import { visibilityLabels } from '@/utils/visibility'
import { cardAspectStyle } from '@/utils/cardAspect'
import { cardImageUrls, cardBackImageUrls } from '@/utils/cardImages'
import { cardLabel } from '@/utils/deck'
import type { AccountReading, Reading, ReadingInfo, ReadingCard, SpreadPosition } from '@/types'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import LightboxOverlay from '@/components/LightboxOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import NumberField from '@/components/NumberField.vue'
import PageHeader from '@/components/PageHeader.vue'
import Tooltip from '@/components/Tooltip.vue'

// Only an owner who chooses to edit needs it (and the notes editor it carries).
const ReadingEditDialog = defineAsyncComponent(() => import('@/components/ReadingEditDialog.vue'))

const route = useRoute()
const router = useRouter()
const { deckLookup, fetchDecks } = useDecks()
const { isLoggedIn, currentUser } = useUser()
const toasts = useToasts()
const { confirm } = useConfirm()
const { record: recordReading } = useRecentReadings()
const { exporting, exportReading } = useReadingExport()
const { get: getReading, unlock: unlockReading, drawMore, finalize } = useReadings()
const showExportModal = ref(false)

const reading = ref<Reading | null>(null)
const readingInfo = ref<ReadingInfo | null>(null)
const isLoading = ref(false)
const notFound = ref(false)
const lightboxIndex = ref<number | null>(null)

// Author label + custom title + password gate.
const readerName = ref('Guest')
const readingName = ref<string | null>(null)
const readingNotes = ref<string | null>(null)
const showNotes = ref(true)
const locked = ref(false)
const unlockPassword = ref('')
const unlocking = ref(false)
const unlockError = ref('')

// Custom title takes precedence over the generic heading.
const displayTitle = computed(() => readingName.value || 'Your Reading')
const readingNotesHtml = computed(() =>
  readingNotes.value ? renderMarkdown(readingNotes.value) : '',
)

const readingDeck = computed(() => {
  if (!readingInfo.value) {
    return null
  }
  return deckLookup.value[readingInfo.value.deck_id] ?? null
})

const cardBack = computed(() =>
  readingInfo.value ? cardBackImageUrls(readingInfo.value.deck_id) : null,
)

const readingUrl = computed(() => {
  if (!reading.value) {
    return ''
  }
  return 'https://tarotgen.io/reading/' + reading.value.reading_id
})

const readingCards = computed<ReadingCard[]>(() => {
  const info = readingInfo.value
  if (!info?.draw) {
    return []
  }
  return info.draw.map((card) => {
    const urls = cardImageUrls(info.deck_id, card.card_id)
    return {
      ...card,
      card_name: cardLabel(card),
      imgUrl: urls.display,
      pngUrl: urls.png,
      thumbUrl: urls.thumb,
    }
  })
})

const spread = computed(() => readingInfo.value?.spread ?? null)
const showSpreadDetails = ref(false)

// ── The journal and who can open it ──────────────────────────────────────────
// The question, querent and tags reach only the owner's payload. A private
// reading reaches nobody else at all, so its link is nothing to share.
const isPrivate = computed(() => reading.value?.visibility === 'private')
const journalTags = computed(() => reading.value?.tags ?? [])
// A guest's chosen expiry: the reading (and its link) is gone after this.
const expiresAt = computed(() => reading.value?.expires_at ?? null)

const editing = ref(false)
const editable = computed(() => {
  const r = reading.value
  if (!r?.is_owner) {
    return null
  }
  return {
    reading_id: r.reading_id,
    reading_name: r.reading_name,
    reading_notes: r.reading_notes,
    question: r.question ?? null,
    querent: r.querent ?? null,
    tags: r.tags ?? [],
    visibility: r.visibility,
    hide_user: r.hide_user ?? false,
  }
})

/** The owner saved the edit dialog: show what was saved, without a refetch. */
function onEdited(saved: AccountReading): void {
  editing.value = false
  toasts.success('Reading updated.')
  const current = reading.value
  if (!current) {
    return
  }
  reading.value = {
    ...current,
    reading_name: saved.reading_name,
    reading_notes: saved.reading_notes,
    question: saved.question,
    querent: saved.querent,
    tags: saved.tags,
    visibility: saved.visibility,
    hide_user: saved.hide_user,
  }
  readingName.value = saved.reading_name
  readingNotes.value = saved.reading_notes
  // The owner is the one looking, so their own name is the label when shown.
  readerName.value = saved.hide_user ? 'Guest' : (currentUser.value?.display_name ?? 'Guest')
}

// ── Owner actions: draw more cards + mark final ─────────────────────────────
const isOwner = computed(() => reading.value?.is_owner ?? false)
const isFinal = computed(() => reading.value?.is_final ?? false)
// The server only sets can_draw_more for the owner (which already requires a
// session), but gate on login explicitly so the action is unambiguously
// available to logged-in users only.
const canDrawMore = computed(() => isLoggedIn.value && (reading.value?.can_draw_more ?? false))

const drawnCount = computed(() => readingInfo.value?.draw.length ?? 0)
const deckExtraCards = computed(() => readingDeck.value?.additional_cards ?? 0)

// Cards drawn after a spread was placed sit at the end of the draw with no
// position yet — surfaced as a prompt to open the editor and place them.
const unplacedNewCount = computed(() => {
  if (!spread.value) {
    return 0
  }
  return Math.max(0, drawnCount.value - spread.value.positions.length)
})

const showDrawMore = ref(false)
const drawing = ref(false)
const drawForm = reactive({ count: 1, useReversals: false, useAdditionalCards: false })

// Cards still available to draw from this deck (free-draw uses the system total).
const maxDrawMore = computed(() => {
  // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing -- a system of 0 cards falls back to 78 too, as NewReadingView's count does
  const base = readingDeck.value?.system_total_cards || 78
  const total = base + (drawForm.useAdditionalCards ? deckExtraCards.value : 0)
  return Math.max(0, total - drawnCount.value)
})

function openDrawMore() {
  drawForm.count = 1
  drawForm.useReversals = false
  drawForm.useAdditionalCards = false
  showDrawMore.value = true
}

async function submitDrawMore() {
  if (!reading.value) {
    return
  }
  const id = reading.value.reading_id
  drawing.value = true
  const res = await drawMore(
    id,
    new URLSearchParams({
      count: String(Math.min(Math.max(1, drawForm.count), Math.max(1, maxDrawMore.value))),
      use_reversals: String(drawForm.useReversals),
      use_additional_cards: String(drawForm.useAdditionalCards),
    }),
  )
  drawing.value = false

  if (!res.ok) {
    toasts.error(res.error, { detail: res })
    return
  }
  showDrawMore.value = false
  if (res.data.reading_info.spread) {
    // Spread reading: send the owner to the editor to place the new cards.
    toasts.success('Cards drawn! Now place them on your spread.')
    void router.push({ name: 'free-draw-placement', params: { id } })
  } else {
    applyReading(res.data)
    toasts.success('Added new cards to your reading.')
  }
}

async function markFinal() {
  if (!reading.value) {
    return
  }
  const ok = await confirm({
    title: 'Mark reading as final?',
    message:
      'This permanently locks the reading — you will no longer be able to draw additional cards into it. This cannot be undone.',
    confirmLabel: 'Mark Final',
    danger: true,
  })
  if (!ok) {
    return
  }
  const res = await finalize(reading.value.reading_id)
  if (!res.ok) {
    toasts.error(res.error, { detail: res })
    return
  }
  applyReading(res.data)
  toasts.success('Reading marked as final.')
}

const spreadDescriptionHtml = computed(() =>
  spread.value ? renderMarkdown(spread.value.description) : '',
)

interface SpreadCard {
  position: SpreadPosition
  card: ReadingCard
  drawIndex: number
}

// Pair each spread position (by order) with the drawn card at the matching index.
const spreadCards = computed<SpreadCard[]>(() => {
  if (!spread.value) {
    return []
  }
  const cards = readingCards.value
  return [...spread.value.positions]
    .sort((a, b) => a.order - b.order)
    .map((position) => {
      const drawIndex = position.order - 1
      // A position past the cards drawn so far has no card yet.
      const card = drawIndex < cards.length ? cards[drawIndex] : undefined
      return { position, card, drawIndex }
    })
    .filter((sc): sc is SpreadCard => sc.card !== undefined)
})

// Spread canvas geometry (bounding-box fit + per-card positioning) is shared
// with the new-reading preview via this composable.
const { cardStyle } = useSpreadLayout(() => spread.value?.positions ?? [])

// Unified model for the Card Details panel + flip mechanic — covers both spreads
// and free draws (which have no positions/titles).
interface DetailItem {
  order: number
  title: string
  /** What the position asks, from its spread; readings made before these have none. */
  description: string
  card: ReadingCard
  drawIndex: number
}

const detailItems = computed<DetailItem[]>(() => {
  if (spread.value) {
    return spreadCards.value.map((sc) => ({
      order: sc.position.order,
      title: sc.position.title,
      description: sc.position.description ?? '',
      card: sc.card,
      drawIndex: sc.drawIndex,
    }))
  }
  return readingCards.value.map((card, i) => ({
    order: i + 1,
    title: '',
    description: '',
    card,
    drawIndex: i,
  }))
})

// Free draws are shown as a "Free Draw" spread for display purposes.
const displaySpreadName = computed(() => spread.value?.name ?? 'Free Draw')

const allFlipped = computed(
  () =>
    detailItems.value.length > 0 && detailItems.value.every((d) => flipped.value.has(d.drawIndex)),
)

// Per-draw-index flip state for spread cards.
const flipped = ref<Set<number>>(new Set())
const flippingAll = ref(false)
// The card/row currently highlighted (hover or focus).
const activeIndex = ref<number | null>(null)

function flipCard(drawIndex: number) {
  const next = new Set(flipped.value)
  next.add(drawIndex)
  flipped.value = next
}

// Hover/focus a card or its details row → highlight the matching pair.
function setActive(drawIndex: number) {
  activeIndex.value = drawIndex
}

function clearActive() {
  if (!flippingAll.value) {
    activeIndex.value = null
  }
}

/**
 * What a card's button is called. Face down it names only its place, so a
 * screen reader can't give the card away before it is turned; face up it adds
 * the card, as the caption and the details list do.
 */
function cardButtonLabel(order: number, title: string, card: ReadingCard, faceUp: boolean): string {
  const place = title ? `${String(order)}, ${title}` : String(order)
  if (!faceUp) {
    return `Face-down card ${place}`
  }
  return `Card ${place}: ${card.card_name}${card.reversed ? ', reversed' : ''}`
}

function onSpreadCardClick(drawIndex: number) {
  if (!flipped.value.has(drawIndex)) {
    // First tap reveals the card.
    flipCard(drawIndex)
    activeIndex.value = drawIndex
  } else {
    // Tapping an already-revealed card enlarges it.
    lightboxIndex.value = drawIndex
  }
}

// Toggle: reveal every remaining card one-by-one, or — if all are already
// revealed — flip them all back face-down (same flip animation either way).
async function flipAll() {
  if (flippingAll.value) {
    return
  }
  if (allFlipped.value) {
    flipped.value = new Set()
    activeIndex.value = null
    return
  }
  flippingAll.value = true
  for (const item of detailItems.value) {
    if (!flipped.value.has(item.drawIndex)) {
      flipCard(item.drawIndex)
      activeIndex.value = item.drawIndex
      await new Promise((resolve) => setTimeout(resolve, 250))
    }
  }
  flippingAll.value = false
  activeIndex.value = null
}

function copyReadingUrl() {
  void navigator.clipboard.writeText(readingUrl.value)
  toasts.success('Reading link copied to clipboard.')
}

// The print styles (bottom of this file) lay the reading out for paper, every
// card face up whatever was revealed here, so the browser's own Print (and its
// Save as PDF) needs nothing more from the page.
function printReading() {
  window.print()
}

function exportAt(scale: number) {
  showExportModal.value = false
  if (!reading.value || !readingInfo.value) {
    return
  }
  void exportReading({
    fileName: 'tarot-reading-' + reading.value.reading_id + (scale > 1 ? '-hires' : '') + '.png',
    readingId: reading.value.reading_id,
    title: spread.value?.name || 'Tarot Reading',
    subtitle:
      (readingDeck.value?.name ?? 'Tarot') + '  ·  ' + formatDateTime(reading.value.reading_time),
    cards: readingCards.value.map((c) => ({
      imgUrl: c.imgUrl,
      fallbackUrl: c.pngUrl,
      reversed: c.reversed,
      card_name: c.card_name,
    })),
    positions: spread.value?.positions ?? null,
    cardAspect:
      readingDeck.value && readingDeck.value.card_aspect_w > 0
        ? readingDeck.value.card_aspect_h / readingDeck.value.card_aspect_w
        : undefined,
    scale,
  })
}

// Populate view state from an accessible reading payload.
function applyReading(data: Reading) {
  reading.value = data
  const info = data.reading_info
  readingInfo.value = info
  readingName.value = data.reading_name
  readingNotes.value = data.reading_notes
  readerName.value = data.reader
  locked.value = false
  recordReading({
    id: data.reading_id,
    deckName: deckLookup.value[info.deck_id]?.name ?? 'Tarot',
    summary:
      data.reading_name ??
      info.spread?.name ??
      String(info.draw.length) + (info.draw.length === 1 ? ' card' : ' cards'),
    at: data.reading_time,
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function fetchReading(id: string) {
  isLoading.value = true
  notFound.value = false
  locked.value = false
  unlockError.value = ''
  unlockPassword.value = ''
  reading.value = null
  readingInfo.value = null
  readingName.value = null
  readingNotes.value = null
  readerName.value = 'Guest'
  try {
    const res = await getReading(id)
    if (!res.ok) {
      notFound.value = true
      // Offline or rate-limited is not "no such reading": say what happened.
      toasts.error(res.status === 404 ? "We couldn't find a reading with that code." : res.error, {
        detail: res,
      })
      return
    }
    if (res.data.locked) {
      locked.value = true
      readingName.value = res.data.reading_name
      return
    }
    applyReading(res.data)
  } finally {
    isLoading.value = false
  }
}

async function unlock() {
  const id = route.params.id
  if (typeof id !== 'string' || !id) {
    return
  }
  unlocking.value = true
  unlockError.value = ''
  const res = await unlockReading(id, unlockPassword.value)
  unlocking.value = false

  // A wrong password is a 401 carrying the server's message; success is the reading.
  if (res.ok) {
    applyReading(res.data)
  } else {
    unlockError.value = res.error
  }
}

// Refetch whenever the :id changes — including reading → reading navigation
// (e.g. the navbar "Reading Code" box), where the component is reused and
// onMounted would not fire again. Reset the per-reading view state too.
watch(
  () => route.params.id,
  (id) => {
    if (typeof id !== 'string' || !id) {
      return
    }
    flipped.value = new Set()
    activeIndex.value = null
    showSpreadDetails.value = false
    lightboxIndex.value = null
    // deckLookup resolves this reading's deck name/aspect; cached module-wide,
    // so this is a no-op after the first call.
    void fetchDecks()
    void fetchReading(id)
  },
  { immediate: true },
)
</script>

<template>
  <section v-if="reading && readingInfo" class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader :title="displayTitle">
            <span v-if="isFinal" class="tag is-dark is-medium reading-final-tag">
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock-keyhole']"
              /></span>
              <span>Final</span>
            </span>
          </PageHeader>

          <!-- Cards drawn into a spread that still need a position on the canvas. -->
          <div v-if="isOwner && !isFinal && unplacedNewCount > 0" class="myst-callout">
            <p>
              You have {{ unplacedNewCount }} newly drawn card{{
                unplacedNewCount === 1 ? '' : 's'
              }}
              waiting to be placed on your spread.
              <router-link
                :to="{ name: 'free-draw-placement', params: { id: reading.reading_id } }"
                class="ml-1"
              >
                Arrange them now </router-link
              >.
            </p>
          </div>

          <!-- A guest chose to have this reading deleted early. -->
          <div v-if="expiresAt" class="myst-callout reading-expiry mb-4" role="note">
            <p>
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['hourglass-half']"
              /></span>
              This reading will be deleted on {{ formatDateTime(expiresAt) }}. Its link stops
              working then.
            </p>
          </div>

          <div class="columns reading-top">
            <div class="column is-7">
              <dl class="reading-meta settings-panel">
                <div class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['hashtag']" /></span
                    >Reading ID
                  </dt>
                  <dd>
                    <code>{{ reading.reading_id }}</code>
                  </dd>
                </div>
                <div class="meta-row">
                  <dt>
                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['link']" /></span
                    >Reading URL
                  </dt>
                  <!-- Private: the link opens it for nobody else, so there's nothing to share. -->
                  <dd v-if="isPrivate" class="reading-private-note">
                    <span class="icon is-small"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
                    /></span>
                    Private: only you can open this reading.
                  </dd>
                  <dd v-else class="meta-url">
                    <span class="reading-url-text">{{ readingUrl }}</span>
                    <Tooltip text="Copy Reading URL">
                      <button type="button" class="button is-small is-link" @click="copyReadingUrl">
                        <span class="icon is-small"
                          ><FontAwesomeIcon :icon="byPrefixAndName.fas['copy']"
                        /></span>
                        <span>Copy</span>
                      </button>
                    </Tooltip>
                  </dd>
                </div>
                <!-- The owner's journal: theirs alone, never in anyone else's payload. -->
                <div v-if="reading.question" class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-question']" /></span
                    >Question
                  </dt>
                  <dd class="reading-question">{{ reading.question }}</dd>
                </div>
                <div v-if="reading.querent" class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-user']" /></span
                    >For
                  </dt>
                  <dd>{{ reading.querent }}</dd>
                </div>
                <div v-if="journalTags.length" class="meta-row">
                  <dt>
                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['tags']" /></span
                    >Tags
                  </dt>
                  <dd class="reading-tag-list">
                    <span v-for="tag in journalTags" :key="tag" class="data-chip">{{ tag }}</span>
                  </dd>
                </div>
                <div v-if="isOwner" class="meta-row meta-row--privacy">
                  <dt>
                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['eye']" /></span
                    >Who Can Open It
                  </dt>
                  <dd class="meta-privacy">
                    <span>{{ visibilityLabels[reading.visibility] }}</span>
                    <button type="button" class="button is-small" @click="editing = true">
                      <span class="icon is-small"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                      /></span>
                      <span>Edit</span>
                    </button>
                  </dd>
                </div>
                <div class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['calendar-day']" /></span
                    >Date
                  </dt>
                  <dd>{{ formatDateTime(reading.reading_time) }}</dd>
                </div>
                <div class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['table-cells']" /></span
                    >Spread
                  </dt>
                  <dd>{{ displaySpreadName }}</dd>
                </div>
                <div class="meta-row">
                  <dt>
                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['user']" /></span
                    >Reader
                  </dt>
                  <dd>{{ readerName }}</dd>
                </div>
                <div v-if="readingDeck" class="meta-row">
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['cards-blank']" /></span
                    >Deck
                  </dt>
                  <dd>
                    {{ readingDeck.name }}
                    <span v-if="readingDeck.artist" class="meta-sub"
                      >· art by {{ readingDeck.artist }}</span
                    >
                    <span v-if="readingDeck.system_short_name" class="data-chip ml-2">{{
                      readingDeck.system_short_name
                    }}</span>
                  </dd>
                </div>
                <div
                  v-if="readingDeck && readingDeck.purchase_url"
                  class="meta-row meta-row--purchase"
                >
                  <dt>
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['cart-shopping']" /></span
                    >Purchase
                  </dt>
                  <dd>
                    <a :href="readingDeck.purchase_url" target="_blank" rel="noopener noreferrer"
                      >Buy this deck</a
                    >
                  </dd>
                </div>
              </dl>

              <!-- Free draw: actions sit under the reading details. -->
              <div v-if="!spread" class="buttons mt-3">
                <button
                  type="button"
                  class="button is-primary"
                  :disabled="flippingAll"
                  @click="flipAll"
                >
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-rotate']"
                  /></span>
                  <span>{{ allFlipped ? 'Flip All Back' : 'Flip All' }}</span>
                </button>
                <button
                  type="button"
                  class="button is-link"
                  :class="{ 'is-loading': exporting }"
                  @click="showExportModal = true"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['image']" /></span>
                  <span>Save as Image</span>
                </button>
                <button type="button" class="button is-link" @click="printReading">
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['print']" /></span>
                  <span>Print</span>
                </button>
                <button
                  v-if="canDrawMore"
                  type="button"
                  class="button is-link is-light"
                  @click="openDrawMore"
                >
                  <span class="icon">
                    <FontAwesomeLayers>
                      <FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" />
                      <FontAwesomeIcon
                        :icon="byPrefixAndName.fas['circle-plus']"
                        transform="shrink-6 down-6 right-7"
                      />
                    </FontAwesomeLayers>
                  </span>
                  <span>Draw More Cards</span>
                </button>
                <Tooltip v-if="isOwner && !isFinal" text="Permanently lock this reading">
                  <button type="button" class="button is-light" @click="markFinal">
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock-keyhole']"
                    /></span>
                    <span>Mark as Final</span>
                  </button>
                </Tooltip>
              </div>
            </div>

            <!-- Spread description sits beside the details to use the space. -->
            <div v-if="spread && spread.description" class="column is-5">
              <CollapsibleRoot v-model:open="showSpreadDetails" class="spread-details">
                <CollapsibleTrigger class="spread-details-header">
                  <span class="icon">
                    <FontAwesomeIcon
                      :icon="
                        showSpreadDetails
                          ? byPrefixAndName.fas['chevron-down']
                          : byPrefixAndName.fas['chevron-right']
                      "
                    />
                  </span>
                  <span>Spread Details: {{ spread.name }}</span>
                </CollapsibleTrigger>
                <CollapsibleContent class="collapsible-anim">
                  <!-- Sanitized by renderMarkdown() (marked + DOMPurify). -->
                  <!-- eslint-disable-next-line vue/no-v-html -->
                  <div class="spread-details-body content" v-html="spreadDescriptionHtml"></div>
                </CollapsibleContent>
              </CollapsibleRoot>
            </div>
          </div>

          <!-- Free draw: cards use the full width; no per-card details list. -->
          <div v-if="!spread" class="reading-grid" :style="cardAspectStyle(readingDeck)">
            <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- hover/focus only syncs the highlight with the details list; the card's own role=button below is the control -->
            <div
              v-for="item in detailItems"
              :key="'g-' + item.drawIndex"
              class="reading-grid-card"
              :class="{
                'is-flipped': flipped.has(item.drawIndex),
                'is-active': activeIndex === item.drawIndex,
              }"
              @mouseenter="setActive(item.drawIndex)"
              @mouseleave="clearActive"
              @focusin="setActive(item.drawIndex)"
              @focusout="clearActive"
            >
              <!-- Named by cardButtonLabel; the face's alt stays empty until it is turned. -->
              <div
                class="spread-flip"
                role="button"
                tabindex="0"
                :aria-label="
                  cardButtonLabel(item.order, item.title, item.card, flipped.has(item.drawIndex))
                "
                @click="onSpreadCardClick(item.drawIndex)"
                @keydown.enter.prevent="onSpreadCardClick(item.drawIndex)"
                @keydown.space.prevent="onSpreadCardClick(item.drawIndex)"
              >
                <div class="spread-face spread-face--back">
                  <img :src="cardBack?.thumb" alt="" />
                  <span class="spread-order-badge" aria-hidden="true">{{ item.order }}</span>
                </div>
                <div class="spread-face spread-face--reveal">
                  <img
                    :src="item.card.thumbUrl"
                    :class="{ reversed: item.card.reversed }"
                    :alt="flipped.has(item.drawIndex) ? item.card.card_name : ''"
                    loading="lazy"
                  />
                  <!-- Print only (the caption names the card on screen): pairs the
                       face with its row in the printed list. -->
                  <span class="spread-order-badge spread-order-badge--reveal" aria-hidden="true">{{
                    item.order
                  }}</span>
                </div>
              </div>
              <p class="reading-grid-caption">
                <template v-if="flipped.has(item.drawIndex)">
                  {{ item.card.card_name
                  }}<span v-if="item.card.reversed" class="has-text-warning"> (Reversed)</span>
                </template>
                <span v-else class="has-text-grey is-italic">Card {{ item.order }}</span>
              </p>
            </div>
          </div>

          <!-- Spread: the canvas and card details sit side by side. -->
          <div v-else class="columns reading-main">
            <div class="column is-7-desktop">
              <div class="spread-canvas reading-canvas" :style="cardAspectStyle(readingDeck)">
                <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- hover/focus only syncs the highlight with the details list; the card's own role=button below is the control -->
                <div
                  v-for="sc in spreadCards"
                  :key="sc.drawIndex"
                  class="spread-card"
                  :class="{
                    'is-flipped': flipped.has(sc.drawIndex),
                    'is-active': activeIndex === sc.drawIndex,
                  }"
                  :style="cardStyle(sc.position)"
                  @mouseenter="setActive(sc.drawIndex)"
                  @mouseleave="clearActive"
                  @focusin="setActive(sc.drawIndex)"
                  @focusout="clearActive"
                >
                  <!-- Named by cardButtonLabel; the face's alt stays empty until it is turned. -->
                  <div
                    class="spread-flip"
                    role="button"
                    tabindex="0"
                    :aria-label="
                      cardButtonLabel(
                        sc.position.order,
                        sc.position.title,
                        sc.card,
                        flipped.has(sc.drawIndex),
                      )
                    "
                    @click="onSpreadCardClick(sc.drawIndex)"
                    @keydown.enter.prevent="onSpreadCardClick(sc.drawIndex)"
                    @keydown.space.prevent="onSpreadCardClick(sc.drawIndex)"
                  >
                    <div class="spread-face spread-face--back">
                      <img :src="cardBack?.thumb" alt="" />
                      <span class="spread-order-badge" aria-hidden="true">{{
                        sc.position.order
                      }}</span>
                    </div>
                    <div class="spread-face spread-face--reveal">
                      <img
                        :src="sc.card.thumbUrl"
                        :class="{ reversed: sc.card.reversed }"
                        :alt="flipped.has(sc.drawIndex) ? sc.card.card_name : ''"
                        loading="lazy"
                      />
                      <span
                        class="spread-order-badge spread-order-badge--reveal"
                        aria-hidden="true"
                        >{{ sc.position.order }}</span
                      >
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="column is-5-desktop">
              <!-- Card details + actions -->
              <div class="reading-details">
                <h2 class="spread-detail-list-title">Card Details</h2>
                <p class="has-text-grey is-size-7 mb-3">
                  Tap each card to reveal it. Tap a revealed card again to enlarge it.
                </p>

                <div class="reading-detail-grid">
                  <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events, vuejs-accessibility/no-static-element-interactions, vuejs-accessibility/mouse-events-have-key-events -- a mouse shortcut for each card's own role=button above; a second tab stop per card would double the tab order -->
                  <div
                    v-for="item in detailItems"
                    :key="'row-' + item.drawIndex"
                    class="spread-detail-row"
                    :class="{
                      'is-active': activeIndex === item.drawIndex,
                      'is-revealed': flipped.has(item.drawIndex),
                    }"
                    @mouseenter="setActive(item.drawIndex)"
                    @mouseleave="clearActive"
                    @click="onSpreadCardClick(item.drawIndex)"
                  >
                    <span class="spread-detail-order">{{ item.order }}</span>
                    <span class="spread-detail-text">
                      <strong v-if="item.title">{{ item.title }}</strong>
                      <template v-if="flipped.has(item.drawIndex)">
                        <span>{{ item.card.card_name }}</span>
                        <span v-if="item.card.reversed" class="has-text-warning">(Reversed)</span>
                      </template>
                      <span v-else class="has-text-grey is-italic">Not yet revealed</span>
                      <span v-if="item.description" class="spread-detail-about">{{
                        item.description
                      }}</span>
                    </span>
                  </div>
                </div>

                <div class="buttons mt-4">
                  <button
                    type="button"
                    class="button is-primary"
                    :disabled="flippingAll"
                    @click="flipAll"
                  >
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-rotate']"
                    /></span>
                    <span>{{ allFlipped ? 'Flip All Back' : 'Flip All' }}</span>
                  </button>
                  <button
                    type="button"
                    class="button is-link"
                    :class="{ 'is-loading': exporting }"
                    @click="showExportModal = true"
                  >
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['image']"
                    /></span>
                    <span>Save as Image</span>
                  </button>
                  <button type="button" class="button is-link" @click="printReading">
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['print']"
                    /></span>
                    <span>Print</span>
                  </button>
                  <button
                    v-if="canDrawMore"
                    type="button"
                    class="button is-link is-light"
                    @click="openDrawMore"
                  >
                    <span class="icon"
                      ><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']"
                    /></span>
                    <span>Draw More Cards</span>
                  </button>
                  <Tooltip v-if="isOwner && !isFinal" text="Permanently lock this reading">
                    <button type="button" class="button is-light" @click="markFinal">
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock-keyhole']"
                      /></span>
                      <span>Mark as Final</span>
                    </button>
                  </Tooltip>
                </div>
              </div>
            </div>
          </div>

          <!-- Print only: the cards' list and the notes, flat on paper. On screen
               the details list reveals names one tap at a time and the notes fold
               away; a printout shows them all. -->
          <div class="reading-print">
            <table class="reading-print-cards">
              <thead>
                <tr>
                  <th scope="col">#</th>
                  <th v-if="spread" scope="col">Position</th>
                  <th scope="col">Card</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in detailItems" :key="'print-' + item.drawIndex">
                  <td>{{ item.order }}</td>
                  <td v-if="spread">
                    {{ item.title
                    }}<template v-if="item.description">
                      — <em>{{ item.description }}</em></template
                    >
                  </td>
                  <td>
                    {{ item.card.card_name
                    }}<em v-if="item.card.reversed" class="reading-print-reversed"> (Reversed)</em>
                  </td>
                </tr>
              </tbody>
            </table>
            <section v-if="readingNotes" class="reading-print-notes">
              <h2 class="spread-detail-list-title">Reading Notes</h2>
              <!-- User-authored notes; sanitized by renderMarkdown() (marked + DOMPurify) -->
              <!-- eslint-disable-next-line vue/no-v-html -->
              <div class="content" v-html="readingNotesHtml"></div>
            </section>
          </div>

          <!-- Reading Notes: full-width detailed interpretation, below the cards. -->
          <CollapsibleRoot v-if="readingNotes" v-model:open="showNotes" class="reading-notes mt-5">
            <CollapsibleTrigger class="spread-details-header">
              <span class="icon">
                <FontAwesomeIcon
                  :icon="
                    showNotes
                      ? byPrefixAndName.fas['chevron-down']
                      : byPrefixAndName.fas['chevron-right']
                  "
                />
              </span>
              <span>Reading Notes</span>
            </CollapsibleTrigger>
            <CollapsibleContent class="collapsible-anim">
              <!-- User-authored notes; sanitized by renderMarkdown() (marked + DOMPurify) -->
              <!-- eslint-disable-next-line vue/no-v-html -->
              <div class="spread-details-body content" v-html="readingNotesHtml"></div>
            </CollapsibleContent>
          </CollapsibleRoot>
        </div>
      </div>
    </div>
  </section>

  <!-- Password-protected: prompt before revealing anything. -->
  <section v-else-if="locked && !isLoading" class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-5-desktop is-7-tablet">
          <PageHeader
            :title="readingName || 'Protected Reading'"
            subtitle="This reading is password-protected."
          />
          <div class="settings-panel has-text-centered">
            <span class="icon is-large has-text-warning">
              <FontAwesomeLayers class="fa-2x">
                <FontAwesomeIcon :icon="byPrefixAndName.fad['scroll']" />
                <FontAwesomeIcon
                  :icon="byPrefixAndName.fas['lock']"
                  transform="shrink-7 down-5 right-6"
                />
              </FontAwesomeLayers>
            </span>
            <p class="mt-3 mb-4">Enter the password below to view it.</p>
            <form @submit.prevent="unlock">
              <div class="field">
                <div class="control has-icons-left">
                  <!-- eslint-disable vuejs-accessibility/no-autofocus -- the password is all a locked reading asks for -->
                  <input
                    v-model="unlockPassword"
                    class="input"
                    type="password"
                    aria-label="Reading password"
                    placeholder="Reading password"
                    autocomplete="off"
                    autofocus
                  />
                  <!-- eslint-enable vuejs-accessibility/no-autofocus -->
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['key']"
                  /></span>
                </div>
              </div>
              <div v-if="unlockError" class="notification is-danger is-light">
                {{ unlockError }}
              </div>
              <button
                class="button is-primary is-fullwidth"
                type="submit"
                :class="{ 'is-loading': unlocking }"
                :disabled="unlocking || !unlockPassword"
              >
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['unlock']" /></span>
                <span>View Reading</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section v-else-if="notFound && !isLoading" class="section">
    <div class="container has-text-centered">
      <span class="icon is-large has-text-grey-light">
        <FontAwesomeIcon :icon="byPrefixAndName.fad['circle-question']" size="2x" />
      </span>
      <h1 class="title is-4 mt-3">Reading not found</h1>
      <p class="subtitle is-6">
        We couldn't find a reading with that code. It may have been mistyped.
      </p>
      <div class="buttons is-centered mt-4">
        <router-link class="button is-primary" :to="{ name: 'new-reading' }">
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
          <span>Start a New Draw</span>
        </router-link>
        <router-link class="button" :to="{ name: 'home' }">Back to Home</router-link>
      </div>
    </div>
  </section>

  <LoadingOverlay v-if="isLoading" message="Loading your reading..." />

  <LightboxOverlay
    v-if="lightboxIndex !== null && cardBack"
    :cards="readingCards"
    :revealed="flipped"
    :card-back="cardBack"
    :initial-index="lightboxIndex"
    :deck-id="readingInfo?.deck_id ?? 0"
    @close="lightboxIndex = null"
  />

  <BaseModal
    :active="showExportModal"
    title="Save reading image"
    max-width="30rem"
    @close="showExportModal = false"
  >
    <p class="mb-4">Choose an image size:</p>
    <div class="export-options">
      <button type="button" class="button export-option" @click="exportAt(1)">
        <span class="export-option-main">
          <strong>Standard</strong>
          <span class="export-option-sub">Best for quick sharing · ~1600px wide</span>
        </span>
      </button>
      <button type="button" class="button is-primary export-option" @click="exportAt(2)">
        <span class="export-option-main">
          <strong>High resolution</strong>
          <span class="export-option-sub">Larger, sharper cards · ~3200px wide</span>
        </span>
      </button>
    </div>
  </BaseModal>

  <!-- Draw additional cards into an existing reading (owner only). -->
  <BaseModal
    :active="showDrawMore"
    title="Draw More Cards"
    max-width="30rem"
    @close="showDrawMore = false"
  >
    <p class="mb-4">
      Draw additional cards from <strong>{{ readingDeck?.name ?? 'this deck' }}</strong> and add
      them to your reading.
      <template v-if="spread"> You'll then place them on your spread.</template>
    </p>

    <div class="field">
      <label for="reading-number-of-cards" class="label">Number of Cards</label>
      <NumberField
        id="reading-number-of-cards"
        v-model="drawForm.count"
        :min="1"
        :max="maxDrawMore"
      />
      <p class="help">
        {{ maxDrawMore }} card{{ maxDrawMore === 1 ? '' : 's' }} left in this deck.
      </p>
    </div>

    <div class="field">
      <label for="reading-reversed-cards" class="label">Reversed Cards</label>
      <ToggleSwitch id="reading-reversed-cards" v-model="drawForm.useReversals">{{
        drawForm.useReversals ? 'On' : 'Off'
      }}</ToggleSwitch>
    </div>

    <div v-if="deckExtraCards > 0" class="field">
      <label for="reading-include-extra-card" class="label"
        >Include {{ deckExtraCards }} Extra Card{{ deckExtraCards === 1 ? '' : 's' }}</label
      >
      <ToggleSwitch id="reading-include-extra-card" v-model="drawForm.useAdditionalCards">{{
        drawForm.useAdditionalCards ? 'On' : 'Off'
      }}</ToggleSwitch>
    </div>

    <template #footer>
      <button type="button" class="button" @click="showDrawMore = false">Cancel</button>
      <button
        type="button"
        class="button is-primary"
        :class="{ 'is-loading': drawing }"
        :disabled="drawing || maxDrawMore < 1 || drawForm.count < 1"
        @click="submitDrawMore"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['cards']" /></span>
        <span>Draw</span>
      </button>
    </template>
  </BaseModal>

  <!-- The owner's title, journal, notes and who can open it. -->
  <ReadingEditDialog
    v-if="editing && editable"
    :reading="editable"
    @close="editing = false"
    @saved="onEdited"
  />
</template>

<style scoped>
.reading-final-tag {
  vertical-align: middle;
}

.reading-private-note {
  color: var(--myst-text-muted);
}

.reading-question {
  font-style: italic;
}

.reading-tag-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.meta-privacy {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
}

.export-options {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.export-option {
  height: auto;
  padding: 0.9rem 1.1rem;
  justify-content: flex-start;
  text-align: left;
  white-space: normal;
}

.export-option-main {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.export-option-sub {
  font-size: 0.8rem;
  font-weight: 400;
  opacity: 0.75;
}

/* ── Print ──────────────────────────────────────────────────
   The reading on paper: title, details, the cards face up, their list and the
   notes. Ink on white and no app chrome come from tokens.css and print.css. */
.reading-print {
  display: none;
}

@media print {
  /* The screen's controls, and the panels the print sheet says again, flat. */
  .buttons,
  .myst-callout,
  .reading-details,
  .spread-details,
  .reading-notes,
  .meta-url .button,
  .meta-privacy .button,
  .meta-row--purchase,
  .reading-grid-caption {
    display: none;
  }

  .reading-print {
    display: block;
  }

  /* One column, full width: details, cards, then their list and the notes. */
  .columns {
    display: block;
    margin: 0;
  }

  .columns > .column {
    width: auto;
    margin: 0;
    padding: 0;
  }

  .reading-final-tag {
    background: none;
    color: var(--myst-text-strong);
    border: 1px solid var(--myst-border-strong);
  }

  .reading-meta {
    max-width: none;
    margin-bottom: 1rem;
    padding: 0;
    background: none;
    border: 0;
  }

  .reading-meta .meta-row {
    grid-template-columns: 7rem 1fr;
    padding: 0.15rem 0;
    border-bottom: 0;
  }

  /* Every card face up, whatever was revealed on screen. The flip transforms
     come from style.css at the same specificity, hence !important. */
  .spread-flip {
    transform: none !important;
    transition: none;
  }

  .spread-face--back {
    display: none;
  }

  .spread-face--reveal {
    transform: none;
  }

  .spread-face {
    box-shadow: none;
  }

  .spread-order-badge--reveal {
    opacity: 1;
    transition: none;
  }

  .spread-card.is-active .spread-flip,
  .reading-grid-card.is-active .spread-flip {
    outline: none;
  }

  .reading-canvas {
    width: 15cm;
    max-width: 100%;
    margin: 0 auto 1rem;
    background: none;
    border: 1px solid var(--myst-border);
    break-inside: avoid;
  }

  .reading-grid {
    grid-template-columns: repeat(5, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
  }

  .reading-grid-card {
    break-inside: avoid;
  }

  .reading-print-cards {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.25rem;
  }

  .reading-print-cards th,
  .reading-print-cards td {
    padding: 0.3rem 0.6rem 0.3rem 0;
    border-bottom: 1px solid var(--myst-border);
    text-align: left;
    vertical-align: top;
    color: var(--myst-text);
  }

  .reading-print-cards th:first-child {
    width: 2.5rem;
  }

  .reading-print-cards th {
    font-size: 0.75rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--myst-text-muted);
  }

  .reading-print-cards tr {
    break-inside: avoid;
  }

  .reading-print-reversed {
    color: var(--myst-text-muted);
  }
}
</style>
