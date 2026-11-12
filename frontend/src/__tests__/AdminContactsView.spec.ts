import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import ContactsView from '@/views/admin/ContactsView.vue'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser, lazyLoad } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  dialog,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  typeInto,
  apiCalls,
} from './helpers/page'
import type { Contact } from '@/types'

const contact = (contact_id: number, name: string, overrides: Partial<Contact> = {}): Contact => ({
  contact_id,
  user_id: null,
  name,
  email: `${name.toLowerCase()}@example.test`,
  message: `A message from ${name}.`,
  is_read: 0,
  submitted_at: `2026-10-0${contact_id} 10:00:00`,
  ...overrides,
})

const unread = [
  contact(1, 'Ann', { message: 'The Thoth deck shows the wrong card for **The Fool**.' }),
  contact(3, 'Cal', { message: 'Can I submit my own deck?' }),
]
const read = contact(2, 'Bo', { is_read: 1 })

describe('admin ContactsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  it('lists the unread messages, newest first, with a mail link for each sender', async () => {
    const api = stubApi({ 'GET /admin/contacts': { body: unread } })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    expect(apiCalls(api)).toEqual([['GET', '/api/admin/contacts?show_read=0']])
    expect(tableRows()).toEqual([
      ['3', formatDateTime('2026-10-03 10:00:00'), 'Cal', 'cal@example.test', ''],
      ['1', formatDateTime('2026-10-01 10:00:00'), 'Ann', 'ann@example.test', ''],
    ])
    expect(row('Ann').querySelector('a')?.getAttribute('href')).toBe('mailto:ann@example.test')
  })

  it('says there are no unread messages when the inbox is clear', async () => {
    stubApi({ 'GET /admin/contacts': { body: [] } })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    expect(document.body.textContent).toContain('No contact submissions (unread).')
  })

  it('reloads with the read messages included, and back again', async () => {
    const api = stubApi({
      'GET /admin/contacts': (_body, url) => ({
        body: url.searchParams.get('show_read') === '1' ? [...unread, read] : unread,
      }),
    })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    await click(button('Show Read'))
    expect(column(2)).toEqual(['Cal', 'Bo', 'Ann'])
    expect(hasButton('Showing Read')).toBe(true)

    await click(button('Showing Read'))
    expect(column(2)).toEqual(['Cal', 'Ann'])
    expect(apiCalls(api).map(([, url]) => url)).toEqual([
      '/api/admin/contacts?show_read=0',
      '/api/admin/contacts?show_read=1',
      '/api/admin/contacts?show_read=0',
    ])
  })

  it('says there are no messages at all when none are left, read ones included', async () => {
    stubApi({ 'GET /admin/contacts': { body: [] } })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    await click(button('Show Read'))

    expect(document.body.textContent).toContain('No contact submissions.')
  })

  it('opens a message rendered from its Markdown, and closes it', async () => {
    stubApi({
      'GET /admin/contacts': {
        body: [contact(4, 'Dee', { message: 'Hi **there**' })],
      },
    })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    await click(button('View', row('Dee')))

    const message = dialog('Message')
    expect(message?.querySelector('strong')?.textContent).toBe('there')

    await click(button('Close', message ?? document.body))
    expect(dialog('Message')).toBeNull()
  })

  it('marks a message read and reloads the list, which then leaves it out', async () => {
    let marked = false
    const api = stubApi({
      'GET /admin/contacts': () => ({ body: marked ? [unread[1]] : unread }),
      'PATCH /admin/contacts/1': () => {
        marked = true
        return { body: { success: true } }
      },
    })
    await mountPage(ContactsView, { path: '/admin/contacts' })
    api.mockClear()

    await click(button('Mark Read', row('Ann')))

    expect(apiCalls(api)).toEqual([
      ['PATCH', '/api/admin/contacts/1', { is_read: true }],
      ['GET', '/api/admin/contacts?show_read=0'],
    ])
    expect(column(2)).toEqual(['Cal'])
  })

  it('marks a read message unread again', async () => {
    const api = stubApi({
      'GET /admin/contacts': { body: [...unread, read] },
      'PATCH /admin/contacts/2': { body: { success: true } },
    })
    await mountPage(ContactsView, { path: '/admin/contacts' })
    await click(button('Show Read'))
    api.mockClear()

    await click(button('Mark Unread', row('Bo')))

    expect(apiCalls(api)[0]).toEqual(['PATCH', '/api/admin/contacts/2', { is_read: false }])
  })

  it("shows the server's reason and keeps the list when marking fails", async () => {
    const api = stubApi({
      'GET /admin/contacts': { body: unread },
      'PATCH /admin/contacts/1': { status: 400, body: { error: 'Nothing to update.' } },
    })
    await mountPage(ContactsView, { path: '/admin/contacts' })
    api.mockClear()

    await click(button('Mark Read', row('Ann')))

    expect(apiCalls(api)).toHaveLength(1)
    expect(toasts()).toEqual(['Nothing to update.'])
    expect(button('Mark Read', row('Ann')).disabled).toBe(false)
  })

  it('filters by name, email, message or ID as the admin types', async () => {
    stubApi({ 'GET /admin/contacts': { body: unread } })
    await mountPage(ContactsView, { path: '/admin/contacts' })
    const search = labelled('Search by name, email, or message...')

    await typeInto(search, 'fool')
    expect(column(2)).toEqual(['Ann'])

    await typeInto(search, 'cal@')
    expect(column(2)).toEqual(['Cal'])

    await typeInto(search, 'nobody')
    expect(tableRows()).toEqual([])
  })

  it('sorts by a column, and reverses on a second click', async () => {
    stubApi({ 'GET /admin/contacts': { body: [...unread, read] } })
    await mountPage(ContactsView, { path: '/admin/contacts' })

    await click(header('Name'))
    expect(column(2)).toEqual(['Ann', 'Bo', 'Cal'])

    await click(header('Name'))
    expect(column(2)).toEqual(['Cal', 'Bo', 'Ann'])
  })

  it('drops to the login page when the session has expired', async () => {
    stubApi({ 'GET /admin/contacts': { status: 401, body: { error: 'Unauthorized' } } })
    const { router } = await mountPage(ContactsView, { path: '/admin/contacts' })

    // The login route's component loads lazily, so the navigation lands a beat later.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), lazyLoad)
    expect(useUser().currentUser.value).toBeNull()
  })
})
