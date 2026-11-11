<script setup lang="ts">
/**
 * The collapsible card-definition list shared by the public "Submit a Deck
 * System" flow and the admin deck-system editor. Owns the accordion, the
 * expand/collapse toolbar, per-row open state and title-input focus management,
 * so neither view re-implements identical markup and CSS.
 *
 * The array stays caller-owned (`v-model`, mutated in place): each view seeds
 * and resizes it itself, because that is bound to its own `total_cards` field
 * and save payload. Open state resets by bumping `:key`, the same way
 * SpreadCanvasEditor callers reset the canvas.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, nextTick, useId } from 'vue'
import {
  AccordionRoot,
  AccordionItem,
  AccordionHeader,
  AccordionTrigger,
  AccordionContent,
} from 'reka-ui'
import type { DeckSystemCard } from '@/types'

const props = withDefaults(
  defineProps<{
    title?: string
    /** Heading level, so the list nests correctly in each page's outline. */
    headingTag?: 'h4' | 'h5'
    /** e.g. '600px' — the list scrolls internally instead of growing the page. */
    maxHeight?: string | null
    /** Rows open at mount. Bump `:key` to re-seed. */
    initialOpen?: string[]
  }>(),
  {
    title: 'Card Definitions',
    headingTag: 'h4',
    maxHeight: null,
    initialOpen: () => ['0'],
  },
)

const cards = defineModel<DeckSystemCard[]>({ required: true })

const uid = useId()
/** A per-card field id: one editor lists many cards. */
function fieldId(index: number, field: string): string {
  return `${uid}-${String(index)}-${field}`
}

defineSlots<{
  /** Helper copy between the toolbar and the list. */
  hint(): unknown
}>()

const openCards = ref<string[]>([...props.initialOpen])
const titleInputs = ref<(HTMLInputElement | null)[]>([])

const listStyle = computed(() =>
  props.maxHeight === null ? undefined : { '--deck-cards-max-height': props.maxHeight },
)

// `unknown` rather than Vue's `Element | ComponentPublicInstance | null`: under
// the shared eslint config ComponentPublicInstance resolves to an error type
// that acts as `any`, which trips no-redundant-type-constituents.
function setTitleRef(el: unknown, index: number): void {
  titleInputs.value[index] = (el as HTMLInputElement | null) ?? null
}

/**
 * Focus a row's title input once it exists.
 *
 * Reka unmounts a collapsed AccordionContent, so a row we have only just opened
 * has no input on the next tick — Presence mounts it a frame later. A plain
 * `nextTick(() => input?.focus())` would therefore silently do nothing, and
 * "Done" would advance to the next card without focusing it. Retry across a few
 * animation frames, bounded so a row that never mounts can't spin forever.
 */
function focusCard(index: number): void {
  let attempts = 0
  const tryFocus = (): void => {
    const el = titleInputs.value[index]
    if (el) {
      el.focus()
      return
    }
    if (attempts++ < 10) {
      requestAnimationFrame(tryFocus)
    }
  }
  void nextTick(tryFocus)
}

function markDone(index: number): void {
  const next = index + 1
  if (next < cards.value.length) {
    openCards.value = [String(next)]
    focusCard(next)
  } else {
    openCards.value = []
  }
}

function expandAll(): void {
  openCards.value = cards.value.map((_, i) => String(i))
}

function collapseAll(): void {
  openCards.value = []
}

/** Expand + focus the first unnamed card. False when every card is named. */
function revealFirstMissing(): boolean {
  const index = cards.value.findIndex((c) => c.name.trim() === '')
  if (index < 0) {
    return false
  }
  if (!openCards.value.includes(String(index))) {
    openCards.value = [...openCards.value, String(index)]
  }
  focusCard(index)
  return true
}

defineExpose({ expandAll, collapseAll, revealFirstMissing })
</script>

<template>
  <div class="is-flex is-align-items-center is-justify-content-space-between mt-4 mb-3">
    <component :is="headingTag" class="title is-5 mb-0">{{ title }}</component>
    <div class="buttons are-small mb-0">
      <button type="button" class="button is-small is-ghost" @click="expandAll">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-down']" /></span>
        <span>Expand All</span>
      </button>
      <button type="button" class="button is-small is-ghost" @click="collapseAll">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']" /></span>
        <span>Collapse All</span>
      </button>
    </div>
  </div>

  <slot name="hint" />

  <AccordionRoot
    v-model="openCards"
    type="multiple"
    class="deck-system-cards"
    :class="{ 'is-scrollable': maxHeight !== null }"
    :style="listStyle"
  >
    <AccordionItem
      v-for="(card, index) in cards"
      :key="card.card_id"
      :value="String(index)"
      class="deck-card-entry"
      :class="{ 'is-missing-name': !card.name.trim() }"
    >
      <AccordionHeader>
        <AccordionTrigger type="button" class="deck-card-header">
          <span class="deck-card-number">{{ card.card_id }}</span>
          <span class="deck-card-title">{{ card.name.trim() || 'Untitled Card' }}</span>
          <span v-if="!card.name.trim()" class="tag is-danger is-light ml-2">Name required</span>
          <span class="icon deck-card-chevron">
            <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
          </span>
        </AccordionTrigger>
      </AccordionHeader>

      <AccordionContent class="deck-card-body">
        <div class="field">
          <label :for="fieldId(index, 'title')" class="label is-small"
            >Card Title <span class="has-text-danger">*</span></label
          >
          <div class="control">
            <input
              :id="fieldId(index, 'title')"
              :ref="(el) => setTitleRef(el, index)"
              v-model="card.name"
              class="input"
              type="text"
              placeholder="e.g. The Fool"
            />
          </div>
        </div>

        <div class="columns is-multiline">
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'keywords')" class="label is-small">Keywords</label>
              <div class="control">
                <input
                  :id="fieldId(index, 'keywords')"
                  v-model="card.keywords"
                  class="input"
                  type="text"
                  placeholder="Keywords..."
                />
              </div>
            </div>
          </div>
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'reversed-keywords')" class="label is-small"
                >Reversed Keywords</label
              >
              <div class="control">
                <input
                  :id="fieldId(index, 'reversed-keywords')"
                  v-model="card.reversed_keywords"
                  class="input"
                  type="text"
                  placeholder="Reversed keywords..."
                />
              </div>
            </div>
          </div>
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'meaning')" class="label is-small">Meaning</label>
              <div class="control">
                <textarea
                  :id="fieldId(index, 'meaning')"
                  v-model="card.meaning"
                  class="textarea is-small"
                  placeholder="Meaning..."
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'reversed-meaning')" class="label is-small"
                >Reversed Meaning</label
              >
              <div class="control">
                <textarea
                  :id="fieldId(index, 'reversed-meaning')"
                  v-model="card.reversed_meaning"
                  class="textarea is-small"
                  placeholder="Reversed meaning..."
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'advice')" class="label is-small">Advice</label>
              <div class="control">
                <textarea
                  :id="fieldId(index, 'advice')"
                  v-model="card.advice"
                  class="textarea is-small"
                  placeholder="Advice..."
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>
          <div class="column is-6">
            <div class="field">
              <label :for="fieldId(index, 'reversed-advice')" class="label is-small"
                >Reversed Advice</label
              >
              <div class="control">
                <textarea
                  :id="fieldId(index, 'reversed-advice')"
                  v-model="card.reversed_advice"
                  class="textarea is-small"
                  placeholder="Reversed advice..."
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="has-text-right">
          <button type="button" class="button is-small is-success" @click="markDone(index)">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
            <span>Done</span>
          </button>
        </div>
      </AccordionContent>
    </AccordionItem>
  </AccordionRoot>
</template>

<style scoped>
.deck-system-cards {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.deck-system-cards.is-scrollable {
  max-height: var(--deck-cards-max-height, 600px);
  overflow-y: auto;

  /* Keeps a row's focus ring off the scroll container's edge. */
  padding-right: 0.25rem;
}

.deck-card-entry {
  /* When the container is a flex column with a capped height, entries would
     shrink (flex-shrink defaults to 1) to fit and overflow:hidden would clip
     each one to a sliver. Keep their natural height and let the container
     scroll instead. No-op when the container isn't capped. */
  flex-shrink: 0;
  border: 1px solid var(--myst-border);
  border-radius: 8px;
  overflow: hidden;
  transition: border-color 0.15s ease;
}

.deck-card-entry[data-state='open'] {
  border-color: var(--myst-border-strong);
}

.deck-card-entry.is-missing-name:not([data-state='open']) {
  border-color: var(--myst-danger);
}

.deck-card-header {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.65rem 0.9rem;
  cursor: pointer;
  background: var(--myst-surface-3);
  border: 0;
  font: inherit;
  color: inherit;
  text-align: left;
  user-select: none;
  transition: background-color 0.12s ease;
}

.deck-card-header:hover {
  background: var(--myst-surface);
}

.deck-card-number {
  font-size: 0.8rem;
  font-weight: 700;
  opacity: 0.5;
  min-width: 2ch;
  text-align: right;
}

.deck-card-title {
  font-weight: 600;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deck-card-chevron {
  margin-left: auto;
  opacity: 0.5;
  transition: transform 0.15s ease;
}

.deck-card-header[data-state='open'] .deck-card-chevron {
  transform: rotate(180deg);
}

.deck-card-body {
  padding: 1rem;
  border-top: 1px solid var(--myst-border);
}
</style>
