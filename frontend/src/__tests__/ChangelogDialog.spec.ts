import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import ChangelogDialog from '@/components/admin/ChangelogDialog.vue'
import type { ChangelogRelease } from '@/utils/changelog'
import { mountWithApp, cleanup } from './helpers/app'
import { button, click, dialog } from './helpers/page'

// The notes the build plugin would bake in; each test sets its own.
const releases = vi.hoisted((): ChangelogRelease[] => [])
vi.mock('virtual:changelog', () => ({ releases }))

const unreleased: ChangelogRelease = {
  version: null,
  date: '',
  body: "#### Fixed\n\n- Saving a deck system kept another admin's approval.",
}
const latest: ChangelogRelease = {
  version: '5.2.0',
  date: '2026-10-31',
  body: 'A journal you can search.\n\n#### Added\n\n- **Journal fields.** Question, querent and tags.',
}
const older: ChangelogRelease = {
  version: '5.1.0',
  date: '2026-10-30',
  body: '#### Added\n\n- **Daily card.** One card a day.',
}

async function open(...list: ChangelogRelease[]) {
  releases.splice(0, releases.length, ...list)
  const { wrapper } = await mountWithApp(ChangelogDialog)
  return wrapper
}

const changelog = () => dialog('Changelog')

/** Each release's fold, as its heading reads and whether it is open. */
function folds(): [string, boolean][] {
  return [...(changelog()?.querySelectorAll('h3 button') ?? [])].map((trigger) => [
    trigger.textContent.replace(/\s+/g, ' ').trim(),
    trigger.getAttribute('aria-expanded') === 'true',
  ])
}

/** The rendered notes of the open releases. */
const notes = () => [...(changelog()?.querySelectorAll('.changelog-notes') ?? [])]

describe('ChangelogDialog', () => {
  beforeEach(() => {
    releases.length = 0
  })
  afterEach(cleanup)

  it('lists every release, newest first: the newest open, the rest folded', async () => {
    await open(latest, older)

    expect(folds()).toEqual([
      ['v5.2.0, released 2026-10-31', true],
      ['v5.1.0, released 2026-10-30', false],
    ])
    expect(notes()).toHaveLength(1)
    expect(notes().at(0)?.textContent).toContain('A journal you can search.')
    expect(changelog()?.textContent).not.toContain('Daily card')
    // Nothing waits to be released, so it says nothing of it.
    expect(changelog()?.textContent).not.toContain('Unreleased')
  })

  it('unfolds a folded release and folds an open one', async () => {
    await open(latest, older)

    await click(button('v5.1.0, released 2026-10-30'))
    expect(folds()).toEqual([
      ['v5.2.0, released 2026-10-31', true],
      ['v5.1.0, released 2026-10-30', true],
    ])
    expect(changelog()?.textContent).toContain('One card a day.')

    await click(button('v5.2.0, released 2026-10-31'))
    expect(folds()).toEqual([
      ['v5.2.0, released 2026-10-31', false],
      ['v5.1.0, released 2026-10-30', true],
    ])
    expect(changelog()?.textContent).not.toContain('A journal you can search.')
  })

  it('shows changes not yet released first, as Unreleased, and open', async () => {
    await open(unreleased, latest, older)

    expect(folds()).toEqual([
      ['Unreleased', true],
      ['v5.2.0, released 2026-10-31', false],
      ['v5.1.0, released 2026-10-30', false],
    ])
    expect(notes().at(0)?.textContent).toContain(
      "Saving a deck system kept another admin's approval.",
    )
  })

  it('renders the notes as markdown, each list under its label', async () => {
    await open(latest)

    const rendered = notes().at(0)
    expect(rendered?.querySelector('p')?.textContent).toBe('A journal you can search.')
    // A level below the release's own heading (h3).
    expect(rendered?.querySelector('h4')?.textContent).toBe('Added')
    expect(rendered?.querySelector('li strong')?.textContent).toBe('Journal fields.')
  })

  it('sanitises the notes', async () => {
    await open({
      version: '0.0.1',
      date: '',
      body: 'Hi <img src="x" onerror="alert(1)"><script>alert(2)</script> [link](javascript:alert(3))',
    })

    const rendered = notes().at(0)
    expect(rendered?.querySelector('img')).not.toBeNull()
    expect(rendered?.innerHTML).not.toMatch(/<script|onerror|javascript:/)
    // A release without a date shows its version alone.
    expect(folds()).toEqual([['v0.0.1', true]])
  })

  it('says so when there are no releases', async () => {
    await open()

    expect(changelog()?.textContent).toContain('No releases yet.')
    expect(folds()).toEqual([])
  })

  it('asks to close', async () => {
    const wrapper = await open(latest)

    await click(button('Close'))

    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
