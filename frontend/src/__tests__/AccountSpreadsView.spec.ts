import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import AccountSpreadsView from '@/views/AccountSpreadsView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'
import type { UserSpread } from '@/types'

// The view inside what App.vue gives it: the TooltipProvider its Tooltips need
// and the shared ConfirmDialog its confirm() calls open.
const Page = defineComponent({
  render: () => h(TooltipProvider, null, () => [h(AccountSpreadsView), h(ConfirmDialog)]),
})

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

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

function row(name: string): HTMLTableRowElement {
  const found = [...document.body.querySelectorAll('tbody tr')].find(
    (tr): tr is HTMLTableRowElement =>
      tr instanceof HTMLTableRowElement && tr.querySelector('strong')?.textContent === name,
  )
  if (!found) {
    throw new Error(`No spread named "${name}"`)
  }
  return found
}

function field(label: string): HTMLInputElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const input = el && document.getElementById(el.htmlFor)
  if (!(input instanceof HTMLInputElement)) {
    throw new Error(`No field labelled "${label}"`)
  }
  return input
}

const names = () => [...document.body.querySelectorAll('tbody tr strong')].map((s) => s.textContent)

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

const editDialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')
const confirmDialog = () => document.body.querySelector<HTMLElement>('[role="alertdialog"]')

async function answer(choice: string) {
  const open = confirmDialog()
  if (!open) {
    throw new Error('No confirm dialog is open')
  }
  await click(button(choice, open))
}

const spread = (user_spread_id: number, name: string, description = ''): UserSpread => ({
  user_spread_id,
  user_id: 7,
  name,
  description,
  card_count: 2,
  positions: [
    { order: 1, title: 'Past', x: 30, y: 50, rotation: 0 },
    { order: 2, title: 'Future', x: 70, y: 50, rotation: 0 },
  ],
  created_at: '2026-09-01 10:00:00',
  updated_at: '2026-09-02 10:00:00',
})

describe('AccountSpreadsView', () => {
  beforeEach(() => {
    signIn(testUser())
    useUserSpreads().userSpreads.value = []
    useToasts().toasts.value = []
    useConfirm().settle(false)
  })
  afterEach(cleanup)

  it('lists the spreads with their descriptions and card counts', async () => {
    stubApi({
      'GET /account/spreads': {
        body: [spread(1, 'Two Paths', 'Choose between two roads.'), spread(2, 'Daily Pull')],
      },
    })
    await mountWithApp(Page, { path: '/account/spreads' })

    expect(names()).toEqual(['Two Paths', 'Daily Pull'])
    expect(text(row('Two Paths'))).toContain('Choose between two roads.')
    expect(row('Daily Pull').cells[1]!.textContent).toBe('2')
  })

  it('shows a spinner, not "no spreads", while loading', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>(() => {})),
    )
    await mountWithApp(Page, { path: '/account/spreads' })

    expect(document.body.querySelector('.fa-spin')).not.toBeNull()
    expect(pageText()).not.toContain("You haven't created any personal spreads yet.")
  })

  it('points a user with no spreads at the spread designer', async () => {
    stubApi({ 'GET /account/spreads': { body: [] } })
    await mountWithApp(Page, { path: '/account/spreads' })

    expect(pageText()).toContain("You haven't created any personal spreads yet.")
    const first = [...document.body.querySelectorAll('a')].find(
      (a) => text(a) === 'Create your first spread',
    )
    expect(first?.getAttribute('href')).toBe('/create-spread')
  })

  describe('editing', () => {
    it('saves the edited spread and shows its new name', async () => {
      const api = stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'PUT /account/spreads/1': { body: spread(1, 'Three Paths') },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Edit', row('Two Paths')))
      const nameField = field('Spread Name')
      expect(nameField.value).toBe('Two Paths')
      nameField.value = 'Three Paths'
      nameField.dispatchEvent(new Event('input'))
      await click(button('Save Changes'))

      // The spread as edited, its layout untouched.
      expect(JSON.parse(String(api.mock.calls[1]?.[1]?.body))).toEqual({
        name: 'Three Paths',
        description: '',
        card_count: 2,
        positions: spread(1, '').positions,
      })
      expect(toastMessages()).toEqual(['Spread updated successfully.'])
      expect(editDialog()).toBeNull()
      expect(names()).toEqual(['Three Paths'])
    })

    it("keeps the editor open and shows the server's reason when the save is refused", async () => {
      stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'PUT /account/spreads/1': {
          status: 400,
          body: { error: 'A spread needs at least one card position.' },
        },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Edit', row('Two Paths')))
      await click(button('Save Changes'))

      expect(editDialog()?.textContent).toContain('A spread needs at least one card position.')
      expect(editDialog()?.textContent).not.toContain('Failed to save changes.')
      expect(names()).toEqual(['Two Paths'])
    })

    it('keeps the editor open and says so when the save fails', async () => {
      stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'PUT /account/spreads/1': { status: 500 },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Edit', row('Two Paths')))
      await click(button('Save Changes'))

      expect(editDialog()?.textContent).toContain('Failed to save changes. Please try again.')
      expect(names()).toEqual(['Two Paths'])
    })

    it('closes the editor on Cancel', async () => {
      const api = stubApi({ 'GET /account/spreads': { body: [spread(1, 'Two Paths')] } })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Edit', row('Two Paths')))
      await click(button('Cancel'))

      expect(editDialog()).toBeNull()
      expect(api).toHaveBeenCalledTimes(1)
    })
  })

  describe('deleting', () => {
    it('deletes after a warning naming the spread', async () => {
      const api = stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths'), spread(2, 'Daily Pull')] },
        'DELETE /account/spreads/1': { status: 204 },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Delete', row('Two Paths')))
      expect(confirmDialog()?.textContent).toContain('delete "Two Paths"?')
      await answer('Delete')

      expect(api).toHaveBeenCalledTimes(2)
      expect(toastMessages()).toEqual(['Spread deleted.'])
      expect(names()).toEqual(['Daily Pull'])
    })

    it('keeps the spread when the warning is cancelled', async () => {
      const api = stubApi({ 'GET /account/spreads': { body: [spread(1, 'Two Paths')] } })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Delete', row('Two Paths')))
      await answer('Cancel')

      expect(api).toHaveBeenCalledTimes(1)
      expect(names()).toEqual(['Two Paths'])
    })

    it('says so when the delete fails', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'DELETE /account/spreads/1': { status: 500 },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Delete', row('Two Paths')))
      await answer('Delete')

      expect(toastMessages()).toEqual(['Failed to delete the spread.'])
      expect(names()).toEqual(['Two Paths'])
    })
  })

  describe('submitting as a public spread', () => {
    it('sends the spread for review once the user confirms', async () => {
      const api = stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'POST /account/spreads/1/submit': { status: 201 },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Submit as public spread', row('Two Paths')))
      expect(confirmDialog()?.textContent).toContain('Submit "Two Paths" for admin review?')
      await answer('Submit for Review')

      expect(api).toHaveBeenCalledTimes(2)
      expect(toastMessages()).toEqual(['Spread submitted for review!'])
    })

    it('sends nothing when the user cancels', async () => {
      const api = stubApi({ 'GET /account/spreads': { body: [spread(1, 'Two Paths')] } })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Submit as public spread', row('Two Paths')))
      await answer('Cancel')

      expect(api).toHaveBeenCalledTimes(1)
    })

    it('says so when the submission fails', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      stubApi({
        'GET /account/spreads': { body: [spread(1, 'Two Paths')] },
        'POST /account/spreads/1/submit': { status: 409, body: { error: 'Already submitted.' } },
      })
      await mountWithApp(Page, { path: '/account/spreads' })

      await click(button('Submit as public spread', row('Two Paths')))
      await answer('Submit for Review')

      expect(toastMessages()).toEqual(['Failed to submit the spread. Please try again.'])
    })
  })
})
