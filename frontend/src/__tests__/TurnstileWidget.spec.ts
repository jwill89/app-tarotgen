import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h, ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import TurnstileWidget from '@/components/TurnstileWidget.vue'
import { mountWithApp, cleanup, stubApi } from './helpers/app'

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

type RenderOptions = Record<string, unknown> & {
  sitekey?: string
  callback?: (token: string) => void
  'expired-callback'?: () => void
  'error-callback'?: () => void
}

/** A stand-in for Cloudflare's script: records what was rendered, reset and removed. */
function fakeTurnstile() {
  let options: RenderOptions = {}
  const api = {
    render: vi.fn((_el: HTMLElement, opts: RenderOptions) => {
      options = opts
      return 'widget-1'
    }),
    reset: vi.fn(),
    remove: vi.fn(),
  }
  return { api, options: () => options }
}

/** Mount bound both ways, as a form does (`v-model` + `v-model:enabled`). */
async function mountWidget() {
  const token = ref('')
  const enabled = ref(false)
  const { wrapper } = await mountWithApp({
    render: () =>
      h(TurnstileWidget, {
        modelValue: token.value,
        enabled: enabled.value,
        'onUpdate:modelValue': (value: string) => (token.value = value),
        'onUpdate:enabled': (value: boolean) => (enabled.value = value),
      }),
  })
  const widget = wrapper.getComponent(TurnstileWidget)
  const shown = () => (widget.get('.field').element as HTMLElement).style.display !== 'none'
  return { widget, token, enabled, shown }
}

// Scripts the widget adds to <head>. jsdom never fetches them, so they are held
// back here and the test says when one loads or fails.
let scripts: HTMLScriptElement[] = []

async function settleScript(index: number, outcome: 'load' | 'error'): Promise<void> {
  scripts[index]!.dispatchEvent(new Event(outcome))
  await flushPromises()
}

describe('TurnstileWidget', () => {
  beforeEach(() => {
    scripts = []
    vi.spyOn(document.head, 'appendChild').mockImplementation(<T extends Node>(node: T): T => {
      if (node instanceof HTMLScriptElement) {
        scripts.push(node)
      }
      return node
    })
    delete window.turnstile
  })
  afterEach(cleanup)

  it('stays hidden and off where Turnstile is not configured', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: null } } })

    const { enabled, shown } = await mountWidget()

    expect(enabled.value).toBe(false)
    expect(shown()).toBe(false)
    expect(scripts).toEqual([])
  })

  it('stays off when the config cannot be loaded', async () => {
    stubApi({ 'GET /config': { status: 500, body: { error: 'Boom' } } })

    const { enabled } = await mountWidget()

    expect(enabled.value).toBe(false)
  })

  it('renders the challenge with the site key and reports the solved token', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile.api

    const { enabled, shown, token } = await mountWidget()

    expect(enabled.value).toBe(true)
    expect(shown()).toBe(true)
    expect(turnstile.api.render).toHaveBeenCalledTimes(1)
    expect(turnstile.options().sitekey).toBe('site-key-1')

    turnstile.options().callback?.('token-abc')
    expect(token.value).toBe('token-abc')
  })

  it('clears the token when the challenge expires or errors', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile.api
    const { token } = await mountWidget()

    turnstile.options().callback?.('token-abc')
    turnstile.options()['expired-callback']?.()
    expect(token.value).toBe('')

    turnstile.options().callback?.('token-def')
    turnstile.options()['error-callback']?.()
    expect(token.value).toBe('')
  })

  it('clears a spent token and resets the challenge on reset()', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile.api
    const { widget, token } = await mountWidget()
    turnstile.options().callback?.('token-abc')

    widget.vm.reset()

    expect(token.value).toBe('')
    expect(turnstile.api.reset).toHaveBeenCalledWith('widget-1')
  })

  it('removes its challenge when it goes away', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    window.turnstile = turnstile.api
    await mountWidget()

    await cleanup()

    expect(turnstile.api.remove).toHaveBeenCalledWith('widget-1')
  })

  // The script's load is shared module state for the whole file: this test
  // leaves it failed (so forgotten), and the next one loads it for good.
  it('shows no challenge when the script fails to load, and tries again next time', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    const first = await mountWidget()

    await settleScript(0, 'error')
    expect(first.token.value).toBe('')
    expect(turnstile.api.render).not.toHaveBeenCalled()

    await cleanup()
    await mountWidget()
    expect(scripts.map((s) => s.src)).toEqual([SCRIPT, SCRIPT])
    await settleScript(1, 'error')
  })

  it('loads the script once, async, and renders when it arrives', async () => {
    stubApi({ 'GET /config': { body: { turnstile_sitekey: 'site-key-1' } } })
    const turnstile = fakeTurnstile()
    await mountWidget()
    await mountWidget()

    expect(scripts).toHaveLength(1)
    expect(scripts[0]!.src).toBe(SCRIPT)
    expect(scripts[0]!.async).toBe(true)
    expect(turnstile.api.render).not.toHaveBeenCalled()

    window.turnstile = turnstile.api
    await settleScript(0, 'load')

    expect(turnstile.api.render).toHaveBeenCalledTimes(2)
  })
})
