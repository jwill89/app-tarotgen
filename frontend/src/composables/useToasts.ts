import { ref, type Ref } from 'vue'

export type ToastType = 'success' | 'error' | 'warning' | 'info'

interface Toast {
  id: number
  type: ToastType
  message: string
  /** Resolved auto-dismiss duration in ms (0 = persist). Reka Toast owns the timer. */
  duration: number
}

export interface ToastOptions {
  /** Milliseconds before auto-dismiss. 0 disables auto-dismiss. */
  duration?: number
  /**
   * Extra context logged to the console alongside the toast (errors/warnings
   * only) — e.g. the raw Error or response body — so nothing is lost if the
   * toast is missed or auto-dismisses.
   */
  detail?: unknown
}

// Module-level queue shared by every caller and the single <ToastContainer>.
const toasts: Ref<Toast[]> = ref([])
let nextId = 1

// Sensible defaults: transient confirmations vanish quickly; errors linger.
const DEFAULT_DURATION: Record<ToastType, number> = {
  success: 4000,
  info: 4000,
  warning: 6000,
  error: 8000,
}

function dismiss(id: number): void {
  toasts.value = toasts.value.filter((t) => t.id !== id)
}

function push(type: ToastType, message: string, options: ToastOptions = {}): number {
  // Mirror problems to the console so they're recoverable after the toast goes.
  if (type === 'error') {
    console.error('[toast] ' + message, options.detail ?? '')
  } else if (type === 'warning') {
    console.warn('[toast] ' + message, options.detail ?? '')
  }

  const id = nextId++
  // Reka's <ToastRoot> owns the auto-dismiss timer (with pause-on-hover), so we
  // just resolve the duration here and hand it to the rendered toast.
  const duration = options.duration ?? DEFAULT_DURATION[type]
  toasts.value = [...toasts.value, { id, type, message, duration }]

  return id
}

export function useToasts() {
  return {
    toasts,
    dismiss,
    success: (message: string, options?: ToastOptions) => push('success', message, options),
    error: (message: string, options?: ToastOptions) => push('error', message, options),
    warning: (message: string, options?: ToastOptions) => push('warning', message, options),
    info: (message: string, options?: ToastOptions) => push('info', message, options),
  }
}
