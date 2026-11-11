<script setup lang="ts">
/**
 * Quiet, tooltip-labelled icon button for data-table row actions, in place of
 * clusters of saturated per-intent text buttons that would make the admin
 * tables read as a rainbow. Calm at rest; the intent colour only appears on
 * hover (styling lives in the global .myst-iconbtn rules).
 *
 *   <IconButton :icon="byPrefixAndName.fas['pen-to-square']" label="Edit" @click="edit(row)" />
 *   <IconButton :icon="byPrefixAndName.fas['trash']" label="Delete" intent="danger" @click="del(row)" />
 *
 * The label is both the tooltip text and the accessible name, so icon-only
 * actions stay screen-reader friendly. Requires a <TooltipProvider> ancestor
 * (mounted once at the app root).
 */
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import Tooltip from '@/components/Tooltip.vue'

withDefaults(
  defineProps<{
    icon: IconDefinition
    label: string
    intent?: 'default' | 'primary' | 'info' | 'success' | 'warning' | 'danger' | 'gold'
    loading?: boolean
    disabled?: boolean
    side?: 'top' | 'right' | 'bottom' | 'left'
  }>(),
  { intent: 'default', side: 'top' },
)

defineEmits<{ click: [] }>()
</script>

<template>
  <Tooltip :text="label" :side="side">
    <button
      type="button"
      class="myst-iconbtn"
      :class="[`is-${intent}`, { 'is-loading': loading }]"
      :disabled="disabled || loading"
      :aria-label="label"
      @click="$emit('click')"
    >
      <span class="icon"><FontAwesomeIcon :icon="icon" /></span>
    </button>
  </Tooltip>
</template>
