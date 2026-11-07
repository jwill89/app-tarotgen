import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import ContactView from '@/views/ContactView.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import { useToasts } from '@/composables/useToasts'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

function button(name: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].filter((b) => text(b) === name)
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

function field(label: string): HTMLInputElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const input = el && document.getElementById(el.htmlFor)
  if (!(input instanceof HTMLInputElement)) {
    throw new Error(`No field labelled "${label}"`)
  }
  return input
}

async function type(label: string, value: string) {
  const el = field(label)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

// The message editor's toolbar tooltips need the provider App.vue mounts.
async function mountContact() {
  const { wrapper } = await mountWithApp(TooltipProvider, {
    path: '/contact',
    slots: { default: () => h(ContactView) },
  })
  return wrapper
}

/** Every call the page makes on its own (the Turnstile widget asks for /config). */
const stub = (handlers: Parameters<typeof stubApi>[0] = {}, config: object = {}) =>
  stubApi({ 'GET /config': { body: config }, ...handlers })

/** What the form sent to POST /contacts, if it sent anything. */
const sentMessages = (api: ReturnType<typeof stubApi>): unknown[] =>
  api.mock.calls
    .filter(([url, init]) => url === '/api/contacts' && init?.method === 'POST')
    .map(([, init]) => JSON.parse(String(init?.body)))

async function fillIn(wrapper: VueWrapper) {
  await type('Name', 'Alice')
  await type('Email', 'alice@example.test')
  await writeMessage(wrapper, 'Hello')
}

/** Type into the message editor (TipTap emits markdown through v-model). */
async function writeMessage(wrapper: VueWrapper, markdown: string) {
  wrapper.findComponent(MarkdownEditor).vm.$emit('update:modelValue', markdown)
  await flushPromises()
}

describe('ContactView', () => {
  beforeEach(() => {
    signIn(null)
    useToasts().toasts.value = []
  })
  afterEach(cleanup)

  it("fills in a signed-in user's name and email, and keeps them fixed", async () => {
    signIn(testUser({ display_name: 'Lyra', email: 'lyra@example.test' }))
    stub()
    await mountContact()

    expect([field('Name').value, field('Email').value]).toEqual(['Lyra', 'lyra@example.test'])
    expect([field('Name').disabled, field('Email').disabled]).toEqual([true, true])
  })

  it('lets a visitor type their own details', async () => {
    stub()
    await mountContact()

    expect([field('Name').value, field('Email').value]).toEqual(['', ''])
    expect(field('Name').disabled).toBe(false)
  })

  it('asks for every field before sending', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const api = stub()
    const wrapper = await mountContact()

    await type('Name', 'Alice')
    await type('Email', 'alice@example.test')
    await writeMessage(wrapper, '   ')
    await click(button('Send Message'))

    expect(sentMessages(api)).toEqual([])
    expect(toastMessages()).toEqual(['Please fill out all fields.'])
  })

  it('sends the message and thanks the sender', async () => {
    const api = stub({ 'POST /contacts': { status: 201, body: { success: true } } })
    const wrapper = await mountContact()

    await type('Name', ' Alice ')
    await type('Email', 'alice@example.test')
    await writeMessage(wrapper, 'Could you add the **Thoth** deck?')
    await click(button('Send Message'))

    expect(sentMessages(api)).toEqual([
      {
        name: 'Alice',
        email: 'alice@example.test',
        message: 'Could you add the **Thoth** deck?',
        // Turnstile isn't configured here, so there's no challenge to pass.
        turnstile_token: '',
      },
    ])
    expect(toastMessages()).toEqual(['Your message has been sent!'])
    expect(pageText()).toContain('Thanks for reaching out!')
    expect(document.body.querySelector('form')).toBeNull()
  })

  it('offers a fresh message, keeping who it is from', async () => {
    stub({ 'POST /contacts': { status: 201, body: { success: true } } })
    const wrapper = await mountContact()
    await type('Name', 'Alice')
    await type('Email', 'alice@example.test')
    await writeMessage(wrapper, 'First message')
    await click(button('Send Message'))

    await click(button('Send Another Message'))

    expect(field('Name').value).toBe('Alice')
    expect(wrapper.findComponent(MarkdownEditor).props('modelValue')).toBe('')
  })

  it("keeps the message and shows the server's reason when it isn't sent", async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    stub({ 'POST /contacts': { status: 400, body: { error: 'Please enter a valid email.' } } })
    const wrapper = await mountContact()

    await type('Name', 'Alice')
    await type('Email', 'alice@example')
    await writeMessage(wrapper, 'Hello')
    await click(button('Send Message'))

    expect(toastMessages()).toEqual(['Please enter a valid email.'])
    expect(document.body.querySelector('form')).not.toBeNull()
    expect(wrapper.findComponent(MarkdownEditor).props('modelValue')).toBe('Hello')
    expect(button('Send Message').disabled).toBe(false)
  })

  describe('with Cloudflare Turnstile switched on', () => {
    let solve: (token: string) => void
    const turnstile = { render: vi.fn(), reset: vi.fn(), remove: vi.fn() }

    beforeEach(() => {
      solve = () => {}
      turnstile.render
        .mockReset()
        .mockImplementation((_el: HTMLElement, opts: Record<string, unknown>) => {
          const callback = opts.callback
          if (typeof callback === 'function') {
            solve = (token) => callback(token)
          }
          return 'widget-1'
        })
      turnstile.reset.mockReset()
      // Already "loaded", so the widget never fetches Cloudflare's script.
      vi.stubGlobal('turnstile', turnstile)
    })

    it('holds the message until the challenge is solved, then sends its token', async () => {
      const api = stub(
        { 'POST /contacts': { status: 201, body: { success: true } } },
        { turnstile_sitekey: '0x4AAA' },
      )
      const wrapper = await mountContact()
      await fillIn(wrapper)

      expect(turnstile.render).toHaveBeenCalledWith(
        expect.any(HTMLElement),
        expect.objectContaining({ sitekey: '0x4AAA' }),
      )
      expect(button('Send Message').disabled).toBe(true)

      solve('cf-token')
      await flushPromises()
      expect(button('Send Message').disabled).toBe(false)
      await click(button('Send Message'))

      expect(sentMessages(api)).toEqual([expect.objectContaining({ turnstile_token: 'cf-token' })])
      expect(pageText()).toContain('Thanks for reaching out!')
    })

    it('asks for a fresh challenge after a failed attempt (tokens are single-use)', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      stub(
        {
          'POST /contacts': {
            status: 400,
            body: { error: 'Captcha verification failed. Please try again.' },
          },
        },
        { turnstile_sitekey: '0x4AAA' },
      )
      const wrapper = await mountContact()
      await fillIn(wrapper)
      solve('cf-token')
      await flushPromises()

      await click(button('Send Message'))

      expect(toastMessages()).toEqual(['Captcha verification failed. Please try again.'])
      expect(turnstile.reset).toHaveBeenCalledWith('widget-1')
      expect(button('Send Message').disabled).toBe(true)
      expect(wrapper.findComponent(MarkdownEditor).props('modelValue')).toBe('Hello')
    })

    it('needs a new challenge for another message', async () => {
      stub(
        { 'POST /contacts': { status: 201, body: { success: true } } },
        { turnstile_sitekey: '0x4AAA' },
      )
      const wrapper = await mountContact()
      await fillIn(wrapper)
      solve('cf-token')
      await flushPromises()
      await click(button('Send Message'))

      await click(button('Send Another Message'))
      await writeMessage(wrapper, 'And another thing')

      // The first token was spent: the fresh widget must be solved again.
      expect(turnstile.render).toHaveBeenCalledTimes(2)
      expect(button('Send Message').disabled).toBe(true)
    })
  })
})
