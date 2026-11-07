/**
 * Local calendar days as the daily card keeps them: "YYYY-MM-DD" (a day) and
 * "YYYY-MM" (a month), in the browser's own time zone. The server stores the
 * day the browser says it is, so these never pass through UTC.
 */
import type { DailyCard, KeptDailyCard } from '@/types'

const pad = (n: number) => String(n).padStart(2, '0')

/** The local date of `date` (default: now), as "YYYY-MM-DD". */
export function localDay(date: Date = new Date()): string {
  return `${String(date.getFullYear())}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** A day's or month's parts as numbers (month 1–12). */
function parts(value: string): [number, number, number] {
  const [y = 0, m = 1, d = 1] = value.split('-').map(Number)
  return [y, m, d]
}

/** A local Date at midnight on a "YYYY-MM-DD" day (or the 1st of a "YYYY-MM" month). */
export function dateOf(value: string): Date {
  const [y, m, d] = parts(value)
  return new Date(y, m - 1, d)
}

/** The month `by` months from "YYYY-MM". */
export function shiftMonth(month: string, by: number): string {
  const [y, m] = parts(month)
  const date = new Date(y, m - 1 + by, 1)
  return `${String(date.getFullYear())}-${pad(date.getMonth() + 1)}`
}

/**
 * A month laid out as calendar weeks, Sunday first: each week seven cells, a
 * day ("YYYY-MM-DD") or null for the days of the months either side.
 */
export function monthWeeks(month: string): (string | null)[][] {
  const [y, m] = parts(month)
  const lead = new Date(y, m - 1, 1).getDay()
  const length = new Date(y, m, 0).getDate()

  const cells: (string | null)[] = Array.from({ length: lead }, () => null)
  for (let d = 1; d <= length; d++) {
    cells.push(`${String(y)}-${pad(m)}-${pad(d)}`)
  }
  while (cells.length % 7 !== 0) {
    cells.push(null)
  }

  const weeks: (string | null)[][] = []
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7))
  }
  return weeks
}

/** Whether a daily card's reading is still there: the API nulls it and its card together. */
export function isKept(card: DailyCard): card is KeptDailyCard {
  return card.reading_id !== null
}

/**
 * One day of the calendar: not drawn, drawn (its card opens its reading), or
 * drawn and its reading deleted since — still drawn, so no new card that day,
 * but nothing left to open.
 */
export type CalendarDay =
  | { day: string; status: 'empty' }
  | { day: string; status: 'drawn'; card: KeptDailyCard }
  | { day: string; status: 'deleted' }

/** A month's weeks (as `monthWeeks`), each day with its daily card from `cards`. */
export function monthCalendar(month: string, cards: DailyCard[]): (CalendarDay | null)[][] {
  const byDay = new Map(cards.map((c) => [c.day, c]))
  return monthWeeks(month).map((week) =>
    week.map((day): CalendarDay | null => {
      if (day === null) {
        return null
      }
      const card = byDay.get(day)
      if (!card) {
        return { day, status: 'empty' }
      }
      return isKept(card) ? { day, status: 'drawn', card } : { day, status: 'deleted' }
    }),
  )
}
