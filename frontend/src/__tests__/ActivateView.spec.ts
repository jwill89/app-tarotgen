import { describe, it, expect, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ActivateView from '@/views/ActivateView.vue'
import { mountWithApp, cleanup, stubApi, problem } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const links = () =>
  [...document.body.querySelectorAll('a')].map((a) => [text(a), a.getAttribute('href')])

describe('ActivateView', () => {
  afterEach(cleanup)

  it('activates the account from the emailed token, then points to login', async () => {
    const api = stubApi({
      'POST /auth/activate': { body: { message: 'Your account is now active.' } },
    })
    await mountWithApp(ActivateView, { path: '/activate?token=tok123' })

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({ token: 'tok123' })
    expect(pageText()).toContain('Your account is now active.')
    expect(links()).toContainEqual(['Log In', '/login'])
  })

  it('shows it is working while the server answers', async () => {
    let answer: (r: Response) => void = () => {}
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((resolve) => (answer = resolve))),
    )
    await mountWithApp(ActivateView, { path: '/activate?token=tok123' })

    expect(pageText()).toContain('Activating your account…')

    answer(new Response(JSON.stringify({}), { status: 200 }))
    await flushPromises()
    // No message from the server: the page still confirms in its own words.
    expect(pageText()).toContain('Your account is now active.')
  })

  it('explains a refused link and offers to register again', async () => {
    stubApi({
      'POST /auth/activate': {
        status: 422,
        body: problem('This activation link has expired.'),
      },
    })
    await mountWithApp(ActivateView, { path: '/activate?token=old' })

    expect(pageText()).toContain('This activation link has expired.')
    expect(links()).toContainEqual(['Register again', '/register'])
  })

  it("doesn't ask the server about a link with no token", async () => {
    const api = stubApi({})
    await mountWithApp(ActivateView, { path: '/activate' })

    expect(api).not.toHaveBeenCalled()
    expect(pageText()).toContain('No activation token was provided.')
  })
})
