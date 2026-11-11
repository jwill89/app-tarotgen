import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/composables/useConfirm'
import { mountWithApp, cleanup } from './helpers/app'

const { confirm, settle } = useConfirm()

const dialog = () => document.body.querySelector<HTMLElement>('[role="alertdialog"]')
const button = (label: string) =>
  [...(dialog()?.querySelectorAll<HTMLButtonElement>('button') ?? [])].find(
    (b) => b.textContent.trim() === label,
  )

/** Ask, and wait for the dialog to open. Wrapped, so awaiting this doesn't await the answer. */
async function ask(options: Parameters<typeof confirm>[0]): Promise<{ answer: Promise<boolean> }> {
  const answer = confirm(options)
  await flushPromises()
  return { answer }
}

describe('ConfirmDialog', () => {
  beforeEach(async () => {
    // The prompt queue is module state: start every test with nothing pending.
    settle(false)
    await mountWithApp(ConfirmDialog)
  })
  afterEach(cleanup)

  it('shows nothing until something asks', () => {
    expect(dialog()).toBeNull()
  })

  it('asks the question with the given title, message and labels', async () => {
    void ask({
      title: 'Delete reading?',
      message: 'This cannot be undone.',
      confirmLabel: 'Delete',
      cancelLabel: 'Keep it',
      danger: true,
    })
    await flushPromises()

    const titleId = dialog()?.getAttribute('aria-labelledby') ?? ''
    const messageId = dialog()?.getAttribute('aria-describedby') ?? ''
    expect(document.getElementById(titleId)?.textContent).toBe('Delete reading?')
    expect(document.getElementById(messageId)?.textContent.trim()).toBe('This cannot be undone.')
    expect(
      [...(dialog()?.querySelectorAll('button') ?? [])].map((b) => b.textContent.trim()),
    ).toEqual(['Keep it', 'Delete'])
  })

  it('falls back to a generic title and labels', async () => {
    void ask({ message: 'Proceed?' })
    await flushPromises()

    expect(dialog()?.textContent).toContain('Please confirm')
    expect(button('Cancel')).toBeDefined()
    expect(button('Confirm')).toBeDefined()
  })

  it('puts focus on Cancel, so Enter alone never confirms', async () => {
    void ask({ message: 'Proceed?' })
    await flushPromises()

    expect(document.activeElement).toBe(button('Cancel'))
  })

  // Reka's own close and the button's click both fire on the one click; the
  // dialog must not settle before the click has recorded the choice, or this
  // would resolve false.
  it('resolves true when the user confirms', async () => {
    const { answer } = await ask({ message: 'Proceed?' })

    button('Confirm')?.click()

    await expect(answer).resolves.toBe(true)
    await flushPromises()
    expect(dialog()).toBeNull()
  })

  it('resolves false when the user cancels', async () => {
    const { answer } = await ask({ message: 'Proceed?' })

    button('Cancel')?.click()

    await expect(answer).resolves.toBe(false)
  })

  it('resolves false on Escape', async () => {
    const { answer } = await ask({ message: 'Proceed?' })

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    await expect(answer).resolves.toBe(false)
  })

  it('does not close on a click outside: the user must choose', async () => {
    const { answer } = await ask({ message: 'Proceed?' })
    let settled = false
    void answer.then(() => (settled = true))
    await new Promise((resolve) => setTimeout(resolve, 0))

    document.body
      .querySelector('.myst-modal-overlay')
      ?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    await flushPromises()

    expect(settled).toBe(false)
    expect(dialog()).not.toBeNull()
  })

  it("does not carry one prompt's confirmation into the next one's Escape", async () => {
    const { answer: first } = await ask({ message: 'First?' })
    button('Confirm')?.click()
    await expect(first).resolves.toBe(true)
    await flushPromises()

    const { answer: second } = await ask({ message: 'Second?' })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

    await expect(second).resolves.toBe(false)
  })
})
