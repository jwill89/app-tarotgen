/**
 * Format a UTC datetime string from the API (SQLite `CURRENT_TIMESTAMP`,
 * shaped 'YYYY-MM-DD HH:MM:SS' with no zone marker) into the browser's local
 * time. Falls back to returning the raw value if it can't be parsed.
 */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  // SQLite stores UTC without a zone suffix; normalise to ISO-8601 and mark
  // it as UTC so the Date constructor doesn't assume local time.
  let iso = value.trim().replace(' ', 'T')
  if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(iso)) {
    iso += 'Z'
  }

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return value
  }

  // Date portion in ISO-style YYYY-MM-DD (built from local components),
  // with the time portion left in the browser's locale format.
  const pad = (n: number) => String(n).padStart(2, '0')
  const datePart = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

  return `${datePart} ${date.toLocaleTimeString()}`
}
