import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import HomeView from '@/views/HomeView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useRecentReadings, type RecentReading } from '@/composables/useRecentReadings'
import { useConfirm } from '@/composables/useConfirm'
import { STORAGE_KEYS } from '@/constants'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()

function button(name: string, root: ParentNode = document.body): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].filter(
    (b) => text(b) === name || b.getAttribute('aria-label') === name,
  )
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

async function answer(choice: string) {
  const open = document.body.querySelector<HTMLElement>('[role="alertdialog"]')
  if (!open) {
    throw new Error('No confirm dialog is open')
  }
  await click(button(choice, open))
}

const recentRows = () =>
  [...document.body.querySelectorAll('.recent-table tbody tr')].map((tr) =>
    [...tr.querySelectorAll('td')].slice(0, 2).map(text),
  )
const linkTexts = () => [...document.body.querySelectorAll('a')].map(text)
const stored = () => JSON.parse(localStorage.getItem(STORAGE_KEYS.recentReadings) ?? '[]')

const recent = (id: string, summary: string, at: string): RecentReading => ({
  id,
  summary,
  deckName: 'Rider-Waite Tarot',
  at,
})

// The confirm() prompts render in the one ConfirmDialog App.vue mounts.
async function mountHome() {
  const { wrapper, router } = await mountWithApp(
    { render: () => [h(HomeView), h(ConfirmDialog)] },
    { path: '/' },
  )
  return { wrapper, router }
}

describe('HomeView', () => {
  beforeEach(() => {
    signIn(null)
    useRecentReadings().clear()
    useConfirm().settle(false)
    stubApi({})
  })
  afterEach(cleanup)

  describe('looking up a reading by its code', () => {
    it('opens the reading for the code typed', async () => {
      const { router } = await mountHome()
      const input = document.body.querySelector<HTMLInputElement>(
        'input[aria-label="Reading Code"]',
      )
      if (!input) {
        throw new Error('No reading code field')
      }

      input.value = '  abc123 '
      input.dispatchEvent(new Event('input'))
      await click(button('View reading'))

      await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/reading/abc123'))
      expect(input.value).toBe('')
    })

    it('stays put without a code', async () => {
      const { router } = await mountHome()

      await click(button('View reading'))

      expect(router.currentRoute.value.name).toBe('home')
    })
  })

  it('offers deck submissions only to signed-in users', async () => {
    await mountHome()
    expect(linkTexts().some((t) => t.startsWith('Submit a Deck'))).toBe(false)
    await cleanup()

    signIn(testUser())
    await mountHome()
    expect(linkTexts().some((t) => t.startsWith('Submit a Deck'))).toBe(true)
  })

  describe('recent readings', () => {
    it("is hidden until there's a reading to show", async () => {
      await mountHome()

      expect(document.body.textContent).not.toContain('Your Recent Readings')
    })

    it('lists the readings made on this device, newest first, with links to them', async () => {
      const { record } = useRecentReadings()
      record(recent('old', 'Two Paths', '2026-09-01 10:00:00'))
      record(recent('new', '3 cards', '2026-10-01 10:00:00'))
      await mountHome()

      expect(recentRows()).toEqual([
        ['3 cards', 'Rider-Waite Tarot'],
        ['Two Paths', 'Rider-Waite Tarot'],
      ])
      const open = document.body.querySelector('a[aria-label="Open Two Paths"]')
      expect(open?.getAttribute('href')).toBe('/reading/old')
    })

    it('forgets one reading once the user confirms', async () => {
      const { record } = useRecentReadings()
      record(recent('a', 'Two Paths', '2026-09-01 10:00:00'))
      record(recent('b', '3 cards', '2026-10-01 10:00:00'))
      await mountHome()

      await click(button('Remove Two Paths from recent readings'))
      await answer('Remove')

      expect(recentRows()).toEqual([['3 cards', 'Rider-Waite Tarot']])
      expect(stored().map((r: RecentReading) => r.id)).toEqual(['b'])
    })

    it('keeps the reading when the user cancels', async () => {
      useRecentReadings().record(recent('a', 'Two Paths', '2026-09-01 10:00:00'))
      await mountHome()

      await click(button('Remove Two Paths from recent readings'))
      await answer('Cancel')

      expect(recentRows()).toHaveLength(1)
    })

    it('clears the whole history once the user confirms', async () => {
      const { record } = useRecentReadings()
      record(recent('a', 'Two Paths', '2026-09-01 10:00:00'))
      record(recent('b', '3 cards', '2026-10-01 10:00:00'))
      await mountHome()

      await click(button('Clear all'))
      await answer('Clear all')

      expect(document.body.textContent).not.toContain('Your Recent Readings')
      expect(stored()).toEqual([])
    })
  })
})
