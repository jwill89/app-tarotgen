<script setup lang="ts">
/**
 * Toast stack, built on Reka UI's Toast primitives. `useToasts` owns the
 * queue; Reka handles the animation, swipe-to-dismiss, and pause-on-hover
 * timing. When a toast closes (timer, swipe, or the close button) we drop it
 * from the queue after a short beat so its close animation can play. Styles are
 * global (in style.css) so they apply regardless of where Reka mounts the nodes.
 */
import { byPrefixAndName } from '@/fontawesome'
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core'
import { ToastProvider, ToastRoot, ToastTitle, ToastClose, ToastViewport } from 'reka-ui'
import { useToasts, type ToastType } from '@/composables/useToasts'

const { toasts, dismiss } = useToasts()

// Object form (byPrefixAndName), matching the rest of the app — the string
// form ('fa-circle-check') doesn't resolve with this self-hosted setup.
const ICONS: Record<ToastType, IconDefinition> = {
  success: byPrefixAndName.fas['circle-check'],
  error: byPrefixAndName.fas['circle-exclamation'],
  warning: byPrefixAndName.fas['triangle-exclamation'],
  info: byPrefixAndName.fas['circle-info'],
}

// Persisting toasts (duration 0) get an effectively-infinite Reka timer.
const PERSIST = 1_000_000_000

function onOpenChange(open: boolean, id: number): void {
  // Let the close animation finish before removing the node from the queue.
  if (!open) {
    window.setTimeout(() => dismiss(id), 160)
  }
}
</script>

<template>
  <ToastProvider swipe-direction="right">
    <ToastRoot
      v-for="toast in toasts"
      :key="toast.id"
      class="toast-item"
      :class="'toast-item--' + toast.type"
      :duration="toast.duration > 0 ? toast.duration : PERSIST"
      @update:open="(open: boolean) => onOpenChange(open, toast.id)"
    >
      <span class="toast-icon icon">
        <FontAwesomeIcon :icon="ICONS[toast.type]" />
      </span>
      <ToastTitle class="toast-message">{{ toast.message }}</ToastTitle>
      <ToastClose class="toast-close" aria-label="Dismiss">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
      </ToastClose>
    </ToastRoot>
    <ToastViewport class="toast-viewport" />
  </ToastProvider>
</template>
