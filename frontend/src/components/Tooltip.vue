<script setup lang="ts">
withDefaults(
  defineProps<{
    text: string
    side?: 'top' | 'right' | 'bottom' | 'left'
  }>(),
  { side: 'top' },
)
/* A deliberately single-word wrapper name — it reads naturally at every call site
   (<Tooltip text="…">). */
/* eslint-disable-next-line vue/multi-word-component-names */
defineOptions({ name: 'Tooltip' })
/**
 * Themed tooltip built on Reka UI's Tooltip. Wrap any focusable control to give
 * it a consistent, styled, collision-aware tooltip (use it, not a bare `title=`):
 *
 *   <Tooltip text="Undo"><button …>…</button></Tooltip>
 *
 * Requires a <TooltipProvider> ancestor — mounted once at the app root in App.vue.
 * The slot must have a single root element (it becomes the trigger). Content is
 * portaled to <body>, so its styling lives in the global .myst-tooltip* rules.
 */
import { TooltipRoot, TooltipTrigger, TooltipPortal, TooltipContent, TooltipArrow } from 'reka-ui'
</script>

<template>
  <TooltipRoot>
    <TooltipTrigger as-child>
      <slot />
    </TooltipTrigger>
    <TooltipPortal>
      <TooltipContent class="myst-tooltip" :side="side" :side-offset="6" :collision-padding="8">
        {{ text }}
        <TooltipArrow class="myst-tooltip__arrow" :width="10" :height="5" />
      </TooltipContent>
    </TooltipPortal>
  </TooltipRoot>
</template>
