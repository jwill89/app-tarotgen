import { ref, type Ref } from 'vue'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'

/**
 * Tracks the visitor's recently generated/viewed readings in localStorage so
 * they can find their way back to a reading whose code they didn't save. This
 * is purely a client-side convenience — nothing is sent to the server.
 */
export interface RecentReading {
  id: string
  deckName: string
  /** Spread name, or a fallback like "3 cards". */
  summary: string
  /** The reading's own date (server timestamp); the list is sorted by this. */
  at: string
}

const MAX_ENTRIES = 12

/** Normalise the timestamp for chronological string comparison (T or space). */
function sortKey(r: RecentReading): string {
  return (r.at || '').replace('T', ' ')
}

/** Newest reading date first. */
function byDateDesc(a: RecentReading, b: RecentReading): number {
  return sortKey(b).localeCompare(sortKey(a))
}

function load(): RecentReading[] {
  // Untrusted localStorage JSON — treat as `unknown` and validate each entry.
  const raw = local.get<unknown>(STORAGE_KEYS.recentReadings, [])
  // Defend against malformed/old data shapes.
  if (!Array.isArray(raw)) {
    return []
  }
  return raw.filter((r) => r && typeof r.id === 'string' && r.id !== '').sort(byDateDesc)
}

const recent: Ref<RecentReading[]> = ref(load())

function persist(): void {
  local.set(STORAGE_KEYS.recentReadings, recent.value)
}

export function useRecentReadings() {
  /** Add/refresh a reading, de-duplicated, sorted by reading date, and capped. */
  function record(entry: RecentReading): void {
    if (!entry.id) {
      return
    }
    const without = recent.value.filter((r) => r.id !== entry.id)
    recent.value = [entry, ...without].sort(byDateDesc).slice(0, MAX_ENTRIES)
    persist()
  }

  function remove(id: string): void {
    recent.value = recent.value.filter((r) => r.id !== id)
    persist()
  }

  function clear(): void {
    recent.value = []
    persist()
  }

  return { recent, record, remove, clear }
}
