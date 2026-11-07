<script setup lang="ts">
/**
 * The spread form: name, card count, description, the layout canvas, and per
 * position a title and an optional description (what the position asks about,
 * never what a card means). Admin, Create a Spread and My Spreads all use it;
 * given `categories` (admin), it also picks the library category.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, useId } from 'vue'
import type { SpreadCategory, SpreadDraft, SpreadPosition } from '@/types'
import { renderMarkdown } from '@/utils/markdown'
import BaseModal from '@/components/BaseModal.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import SpreadCanvasEditor from '@/components/SpreadCanvasEditor.vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'

const props = withDefaults(
  defineProps<{
    spread: SpreadDraft | null
    saveLabel?: string
    /** The library categories, to pick one (admin); none given, no picker. */
    categories?: SpreadCategory[]
  }>(),
  {
    saveLabel: 'Save Spread',
    categories: undefined,
  },
)

const emit = defineEmits<{
  save: [
    payload: {
      name: string
      description: string
      card_count: number
      positions: SpreadPosition[]
      category_id?: number
    },
  ]
  cancel: []
}>()

/** A position's description is capped at this (the API's NormalizesPositions). */
const POSITION_DESCRIPTION_MAX = 280

interface EditorSlot extends SpreadSlotBase {
  description: string
}

const uid = useId()
const name = ref('')
const description = ref('')
const categoryId = ref<number | null>(null)
const showPreview = ref(false)
const cardCount = ref(1)
// A stable reactive array, mutated in place so the canvas composables keep their
// captured reference; the canvas is remounted (via editorKey) on each (re)load.
const slots = ref<EditorSlot[]>([])
const editorKey = ref(0)
const error = ref('')
const saving = ref(false)

const previewHtml = computed(() => renderMarkdown(description.value))
const allPlaced = computed(() => slots.value.every((s) => s.placed))
const categoryOptions = computed(() =>
  (props.categories ?? []).map((c) => ({ value: c.category_id, label: c.name })),
)

function emptySlot(): EditorSlot {
  return { title: '', description: '', x: 50, y: 50, rotation: 0, placed: false }
}

// ── Initialize from prop ────────────────────────────────────
function init() {
  name.value = props.spread?.name ?? ''
  description.value = props.spread?.description ?? ''
  categoryId.value = props.spread?.category_id ?? props.categories?.[0]?.category_id ?? null
  slots.value.length = 0

  const positions = props.spread?.positions ?? []
  if (positions.length > 0) {
    positions
      .slice()
      .sort((a, b) => a.order - b.order)
      .forEach((p) => {
        slots.value.push({
          title: p.title,
          description: p.description ?? '',
          x: p.x,
          y: p.y,
          rotation: p.rotation,
          placed: true,
        })
      })
  } else {
    slots.value.push(emptySlot())
  }
  cardCount.value = slots.value.length
  editorKey.value++ // remount the canvas → fresh history / selection / zoom
}

watch(() => props.spread, init, { immediate: true })

// The categories can arrive after the form: a new spread then starts in the first.
watch(
  () => props.categories,
  (list) => {
    const first = list?.[0]
    if (categoryId.value === null && first) {
      categoryId.value = first.category_id
    }
  },
)

// ── Card count → resize slots (in place) ────────────────────
watch(cardCount, (val) => {
  const target = Math.max(1, Math.min(78, Math.floor(val || 1)))
  while (slots.value.length < target) {
    slots.value.push(emptySlot())
  }
  while (slots.value.length > target) {
    slots.value.pop()
  }
})

// Keep the card-count field in sync when an undo/redo changes the slot count.
function onRestore(length: number) {
  cardCount.value = length
}

// ── Save ────────────────────────────────────────────────────
function onSave() {
  error.value = ''
  if (!name.value.trim()) {
    error.value = 'Please enter a spread name.'
    return
  }
  if (!allPlaced.value) {
    error.value = 'Every card must be placed on the layout before saving.'
    return
  }

  const positions: SpreadPosition[] = slots.value.map((s: EditorSlot, i: number) => {
    const about = s.description.trim()
    return {
      order: i + 1,
      title: s.title,
      x: s.x,
      y: s.y,
      rotation: s.rotation,
      ...(about ? { description: about } : {}),
    }
  })

  saving.value = true
  emit('save', {
    name: name.value.trim(),
    description: description.value,
    card_count: slots.value.length,
    positions,
    ...(props.categories && categoryId.value !== null ? { category_id: categoryId.value } : {}),
  })
}

defineExpose({
  finishSaving: () => {
    saving.value = false
  },
  setError: (msg: string) => {
    saving.value = false
    error.value = msg
  },
})
</script>

<template>
  <div>
    <div class="columns">
      <div class="column is-8">
        <div class="field">
          <label :for="uid + '-name'" class="label">Spread Name</label>
          <input :id="uid + '-name'" v-model="name" class="input" placeholder="e.g. Celtic Cross" />
        </div>
      </div>
      <div class="column is-4">
        <div class="field">
          <label :for="uid + '-count'" class="label">Number of Cards</label>
          <input
            :id="uid + '-count'"
            v-model.number="cardCount"
            class="input"
            type="number"
            min="1"
            max="78"
          />
        </div>
      </div>
    </div>

    <div v-if="categories" class="field">
      <label :for="uid + '-category'" class="label">Category</label>
      <BaseSelect
        :id="uid + '-category'"
        v-model="categoryId"
        :options="categoryOptions"
        placeholder="Choose a category"
      />
      <p class="help">Where the spread library lists it.</p>
    </div>

    <div class="field">
      <label :for="uid + '-description'" class="label">
        <span :id="uid + '-description-caption'">Description (Markdown)</span>
        <button
          type="button"
          class="button is-small is-text ml-2"
          @click="showPreview = !showPreview"
        >
          {{ showPreview ? 'Edit' : 'Preview' }}
        </button>
      </label>
      <textarea
        v-if="!showPreview"
        :id="uid + '-description'"
        v-model="description"
        :aria-labelledby="uid + '-description-caption'"
        class="textarea"
        rows="5"
        placeholder="Describe the spread. Markdown is supported."
      ></textarea>
      <!-- Sanitized by renderMarkdown() (marked + DOMPurify) — see utils/markdown.ts -->
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div v-else class="content box" v-html="previewHtml"></div>
    </div>

    <SpreadCanvasEditor
      :key="editorKey"
      v-model="slots"
      edit-label="Edit position…"
      @restore="onRestore"
    >
      <template #card="{ item }">
        <span v-if="item.title" class="editor-card-title">{{ item.title }}</span>
      </template>

      <template #edit-modal="{ item, index, close }">
        <BaseModal
          :active="index !== null"
          :title="index !== null ? 'Position #' + (index + 1) : ''"
          max-width="28rem"
          @close="close"
        >
          <template v-if="item">
            <div class="field">
              <label :for="uid + '-position-title'" class="label">Position Title</label>
              <input
                :id="uid + '-position-title'"
                v-model="item.title"
                class="input"
                placeholder="e.g. The Present"
                @keyup.enter="close"
              />
            </div>
            <div class="field">
              <label :for="uid + '-position-description'" class="label">
                What it asks <span class="has-text-weight-normal is-size-7">(optional)</span>
              </label>
              <textarea
                :id="uid + '-position-description'"
                v-model="item.description"
                class="textarea"
                rows="3"
                :maxlength="POSITION_DESCRIPTION_MAX"
                :aria-describedby="uid + '-position-description-help'"
                placeholder="e.g. What stands in your way"
              ></textarea>
              <p :id="uid + '-position-description-help'" class="help">
                What this position is about, in a sentence — not what a card here would mean.
                {{ POSITION_DESCRIPTION_MAX - item.description.length }} characters left.
              </p>
            </div>
          </template>
          <template #footer>
            <button type="button" class="button is-primary" @click="close">Done</button>
          </template>
        </BaseModal>
      </template>
    </SpreadCanvasEditor>

    <div v-if="error" class="notification is-danger is-light">{{ error }}</div>

    <div class="field is-grouped mt-4">
      <div class="control">
        <button
          type="button"
          class="button is-success"
          :class="{ 'is-loading': saving }"
          @click="onSave"
        >
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
          <span>{{ props.saveLabel }}</span>
        </button>
      </div>
      <div class="control">
        <button type="button" class="button" @click="emit('cancel')">Cancel</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.editor-card-title {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
  font-size: 0.62rem;
  line-height: 1.1;
  text-align: center;
  color: rgb(255 255 255);
  text-shadow: 0 1px 3px rgb(0 0 0 / 80%);
  overflow: hidden;
  max-height: 2.4em;
}
</style>
