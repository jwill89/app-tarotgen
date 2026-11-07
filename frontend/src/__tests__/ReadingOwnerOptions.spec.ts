import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { h } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import { mountWithApp, cleanup, signIn, stubApi, testUser } from './helpers/app'
import { choose, labelled, optionsOf } from './helpers/page'

async function mountOptions(notes = false) {
  // The notes editor's toolbar tooltips need the provider App.vue mounts.
  const { wrapper, router } = await mountWithApp(TooltipProvider, {
    slots: { default: () => h(ReadingOwnerOptions, { notes }) },
  })
  return { wrapper, router, options: wrapper.getComponent(ReadingOwnerOptions) }
}

/** A segment of the "Who Can Open It" control. */
function segment(wrapper: VueWrapper, label: string) {
  const found = wrapper.findAll('.myst-segmented__btn').find((b) => b.text() === label)
  if (!found) {
    throw new Error(`No "${label}" choice`)
  }
  return found
}

/** Type a tag and press Enter, as a reader adds one. */
async function addTag(wrapper: VueWrapper, tag: string) {
  const input = wrapper.get('#ro-tags')
  await input.setValue(tag)
  await input.trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

const chips = (wrapper: VueWrapper) => wrapper.findAll('.tag-input__chip').map((c) => c.text())

describe('ReadingOwnerOptions', () => {
  beforeEach(() => {
    signIn(testUser())
  })
  afterEach(cleanup)

  describe('for a guest', () => {
    beforeEach(() => {
      signIn(null)
    })

    it('invites them to log in for the owner options', async () => {
      const { wrapper, router } = await mountOptions()

      const login = wrapper.get('a')
      expect(login.text()).toBe('Log in')
      expect(login.attributes('href')).toBe(router.resolve({ name: 'login' }).href)
      expect(wrapper.find('#ro-name').exists()).toBe(false)
      expect(wrapper.find('#ro-tags').exists()).toBe(false)
    })

    it('keeps the reading the usual year unless they choose sooner', async () => {
      const { options } = await mountOptions()

      expect(await optionsOf(labelled('Keep This Reading For'))).toEqual([
        'A year (the usual)',
        '30 days',
        '7 days',
        '1 day',
      ])
      expect(options.vm.collect()).toEqual({})

      await choose(labelled('Keep This Reading For'), '7 days')
      expect(options.vm.collect()).toEqual({ expires_in_days: '7' })
    })

    it('forgets the chosen expiry on reset', async () => {
      const { options } = await mountOptions()
      await choose(labelled('Keep This Reading For'), '1 day')

      options.vm.reset()
      await flushPromises()

      expect(options.vm.collect()).toEqual({})
    })
  })

  it('sends only that a signed-in reader left everything as it was', async () => {
    const { options } = await mountOptions()

    expect(options.vm.collect()).toEqual({ hide_user: false, visibility: 'link' })
  })

  it('collects the title, question, querent and tags a signed-in reader writes, trimmed', async () => {
    const { wrapper, options } = await mountOptions()

    await wrapper.get('#ro-name').setValue('  Morning reflection  ')
    await wrapper.get('#ro-question').setValue(' Should I take the job? ')
    await wrapper.get('#ro-querent').setValue(' My sister ')
    await addTag(wrapper, 'Career')
    await addTag(wrapper, '#monthly')
    await addTag(wrapper, 'career')
    await wrapper.get('[role="switch"]').trigger('click')

    expect(chips(wrapper)).toEqual(['career', 'monthly'])
    expect(options.vm.collect()).toEqual({
      reading_name: 'Morning reflection',
      question: 'Should I take the job?',
      querent: 'My sister',
      tags: ['career', 'monthly'],
      hide_user: true,
      visibility: 'link',
    })
  })

  it('names each field for assistive tech', async () => {
    const { wrapper } = await mountOptions()

    expect(wrapper.get('label[for="ro-name"]').text()).toContain('Reading Title')
    expect(wrapper.get('label[for="ro-question"]').text()).toContain('Question')
    expect(wrapper.get('label[for="ro-querent"]').text()).toContain('For')
    expect(wrapper.get('label[for="ro-tags"]').text()).toContain('Tags')
    const group = wrapper.get('.myst-segmented')
    expect(document.getElementById(group.attributes('aria-labelledby') ?? '')?.textContent).toBe(
      'Who Can Open It',
    )
    const hide = wrapper.get('[role="switch"]')
    expect(wrapper.get(`label[for="${hide.attributes('id')}"]`).text()).toBe(
      'Hide my display name on this reading',
    )
  })

  it('says the question and querent stay with the reader', async () => {
    const { wrapper } = await mountOptions()

    expect(wrapper.text()).toContain(
      'The question and who it was for are yours alone: nobody you share the reading with sees them.',
    )
  })

  it('asks for a password only when the reading is to have one', async () => {
    const { wrapper, options } = await mountOptions()
    expect(wrapper.find('#ro-password').exists()).toBe(false)

    await segment(wrapper, 'Password').trigger('click')
    expect(wrapper.get('label[for="ro-password"]').text()).toContain('View Password')
    await wrapper.get('#ro-password').setValue('s3cret')

    expect(options.vm.collect()).toMatchObject({ visibility: 'password', password: 's3cret' })
    expect(wrapper.text()).toContain('Anyone but you needs the password to open it.')
  })

  it('hides the password as it is typed, with a switch to show it', async () => {
    const { wrapper } = await mountOptions()
    await segment(wrapper, 'Password').trigger('click')
    const password = wrapper.get('#ro-password')

    expect(password.attributes('type')).toBe('password')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(1)

    await password.setValue('s3cret')
    const show = wrapper.findAll('[role="switch"]').at(0)
    expect(wrapper.get(`label[for="${show?.attributes('id')}"]`).text()).toBe('Show password')
    await show?.trigger('click')

    expect(password.attributes('type')).toBe('text')
  })

  it('makes a reading private, dropping a password typed on the way', async () => {
    const { wrapper, options } = await mountOptions()
    await segment(wrapper, 'Password').trigger('click')
    await wrapper.get('#ro-password').setValue('s3cret')

    await segment(wrapper, 'Private').trigger('click')

    expect(wrapper.find('#ro-password').exists()).toBe(false)
    expect(wrapper.text()).toContain('Only you can open it.')
    const fields = options.vm.collect()
    expect(fields).toMatchObject({ visibility: 'private' })
    expect(fields).not.toHaveProperty('password')
  })

  it("suggests the reader's own tags once the tag field is used", async () => {
    const api = stubApi({
      'GET /account/tags': {
        body: [
          { tag: 'career', count: 3 },
          { tag: 'love', count: 5 },
          { tag: 'monthly', count: 1 },
        ],
      },
    })
    const { wrapper } = await mountOptions()
    expect(api).not.toHaveBeenCalled()

    await wrapper.get('#ro-tags').trigger('focus')
    await flushPromises()
    // Most used first.
    expect(wrapper.findAll('.tag-input__suggestion').map((b) => b.text())).toEqual([
      'love',
      'career',
      'monthly',
    ])

    await wrapper.get('#ro-tags').setValue('car')
    expect(wrapper.findAll('.tag-input__suggestion').map((b) => b.text())).toEqual(['career'])
    await wrapper.get('[aria-label="Add tag career"]').trigger('click')

    expect(chips(wrapper)).toEqual(['career'])
    expect((wrapper.get('#ro-tags').element as HTMLInputElement).value).toBe('')
    // A chosen tag isn't offered again; the field's focus doesn't fetch twice.
    expect(wrapper.findAll('.tag-input__suggestion').map((b) => b.text())).toEqual([
      'love',
      'monthly',
    ])
    await wrapper.get('#ro-tags').trigger('focus')
    expect(api).toHaveBeenCalledTimes(1)
  })

  it('offers reading notes only where the page asks for them', async () => {
    const without = await mountOptions(false)
    expect(without.wrapper.find('[role="textbox"]').exists()).toBe(false)

    const withNotes = await mountOptions(true)
    const editor = withNotes.wrapper.get('[role="textbox"]')
    const captionId = editor.attributes('aria-labelledby') ?? ''
    expect(document.getElementById(captionId)?.textContent).toContain('Reading Notes')
  })

  it('collects the notes as markdown when the page asks for them', async () => {
    const { wrapper, options } = await mountOptions(true)
    const editor = wrapper.get('[role="textbox"]').element as HTMLElement

    // ProseMirror reads typing back from the DOM it watches.
    const paragraph = editor.querySelector('p')
    if (!paragraph) {
      throw new Error('No paragraph to type into')
    }
    paragraph.textContent = 'A gentle start'
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(options.vm.collect().reading_notes).toBe('A gentle start')
  })

  it('clears everything on reset', async () => {
    const { wrapper, options } = await mountOptions()
    await wrapper.get('#ro-name').setValue('Evening')
    await wrapper.get('#ro-question').setValue('Why?')
    await wrapper.get('#ro-querent').setValue('Sam')
    await addTag(wrapper, 'career')
    await wrapper.get('[role="switch"]').trigger('click')
    await segment(wrapper, 'Password').trigger('click')
    await wrapper.get('#ro-password').setValue('s3cret')

    options.vm.reset()
    await flushPromises()

    expect(options.vm.collect()).toEqual({ hide_user: false, visibility: 'link' })
    expect((wrapper.get('#ro-name').element as HTMLInputElement).value).toBe('')
    expect(chips(wrapper)).toEqual([])
    expect(wrapper.find('#ro-password').exists()).toBe(false)
  })
})
