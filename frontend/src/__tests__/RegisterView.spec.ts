import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import RegisterView from '@/views/RegisterView.vue'
import { mountWithApp, cleanup, stubApi, signIn } from './helpers/app'

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

/** The control a `<label>` with this text points at. */
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

async function fillIn(confirm = 'a long passphrase') {
  await type('Email', 'new@example.test')
  await type('Display Name', 'Newcomer')
  await type('Password', 'a long passphrase')
  await type('Confirm Password', confirm)
}

describe('RegisterView', () => {
  beforeEach(() => signIn(null))
  afterEach(cleanup)

  it('creates the account and says what happens next', async () => {
    const api = stubApi({
      'POST /auth/register': {
        status: 201,
        body: { message: 'Account created. Check new@example.test for the activation link.' },
      },
    })
    await mountWithApp(RegisterView, { path: '/register' })

    await fillIn()
    await click(button('Create Account'))

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      email: 'new@example.test',
      display_name: 'Newcomer',
      password: 'a long passphrase',
    })
    expect(pageText()).toContain('Account created. Check new@example.test for the activation link.')
    // Also how an email that already has an account is answered: no link to show.
    expect(pageText()).not.toContain('Dev shortcut')
    expect(document.body.querySelector('form')).toBeNull()
    expect(document.body.querySelector('a[href="/login"]')?.textContent).toContain('Go to Login')
  })

  it('offers the activation link directly where the server sends no email', async () => {
    stubApi({
      'POST /auth/register': {
        status: 201,
        body: { message: 'Account created.', activation_link: 'http://localhost/activate?token=t' },
      },
    })
    await mountWithApp(RegisterView, { path: '/register' })

    await fillIn()
    await click(button('Create Account'))

    const link = [...document.body.querySelectorAll('a')].find(
      (a) => text(a) === 'Activate my account',
    )
    expect(link?.getAttribute('href')).toBe('http://localhost/activate?token=t')
  })

  it('flags mismatched passwords as they are typed, and before sending', async () => {
    const api = stubApi({})
    await mountWithApp(RegisterView, { path: '/register' })

    await fillIn('a long passphrasf')
    expect(pageText()).toContain('Passwords do not match.')

    await click(button('Create Account'))

    expect(api).not.toHaveBeenCalled()
    expect(document.body.querySelector('.notification')?.textContent).toContain(
      'Passwords do not match.',
    )
  })

  it("lists each of the server's validation errors and keeps the form", async () => {
    stubApi({
      'POST /auth/register': {
        status: 422,
        body: {
          error: 'Validation failed.',
          errors: ['That email is already registered.', 'Password must be at least 12 characters.'],
        },
      },
    })
    await mountWithApp(RegisterView, { path: '/register' })

    await fillIn()
    await click(button('Create Account'))

    expect([...document.body.querySelectorAll('.notification li')].map(text)).toEqual([
      'That email is already registered.',
      'Password must be at least 12 characters.',
    ])
    expect(field('Email').value).toBe('new@example.test')
  })

  it('can show the password as typed', async () => {
    stubApi({})
    await mountWithApp(RegisterView, { path: '/register' })
    expect(field('Password').type).toBe('password')

    await click(labelled('Show password'))

    expect(field('Password').type).toBe('text')
    expect(field('Confirm Password').type).toBe('text')
  })

  it('sends the browser to Google to sign up', async () => {
    vi.stubGlobal('location', { href: 'https://tarotgen.test/register' })
    stubApi({})
    await mountWithApp(RegisterView, { path: '/register' })

    await click(button('Sign up with Google'))

    expect(window.location.href).toBe('/api/auth/google?intent=register')
  })

  it('shows the error a Google sign-up came back with, and tidies the address', async () => {
    stubApi({})
    const { router } = await mountWithApp(RegisterView, {
      path: '/register?oauth_error=That%20Google%20account%20is%20already%20registered.',
    })

    expect(pageText()).toContain('That Google account is already registered.')
    expect(router.currentRoute.value.query).toEqual({})
  })
})
