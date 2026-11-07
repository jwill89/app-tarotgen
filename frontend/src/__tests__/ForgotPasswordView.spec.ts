import { describe, it, expect, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { mountWithApp, cleanup, stubApi } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)

async function requestFor(email: string) {
  const input = document.getElementById('fp-email')
  if (!(input instanceof HTMLInputElement)) {
    throw new Error('No email field')
  }
  input.value = email
  input.dispatchEvent(new Event('input'))
  const send = [...document.body.querySelectorAll('button')].find(
    (b) => text(b) === 'Send Reset Link',
  )
  send?.click()
  await flushPromises()
}

describe('ForgotPasswordView', () => {
  afterEach(cleanup)

  it('asks for the reset email and shows the same answer whoever asks', async () => {
    const api = stubApi({
      'POST /auth/forgot-password': {
        body: {
          message: 'If an account exists for that email, a password-reset link has been sent.',
        },
      },
    })
    await mountWithApp(ForgotPasswordView, { path: '/forgot-password' })

    await requestFor('reader@example.test')

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      email: 'reader@example.test',
    })
    expect(pageText()).toContain(
      'If an account exists for that email, a password-reset link has been sent.',
    )
    expect(document.body.querySelector('form')).toBeNull()
  })

  it('offers the reset link directly where the server sends no email', async () => {
    stubApi({
      'POST /auth/forgot-password': {
        body: { message: 'Sent.', reset_link: 'http://localhost/reset-password?token=t' },
      },
    })
    await mountWithApp(ForgotPasswordView, { path: '/forgot-password' })

    await requestFor('reader@example.test')

    const link = [...document.body.querySelectorAll('a')].find(
      (a) => text(a) === 'Reset my password',
    )
    expect(link?.getAttribute('href')).toBe('http://localhost/reset-password?token=t')
  })

  // Both used to land on the green "sent" notice with the form gone, though
  // nothing had been sent.
  it('reports a rate limit as a failure and keeps the form for later', async () => {
    stubApi({
      'POST /auth/forgot-password': {
        status: 429,
        body: { error: 'Too many requests. Please try again later.' },
      },
    })
    await mountWithApp(ForgotPasswordView, { path: '/forgot-password' })

    await requestFor('reader@example.test')

    expect(document.body.querySelector('.notification.is-danger')?.textContent).toContain(
      'Too many requests. Please try again later.',
    )
    expect(document.body.querySelector('.notification.is-success')).toBeNull()
    expect(document.body.querySelector('form')).not.toBeNull()
  })

  it('reports a network failure and lets the user try again', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await mountWithApp(ForgotPasswordView, { path: '/forgot-password' })

    await requestFor('reader@example.test')
    expect(pageText()).toContain(NETWORK_ERROR_MESSAGE)

    stubApi({ 'POST /auth/forgot-password': { body: { message: 'Sent.' } } })
    await requestFor('reader@example.test')

    expect(pageText()).not.toContain(NETWORK_ERROR_MESSAGE)
    expect(pageText()).toContain('Sent.')
  })
})
