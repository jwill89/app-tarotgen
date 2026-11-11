import { describe, it, expect, afterEach, vi } from 'vitest'
import { h, nextTick, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import { mountWithApp, cleanup } from './helpers/app'

async function mountEditor(
  initial: string,
  props: { labelledby?: string; placeholder?: string } = {},
) {
  const model = ref(initial)
  const updates: string[] = []
  // The toolbar's tooltips need the provider App.vue mounts.
  const { wrapper } = await mountWithApp(TooltipProvider, {
    slots: {
      default: () =>
        h(MarkdownEditor, {
          modelValue: model.value,
          ...props,
          'onUpdate:modelValue': (md: string) => {
            updates.push(md)
            model.value = md
          },
        }),
    },
  })
  const textbox = () => wrapper.get('[role="textbox"]')
  return { wrapper, model, updates, textbox }
}

type Mounted = Awaited<ReturnType<typeof mountEditor>>

/** Select the whole document the way a user would: Ctrl/Cmd+A in the editor. */
async function selectAll({ textbox }: Mounted): Promise<void> {
  const el = textbox().element as HTMLElement
  el.focus()
  el.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', ctrlKey: true, bubbles: true }))
  await nextTick()
}

async function press(editor: Mounted, label: string): Promise<void> {
  await editor.wrapper.get(`button[aria-label="${label}"]`).trigger('click')
  await flushPromises()
}

describe('MarkdownEditor', () => {
  afterEach(cleanup)

  it('is a multi-line text box named by its caption', async () => {
    const { textbox } = await mountEditor('', { labelledby: 'notes-caption' })

    expect(textbox().attributes('aria-multiline')).toBe('true')
    expect(textbox().attributes('aria-labelledby')).toBe('notes-caption')
  })

  it('shows the markdown it is given as formatted text', async () => {
    const { textbox } = await mountEditor('## Past\n\nThe **Tower**, _reversed_.')

    expect(textbox().get('h2').text()).toBe('Past')
    expect(textbox().get('strong').text()).toBe('Tower')
    expect(textbox().get('em').text()).toBe('reversed')
  })

  it.each([
    ['Bold', '**Hello**'],
    ['Italic', '_Hello_'],
    ['Heading', '## Hello'],
    ['Quote', '> Hello'],
    ['Bulleted list', '-   Hello'],
    ['Numbered list', '1.  Hello'],
    ['Inline code', '`Hello`'],
  ])('turns the selection into markdown with the %s button', async (label, markdown) => {
    const editor = await mountEditor('Hello')
    await selectAll(editor)

    await press(editor, label)

    expect(editor.updates.at(-1)).toBe(markdown)
  })

  it('undoes a format when its button is pressed again', async () => {
    const editor = await mountEditor('**Hello**')
    await selectAll(editor)

    await press(editor, 'Bold')

    expect(editor.updates.at(-1)).toBe('Hello')
  })

  it('links the selection to the URL the user gives', async () => {
    // jsdom's window.prompt answers nothing; the link button asks with it.
    const prompt = vi.fn(() => 'https://tarotgen.io/')
    vi.stubGlobal('prompt', prompt)
    const editor = await mountEditor('Hello')
    await selectAll(editor)

    await press(editor, 'Link')

    expect(prompt).toHaveBeenCalledWith('URL')
    expect(editor.updates.at(-1)).toBe('[Hello](https://tarotgen.io/)')
  })

  it('removes the link when the URL is left empty', async () => {
    vi.stubGlobal('prompt', () => '')
    const editor = await mountEditor('[Hello](https://tarotgen.io/)')
    await selectAll(editor)

    await press(editor, 'Link')

    expect(editor.updates.at(-1)).toBe('Hello')
  })

  it('reports an emptied editor as empty markdown', async () => {
    const editor = await mountEditor('Hello')
    await selectAll(editor)

    const el = editor.textbox().element as HTMLElement
    el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true }))
    await flushPromises()

    expect(editor.updates.at(-1)).toBe('')
  })

  // TipTap 3 takes `{ emitUpdate: false }`; a bare `false` (the v2 form) is
  // ignored, so a parent resetting the form would have its own value echoed back.
  it('takes a new value from its parent without echoing it back', async () => {
    const editor = await mountEditor('Hello')

    editor.model.value = '**Reset**'
    await flushPromises()

    expect(editor.textbox().get('strong').text()).toBe('Reset')
    expect(editor.updates).toEqual([])
  })
})
