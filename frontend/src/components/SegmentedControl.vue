<script setup lang="ts" generic="T extends string">
/**
 * Segmented single-select control built on Reka UI's ToggleGroup. Same look as
 * the old hand-rolled .myst-segmented buttons, but with roving-tabindex arrow-key
 * navigation and proper aria (role=group + aria-pressed). Always keeps one option
 * selected — clicking the active option is a no-op (unlike a bare ToggleGroup,
 * which would deselect it). `T` (the value union) is inferred from the v-model.
 *
 *   <SegmentedControl v-model="mode" :options="[{ value: 'a', label: 'A', icon }]" />
 */
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { ToggleGroupRoot, ToggleGroupItem } from 'reka-ui'

interface SegmentOption {
  value: T
  label: string
  icon?: IconDefinition
}

const props = defineProps<{
  options: SegmentOption[]
  ariaLabel?: string
}>()

const model = defineModel<T>({ required: true })

// ToggleGroup type="single" allows deselect (value → ''); a segmented control
// must always keep a value, so take only an update naming one of the options.
function onUpdate(value: unknown): void {
  const option = props.options.find((o) => o.value === value)
  if (option) {
    model.value = option.value
  }
}
</script>

<template>
  <ToggleGroupRoot
    type="single"
    :model-value="model"
    :aria-label="ariaLabel"
    class="myst-segmented"
    @update:model-value="onUpdate"
  >
    <ToggleGroupItem
      v-for="opt in options"
      :key="opt.value"
      :value="opt.value"
      type="button"
      class="myst-segmented__btn"
    >
      <span v-if="opt.icon" class="icon"><FontAwesomeIcon :icon="opt.icon" /></span>
      <span>{{ opt.label }}</span>
    </ToggleGroupItem>
  </ToggleGroupRoot>
</template>
