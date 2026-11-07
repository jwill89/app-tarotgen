import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ResetPasswordView from '@/views/ResetPasswordView.vue'
import { mountWithApp, cleanup, stubApi } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)

function button(name: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].filter((b) => text(b) === name)
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

function labelled(label: string): HTMLElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const control = el && document.getElementById(el.htmlFor)
  if (!control) {
    throw new Error(`Nothing is labelled "${label}"`)
  }
  return control
}

function field(label: string): HTMLInputElement {
  const input = labelled(label)
  if (!(input instanceof HTMLInputElement)) {
    throw new Error(`"${label}" is not a text field`)
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

const link = (name: string) =>
  [...document.body.querySelectorAll('a')].find((a) => text(a) === name)?.getAttribute('href')

describe('ResetPasswordView', () => {
  afterEach(cleanup)

  it('explains a link without its token and offers a new one', async () => {
    stubApi({})
    await mountWithApp(ResetPasswordView, { path: '/reset-password' })

    expect(pageText()).toContain('This reset link is missing its token.')
    expect(document.body.querySelector('form')).toBeNull()
    expect(link('Request a new link')).toBe('/forgot-password')
  })

  it('sets the new password with the token from the link', async () => {
    const api = stubApi({ 'POST /auth/reset-password': { body: { message: 'Password updated.' } } })
    await mountWithApp(ResetPasswordView, { path: '/reset-password?token=tok123' })

    await type('New Password', 'a new passphrase')
    await type('Confirm New Password', 'a new passphrase')
    await click(button('Update Password'))

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      token: 'tok123',
      password: 'a new passphrase',
    })
    expect(pageText()).toContain('Your password has been updated. You can now log in.')
    expect(link('Log In')).toBe('/login')
  })

  it('catches mismatched passwords before sending', async () => {
    const api = stubApi({})
    await mountWithApp(ResetPasswordView, { path: '/reset-password?token=tok123' })

    await type('New Password', 'a new passphrase')
    await type('Confirm New Password', 'something else')
    expect(pageText()).toContain('Passwords do not match.')
    await click(button('Update Password'))

    expect(api).not.toHaveBeenCalled()
    expect(document.body.querySelector('.notification')?.textContent).toContain(
      'Passwords do not match.',
    )
  })

  it("shows the server's reason and keeps the form for another try", async () => {
    stubApi({
      'POST /auth/reset-password': {
        status: 400,
        body: { error: 'This reset link is invalid or has expired.' },
      },
    })
    await mountWithApp(ResetPasswordView, { path: '/reset-password?token=old' })

    await type('New Password', 'a new passphrase')
    await type('Confirm New Password', 'a new passphrase')
    await click(button('Update Password'))

    expect(pageText()).toContain('This reset link is invalid or has expired.')
    expect(field('New Password').value).toBe('a new passphrase')
  })

  it('can show the password as typed', async () => {
    stubApi({})
    await mountWithApp(ResetPasswordView, { path: '/reset-password?token=tok123' })

    await click(labelled('Show password'))

    expect(field('New Password').type).toBe('text')
  })
})
