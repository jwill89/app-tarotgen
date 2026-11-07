/**
 * Safe wrappers around Web Storage. All access is guarded so that private
 * browsing, disabled storage, or quota errors degrade gracefully instead of
 * throwing. Values are JSON-encoded.
 */

function read<T>(storage: Storage, key: string, fallback: T): T {
  try {
    const raw = storage.getItem(key)
    if (raw === null) {
      return fallback
    }
    return JSON.parse(raw) as T
  } catch {
    return fallback
  }
}

function write(storage: Storage, key: string, value: unknown): void {
  try {
    storage.setItem(key, JSON.stringify(value))
  } catch {
    // Ignore (quota exceeded, storage unavailable, etc.)
  }
}

function remove(storage: Storage, key: string): void {
  try {
    storage.removeItem(key)
  } catch {
    // Ignore
  }
}

/** Persists across browser sessions. Use for non-sensitive preferences. */
export const local = {
  get: <T>(key: string, fallback: T): T => read(localStorage, key, fallback),
  set: (key: string, value: unknown): void => write(localStorage, key, value),
  remove: (key: string): void => remove(localStorage, key),
}

/** Cleared when the browser session ends. Use for session-scoped state. */
export const session = {
  get: <T>(key: string, fallback: T): T => read(sessionStorage, key, fallback),
  set: (key: string, value: unknown): void => write(sessionStorage, key, value),
  remove: (key: string): void => remove(sessionStorage, key),
}
