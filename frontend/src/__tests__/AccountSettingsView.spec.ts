import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import AccountSettingsView from '@/views/AccountSettingsView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { useUser } from '@/composables/useUser'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'
import type { Passkey, PluginToken } from '@/types'

// The view inside what App.vue gives it: the TooltipProvider its Tooltips need
// and the shared ConfirmDialog its confirm() calls open.
const Page = defineComponent({
  render: () => h(TooltipProvider, null, () => [h(AccountSettingsView), h(ConfirmDialog)]),
})

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()

function button(name: string, root: ParentNode = document.body): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].filter(
    (b) => text(b) === name || b.getAttribute('aria-label') === name,
  )
  const [only] = found
  if (!only || found.length !== 1) {
    throw new Error(`Expected one "${name}" button, found ${found.length}`)
  }
  return only
}

/** A control by the label, heading or aria-label that names it. */
function field(name: string): HTMLInputElement {
  const label = [...document.body.querySelectorAll('label')].find((l) => text(l) === name)
  const heading = [...document.body.querySelectorAll('h2')].find((h2) => text(h2) === name)
  const el = label
    ? document.getElementById(label.htmlFor)
    : heading
      ? document.querySelector(`[aria-labelledby="${heading.id}"]`)
      : document.querySelector(`[aria-label="${name}"]`)
  if (!(el instanceof HTMLInputElement)) {
    throw new Error(`No field named "${name}"`)
  }
  return el
}

async function type(name: string, value: string) {
  const el = field(name)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

const confirmDialog = () => document.body.querySelector<HTMLElement>('[role="alertdialog"]')
const confirmText = () => text(confirmDialog() ?? document.createElement('p'))

async function answer(choice: string) {
  const open = confirmDialog()
  if (!open) {
    throw new Error('No confirm dialog is open')
  }
  await click(button(choice, open))
}

const toastMessages = () => useToasts().toasts.value.map((t) => t.message)
const pageText = () => text(document.body)
/** Each call the page made, as `'METHOD /path'` (after /api, query dropped). */
const calledWith = (api: ReturnType<typeof stubApi>): string[] =>
  api.mock.calls.map((call) => {
    const url: string = call[0]
    const init: RequestInit | undefined = call[1]
    const path = new URL(url, 'https://tarotgen.test').pathname.replace(/^\/api/, '')
    return `${init?.method ?? 'GET'} ${path}`
  })
/** The JSON body of the first `'METHOD /path'` call, if it had one. */
const sent = (api: ReturnType<typeof stubApi>, key: string): unknown => {
  const found = calledWith(api).indexOf(key)
  if (found < 0) {
    return undefined
  }
  const init: RequestInit | undefined = api.mock.calls[found]![1]
  return typeof init?.body === 'string' ? JSON.parse(init.body) : undefined
}

const passkey = (passkey_id: number, name: string): Passkey => ({
  passkey_id,
  credential_id: `cred-${passkey_id}`,
  name,
  created_at: '2026-09-01 10:00:00',
  last_used_at: null,
})

const pluginToken = (id: number, label: string, revoked_at: string | null = null): PluginToken => ({
  id,
  user_id: 7,
  label,
  scope: 'plugin',
  created_at: '2026-09-01 10:00:00',
  last_used_at: '2026-10-01 10:00:00',
  expires_at: null,
  revoked_at,
})

/** Every call the page makes on load, plus the test's own. */
function stub(handlers: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({ 'GET /account/tokens': { body: [] }, ...handlers })
}

/**
 * Turn on WebAuthn for the page: jsdom has no PublicKeyCredential and its
 * navigator.credentials is null (vitest.setup.ts), so the passkey panel would
 * stay hidden.
 */
function supportPasskeys(create = vi.fn()) {
  vi.stubGlobal('PublicKeyCredential', vi.fn())
  const credentials: CredentialsContainer = {
    create,
    get: vi.fn(),
    preventSilentAccess: vi.fn(),
    store: vi.fn(),
  }
  vi.spyOn(navigator, 'credentials', 'get').mockReturnValue(credentials)
}

async function mountSettings(path = '/account/settings') {
  return mountWithApp(Page, { path })
}

describe('AccountSettingsView', () => {
  beforeEach(() => {
    signIn(testUser({ display_name: 'Reader' }))
    useToasts().toasts.value = []
    useConfirm().settle(false)
  })
  afterEach(cleanup)

  describe('display name', () => {
    it('starts from the current name and saves the new one', async () => {
      const api = stub({
        'PATCH /account': { body: { user: testUser({ display_name: 'Morgan' }) } },
      })
      await mountSettings()
      expect(field('Display Name').value).toBe('Reader')

      await type('Display Name', 'Morgan')
      await click(button('Save Name'))

      expect(sent(api, 'PATCH /account')).toEqual({ display_name: 'Morgan' })
      expect(toastMessages()).toEqual(['Display name updated.'])
      expect(useUser().currentUser.value?.display_name).toBe('Morgan')
    })

    it("shows why the name wasn't saved", async () => {
      stub({ 'PATCH /account': { status: 422, body: { error: 'That display name is taken.' } } })
      await mountSettings()

      await type('Display Name', 'Admin')
      await click(button('Save Name'))

      expect(pageText()).toContain('That display name is taken.')
      expect(toastMessages()).toEqual([])
    })
  })

  describe('password', () => {
    it('catches mismatched new passwords before asking the server', async () => {
      const api = stub()
      await mountSettings()

      await type('Current Password', 'old pass')
      await type('New Password', 'a new passphrase')
      await type('Confirm New Password', 'a new passphrasf')
      await click(button('Change Password'))

      expect(pageText()).toContain('New passwords do not match.')
      expect(calledWith(api)).toEqual(['GET /account/tokens'])
    })

    it('changes the password and clears the form', async () => {
      const api = stub({ 'POST /account/change-password': { body: { message: 'Changed.' } } })
      await mountSettings()

      await type('Current Password', 'old pass')
      await type('New Password', 'a new passphrase')
      await type('Confirm New Password', 'a new passphrase')
      await click(button('Change Password'))

      expect(sent(api, 'POST /account/change-password')).toEqual({
        current_password: 'old pass',
        new_password: 'a new passphrase',
      })
      expect(toastMessages()).toEqual(['Password changed. Your other sessions were signed out.'])
      expect(
        ['Current Password', 'New Password', 'Confirm New Password'].map((f) => field(f).value),
      ).toEqual(['', '', ''])
    })

    it('keeps what was typed and says why when the change is refused', async () => {
      stub({
        'POST /account/change-password': {
          status: 403,
          body: { error: 'Current password is incorrect.' },
        },
      })
      await mountSettings()

      await type('Current Password', 'wrong')
      await type('New Password', 'a new passphrase')
      await type('Confirm New Password', 'a new passphrase')
      await click(button('Change Password'))

      expect(pageText()).toContain('Current password is incorrect.')
      expect(field('New Password').value).toBe('a new passphrase')
    })
  })

  describe('Google account', () => {
    it('sends the browser to Google to link an account', async () => {
      vi.stubGlobal('location', { href: 'https://tarotgen.test/account/settings' })
      stub()
      await mountSettings()

      await click(button('Link Google Account'))

      expect(window.location.href).toBe('/api/auth/google?intent=link')
    })

    it('unlinks Google and shows the account as unlinked', async () => {
      signIn(testUser({ google_linked: true }))
      const api = stub({
        'POST /auth/google/unlink': { body: { message: 'Unlinked.' } },
        'GET /auth/me': { body: { user: testUser({ google_linked: false }) } },
      })
      await mountSettings()
      expect(pageText()).toContain('Your Google account is linked.')

      await click(button('Unlink Google'))

      expect(calledWith(api)).toContain('GET /auth/me')
      expect(toastMessages()).toEqual(['Google account unlinked.'])
      expect(button('Link Google Account')).toBeTruthy()
    })

    it("explains why Google can't be unlinked", async () => {
      signIn(testUser({ google_linked: true }))
      const refusal =
        'That would leave you no way to sign in. First turn password login back on or add a passkey.'
      stub({ 'POST /auth/google/unlink': { status: 409, body: { error: refusal } } })
      await mountSettings()

      await click(button('Unlink Google'))

      expect(pageText()).toContain(refusal)
      expect(button('Unlink Google')).toBeTruthy()
    })

    it("doesn't offer to unlink Google while it's the only way to sign in", async () => {
      signIn(testUser({ google_linked: true, has_password: false }))
      stub()
      await mountSettings()

      expect(button('Unlink Google').disabled).toBe(true)
      expect(pageText()).toContain(
        'Google is your only way to sign in. To unlink it, first set a password or add a passkey.',
      )
    })

    it('offers to unlink Google once a passkey can sign in instead', async () => {
      signIn(testUser({ google_linked: true, has_password: false, has_passkeys: true }))
      stub()
      await mountSettings()

      expect(button('Unlink Google').disabled).toBe(false)
    })

    it('shows the error Google sign-in came back with, once', async () => {
      stub()
      const { router } = await mountSettings('/account/settings?oauth_error=Access%20denied.')

      expect(pageText()).toContain('Access denied.')
      expect(router.currentRoute.value.query).toEqual({})
    })

    it('confirms a completed link and refreshes the account', async () => {
      const api = stub({ 'GET /auth/me': { body: { user: testUser({ google_linked: true }) } } })
      const { router } = await mountSettings('/account/settings?oauth_success=Google%20linked.')

      expect(toastMessages()).toEqual(['Google linked.'])
      expect(router.currentRoute.value.query).toEqual({})
      expect(calledWith(api)).toContain('GET /auth/me')
      expect(button('Unlink Google')).toBeTruthy()
    })
  })

  describe('an account without a password', () => {
    beforeEach(() => {
      signIn(testUser({ google_linked: true, has_password: false }))
    })

    it('explains it signs in with Google instead of offering to change a password', async () => {
      stub()
      await mountSettings()

      expect(pageText()).toContain('Your account signs in with Google and has no password yet.')
      expect(document.getElementById('cur-pw')).toBeNull()
      expect([...document.body.querySelectorAll('button')].map(text)).not.toContain(
        'Change Password',
      )
    })

    it('emails a link to set one to the signed-in address', async () => {
      const api = stub({
        'POST /auth/forgot-password': { body: { success: true, message: 'Sent if it exists.' } },
      })
      await mountSettings()

      await click(button('Email me a link to set a password'))

      expect(sent(api, 'POST /auth/forgot-password')).toEqual({ email: 'reader@example.test' })
      expect(pageText()).toContain('Link sent. Open the email we just sent to set your password')
    })

    it('offers the link itself where the server sends no email', async () => {
      stub({
        'POST /auth/forgot-password': {
          body: { success: true, message: 'Sent.', reset_link: '/reset-password?token=abc' },
        },
      })
      await mountSettings()

      await click(button('Email me a link to set a password'))

      expect(document.body.querySelector('a[href="/reset-password?token=abc"]')).not.toBeNull()
      expect(pageText()).not.toContain('Link sent.')
    })

    it("says why the link wasn't sent", async () => {
      stub({
        'POST /auth/forgot-password': {
          status: 429,
          body: { error: 'Too many requests. Please try again later.' },
        },
      })
      await mountSettings()

      await click(button('Email me a link to set a password'))

      expect(pageText()).toContain('Too many requests. Please try again later.')
    })

    it('needs a password set before the account can be deleted', async () => {
      stub()
      await mountSettings()

      expect(pageText()).toContain(
        "Deleting your account needs your password, and it doesn't have one yet.",
      )
      expect(document.getElementById('del-pw')).toBeNull()
      expect([...document.body.querySelectorAll('button')].map(text)).not.toContain(
        'Delete My Account',
      )
    })

    it('has no password login to turn off', async () => {
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [] } })
      await mountSettings()

      expect(pageText()).not.toContain('Password Login')
    })
  })

  describe('passkeys', () => {
    it("isn't offered where the browser has no passkeys", async () => {
      stub()
      await mountSettings()

      expect(pageText()).not.toContain('Passkeys let you sign in')
    })

    it('lists the registered passkeys', async () => {
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone'), passkey(4, 'Laptop')] } })
      await mountSettings()

      expect(pageText()).toContain('Phone')
      expect(pageText()).toContain('Laptop')
      expect(pageText()).not.toContain('No passkeys registered yet.')
    })

    it('says when there are none yet', async () => {
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [] } })
      await mountSettings()

      expect(pageText()).toContain('No passkeys registered yet.')
    })

    /** The API side of a passkey registration that the server accepts. */
    /** The server's side of a registration: the key is listed once it's registered. */
    function registration(name: string) {
      let registered = false
      return {
        'GET /auth/passkeys': () => ({ body: registered ? [passkey(9, name)] : [] }),
        'POST /auth/passkeys/register/options': {
          body: { publicKey: { challenge: 'AQID', user: { id: 'BAUG' }, pubKeyCredParams: [] } },
        },
        'POST /auth/passkeys/register': () => {
          registered = true
          return { status: 201, body: { success: true, passkey: passkey(9, name) } }
        },
        'GET /auth/me': { body: { user: testUser({ has_passkeys: true }) } },
      }
    }
    const attestation = {
      response: {
        clientDataJSON: new Uint8Array([1]).buffer,
        attestationObject: new Uint8Array([2]).buffer,
      },
    }

    it('registers a passkey under the name given', async () => {
      const create = vi.fn().mockResolvedValue(attestation)
      supportPasskeys(create)
      const api = stub(registration('MacBook'))
      await mountSettings()

      await type('Passkey name', '  MacBook ')
      await click(button('Add Passkey'))

      expect(create).toHaveBeenCalledTimes(1)
      expect(sent(api, 'POST /auth/passkeys/register')).toMatchObject({ name: 'MacBook' })
      expect(toastMessages()).toEqual(['Passkey registered!'])
      expect(pageText()).toContain('MacBook')
      expect(field('Passkey name').value).toBe('')
    })

    it('names an unnamed passkey for the user', async () => {
      supportPasskeys(vi.fn().mockResolvedValue(attestation))
      const api = stub(registration('My Passkey'))
      await mountSettings()

      await click(button('Add Passkey'))

      expect(sent(api, 'POST /auth/passkeys/register')).toMatchObject({ name: 'My Passkey' })
    })

    it('says so when the passkey prompt is closed', async () => {
      supportPasskeys(vi.fn().mockResolvedValue(null))
      const api = stub(registration('Phone'))
      await mountSettings()

      await type('Passkey name', 'Phone')
      await click(button('Add Passkey'))

      expect(calledWith(api)).not.toContain('POST /auth/passkeys/register')
      expect(pageText()).toContain('Passkey creation was cancelled.')
      expect(field('Passkey name').value).toBe('Phone')
    })

    it('removes a passkey once the user confirms', async () => {
      supportPasskeys()
      const api = stub({
        'GET /auth/passkeys': { body: [passkey(3, 'Phone'), passkey(4, 'Laptop')] },
        'DELETE /auth/passkeys/3': { status: 204 },
        'GET /auth/me': { body: { user: testUser({ has_passkeys: true }) } },
      })
      await mountSettings()

      const removeButtons = [...document.body.querySelectorAll('button')].filter(
        (b) => b.getAttribute('aria-label') === 'Remove',
      )
      await click(removeButtons[0]!)
      expect(confirmText()).toContain('Remove "Phone"?')
      await answer('Remove')

      expect(calledWith(api)).toContain('DELETE /auth/passkeys/3')
      expect(toastMessages()).toEqual(['Passkey removed.'])
      expect(pageText()).not.toContain('Phone')
    })

    it('keeps a passkey when the user cancels', async () => {
      supportPasskeys()
      const api = stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone')] } })
      await mountSettings()

      await click(button('Remove'))
      await answer('Cancel')

      expect(calledWith(api)).not.toContain('DELETE /auth/passkeys/3')
      expect(pageText()).toContain('Phone')
    })

    it('renames a passkey to what the user types', async () => {
      supportPasskeys()
      vi.stubGlobal(
        'prompt',
        vi.fn(() => ' Work phone '),
      )
      const api = stub({
        'GET /auth/passkeys': { body: [passkey(3, 'Phone')] },
        'PATCH /auth/passkeys/3': { status: 204 },
      })
      await mountSettings()

      await click(button('Rename'))

      expect(sent(api, 'PATCH /auth/passkeys/3')).toEqual({ name: 'Work phone' })
      expect(toastMessages()).toEqual(['Passkey renamed.'])
      expect(pageText()).toContain('Work phone')
    })

    it('leaves the name alone when the rename prompt is dismissed or unchanged', async () => {
      supportPasskeys()
      const api = stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone')] } })
      await mountSettings()

      vi.stubGlobal(
        'prompt',
        vi.fn(() => null),
      )
      await click(button('Rename'))
      vi.stubGlobal(
        'prompt',
        vi.fn(() => 'Phone'),
      )
      await click(button('Rename'))

      expect(calledWith(api)).toEqual(['GET /auth/passkeys', 'GET /account/tokens'])
    })

    it('shows why a rename failed', async () => {
      supportPasskeys()
      vi.stubGlobal(
        'prompt',
        vi.fn(() => 'x'.repeat(60)),
      )
      stub({
        'GET /auth/passkeys': { body: [passkey(3, 'Phone')] },
        'PATCH /auth/passkeys/3': { status: 422, body: { error: 'Name is too long.' } },
      })
      await mountSettings()

      await click(button('Rename'))

      expect(pageText()).toContain('Name is too long.')
    })

    it("won't turn off password login before there is a passkey to sign in with", async () => {
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [] } })
      await mountSettings()

      expect(button('Disable Password Login').disabled).toBe(true)
      expect(pageText()).toContain(
        'Add a passkey or link your Google account first, so you can still sign in.',
      )
    })

    it('will turn off password login with Google linked instead of a passkey', async () => {
      signIn(testUser({ google_linked: true }))
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [] } })
      await mountSettings()

      expect(button('Disable Password Login').disabled).toBe(false)
    })

    it("won't remove the last passkey while it's the only way to sign in", async () => {
      signIn(testUser({ has_passkeys: true, password_login_disabled: true }))
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone')] } })
      await mountSettings()

      expect(button('Remove').disabled).toBe(true)
      expect(pageText()).toContain(
        'This passkey is your only way to sign in. To remove it, first turn password login back on or link your Google account.',
      )
    })

    it('will remove the last passkey while Google can sign in instead', async () => {
      signIn(testUser({ has_passkeys: true, password_login_disabled: true, google_linked: true }))
      supportPasskeys()
      stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone')] } })
      await mountSettings()

      expect(button('Remove').disabled).toBe(false)
    })

    it('turns password login off after a warning, then offers to turn it back on', async () => {
      signIn(testUser({ has_passkeys: true }))
      supportPasskeys()
      const passwordLogin = (disabled: boolean) => ({
        body: { user: testUser({ has_passkeys: true, password_login_disabled: disabled }) },
      })
      const api = stub({
        'GET /auth/passkeys': { body: [passkey(3, 'Phone')] },
        'PATCH /auth/passkeys/password-login': passwordLogin(true),
      })
      await mountSettings()

      await click(button('Disable Password Login'))
      expect(confirmText()).toContain('You will only be able to sign in with a passkey or Google.')
      await answer('Disable password login')

      expect(sent(api, 'PATCH /auth/passkeys/password-login')).toEqual({ disable: true })
      expect(toastMessages()).toEqual(['Password login disabled.'])

      // Turning it back on needs no warning.
      const again = stub({ 'PATCH /auth/passkeys/password-login': passwordLogin(false) })
      await click(button('Re-enable Password Login'))
      expect(confirmDialog()).toBeNull()
      expect(sent(again, 'PATCH /auth/passkeys/password-login')).toEqual({ disable: false })
      expect(toastMessages()).toEqual(['Password login disabled.', 'Password login re-enabled.'])
      expect(button('Disable Password Login')).toBeTruthy()
    })

    it('keeps password login on when the warning is cancelled', async () => {
      signIn(testUser({ has_passkeys: true }))
      supportPasskeys()
      const api = stub({ 'GET /auth/passkeys': { body: [passkey(3, 'Phone')] } })
      await mountSettings()

      await click(button('Disable Password Login'))
      await answer('Cancel')

      expect(calledWith(api)).not.toContain('PATCH /auth/passkeys/password-login')
    })
  })

  describe('connected apps', () => {
    it('lists the linked apps, revoked ones without a Revoke button', async () => {
      stub({
        'GET /account/tokens': {
          body: [
            pluginToken(1, 'FFXIV plugin (Lyra)'),
            pluginToken(2, 'Old install', '2026-10-02'),
          ],
        },
      })
      await mountSettings()

      expect(pageText()).toContain('FFXIV plugin (Lyra)')
      expect(pageText()).toMatch(/Old install.*Revoked/)
      expect(
        [...document.body.querySelectorAll('button')].filter((b) => text(b) === 'Revoke'),
      ).toHaveLength(1)
    })

    it('says when no apps are linked', async () => {
      stub()
      await mountSettings()

      expect(pageText()).toContain('No linked apps.')
    })

    it('revokes an app once the user confirms, then reloads the list', async () => {
      let tokens = [pluginToken(1, 'FFXIV plugin (Lyra)')]
      const api = stub({
        'GET /account/tokens': () => ({ body: tokens }),
        'DELETE /account/tokens/1': () => {
          tokens = [pluginToken(1, 'FFXIV plugin (Lyra)', '2026-10-28')]
          return { status: 204 }
        },
      })
      await mountSettings()

      await click(button('Revoke'))
      expect(confirmText()).toContain('Revoke "FFXIV plugin (Lyra)"?')
      await answer('Revoke')

      expect(calledWith(api)).toEqual([
        'GET /account/tokens',
        'DELETE /account/tokens/1',
        'GET /account/tokens',
      ])
      expect(toastMessages()).toEqual(['Plugin access revoked.'])
      expect(pageText()).toMatch(/FFXIV plugin \(Lyra\).*Revoked/)
    })

    it('says so when a revoke fails', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      stub({
        'GET /account/tokens': { body: [pluginToken(1, 'FFXIV plugin')] },
        'DELETE /account/tokens/1': { status: 500 },
      })
      await mountSettings()

      await click(button('Revoke'))
      await answer('Revoke')

      expect(toastMessages()).toEqual(['Could not revoke access. Please try again.'])
    })
  })

  describe('signing out of other devices', () => {
    it('signs out every other device once the user confirms, saying how many', async () => {
      const api = stub({
        'POST /account/sessions/end-others': { body: { success: true, ended: 2 } },
      })
      await mountSettings()

      await click(button('Sign out of all other devices'))
      expect(confirmText()).toContain('This browser stays signed in.')
      await answer('Sign out other devices')

      expect(calledWith(api)).toEqual(['GET /account/tokens', 'POST /account/sessions/end-others'])
      expect(toastMessages()).toEqual(['Signed out of 2 other devices.'])
      expect(useUser().isLoggedIn.value).toBe(true)
    })

    it('counts a single device as one', async () => {
      stub({ 'POST /account/sessions/end-others': { body: { success: true, ended: 1 } } })
      await mountSettings()

      await click(button('Sign out of all other devices'))
      await answer('Sign out other devices')

      expect(toastMessages()).toEqual(['Signed out of 1 other device.'])
    })

    it('says so when no other device was signed in', async () => {
      stub({ 'POST /account/sessions/end-others': { body: { success: true, ended: 0 } } })
      await mountSettings()

      await click(button('Sign out of all other devices'))
      await answer('Sign out other devices')

      expect(toastMessages()).toEqual(['No other devices were signed in.'])
    })

    it('signs nothing out when the user thinks better of it', async () => {
      const api = stub()
      await mountSettings()

      await click(button('Sign out of all other devices'))
      await answer('Cancel')

      expect(calledWith(api)).toEqual(['GET /account/tokens'])
      expect(toastMessages()).toEqual([])
    })

    it("shows the server's reason when the devices can't be signed out", async () => {
      stub({
        'POST /account/sessions/end-others': {
          status: 429,
          body: { error: 'Slow down a moment.' },
        },
      })
      await mountSettings()

      await click(button('Sign out of all other devices'))
      await answer('Sign out other devices')

      expect(pageText()).toContain('Slow down a moment.')
      expect(toastMessages()).toEqual([])
      expect(button('Sign out of all other devices').classList.contains('is-loading')).toBe(false)
    })
  })

  describe('exporting the journal', () => {
    it('downloads the journal as JSON or CSV through plain links', async () => {
      stub()
      await mountSettings()

      const links = [...document.body.querySelectorAll<HTMLAnchorElement>('a[download]')].map(
        (a) => [text(a), a.getAttribute('href')],
      )
      expect(links).toEqual([
        ['Download JSON', '/api/account/readings/export?format=json'],
        ['Download CSV', '/api/account/readings/export?format=csv'],
      ])
      expect(pageText()).toContain("a reading's name and notes repeat on each of its cards' rows")
    })
  })

  describe('deleting the account', () => {
    it('asks for the password before anything else', async () => {
      stub()
      await mountSettings()

      await click(button('Delete My Account'))

      expect(pageText()).toContain('Enter your password to confirm.')
      expect(confirmDialog()).toBeNull()
    })

    it('deletes the account after a final warning and leaves for the home page', async () => {
      const api = stub({ 'DELETE /account': { status: 204 } })
      const { router } = await mountSettings()

      await type('Confirm your password', 'my password')
      await click(button('Delete My Account'))
      expect(confirmText()).toContain('cannot be undone')
      await answer('Delete my account')

      expect(sent(api, 'DELETE /account')).toEqual({ password: 'my password' })
      expect(toastMessages()).toEqual(['Your account has been deleted.'])
      expect(useUser().currentUser.value).toBeNull()
      // The home page is a lazy chunk, so the navigation outlasts one flush.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
    })

    it('does nothing when the warning is cancelled', async () => {
      const api = stub()
      await mountSettings()

      await type('Confirm your password', 'my password')
      await click(button('Delete My Account'))
      await answer('Cancel')

      expect(calledWith(api)).toEqual(['GET /account/tokens'])
      expect(useUser().isLoggedIn.value).toBe(true)
    })

    it('stays signed in and says why when the password is wrong', async () => {
      stub({ 'DELETE /account': { status: 403, body: { error: 'Incorrect password.' } } })
      const { router } = await mountSettings()

      await type('Confirm your password', 'wrong')
      await click(button('Delete My Account'))
      await answer('Delete my account')

      expect(pageText()).toContain('Incorrect password.')
      expect(useUser().isLoggedIn.value).toBe(true)
      expect(router.currentRoute.value.name).toBe('account-settings')
    })
  })
})
