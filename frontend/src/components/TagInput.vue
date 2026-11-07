<script setup lang="ts">
/**
 * A reading's tags, on Reka UI's TagsInput: type one and press Enter or a comma
 * (or paste a list); a chip's × or Backspace removes one. The reader's own tags
 * that match what's typed (their most used, before they type) sit underneath
 * as one-click suggestions. Tags are kept as the server stores them, so
 * "Career" and "career" are one tag. `wantSuggestions` fires on focus, for the
 * page to fetch them then rather than up front.
 *
 *   <TagInput :id="tagsId" v-model="tags" :suggestions="myTags" @want-suggestions="load" />
 */
import { byPrefixAndName } from '@/fontawesome'
import { computed, ref, useTemplateRef } from 'vue'
import {
  TagsInputRoot,
  TagsInputItem,
  TagsInputItemText,
  TagsInputItemDelete,
  TagsInputInput,
} from 'reka-ui'
import { normalizeTag } from '@/utils/visibility'
import type { ReadingTag } from '@/types'

const props = withDefaults(
  defineProps<{
    /** Put on the text input, so a `<label for>` names the field. */
    id?: string
    suggestions?: ReadingTag[]
  }>(),
  { id: undefined, suggestions: () => [] },
)
const emit = defineEmits<{ wantSuggestions: [] }>()
/** The server's limits (ReadingInput::TAGS_MAX, TAG_MAX). */
const MAX_TAGS = 10
const MAX_LENGTH = 30
/** How many suggestions show at once. */
const SHOWN = 6

const model = defineModel<string[]>({ required: true })

const draft = ref('')
const input = useTemplateRef<{ $el: HTMLInputElement }>('input')

// Whatever Reka adds arrives normalised (convertValue). Text it adds whole —
// on blur, say — can still hold commas, which separate tags as the server
// reads them; this splits there and drops what normalises to nothing ("#") and
// any repeat.
const tags = computed<string[]>({
  get: () => model.value,
  set: (value) => {
    const split = value.flatMap((tag) => tag.split(',')).map(normalizeTag)
    model.value = [...new Set(split.filter((tag) => tag !== ''))]
  },
})

const full = computed(() => model.value.length >= MAX_TAGS)

const offered = computed(() => {
  if (full.value) {
    return []
  }
  const typed = normalizeTag(draft.value)
  return props.suggestions
    .filter((s) => !model.value.includes(s.tag) && s.tag.includes(typed))
    .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag))
    .slice(0, SHOWN)
})

function onInput(event: Event): void {
  draft.value = (event.target as HTMLInputElement).value
}

function add(tag: string): void {
  tags.value = [...model.value, tag]
  draft.value = ''
  const el = input.value?.$el
  if (el) {
    el.value = ''
    el.focus()
  }
}
</script>

<template>
  <div class="tag-input">
    <TagsInputRoot
      :id="id"
      v-model="tags"
      :max="MAX_TAGS"
      :convert-value="normalizeTag"
      add-on-paste
      add-on-blur
      class="tag-input__field input"
      @add-tag="draft = ''"
    >
      <TagsInputItem v-for="tag in tags" :key="tag" :value="tag" class="tag-input__chip">
        <TagsInputItemText class="tag-input__chip-text" />
        <TagsInputItemDelete class="tag-input__remove">
          <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
        </TagsInputItemDelete>
      </TagsInputItem>
      <TagsInputInput
        ref="input"
        :max-length="MAX_LENGTH"
        :placeholder="full ? 'Ten tags at most' : tags.length ? 'Add another…' : 'Add a tag…'"
        class="tag-input__text"
        @input="onInput"
        @focus="emit('wantSuggestions')"
      />
    </TagsInputRoot>

    <div v-if="offered.length" class="tag-input__suggestions">
      <span class="tag-input__hint">Your tags:</span>
      <button
        v-for="s in offered"
        :key="s.tag"
        type="button"
        class="tag-input__suggestion"
        :aria-label="`Add tag ${s.tag}`"
        @click="add(s.tag)"
      >
        {{ s.tag }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.tag-input__field {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  height: auto;
  min-height: 2.5em;
  padding: 0.3rem 0.5rem;
}

.tag-input__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.1rem 0.25rem 0.1rem 0.55rem;
  border: 1px solid var(--myst-hair-gold);
  border-radius: 6px;
  background: color-mix(in srgb, var(--myst-gold) 10%, transparent);
  color: var(--myst-gold-bright);
  font-size: 0.82rem;
  line-height: 1.5;
}

.tag-input__chip[data-state='active'] {
  border-color: var(--myst-gold);
}

.tag-input__remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.2rem;
  height: 1.2rem;
  padding: 0;
  border: 0;
  border-radius: 4px;
  background: none;
  color: var(--myst-text-muted);
  font-size: 0.75rem;
  cursor: pointer;
}

.tag-input__remove:hover {
  color: var(--myst-text-strong);
}

.tag-input__text {
  flex: 1 1 8rem;
  min-width: 6rem;
  border: 0;
  outline: none;
  background: none;
  color: var(--myst-text);
  font: inherit;
}

.tag-input__suggestions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.45rem;
}

.tag-input__hint {
  color: var(--myst-text-muted);
  font-size: 0.8rem;
}

.tag-input__suggestion {
  padding: 0.1rem 0.55rem;
  border: 1px dashed var(--myst-border-strong);
  border-radius: 6px;
  background: none;
  color: var(--myst-text-soft);
  font-size: 0.8rem;
  cursor: pointer;
}

.tag-input__suggestion:hover,
.tag-input__suggestion:focus-visible {
  border-color: var(--myst-gold);
  color: var(--myst-gold-bright);
}
</style>
