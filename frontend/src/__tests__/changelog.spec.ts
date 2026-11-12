import { describe, it, expect } from 'vitest'
import { releases } from 'virtual:changelog'
import { parseChangelog } from '@/utils/changelog'
import { APP_VERSION } from '@/constants'

/** A CHANGELOG.md in the repo's own form: title and intro, sections, compare links. */
const changelog = [
  '# Changelog',
  '',
  'All notable changes to **TarotGen.io** are documented in this file.',
  '',
  '## [Unreleased]',
  '',
  '### Fixed',
  '',
  "- Saving a deck system kept another admin's approval.",
  '',
  '## [5.2.0] — 2026-10-31',
  '',
  'A journal you can search.',
  '',
  '> **Before deploying the backend, run both migrations.**',
  '',
  '### Added',
  '',
  '- **Journal fields.** Question, querent and tags.',
  '',
  '#### FFXIV plugin',
  '',
  '- Spread pickers group by category.',
  '',
  '## [5.1.0] - 2026-10-30',
  '',
  '### Changed',
  '',
  '- A hyphen before the date, as Keep a Changelog writes it.',
  '',
  '[Unreleased]: https://github.com/jwill89/app-tarotgen/compare/v5.2.0...HEAD',
  '[5.2.0]: https://github.com/jwill89/app-tarotgen/compare/v5.1.0...v5.2.0',
  '[5.1.0]: https://github.com/jwill89/app-tarotgen/compare/v5.0.0...v5.1.0',
  '',
].join('\n')

const headings = (md: string) => parseChangelog(md).map(({ version, date }) => [version, date])
const notes = (md: string, version: string | null) =>
  parseChangelog(md).find((release) => release.version === version)?.body

describe('parseChangelog', () => {
  it('reads each section as a release, newest first, with its version and date', () => {
    expect(headings(changelog)).toEqual([
      [null, ''],
      ['5.2.0', '2026-10-31'],
      ['5.1.0', '2026-10-30'],
    ])
  })

  it("folds each section's notes into its own release, its headings a level down", () => {
    expect(notes(changelog, '5.2.0')).toBe(
      [
        'A journal you can search.',
        '',
        '> **Before deploying the backend, run both migrations.**',
        '',
        '#### Added',
        '',
        '- **Journal fields.** Question, querent and tags.',
        '',
        '##### FFXIV plugin',
        '',
        '- Spread pickers group by category.',
      ].join('\n'),
    )
    // The last section stops at the compare links, which are left out.
    expect(notes(changelog, '5.1.0')).toBe(
      '#### Changed\n\n- A hyphen before the date, as Keep a Changelog writes it.',
    )
  })

  it("leaves out the file's own title and intro", () => {
    const parsed = JSON.stringify(parseChangelog(changelog))

    expect(parsed).not.toContain('# Changelog')
    expect(parsed).not.toContain('All notable changes')
    expect(parseChangelog('# Changelog\n\nNothing released yet.\n')).toEqual([])
  })

  it('leaves out link reference lines wherever they are', () => {
    const md = [
      '## [1.0.0] — 2025-04-08',
      '',
      'Initial release, [compared][1.0.0].',
      '[1.0.0]: https://github.com/jwill89/app-tarotgen/releases/tag/v1.0.0',
      '   [docs]: <https://example.test/docs> "Docs"',
      '- Generator.',
    ].join('\n')

    expect(notes(md, '1.0.0')).toBe('Initial release, [compared][1.0.0].\n- Generator.')
  })

  it('shows the unreleased changes as a release without a version', () => {
    expect(parseChangelog(changelog)[0]).toEqual({
      version: null,
      date: '',
      body: "#### Fixed\n\n- Saving a deck system kept another admin's approval.",
    })
  })

  it('leaves out an [Unreleased] section that lists nothing', () => {
    const md = [
      '## [Unreleased]',
      '',
      '### Added',
      '',
      '### Fixed',
      '',
      '## [unreleased]',
      '',
      '## [5.2.0] — 2026-10-31',
      '',
      '- Released.',
    ].join('\n')

    expect(headings(md)).toEqual([['5.2.0', '2026-10-31']])
  })

  it('keeps a release with no date, and one that lists nothing', () => {
    expect(parseChangelog('## [0.1.0]\n\n## [0.0.1] — 2025-01-01\n')).toEqual([
      { version: '0.1.0', date: '', body: '' },
      { version: '0.0.1', date: '2025-01-01', body: '' },
    ])
  })

  it('ends a section at a level-2 heading that is not a release, and skips what it holds', () => {
    const md = [
      '## [5.2.0] — 2026-10-31',
      '- Released.',
      '## How to read this file',
      '- Not a release.',
      '## [5.1.0] — 2026-10-30',
      '- Released before.',
    ].join('\n')

    expect(parseChangelog(md)).toEqual([
      { version: '5.2.0', date: '2026-10-31', body: '- Released.' },
      { version: '5.1.0', date: '2026-10-30', body: '- Released before.' },
    ])
  })

  it('leaves fenced code as written', () => {
    const fenced = [
      '```sh',
      '## [9.9.9] is not a release in here',
      '### nor is this a heading to move',
      '[link]: https://example.test stays too',
      '~~~',
      '```',
      '',
      '~~~~',
      '## [8.8.8] — still code',
      '~~~~',
    ]
    const md = ['## [5.2.0] — 2026-10-31', '', ...fenced, '', '### Added', '- After.'].join('\n')

    expect(headings(md)).toEqual([['5.2.0', '2026-10-31']])
    expect(notes(md, '5.2.0')).toBe([...fenced, '', '#### Added', '- After.'].join('\n'))
  })

  it('reads a file saved with Windows line endings the same', () => {
    expect(parseChangelog(changelog.replaceAll('\n', '\r\n'))).toEqual(parseChangelog(changelog))
  })
})

describe('virtual:changelog', () => {
  // The real CHANGELOG.md, through the Vite plugin (config/changelog-plugin.ts)
  // that the build and these specs both load.
  const released = releases.flatMap(({ version, date }) =>
    version === null ? [] : [{ version, date }],
  )

  it('serves the repo root CHANGELOG.md, newest first, back to the first release', () => {
    expect(released.length).toBeGreaterThan(1)
    for (const { version, date } of released) {
      expect(version).toMatch(/^\d+\.\d+\.\d+$/)
      expect(date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    }
    const newestFirst = released.every(
      ({ version }, i) =>
        i === 0 || released[i - 1]!.version.localeCompare(version, 'en', { numeric: true }) > 0,
    )
    expect(newestFirst).toBe(true)
    expect(released.at(-1)?.version).toBe('1.0.0')
  })

  it('has the notes for the version the dashboard shows', () => {
    expect(released.map(({ version }) => version)).toContain(APP_VERSION)
  })

  it('carries no link reference lines and no file title', () => {
    for (const { body } of releases) {
      expect(body).not.toMatch(/^ {0,3}\[[^\]]+\]:\s/m)
      expect(body).not.toMatch(/^# /m)
    }
  })
})
