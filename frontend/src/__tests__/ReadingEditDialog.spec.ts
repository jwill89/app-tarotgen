import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import ReadingEditDialog from '@/components/ReadingEditDialog.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import { mountWithApp, cleanup, signIn, stubApi, testUser, problem } from './helpers/app'
import { apiCalls, button, click, labelled, typeInto, valueOf } from './helpers/page'
import type { AccountReading } from '@/types'

const stored: AccountReading = {
  reading_id: 'abc123',
  reading_info: { deck_id: 1, draw: [] },
  reading_time: '2026-10-01T12:00:00Z',
  user_id: 7,
  hide_user: true,
  reading_name: 'Morning reflection',
  reading_notes: 'The **Tower**.',
  is_final: false,
  password_protected: true,
  daily_card_day: null,
  question: 'Should I take the job?',
  querent: 'My sister',
  tags: ['career'],
  visibility: 'password',
  expires_at: null,
}

/** The dialog over `reading`, recording what it emits. */
async function openDialog(reading: AccountReading | null = stored) {
  const current = ref<AccountReading | null>(reading)
  const saved: AccountReading[] = []
  const Host = defineComponent({
    render: () =>
      h(TooltipProvider, null, () =>
        h(ReadingEditDialog, {
          reading: current.value,
          onClose: () => {
            current.value = null
          },
          onSaved: (r: AccountReading) => {
            saved.push(r)
            current.value = null
          },
        }),
      ),
  })
  const { wrapper } = await mountWithApp(Host)
  return { wrapper, current, saved }
}

const dialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')

describe('ReadingEditDialog', () => {
  beforeEach(() => {
    signIn(testUser())
  })
  afterEach(cleanup)

  it('is closed without a reading', async () => {
    await openDialog(null)

    expect(dialog()).toBeNull()
  })

  it('opens with the reading as it is', async () => {
    await openDialog()

    expect(valueOf(labelled('Reading Title'))).toBe('Morning reflection')
    expect(valueOf(labelled('Question'))).toBe('Should I take the job?')
    expect(valueOf(labelled('For'))).toBe('My sister')
    expect(
      [...document.body.querySelectorAll('.tag-input__chip')].map((c) => c.textContent),
    ).toEqual(['career'])
    expect(dialog()?.querySelector('[role="textbox"] strong')?.textContent).toBe('Tower')
    expect(
      dialog()?.querySelector('.myst-segmented__btn[data-state="on"]')?.textContent.trim(),
    ).toBe('Password')
    // The reading has a password already: a blank field keeps it.
    expect(labelled('New Password (optional)')).toBeTruthy()
    expect(labelled('Hide my display name on this reading').getAttribute('aria-checked')).toBe(
      'true',
    )
  })

  it('saves every field, keeping the password when none is typed, and hands back the result', async () => {
    const api = stubApi({
      'PATCH /account/readings/abc123': (sent) => ({ body: { ...stored, ...(sent as object) } }),
    })
    const { wrapper, saved, current } = await openDialog()

    await typeInto(labelled('Question'), '  Will it last? ')
    await typeInto(labelled('For'), '')
    wrapper.findComponent(MarkdownEditor).vm.$emit('update:modelValue', '## Past')
    await click(labelled('Hide my display name on this reading'))
    await click(button('Save'))

    expect(apiCalls(api)).toEqual([
      [
        'PATCH',
        '/api/account/readings/abc123',
        {
          reading_name: 'Morning reflection',
          question: 'Will it last?',
          querent: '',
          tags: ['career'],
          reading_notes: '## Past',
          hide_user: false,
          visibility: 'password',
        },
      ],
    ])
    expect(saved.map((r) => r.question)).toEqual(['Will it last?'])
    expect(current.value).toBeNull()
  })

  it('sends a new password when one is typed', async () => {
    const api = stubApi({ 'PATCH /account/readings/abc123': { body: stored } })
    await openDialog()

    await typeInto(labelled('New Password (optional)'), 'n3w-secret')
    await click(button('Save'))

    expect(apiCalls(api)[0]?.[2]).toMatchObject({ visibility: 'password', password: 'n3w-secret' })
  })

  it("keeps the dialog open with the server's reason when a save is refused", async () => {
    stubApi({
      'PATCH /account/readings/abc123': {
        status: 422,
        body: problem('A reading can have at most 10 tags.'),
      },
    })
    const { saved } = await openDialog()

    await click(button('Save'))
    await flushPromises()

    expect(dialog()?.textContent).toContain('A reading can have at most 10 tags.')
    expect(saved).toEqual([])
  })

  it('closes without saving on Cancel', async () => {
    const api = stubApi({})
    const { current } = await openDialog()

    await click(button('Cancel'))

    expect(current.value).toBeNull()
    expect(api).not.toHaveBeenCalled()
  })
})
