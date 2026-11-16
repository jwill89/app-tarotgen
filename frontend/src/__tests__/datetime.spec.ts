import { describe, it, expect } from 'vitest'
import { formatDate, formatDateTime, parseTimestamp } from '@/utils/datetime'

// The suite pins TZ=UTC (see vitest.setup.ts), so local-time output is stable.
describe('parseTimestamp', () => {
  it('reads the API form, RFC 3339 in UTC', () => {
    expect(parseTimestamp('2026-11-07T09:18:09Z')?.toISOString()).toBe('2026-11-07T09:18:09.000Z')
  })

  it('reads a zone-less timestamp as UTC, not local time', () => {
    expect(parseTimestamp('2026-11-07 09:18:09')?.toISOString()).toBe('2026-11-07T09:18:09.000Z')
  })

  it('honours an explicit offset', () => {
    expect(parseTimestamp('2026-11-07T11:18:09+02:00')?.toISOString()).toBe(
      '2026-11-07T09:18:09.000Z',
    )
  })

  it('returns null for an empty or unreadable value', () => {
    expect(parseTimestamp('')).toBeNull()
    expect(parseTimestamp(null)).toBeNull()
    expect(parseTimestamp(undefined)).toBeNull()
    expect(parseTimestamp('not a date')).toBeNull()
  })
})

describe('formatDateTime', () => {
  it('returns an empty string for empty input', () => {
    expect(formatDateTime('')).toBe('')
    expect(formatDateTime(null)).toBe('')
    expect(formatDateTime(undefined)).toBe('')
  })

  it('returns the raw value when it cannot be parsed', () => {
    expect(formatDateTime('not a date')).toBe('not a date')
  })

  it('formats the API form, and the zone-less form as the same instant', () => {
    const utc = formatDateTime('2025-06-10T23:30:00Z')
    expect(utc).toBe(`2025-06-10 ${new Date(Date.UTC(2025, 5, 10, 23, 30)).toLocaleTimeString()}`)
    expect(formatDateTime('2025-06-10 23:30:00')).toBe(utc)
    expect(formatDateTime('2025-06-10T23:30:00+00:00')).toBe(utc)
  })

  it('zero-pads single-digit months and days', () => {
    expect(formatDateTime('2025-03-05T08:00:00Z')).toMatch(/^2025-03-05 /)
  })
})

describe('formatDate', () => {
  it('formats the local date alone', () => {
    expect(formatDate('2025-06-10T23:30:00Z')).toBe(
      new Date(Date.UTC(2025, 5, 10, 23, 30)).toLocaleDateString(),
    )
  })

  it('reads a zone-less timestamp as UTC', () => {
    expect(formatDate('2025-06-10 23:30:00')).toBe(formatDate('2025-06-10T23:30:00Z'))
  })

  it('returns an empty string for nothing and the raw value for something unreadable', () => {
    expect(formatDate(null)).toBe('')
    expect(formatDate('')).toBe('')
    expect(formatDate('soon')).toBe('soon')
  })
})
