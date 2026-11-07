import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import UsersView from '@/views/admin/UsersView.vue'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  confirmDialog,
  answerConfirm,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  typeInto,
  apiCalls,
} from './helpers/page'

const ada = testUser({
  user_id: 1,
  email: 'ada@example.test',
  display_name: 'Ada',
  is_admin: true,
  google_linked: true,
  has_passkeys: true,
  registered_at: '2026-01-05 09:00:00',
  last_login_at: '2026-10-20 18:30:00',
})
const bea = testUser({
  user_id: 2,
  email: 'bea@example.test',
  display_name: 'Bea',
  is_active: false,
  registered_at: '2026-10-01 12:00:00',
})
const cy = testUser({
  user_id: 3,
  email: 'cy@example.test',
  display_name: 'Cy',
  registered_at: '2026-03-15 08:00:00',
})
const users = [ada, bea, cy]

describe('admin UsersView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('lists accounts newest first, with their status and dates', async () => {
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })

      expect(tableRows()).toEqual([
        ['2', 'bea@example.test', 'Bea', 'Pending', formatDateTime(bea.registered_at), '—', ''],
        ['3', 'cy@example.test', 'Cy', 'Active', formatDateTime(cy.registered_at), '—', ''],
        [
          '1',
          'ada@example.test',
          'Ada',
          'Active',
          formatDateTime(ada.registered_at),
          formatDateTime(ada.last_login_at),
          '',
        ],
      ])
    })

    it('marks admins, Google-linked accounts and passkey holders beside the name', async () => {
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })

      const markers = (name: string) =>
        [...row(name).cells[2]!.querySelectorAll('svg')].map((svg) => svg.dataset.icon)
      expect(markers('Ada')).toEqual(['user-shield', 'google', 'key'])
      expect(markers('Cy')).toEqual([])
    })

    it('offers Activate and Resend Email only for accounts still pending', async () => {
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })

      expect(hasButton('Activate', row('Bea'))).toBe(true)
      expect(hasButton('Resend Email', row('Bea'))).toBe(true)
      expect(hasButton('Activate', row('Cy'))).toBe(false)
      expect(hasButton('Resend Email', row('Cy'))).toBe(false)
    })

    it('says so when there are no accounts', async () => {
      stubApi({ 'GET /admin/users': { body: [] } })
      await mountPage(UsersView, { path: '/admin/users' })

      expect(document.body.textContent).toContain('No accounts yet.')
    })

    it('filters by email, name or ID as the admin types', async () => {
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })
      const search = labelled('Search by email, name, or ID...')

      await typeInto(search, 'BEA@')
      expect(column(2)).toEqual(['Bea'])

      await typeInto(search, 'cy')
      expect(column(2)).toEqual(['Cy'])

      await typeInto(search, '1')
      expect(column(2)).toEqual(['Ada'])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })

      await click(header('Status'))
      expect(column(3)).toEqual(['Pending', 'Active', 'Active'])

      await click(header('Last Login'))
      expect(column(2)).toEqual(['Bea', 'Cy', 'Ada'])

      await click(header('Last Login'))
      expect(column(2)[0]).toBe('Ada')
    })

    it('drops to the login page when the session has expired', async () => {
      stubApi({ 'GET /admin/users': { status: 401, body: { error: 'Unauthorized' } } })
      const { router } = await mountPage(UsersView, { path: '/admin/users' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
    })
  })

  describe('pending accounts', () => {
    it('activates an account and reloads the list', async () => {
      let activated = false
      const api = stubApi({
        'GET /admin/users': () => ({
          body: activated ? users.map((u) => (u === bea ? { ...bea, is_active: true } : u)) : users,
        }),
        'POST /admin/users/2/activate': () => {
          activated = true
          return { body: { success: true } }
        },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      api.mockClear()

      await click(button('Activate', row('Bea')))

      expect(apiCalls(api)).toEqual([
        ['POST', '/api/admin/users/2/activate', {}],
        ['GET', '/api/admin/users'],
      ])
      expect(toasts()).toEqual(['Bea activated.'])
      expect(row('Bea').cells[3]!.textContent.trim()).toBe('Active')
    })

    it("shows the server's reason when activating fails", async () => {
      const api = stubApi({
        'GET /admin/users': { body: users },
        'POST /admin/users/2/activate': { status: 404, body: { error: 'User not found.' } },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      api.mockClear()

      await click(button('Activate', row('Bea')))

      expect(apiCalls(api)).toEqual([['POST', '/api/admin/users/2/activate', {}]])
      expect(toasts()).toEqual(['User not found.'])
    })

    it('confirms an activation email went out', async () => {
      const api = stubApi({
        'GET /admin/users': { body: users },
        'POST /admin/users/2/resend-activation': { body: { success: true, emailed: true } },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      api.mockClear()

      await click(button('Resend Email', row('Bea')))

      expect(apiCalls(api)).toEqual([['POST', '/api/admin/users/2/resend-activation', {}]])
      expect(toasts()).toEqual(['Activation email sent to bea@example.test.'])
    })

    it('shows the activation link itself when email is not configured', async () => {
      const link = 'https://tarotgen.test/activate?token=abc'
      stubApi({
        'GET /admin/users': { body: users },
        'POST /admin/users/2/resend-activation': {
          body: { success: true, emailed: false, activation_link: link },
        },
      })
      await mountPage(UsersView, { path: '/admin/users' })

      await click(button('Resend Email', row('Bea')))

      expect(toasts()).toEqual([`Email not configured. Activation link: ${link}`])
    })

    it('says the link was regenerated when it was neither emailed nor returned', async () => {
      stubApi({
        'GET /admin/users': { body: users },
        'POST /admin/users/2/resend-activation': { body: { success: true, emailed: false } },
      })
      await mountPage(UsersView, { path: '/admin/users' })

      await click(button('Resend Email', row('Bea')))

      expect(toasts()).toEqual(['Activation link regenerated.'])
    })

    it("shows the server's reason when resending fails", async () => {
      stubApi({
        'GET /admin/users': { body: users },
        'POST /admin/users/2/resend-activation': {
          status: 400,
          body: { error: 'Account is already active.' },
        },
      })
      await mountPage(UsersView, { path: '/admin/users' })

      await click(button('Resend Email', row('Bea')))

      expect(toasts()).toEqual(['Account is already active.'])
    })
  })

  describe('the admin flag', () => {
    it('asks before granting it, and grants nothing on Cancel', async () => {
      const api = stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })
      api.mockClear()

      await click(button('Make Admin', row('Cy')))
      expect(confirmDialog()?.textContent).toContain('Give Cy (cy@example.test) the admin flag?')

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('grants it on confirmation and reloads the list', async () => {
      const api = stubApi({
        'GET /admin/users': { body: users },
        'PATCH /admin/users/3': { body: { ...cy, is_admin: true } },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      await click(button('Make Admin', row('Cy')))
      expect(confirmDialog()?.textContent).toContain('Make admin')
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['PATCH', '/api/admin/users/3', { is_admin: true }],
        ['GET', '/api/admin/users'],
      ])
      expect(toasts()).toEqual(['Cy is now an admin.'])
    })

    it('revokes it from an admin after a warning', async () => {
      const api = stubApi({
        'GET /admin/users': { body: users },
        'PATCH /admin/users/1': { body: { ...ada, is_admin: false } },
      })
      await mountPage(UsersView, { path: '/admin/users' })

      await click(button('Revoke Admin', row('Ada')))
      expect(confirmDialog()?.textContent).toContain(
        'Remove the admin flag from Ada (ada@example.test)?',
      )
      api.mockClear()
      await answerConfirm('confirm')

      expect(apiCalls(api)[0]).toEqual(['PATCH', '/api/admin/users/1', { is_admin: false }])
      expect(toasts()).toEqual(['Admin removed from Ada.'])
    })

    it("shows the server's reason when the change is refused", async () => {
      // Say the account was made protected after the page loaded.
      const refusal = "This account is protected: its admin access can't be removed."
      const api = stubApi({
        'GET /admin/users': { body: users },
        'PATCH /admin/users/1': { status: 403, body: { error: refusal } },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      await click(button('Revoke Admin', row('Ada')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toHaveLength(1)
      expect(toasts()).toEqual([refusal])
      expect(hasButton('Revoke Admin', row('Ada'))).toBe(true)
    })
  })

  describe("accounts that can't be demoted or deleted", () => {
    it('offers neither Revoke Admin nor Delete on your own row', async () => {
      signIn(ada)
      stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })

      expect(hasButton('Revoke Admin', row('Ada'))).toBe(false)
      expect(hasButton('Delete', row('Ada'))).toBe(false)
      expect(hasButton('Delete', row('Cy'))).toBe(true)
    })

    it('offers neither on a protected account, but still lets one be made admin', async () => {
      const protectedAdmin = { ...ada, is_protected: true }
      const protectedReader = { ...cy, is_protected: true }
      stubApi({ 'GET /admin/users': { body: [protectedAdmin, bea, protectedReader] } })
      await mountPage(UsersView, { path: '/admin/users' })

      expect(hasButton('Revoke Admin', row('Ada'))).toBe(false)
      expect(hasButton('Delete', row('Ada'))).toBe(false)
      expect(hasButton('Make Admin', row('Cy'))).toBe(true)
      expect(hasButton('Delete', row('Cy'))).toBe(false)
      expect(hasButton('Delete', row('Bea'))).toBe(true)
    })
  })

  describe('deleting an account', () => {
    it('warns that the readings go too, and deletes nothing on Cancel', async () => {
      const api = stubApi({ 'GET /admin/users': { body: users } })
      await mountPage(UsersView, { path: '/admin/users' })
      api.mockClear()

      await click(button('Delete', row('Cy')))
      expect(confirmDialog()?.textContent).toContain(
        'Permanently delete Cy (cy@example.test)? This also deletes ALL of their saved readings and cannot be undone.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and drops the account from the list', async () => {
      let deleted = false
      const api = stubApi({
        'GET /admin/users': () => ({ body: deleted ? [ada, bea] : users }),
        'DELETE /admin/users/3': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      await click(button('Delete', row('Cy')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/users/3'],
        ['GET', '/api/admin/users'],
      ])
      expect(toasts()).toEqual(['Account deleted.'])
      expect(column(2)).toEqual(['Bea', 'Ada'])
    })

    it('keeps the account listed when the delete fails', async () => {
      stubApi({
        'GET /admin/users': { body: users },
        'DELETE /admin/users/3': { status: 404, body: { error: 'User not found.' } },
      })
      await mountPage(UsersView, { path: '/admin/users' })
      await click(button('Delete', row('Cy')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['User not found.'])
      expect(column(2)).toContain('Cy')
    })
  })
})
