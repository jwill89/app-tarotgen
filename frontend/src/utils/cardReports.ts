/**
 * The reasons a card can be reported for, and helpers for reading a report's
 * submissions. Shared by the lightbox report form and the admin Errored Cards
 * page so both describe a reason the same way.
 */
import type { CardReport, CardReportReason, CardReportSubmission } from '@/types'

/** Longest description a reporter can attach (the API truncates past this). */
export const REPORT_DETAILS_MAX_LENGTH = 500

export interface ReportReasonOption {
  value: CardReportReason
  label: string
  /** One line telling the reporter what falls under this reason. */
  hint: string
  /** Prompts for the detail an admin needs to act on this kind of problem. */
  placeholder: string
}

// Keyed by reason so adding a case to CardReportReason fails type-checking
// until it has copy here too.
const REASON_COPY: Record<CardReportReason, Omit<ReportReasonOption, 'value'>> = {
  artefact: {
    label: 'Scan artefact',
    hint: 'Dust, scratches, glare, or smudges on the image.',
    placeholder: 'Where is it? e.g. a dust speck near the top-left corner',
  },
  cropping: {
    label: 'Cropped or crooked',
    hint: 'Edges cut off, a tilted scan, or uneven borders.',
    placeholder: 'Which edge? e.g. the bottom border is cut off',
  },
  quality: {
    label: 'Poor image quality',
    hint: 'Blurry, low resolution, too dark, or washed out.',
    placeholder: 'What looks off? e.g. the whole card is blurry',
  },
  wrong_card: {
    label: 'Wrong card',
    hint: "The image doesn't match the card's name.",
    placeholder: 'Which card does the image show instead?',
  },
  other: {
    label: 'Something else',
    hint: 'Tell us what you noticed below.',
    placeholder: "Describe what's wrong with this card",
  },
}

/** Every reason, in the order the report form lists them. */
export const REPORT_REASONS: readonly ReportReasonOption[] = (
  Object.keys(REASON_COPY) as CardReportReason[]
).map((value) => ({ value, ...REASON_COPY[value] }))

const REASON_ORDER = new Map(REPORT_REASONS.map((r, i) => [r.value as string, i]))

export function isReportReason(value: unknown): value is CardReportReason {
  return typeof value === 'string' && REASON_ORDER.has(value)
}

/** Display label for a stored reason; a value this build doesn't know shows as-is. */
export function reasonLabel(reason: string): string {
  return isReportReason(reason) ? REASON_COPY[reason].label : reason
}

/** Submissions about the card's current problem (its current round). */
export function currentSubmissions(report: CardReport): CardReportSubmission[] {
  return report.submissions.filter((s) => s.round === report.round)
}

/**
 * Submissions from earlier rounds: problems an admin already resolved before the
 * card was reported again.
 */
export function earlierSubmissions(report: CardReport): CardReportSubmission[] {
  return report.submissions.filter((s) => s.round < report.round)
}

/**
 * How many reports came without a reason, such as from a plugin build that
 * doesn't send one. Those only raise the counter, not the submissions.
 */
export function reportsWithoutReason(report: CardReport): number {
  return Math.max(0, report.report_count - report.submissions.length)
}

export interface ReasonTally {
  reason: CardReportReason
  label: string
  count: number
}

/** Each reason given, with how often — most common first, ties in form order. */
export function tallyReasons(submissions: CardReportSubmission[]): ReasonTally[] {
  const counts = new Map<CardReportReason, number>()
  for (const s of submissions) {
    counts.set(s.reason, (counts.get(s.reason) ?? 0) + 1)
  }

  const order = (reason: string): number => REASON_ORDER.get(reason) ?? REASON_ORDER.size

  return [...counts]
    .map(([reason, count]) => ({ reason, label: reasonLabel(reason), count }))
    .sort((a, b) => b.count - a.count || order(a.reason) - order(b.reason))
}
