import { describe, it, expect, beforeEach } from 'vitest'
import { useRecentReadings, type RecentReading } from '@/composables/useRecentReadings'
import { STORAGE_KEYS } from '@/constants'

const { recent, record, remove, clear } = useRecentReadings()

function entry(id: string, over: Partial<RecentReading> = {}): RecentReading {
  return { id, deckName: 'Rider-Waite', summary: '3 cards', at: '2026-01-01T00:00:00Z', ...over }
}

describe('useRecentReadings', () => {
  beforeEach(() => {
    clear()
    localStorage.clear()
  })

  it('records most-recent first', () => {
    record(entry('a'))
    record(entry('b'))
    expect(recent.value.map((r) => r.id)).toEqual(['b', 'a'])
  })

  it('de-duplicates by id and moves the entry to the front', () => {
    record(entry('a'))
    record(entry('b'))
    record(entry('a', { summary: '5 cards' }))
    expect(recent.value.map((r) => r.id)).toEqual(['a', 'b'])
    expect(recent.value[0]!.summary).toBe('5 cards')
  })

  it('caps the list at 12 entries', () => {
    for (let i = 0; i < 15; i++) {
      record(entry(`id${i}`))
    }
    expect(recent.value).toHaveLength(12)
    expect(recent.value[0]!.id).toBe('id14')
  })

  it('persists to localStorage', () => {
    record(entry('x'))
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEYS.recentReadings) ?? '[]')
    expect(raw[0].id).toBe('x')
  })

  it('removes a single entry', () => {
    record(entry('a'))
    record(entry('b'))
    remove('a')
    expect(recent.value.map((r) => r.id)).toEqual(['b'])
  })

  it('ignores entries without an id', () => {
    record(entry(''))
    expect(recent.value).toHaveLength(0)
  })
})
