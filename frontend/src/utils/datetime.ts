/**
 * Read a timestamp from the API, RFC 3339 in UTC (`2026-11-07T09:18:09Z`), as
 * a Date. A zone-less value (`2026-11-07 09:18:09`, which recent readings kept
 * in the browser may still hold) is read as UTC too. Returns null when the
 * value is empty or isn't a timestamp.
 */
export function parseTimestamp(value: string | null | undefined): Date | null {
  if (!value) {
    return null
  }

  // A zone-less value gets the UTC marker, so Date doesn't take it as local time.
  let iso = value.trim().replace(' ', 'T')
  if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(iso)) {
    iso += 'Z'
  }

  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? null : date
}

/**
 * Format an API timestamp in the browser's local time: the date as YYYY-MM-DD,
 * the time in the browser's locale format. Falls back to the raw value if it
 * can't be parsed.
 */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  const date = parseTimestamp(value)
  if (date === null) {
    return value
  }

  // Built from local components, so the day is the reader's own.
  const pad = (n: number) => String(n).padStart(2, '0')
  const datePart = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

  return `${datePart} ${date.toLocaleTimeString()}`
}

/**
 * Format an API timestamp as the local date alone, in the browser's locale
 * format. Falls back to the raw value if it can't be parsed.
 */
export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  return parseTimestamp(value)?.toLocaleDateString() ?? value
}
