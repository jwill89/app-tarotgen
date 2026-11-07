import { ref, computed, watch, nextTick } from 'vue'

/**
 * Undo/redo history for a reactive array of layout slots (spread / custom-reading
 * editors). Snapshots the array (JSON) on every deep change — coalesced per flush
 * — so multi-property operations (align, distribute, …) become a single step.
 *
 * Rapid streams of changes (dragging) should be wrapped in suspend()/resume():
 * call suspend() when a drag starts and resume() when it ends, so the whole drag
 * collapses into one history entry.
 */
export function useUndoRedo(slots: unknown[], onRestore?: () => void, maxEntries = 500) {
  const stack = ref<string[]>([])
  const pointer = ref(-1)

  let restoring = false
  let suspended = false

  const canUndo = computed(() => pointer.value > 0)
  const canRedo = computed(() => pointer.value < stack.value.length - 1)

  const snapshot = (): string => JSON.stringify(slots)

  function record(): void {
    if (restoring || suspended) {
      return
    }
    const snap = snapshot()
    if (stack.value[pointer.value] === snap) {
      return
    } // no actual change

    // Drop any redo branch, then append the new state.
    stack.value.splice(pointer.value + 1)
    stack.value.push(snap)
    pointer.value = stack.value.length - 1

    // Bound memory for very long sessions.
    if (stack.value.length > maxEntries) {
      stack.value.shift()
      pointer.value--
    }
  }

  function applySnapshot(snap: string): void {
    const data = JSON.parse(snap) as unknown[]
    restoring = true
    slots.splice(0, slots.length, ...data)
    onRestore?.()
    // The deep watcher fires during this flush; clear the flag afterwards.
    void nextTick(() => {
      restoring = false
    })
  }

  function undo(): void {
    const previous = stack.value[pointer.value - 1]
    if (!canUndo.value || previous === undefined) {
      return
    }
    pointer.value--
    applySnapshot(previous)
  }

  function redo(): void {
    const next = stack.value[pointer.value + 1]
    if (!canRedo.value || next === undefined) {
      return
    }
    pointer.value++
    applySnapshot(next)
  }

  /** Seed (or re-seed) the history with the current state as the only entry. */
  function reset(): void {
    stack.value = [snapshot()]
    pointer.value = 0
  }

  function suspend(): void {
    suspended = true
  }
  function resume(): void {
    suspended = false
  }

  watch(
    () => slots,
    () => record(),
    { deep: true },
  )

  return { canUndo, canRedo, undo, redo, reset, record, suspend, resume }
}
