<script setup lang="ts">
/**
 * Modal dialog, built on Reka UI's Dialog primitive.
 *
 * The public API is unchanged from the hand-rolled version, so every existing
 * call site keeps working: props `active` / `title` / `maxWidth` / `closeOnBackdrop`,
 * an emitted `close`, and default + `footer` slots. Reka now provides the focus
 * trap, focus restoration, scroll-lock, and Escape/overlay handling that used to
 * be hand-rolled (or missing). Styles live in style.css because Reka portals the
 * content to <body>, out of this component's scoped styles.
 */
import { byPrefixAndName } from '@/fontawesome'
import {
  DialogRoot,
  DialogPortal,
  DialogOverlay,
  DialogContent,
  DialogTitle,
  DialogClose,
} from 'reka-ui'

const props = withDefaults(
  defineProps<{
    active: boolean
    title?: string
    /** Max width of the panel (any CSS length). Defaults to a compact dialog. */
    maxWidth?: string
    /** Allow closing by clicking the backdrop (default true). */
    closeOnBackdrop?: boolean
    /**
     * Stack above the card lightbox, which itself sits over ordinary modals — for
     * a dialog opened from inside it (e.g. the card report form).
     */
    overLightbox?: boolean
  }>(),
  {
    title: '',
    maxWidth: '30rem',
    closeOnBackdrop: true,
    overLightbox: false,
  },
)

const emit = defineEmits<{ close: [] }>()

// Reka owns the open state internally; mirror its close requests (Escape,
// backdrop, close button) back out as the `close` event the parents expect.
function onOpenChange(open: boolean): void {
  if (!open) {
    emit('close')
  }
}

// When backdrop-dismiss is disabled, veto the outside-interaction close.
function onInteractOutside(e: Event): void {
  if (!props.closeOnBackdrop) {
    e.preventDefault()
  }
}
</script>

<template>
  <DialogRoot :open="active" @update:open="onOpenChange">
    <DialogPortal>
      <DialogOverlay class="myst-modal-overlay" :class="{ 'is-over-lightbox': overLightbox }" />
      <DialogContent
        class="myst-modal"
        :class="{ 'is-over-lightbox': overLightbox }"
        :style="{ maxWidth }"
        :aria-describedby="undefined"
        @pointer-down-outside="onInteractOutside"
        @interact-outside="onInteractOutside"
      >
        <header class="myst-modal-head">
          <DialogTitle class="myst-modal-title">{{ title }}</DialogTitle>
          <DialogClose class="myst-modal-close" aria-label="Close">
            <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
          </DialogClose>
        </header>
        <div class="myst-modal-body">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="myst-modal-foot">
          <slot name="footer" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
