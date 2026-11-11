<script setup lang="ts">
/**
 * Reusable on/off toggle built on Reka UI's Switch, so no view needs its own
 * `<label class="toggle-switch">` + hidden-checkbox markup. The label text goes
 * in the default slot and stays clickable (associated to the switch via a
 * generated id).
 *
 *   <ToggleSwitch v-model="flag">Hide my display name</ToggleSwitch>
 */
import { computed, useId } from 'vue'
import { SwitchRoot, SwitchThumb } from 'reka-ui'

const props = defineProps<{
  compact?: boolean
  /** The switch's id, for a form `<label for>` elsewhere; generated otherwise. */
  id?: string
}>()
const model = defineModel<boolean>({ default: false })
const generatedId = useId()
const id = computed(() => props.id ?? generatedId)
</script>

<template>
  <div class="toggle-switch" :class="{ 'is-compact': compact }">
    <SwitchRoot :id="id" v-model="model" class="toggle-track">
      <SwitchThumb class="toggle-thumb" />
    </SwitchRoot>
    <label v-if="$slots.default" :for="id" class="toggle-state" :class="{ 'is-on': model }">
      <slot />
    </label>
  </div>
</template>
