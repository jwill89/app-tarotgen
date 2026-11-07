import { ref, type Ref } from 'vue'

/**
 * App-level confirmation dialog. A single <ConfirmDialog> (mounted in App.vue)
 * renders the shared modal; callers just `await confirm({...})` and branch on
 * the boolean result — no per-view modal markup or browser prompts.
 */
export interface ConfirmOptions {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  /** Style the confirm button as destructive (red). */
  danger?: boolean
}

interface ConfirmState extends Required<Omit<ConfirmOptions, 'title'>> {
  active: boolean
  title: string
}

const state: Ref<ConfirmState> = ref({
  active: false,
  title: 'Please confirm',
  message: '',
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  danger: false,
})

let resolver: ((value: boolean) => void) | null = null

export function useConfirm() {
  function confirm(options: ConfirmOptions): Promise<boolean> {
    state.value = {
      active: true,
      title: options.title ?? 'Please confirm',
      message: options.message,
      confirmLabel: options.confirmLabel ?? 'Confirm',
      cancelLabel: options.cancelLabel ?? 'Cancel',
      danger: options.danger ?? false,
    }
    return new Promise<boolean>((resolve) => {
      resolver = resolve
    })
  }

  /** Resolve the pending confirm() with the user's choice. */
  function settle(value: boolean): void {
    if (!state.value.active) {
      return
    }
    state.value = { ...state.value, active: false }
    resolver?.(value)
    resolver = null
  }

  return { state, confirm, settle }
}
