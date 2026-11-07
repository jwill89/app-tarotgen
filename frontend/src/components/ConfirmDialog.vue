<script setup lang="ts">
/**
 * App-level confirmation dialog, built on Reka UI's AlertDialog primitive.
 *
 * AlertDialog (vs a plain Dialog) is the correct semantics for a "confirm before
 * proceeding" prompt: it renders role="alertdialog", traps focus, defaults focus
 * to the Cancel action, and does NOT dismiss on an outside click — the user must
 * make an explicit choice (Cancel/Escape or Confirm). A single instance is mounted
 * once in App.vue; callers just `await confirm({...})` via useConfirm().
 * Styles are the shared .myst-modal* rules in style.css (Reka portals to <body>).
 */
import {
  AlertDialogRoot,
  AlertDialogPortal,
  AlertDialogOverlay,
  AlertDialogContent,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogCancel,
  AlertDialogAction,
} from 'reka-ui'
import { watch } from 'vue'
import { useConfirm } from '@/composables/useConfirm'

// Single shared instance: reads the queued request from useConfirm() and
// resolves the awaiting caller on the user's choice.
const { state, settle } = useConfirm()

// The user's choice for the current prompt. Defaults to false, so Escape (or any
// other close) resolves as "cancel". The Cancel/Action buttons set it on click.
let choice = false

function choose(value: boolean): void {
  choice = value
}

// Reset per prompt so a prior confirmation's choice can't leak into an Escape
// dismissal of the next one.
watch(
  () => state.value.active,
  (active) => {
    if (active) {
      choice = false
    }
  },
)

// Reka's internal close handler and a button's @click both fire during the same
// click event, in an order we don't control — settling immediately here let the
// close-driven `false` beat the Action button's `true` (so confirm() always
// resolved false). Deferring to a microtask reads `choice` after BOTH have run.
function onOpenChange(open: boolean): void {
  if (!open) {
    queueMicrotask(() => settle(choice))
  }
}
</script>

<template>
  <AlertDialogRoot :open="state.active" @update:open="onOpenChange">
    <AlertDialogPortal>
      <AlertDialogOverlay class="myst-modal-overlay" />
      <AlertDialogContent class="myst-modal myst-confirm">
        <header class="myst-modal-head">
          <AlertDialogTitle class="myst-modal-title">{{ state.title }}</AlertDialogTitle>
        </header>
        <div class="myst-modal-body">
          <AlertDialogDescription class="myst-confirm-message">
            {{ state.message }}
          </AlertDialogDescription>
        </div>
        <footer class="myst-modal-foot">
          <AlertDialogCancel class="button" type="button" @click="choose(false)">
            {{ state.cancelLabel }}
          </AlertDialogCancel>
          <AlertDialogAction
            class="button"
            type="button"
            :class="state.danger ? 'is-danger' : 'is-primary'"
            @click="choose(true)"
          >
            {{ state.confirmLabel }}
          </AlertDialogAction>
        </footer>
      </AlertDialogContent>
    </AlertDialogPortal>
  </AlertDialogRoot>
</template>

<style scoped>
.myst-confirm {
  max-width: 26rem;
}

.myst-confirm-message {
  margin: 0;
  color: var(--myst-text-muted);
}
</style>
