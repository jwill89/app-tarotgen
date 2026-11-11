import { test, expect } from '@playwright/test'
import { watchForBreakage } from './breakage.ts'
import { adminAccount } from './env.ts'

/**
 * The path a visitor takes, end to end on the built app: draw a reading, open a
 * card, report its image; then an admin finds the report. Each step depends on
 * the last, so they run in order on one seeded database. Everything is found by
 * role and name, never by a Bulma class, so the suite doesn't depend on the
 * CSS framework.
 */
test.describe.serial('smoke', () => {
  test('a visitor draws a reading and reports a card', async ({ page }) => {
    const problems = watchForBreakage(page)

    await page.goto('/')
    await page.getByRole('link', { name: 'New Draw' }).first().click()
    await expect(page).toHaveURL(/\/new$/)
    await expect(page.getByRole('combobox', { name: 'Deck' })).toHaveValue('Smoke Test Deck')

    const spread = page.getByRole('combobox', { name: 'Spread' })
    await spread.fill('past')
    // Enter picks the highlighted option, so wait for the filtered list first.
    await expect(page.getByRole('option', { name: /Past, Present, Future/ })).toBeVisible()
    await spread.press('Enter')
    await expect(spread).toHaveValue('Past, Present, Future')

    // A guest may have the reading deleted sooner than the usual year.
    await page.getByRole('combobox', { name: 'Keep This Reading For' }).click()
    await page.getByRole('option', { name: '7 days' }).click()

    await page.getByRole('button', { name: 'Generate New Draw' }).click()
    await expect(page).toHaveURL(/\/reading\/[A-Za-z0-9]+$/)
    await expect(page.getByText(/This reading will be deleted on /)).toBeVisible()

    // Each card is a button named, while face down, by its place alone: nothing a
    // screen reader reads out gives the card away before it is turned.
    const faceDown = page.getByRole('button', { name: /^Face-down card \d/ })
    await expect(faceDown).toHaveCount(3)
    await expect(faceDown.getByRole('img', { name: /./ })).toHaveCount(0)

    // The first tap turns a card over, and its name then says which card it is;
    // the second opens it in the lightbox.
    await page.getByRole('button', { name: 'Face-down card 1, Past', exact: true }).click()
    const turned = page.getByRole('button', { name: /^Card 1, Past: \S/ })
    await expect(turned).toBeVisible()
    await expect(faceDown).toHaveCount(2)
    await turned.click()
    await expect(page.getByText('Image 1 of 3')).toBeVisible()

    await page.getByRole('button', { name: 'Report a problem' }).click()
    await page.getByRole('radio', { name: 'Cropped or crooked' }).check()
    await page.getByRole('button', { name: 'Send report' }).click()
    await expect(page.getByRole('button', { name: 'Reported' })).toBeDisabled()

    expect(problems).toEqual([])
  })

  test('an admin finds the report under Errored Cards', async ({ page }) => {
    const problems = watchForBreakage(page)
    const admin = adminAccount()

    await page.goto('/login?redirect=/admin/card-reports')
    await page.getByLabel('Email').fill(admin.email)
    await page.getByLabel('Password').fill(admin.password)
    await page.getByRole('button', { name: 'Log In' }).click()

    await expect(page).toHaveURL(/\/admin\/card-reports$/)
    // At least the report just made: a retried run reports another card, so
    // counting exactly one row would fail on the retry itself.
    const row = page.getByRole('row').filter({ hasText: 'Smoke Test Deck' })
    await expect(row.first()).toBeVisible()
    await expect(row.filter({ hasText: 'Cropped or crooked' }).first()).toBeVisible()

    expect(problems).toEqual([])
  })
})
