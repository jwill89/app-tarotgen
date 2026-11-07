<script setup lang="ts">
/**
 * Who can open a reading (AUDIT §9.9): anyone with the link, anyone with its
 * password, or only its owner. Choosing Password asks for one; when the reading
 * already has one (`hasPassword`, editing), leaving the field blank keeps it.
 * Used when drawing (ReadingOwnerOptions) and editing (ReadingEditDialog).
 *
 *   <ReadingPrivacyField v-model="visibility" v-model:password="password" password-id="x" />
 */
import { byPrefixAndName } from '@/fontawesome'
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { ref, useId, watch } from 'vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import { visibilityHelp, visibilityLabels } from '@/utils/visibility'
import type { Visibility } from '@/types'

const props = defineProps<{
  /** The password input's id, for its `<label for>`. */
  passwordId: string
  /** The reading has a password already, which a blank field keeps. */
  hasPassword?: boolean
}>()

const visibility = defineModel<Visibility>({ required: true })
const password = defineModel<string>('password', { required: true })

const captionId = useId()
const showPassword = ref(false)

const options: { value: Visibility; label: string; icon: IconDefinition }[] = [
  { value: 'link', label: visibilityLabels.link, icon: byPrefixAndName.fas.link },
  { value: 'password', label: visibilityLabels.password, icon: byPrefixAndName.fas.key },
  { value: 'private', label: visibilityLabels.private, icon: byPrefixAndName.fas.lock },
]

// A password typed and then abandoned for another choice isn't sent later.
watch(visibility, (now) => {
  if (now !== 'password') {
    password.value = ''
    showPassword.value = false
  }
})
</script>

<template>
  <div class="field">
    <span :id="captionId" class="label">Who Can Open It</span>
    <SegmentedControl
      v-model="visibility"
      :options="options"
      :aria-labelledby="captionId"
      class="privacy-choice"
    />
    <p class="help">{{ visibilityHelp[visibility] }}</p>
  </div>

  <div v-if="visibility === 'password'" class="field">
    <label class="label" :for="passwordId"
      >{{ props.hasPassword ? 'New Password' : 'View Password' }}
      <span v-if="props.hasPassword" class="has-text-grey is-size-7 has-text-weight-normal"
        >(optional)</span
      ></label
    >
    <input
      :id="passwordId"
      v-model="password"
      class="input"
      :type="showPassword ? 'text' : 'password'"
      autocomplete="new-password"
      :placeholder="
        props.hasPassword ? 'Leave blank to keep the current password' : 'At least 4 characters'
      "
    />
    <ToggleSwitch v-if="password" v-model="showPassword" class="mt-2">Show password</ToggleSwitch>
  </div>
</template>

<style scoped>
/* Three choices fit a dialog; on a phone they wrap rather than overflow. */
.privacy-choice {
  flex-wrap: wrap;
  max-width: 100%;
}
</style>
