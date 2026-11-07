<script setup lang="ts">
/**
 * The options a reading is drawn with, beyond the cards. A signed-in reader
 * names it, records the question and who it was for (seen only by them), tags
 * it, chooses who can open it, and may hide their name or add notes. A guest
 * can't set any of that, but may have the reading deleted sooner than the
 * usual year. `collect()` gives the fields to send with the draw.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, useId } from 'vue'
import { useUser } from '@/composables/useUser'
import { useReadingTags } from '@/composables/useReadingTags'
import BaseSelect from '@/components/BaseSelect.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import ReadingPrivacyField from '@/components/ReadingPrivacyField.vue'
import TagInput from '@/components/TagInput.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import type { Visibility } from '@/types'

const props = withDefaults(defineProps<{ notes?: boolean }>(), { notes: false })
const notesCaptionId = useId()

const { isLoggedIn } = useUser()
const { tags: myTags, load: loadTags } = useReadingTags()

/** A guest's choice of how long the reading is kept ('year': the usual retention). */
type Keep = 'year' | '30' | '7' | '1'
const keepOptions: { value: Keep; label: string }[] = [
  { value: 'year', label: 'A year (the usual)' },
  { value: '30', label: '30 days' },
  { value: '7', label: '7 days' },
  { value: '1', label: '1 day' },
]

const readingName = ref('')
const question = ref('')
const querent = ref('')
const tags = ref<string[]>([])
const visibility = ref<Visibility>('link')
const password = ref('')
const hideUser = ref(false)
const readingNotes = ref('')
const keep = ref<string | number | null>('year')

/** What a draw sends besides the cards; a form post joins a list with commas. */
type ReadingOptionFields = Record<string, string | boolean | string[]>

/**
 * The fields to send with the draw: a signed-in reader's options, or a guest's
 * expiry. Blank text fields are left out.
 */
function collect(): ReadingOptionFields {
  if (!isLoggedIn.value) {
    return keep.value === 'year' || keep.value === null
      ? {}
      : { expires_in_days: String(keep.value) }
  }

  const fields: ReadingOptionFields = { hide_user: hideUser.value, visibility: visibility.value }
  const text = {
    reading_name: readingName.value,
    question: question.value,
    querent: querent.value,
  }
  for (const [key, value] of Object.entries(text)) {
    if (value.trim() !== '') {
      fields[key] = value.trim()
    }
  }
  if (tags.value.length > 0) {
    fields.tags = tags.value
  }
  if (visibility.value === 'password') {
    fields.password = password.value
  }
  if (props.notes && readingNotes.value !== '') {
    fields.reading_notes = readingNotes.value
  }
  return fields
}

function reset(): void {
  readingName.value = ''
  question.value = ''
  querent.value = ''
  tags.value = []
  visibility.value = 'link'
  password.value = ''
  hideUser.value = false
  readingNotes.value = ''
  keep.value = 'year'
}

defineExpose({ collect, reset })
</script>

<template>
  <div v-if="isLoggedIn" class="box reading-owner-options">
    <p class="title is-5 mb-3 reading-owner-heading">
      Reading Options
      <span class="has-text-grey is-size-7 has-text-weight-normal">(you're signed in)</span>
    </p>

    <div class="field">
      <label class="label" for="ro-name"
        >Reading Title
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <input
        id="ro-name"
        v-model="readingName"
        class="input"
        maxlength="100"
        autocomplete="off"
        placeholder="e.g. Morning reflection"
      />
    </div>

    <div class="columns mb-0">
      <div class="column is-8">
        <div class="field">
          <label class="label" for="ro-question"
            >Question
            <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
          >
          <input
            id="ro-question"
            v-model="question"
            class="input"
            maxlength="500"
            autocomplete="off"
            placeholder="What was asked"
          />
        </div>
      </div>
      <div class="column is-4">
        <div class="field">
          <label class="label" for="ro-querent"
            >For
            <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
          >
          <input
            id="ro-querent"
            v-model="querent"
            class="input"
            maxlength="100"
            autocomplete="off"
            placeholder="Who it was for"
          />
        </div>
      </div>
    </div>
    <p class="help mt-0 mb-3">
      <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']" /></span>
      The question and who it was for are yours alone: nobody you share the reading with sees them.
    </p>

    <div class="field">
      <label class="label" for="ro-tags"
        >Tags <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <TagInput id="ro-tags" v-model="tags" :suggestions="myTags" @want-suggestions="loadTags" />
    </div>

    <ReadingPrivacyField
      v-model="visibility"
      v-model:password="password"
      password-id="ro-password"
    />

    <div class="field">
      <ToggleSwitch v-model="hideUser">Hide my display name on this reading</ToggleSwitch>
    </div>

    <div v-if="notes" class="field">
      <span :id="notesCaptionId" class="label"
        >Reading Notes
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></span
      >
      <MarkdownEditor
        v-model="readingNotes"
        :labelledby="notesCaptionId"
        placeholder="Write a detailed interpretation… Markdown is supported."
      />
      <p class="help">
        A longer written explanation, shown in a collapsible section on the reading.
      </p>
    </div>
  </div>

  <div v-else class="mb-4">
    <div class="field">
      <label class="label" for="ro-keep">Keep This Reading For</label>
      <BaseSelect id="ro-keep" v-model="keep" :options="keepOptions" />
      <p class="help">
        A reading made without an account is deleted after a year. Choose sooner if you like: its
        link stops working then.
      </p>
    </div>
    <p class="help">
      <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-info']" /></span>
      <router-link :to="{ name: 'login' }">Log in</router-link>
      to name your reading, record your question, add tags, or choose who can open it.
    </p>
  </div>
</template>

<style scoped>
.reading-owner-options {
  border: 1px solid var(--myst-border, rgb(255 255 255 / 12%));
}
</style>
