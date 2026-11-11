import { expect, test, type Page } from '@playwright/test'
import { axeViolations } from '@jwill89/test-a11y'
import { watchForBreakage } from './breakage.ts'
import { seededReading } from './env.ts'

/**
 * The main public pages, each opened by its own address: it shows its heading and
 * what the server sent for it (the seeded deck, spread and reading) or its own
 * words, then passes axe at WCAG 2.2 AA with its best practices. Everything is found
 * by role and name, never by a Bulma class, so the suite doesn't depend on the
 * CSS framework.
 */
const text = (words: string) => (page: Page) => expect(page.getByText(words)).toBeVisible()
const section = (name: string) => (page: Page) =>
  expect(page.getByRole('heading', { level: 2, name, exact: true })).toBeVisible()
const deckChosen = (page: Page) =>
  expect(page.getByRole('combobox', { name: 'Deck' })).toHaveValue('Smoke Test Deck')

const pages: {
  title: string
  /** The address, or how to make it from what was seeded. */
  path: string | (() => string)
  heading: string
  shows: (page: Page) => Promise<void>
}[] = [
  // The logo is the front page's heading.
  {
    title: '/',
    path: '/',
    heading: 'TarotGen.io Tarot Generator',
    shows: (page) => expect(page.getByRole('textbox', { name: 'Reading Code' })).toBeVisible(),
  },
  { title: '/new', path: '/new', heading: 'New Draw', shows: deckChosen },
  {
    title: '/spreads',
    path: '/spreads',
    heading: 'Spreads',
    shows: async (page) => {
      await section('Past, Present, Future')(page)
      await text('General · 3 cards')(page)
    },
  },
  { title: '/custom', path: '/custom', heading: 'Recreate Draw', shows: deckChosen },
  {
    title: '/reading/{id}',
    path: () => `/reading/${seededReading()}`,
    heading: 'Your Reading',
    shows: async (page) => {
      await expect(
        page.getByRole('definition').filter({ hasText: 'Smoke Test Deck' }),
      ).toBeVisible()
      // Three cards, face down, one for each of the spread's positions.
      await expect(page.getByRole('button', { name: /^Face-down card \d/ })).toHaveCount(3)
      await expect(page.getByText('Not yet revealed')).toHaveCount(3)
    },
  },
  {
    title: '/create-spread',
    path: '/create-spread',
    heading: 'Create a Spread',
    shows: (page) => expect(page.getByRole('textbox', { name: 'Spread Name' })).toBeVisible(),
  },
  // A guest is asked to sign in first.
  {
    title: '/submit-deck',
    path: '/submit-deck',
    heading: 'Submit a Deck',
    shows: text('to submit a deck.'),
  },
  {
    title: '/submit-deck-system',
    path: '/submit-deck-system',
    heading: 'Submit a Deck System',
    shows: text('to submit a deck system.'),
  },
  {
    title: '/ffxiv-plugin',
    path: '/ffxiv-plugin',
    heading: 'FFXIV Plugin',
    shows: text('https://tarotgen.io/plugin/repo.json'),
  },
  {
    title: '/contact',
    path: '/contact',
    heading: 'Contact Us',
    shows: (page) => expect(page.getByRole('textbox', { name: 'Message' })).toBeVisible(),
  },
  {
    title: '/privacy',
    path: '/privacy',
    heading: 'Privacy Policy',
    shows: section('8. Data Retention'),
  },
  {
    title: '/terms',
    path: '/terms',
    heading: 'Terms of Service',
    shows: section('3. Entertainment Disclaimer'),
  },
  {
    title: '/login',
    path: '/login',
    heading: 'Log In',
    shows: (page) => expect(page.getByLabel('Password', { exact: true })).toBeVisible(),
  },
  {
    title: '/register',
    path: '/register',
    heading: 'Create an Account',
    shows: (page) => expect(page.getByRole('textbox', { name: 'Display Name' })).toBeVisible(),
  },
  {
    title: '/forgot-password',
    path: '/forgot-password',
    heading: 'Reset Password',
    shows: (page) => expect(page.getByRole('button', { name: 'Send Reset Link' })).toBeVisible(),
  },
  // An address the app doesn't know goes to the front page.
  {
    title: '/no-such-page',
    path: '/no-such-page',
    heading: 'TarotGen.io Tarot Generator',
    shows: (page) => expect(page).toHaveURL(/\/$/),
  },
]

for (const { title, path, heading, shows } of pages) {
  test(`${title} loads and passes axe`, async ({ page }) => {
    const problems = watchForBreakage(page)

    await page.goto(typeof path === 'string' ? path : path())
    await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible()
    await shows(page)
    // A first visit's cookie notice slides in: axe would read its colours mid-fade.
    await expect(page.getByRole('region', { name: 'Cookie notice' })).toHaveCSS('opacity', '1')

    expect(await axeViolations(page)).toEqual([])
    expect(problems).toEqual([])
  })
}
