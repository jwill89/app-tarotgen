import { onBeforeUnmount, onMounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useConfirm } from './useConfirm'

/**
 * Ask before a page with unsaved work is left: inside the app (a link, Back,
 * Cancel) with the shared confirm dialog, and on a reload, a closed tab or
 * another site with the browser's own prompt. `isDirty` says whether there is
 * anything to lose; a page that has just saved makes it false before it
 * navigates, so a save never asks.
 */
export function useUnsavedChanges(isDirty: () => boolean, message: string): void {
  const { confirm } = useConfirm()

  onBeforeRouteLeave(async () => {
    if (!isDirty()) {
      return true
    }
    return confirm({
      title: 'Unsaved changes',
      message,
      confirmLabel: 'Discard Changes',
      cancelLabel: 'Keep Editing',
      danger: true,
    })
  })

  // The browser shows its own wording; preventDefault is all it needs.
  function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (isDirty()) {
      event.preventDefault()
    }
  }

  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
}
