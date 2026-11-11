import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import CookieBanner from '@/components/CookieBanner.vue'
import { mountWithApp, cleanup } from './helpers/app'

// The key sits outside the `tarot.` storage namespace on purpose: renaming it
// would show the banner again to everyone who already dismissed it.
const KEY = 'cookie_notice_dismissed'

describe('CookieBanner', () => {
  beforeEach(() => {
    localStorage.clear()
  })
  afterEach(cleanup)

  it('explains the essential cookies to a first-time visitor', async () => {
    const { wrapper } = await mountWithApp(CookieBanner)

    expect(wrapper.text()).toContain('This site uses essential cookies to keep you logged in.')
    expect(wrapper.find('button').text()).toBe('Got it')
  })

  it('goes away when dismissed, and stays away on the next visit', async () => {
    const { wrapper } = await mountWithApp(CookieBanner)

    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('.cookie-banner').exists()).toBe(false)

    await cleanup()
    const { wrapper: nextVisit } = await mountWithApp(CookieBanner)
    expect(nextVisit.find('.cookie-banner').exists()).toBe(false)
  })

  it('stays hidden for a visitor who dismissed it before', async () => {
    localStorage.setItem(KEY, '1')

    const { wrapper } = await mountWithApp(CookieBanner)

    expect(wrapper.find('.cookie-banner').exists()).toBe(false)
  })

  it('can still be dismissed when the browser refuses to store the choice', async () => {
    vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
      throw new DOMException('The quota has been exceeded.', 'QuotaExceededError')
    })
    const { wrapper } = await mountWithApp(CookieBanner)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('.cookie-banner').exists()).toBe(false)
  })

  it('still shows when the browser refuses to read storage', async () => {
    vi.spyOn(localStorage, 'getItem').mockImplementation(() => {
      throw new DOMException('Access is denied.', 'SecurityError')
    })

    const { wrapper } = await mountWithApp(CookieBanner)

    expect(wrapper.find('.cookie-banner').exists()).toBe(true)
  })
})
