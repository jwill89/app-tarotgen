import { describe, it, expect } from 'vitest'
import {
  REPORT_REASONS,
  currentSubmissions,
  earlierSubmissions,
  isReportReason,
  reasonLabel,
  reportsWithoutReason,
  tallyReasons,
} from '@/utils/cardReports'
import type { CardReport, CardReportReason, CardReportSubmission } from '@/types'

let nextId = 1
function submission(reason: CardReportReason, round = 1, details = ''): CardReportSubmission {
  const id = nextId++
  return {
    submission_id: id,
    report_id: 1,
    round,
    reason,
    details,
    submitted_at: '2026-10-01T12:00:00Z',
  }
}

function report(overrides: Partial<CardReport> = {}): CardReport {
  return {
    report_id: 1,
    deck_id: 1,
    deck_name: 'Rider-Waite',
    card_id: 17,
    card_name: 'The Star',
    report_count: 0,
    resolved_at: null,
    first_reported_at: '2026-10-01T12:00:00Z',
    last_reported_at: '2026-10-01T12:00:00Z',
    round: 1,
    submissions: [],
    ...overrides,
  }
}

describe('REPORT_REASONS', () => {
  it('lists every reason once, with copy for each', () => {
    const values = REPORT_REASONS.map((r) => r.value)
    expect(values).toEqual(['artefact', 'cropping', 'quality', 'wrong_card', 'other'])
    for (const r of REPORT_REASONS) {
      expect(r.label).not.toBe('')
      expect(r.hint).not.toBe('')
      expect(r.placeholder).not.toBe('')
    }
  })
})

describe('isReportReason / reasonLabel', () => {
  it('recognises only known reasons', () => {
    expect(isReportReason('wrong_card')).toBe(true)
    expect(isReportReason('toString')).toBe(false)
    expect(isReportReason('')).toBe(false)
    expect(isReportReason(3)).toBe(false)
  })

  it('labels a known reason and shows an unknown one as-is', () => {
    expect(reasonLabel('artefact')).toBe('Scan artefact')
    expect(reasonLabel('smudged_aura')).toBe('smudged_aura')
  })
})

describe('current / earlier submissions', () => {
  it('splits submissions by whether they belong to the current round', () => {
    const fresh = submission('wrong_card', 2, 'Shows The Moon')
    const old = submission('artefact', 1, 'Dust, top left')
    const r = report({ round: 2, report_count: 2, submissions: [fresh, old] })

    expect(currentSubmissions(r)).toEqual([fresh])
    expect(earlierSubmissions(r)).toEqual([old])
  })

  it('treats everything as current before a card has been resolved and re-reported', () => {
    const r = report({ submissions: [submission('artefact'), submission('quality')] })
    expect(currentSubmissions(r)).toHaveLength(2)
    expect(earlierSubmissions(r)).toHaveLength(0)
  })
})

describe('reportsWithoutReason', () => {
  it('counts reports that never stored a reason', () => {
    expect(
      reportsWithoutReason(report({ report_count: 3, submissions: [submission('artefact')] })),
    ).toBe(2)
  })

  it('is zero when every report has a reason', () => {
    expect(
      reportsWithoutReason(report({ report_count: 1, submissions: [submission('artefact')] })),
    ).toBe(0)
  })
})

describe('tallyReasons', () => {
  it('counts each reason, most common first, ties in form order', () => {
    const tally = tallyReasons([
      submission('other'),
      submission('quality'),
      submission('artefact'),
      submission('quality'),
    ])

    expect(tally).toEqual([
      { reason: 'quality', label: 'Poor image quality', count: 2 },
      { reason: 'artefact', label: 'Scan artefact', count: 1 },
      { reason: 'other', label: 'Something else', count: 1 },
    ])
  })

  it('is empty for no submissions', () => {
    expect(tallyReasons([])).toEqual([])
  })
})
