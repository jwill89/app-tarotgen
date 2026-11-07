<script setup lang="ts">
/**
 * Edit one of the reader's own readings: title, the journal fields (question,
 * who it was for, tags), notes, who can open it, and the hidden-name flag. Used
 * by My Readings and by the owner on the reading page. Open while `reading` is
 * set; `saved` hands back the reading as stored, `close` asks to be closed.
 */
import { byPrefixAndName } from '@/fontawesome'
import { reactive, ref, useId, watch } from 'vue'
import { useAccount } from '@/composables/useAccount'
import { useReadingTags } from '@/composables/useReadingTags'
import BaseModal from '@/components/BaseModal.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import ReadingPrivacyField from '@/components/ReadingPrivacyField.vue'
import TagInput from '@/components/TagInput.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import type { AccountReading, Visibility } from '@/types'

/** What the dialog edits: the fields of a reading its owner can change. */
type EditableReading = Pick<
  AccountReading,
  | 'reading_id'
  | 'reading_name'
  | 'reading_notes'
  | 'question'
  | 'querent'
  | 'tags'
  | 'visibility'
  | 'hide_user'
>

const props = defineProps<{ reading: EditableReading | null }>()
const emit = defineEmits<{ close: []; saved: [reading: AccountReading] }>()

const { updateReading } = useAccount()
const { tags: myTags, load: loadTags } = useReadingTags()

const ids = { name: useId(), question: useId(), querent: useId(), tags: useId(), notes: useId() }
const passwordId = useId()

const form = reactive<{
  reading_name: string
  question: string
  querent: string
  tags: string[]
  reading_notes: string
  visibility: Visibility
  password: string
  hide_user: boolean
}>({
  reading_name: '',
  question: '',
  querent: '',
  tags: [],
  reading_notes: '',
  visibility: 'link',
  password: '',
  hide_user: false,
})
const saving = ref(false)
const errorMsg = ref('')

// Fill the form from the reading each time the dialog opens.
watch(
  () => props.reading,
  (r) => {
    if (!r) {
      return
    }
    form.reading_name = r.reading_name ?? ''
    form.question = r.question ?? ''
    form.querent = r.querent ?? ''
    form.tags = [...r.tags]
    form.reading_notes = r.reading_notes ?? ''
    form.visibility = r.visibility
    form.password = ''
    form.hide_user = r.hide_user
    errorMsg.value = ''
  },
  { immediate: true },
)

async function save(): Promise<void> {
  if (!props.reading) {
    return
  }
  saving.value = true
  errorMsg.value = ''
  const res = await updateReading(props.reading.reading_id, {
    reading_name: form.reading_name.trim(),
    question: form.question.trim(),
    querent: form.querent.trim(),
    tags: form.tags,
    reading_notes: form.reading_notes,
    hide_user: form.hide_user,
    visibility: form.visibility,
    // Blank keeps the password a reading already has (and is refused without one).
    ...(form.visibility === 'password' && form.password !== '' ? { password: form.password } : {}),
  })
  saving.value = false
  if (res.ok && res.reading) {
    emit('saved', res.reading)
  } else {
    errorMsg.value = res.error ?? 'Update failed.'
  }
}
</script>

<template>
  <BaseModal
    :active="reading !== null"
    title="Edit Reading"
    max-width="680px"
    @close="emit('close')"
  >
    <div v-if="errorMsg" class="notification is-danger is-light">{{ errorMsg }}</div>

    <div class="field">
      <label class="label" :for="ids.name">Reading Title</label>
      <input
        :id="ids.name"
        v-model="form.reading_name"
        class="input"
        maxlength="100"
        placeholder="Leave blank for the default title"
      />
    </div>

    <div class="field">
      <label class="label" :for="ids.question">Question</label>
      <input
        :id="ids.question"
        v-model="form.question"
        class="input"
        maxlength="500"
        placeholder="What was asked"
      />
    </div>

    <div class="field">
      <label class="label" :for="ids.querent">For</label>
      <input
        :id="ids.querent"
        v-model="form.querent"
        class="input"
        maxlength="100"
        placeholder="Who it was for"
      />
      <p class="help">
        <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']" /></span>
        Only you see the question and who it was for.
      </p>
    </div>

    <div class="field">
      <label class="label" :for="ids.tags">Tags</label>
      <TagInput
        :id="ids.tags"
        v-model="form.tags"
        :suggestions="myTags"
        @want-suggestions="loadTags"
      />
    </div>

    <div class="field">
      <span :id="ids.notes" class="label"
        >Reading Notes
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></span
      >
      <MarkdownEditor
        v-model="form.reading_notes"
        :labelledby="ids.notes"
        placeholder="Write a detailed interpretation… Markdown is supported."
      />
    </div>

    <ReadingPrivacyField
      v-model="form.visibility"
      v-model:password="form.password"
      :password-id="passwordId"
      :has-password="reading?.visibility === 'password'"
    />

    <div class="field">
      <ToggleSwitch v-model="form.hide_user">Hide my display name on this reading</ToggleSwitch>
    </div>

    <template #footer>
      <button type="button" class="button" @click="emit('close')">Cancel</button>
      <button
        type="button"
        class="button is-success"
        :class="{ 'is-loading': saving }"
        @click="save"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
        <span>Save</span>
      </button>
    </template>
  </BaseModal>
</template>
