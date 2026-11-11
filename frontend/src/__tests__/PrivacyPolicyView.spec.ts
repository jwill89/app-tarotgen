import { describe, it, expect, afterEach, vi } from 'vitest'
import PrivacyPolicyView from '@/views/PrivacyPolicyView.vue'
import { mountWithApp, cleanup } from './helpers/app'

/**
 * A file elsewhere in the repo. Read through Node, typed by hand: Vite won't
 * serve files outside frontend/, and the app's TypeScript has no Node types.
 */
async function repoFile(path: string): Promise<string> {
  const fs = await vi.importActual<{ readFileSync(path: string, encoding: 'utf8'): string }>(
    'node:fs',
  )
  const url = await vi.importActual<{ fileURLToPath(url: string): string }>('node:url')
  // Not `new URL(…, import.meta.url)` inline: Vite would rewrite that into an asset import.
  const here = import.meta.url
  return fs.readFileSync(url.fileURLToPath(new URL(`../../../${path}`, here).href), 'utf8')
}

const retentionPhp = await repoFile('backend/scripts/retention.php')
const readingInputPhp = await repoFile('backend/includes/Tarot/Service/ReadingInput.php')
const deployScript = await repoFile('scripts/deploy.ps1')
const sharedDeploy = await repoFile('shared/deploy/pwsh/SharedDeploy.psm1')

/** The paragraphs under a numbered heading, as one string. */
function section(heading: string): string {
  const h2 = [...document.body.querySelectorAll('h2')].find((h) => h.textContent.includes(heading))
  const parts: string[] = []
  for (let el = h2?.nextElementSibling; el && el.tagName !== 'H2'; el = el.nextElementSibling) {
    parts.push(el.textContent.replace(/\s+/g, ' '))
  }
  return parts.join(' ')
}

// The retention window is stated here and enforced by the daily jobs; change
// both together. These fail when one moves without the other.
describe('PrivacyPolicyView', () => {
  afterEach(cleanup)

  it('states the guest-reading retention that retention.php enforces', async () => {
    const days = Number(/GUEST_READING_DAYS = (\d+);/.exec(retentionPhp)?.[1])
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(days).toBe(365)
    expect(section('Data Retention')).toContain('kept for one year from the day they were created')
  })

  it('states the earlier expiry a guest may choose, as the API offers it', async () => {
    const days = /EXPIRY_DAYS = \[([\d, ]+)\];/.exec(readingInputPhp)?.[1]?.split(', ')
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(days).toEqual(['1', '7', '30'])
    expect(section('Data Retention')).toContain(
      'you can choose to have it deleted sooner: after 1, 7 or 30 days',
    )
  })

  it('names every page that asks for a Turnstile check', async () => {
    const views = import.meta.glob<string>('@/views/**/*.vue', {
      query: '?raw',
      import: 'default',
      eager: true,
    })
    const named: Record<string, string> = {
      'LoginView.vue': 'signing in',
      'ContactView.vue': 'the contact form',
      'NewReadingView.vue': 'drawing a reading while signed out',
      'CustomReadingView.vue': 'drawing a reading while signed out',
      'PluginDrawKeyView.vue': 'getting a draw key for the FFXIV plugin',
    }
    const checked = Object.entries(views)
      .filter(([, source]) => source.includes('<TurnstileWidget'))
      .map(([path]) => path.split('/').pop() ?? path)
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    // A new page with the widget fails here until the policy names it.
    expect(checked.sort()).toEqual(Object.keys(named).sort())
    const sharing = section('Data Sharing')
    expect(sharing).toContain('Cloudflare Turnstile check')
    for (const phrase of Object.values(named)) {
      expect(sharing).toContain(phrase)
    }
  })

  it('states how long the daily backups keep deleted data', async () => {
    // The deploy installs the backup with SharedDeploy's Install-BackupTimer: its
    // -Keep where the deploy passes one, else the module's default.
    const keep = Number(
      /Install-BackupTimer\b[^\n]*-Keep (\d+)/.exec(deployScript)?.[1] ??
        /\[int\]\$Keep = (\d+)/.exec(sharedDeploy)?.[1],
    )
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(section('Data Retention')).toContain(`backups for up to ${keep} days`)
  })
})
