import { expect, test } from '@playwright/test'
import { watchForBreakage } from './breakage.ts'

/**
 * The Content-Security-Policy og.php and /api/docs send, in force on the built app:
 * every other spec fails on a refusal (breakage.ts), so these show that the policy
 * reaches the page, that what it forbids is refused and reported, and that the API
 * reference runs under its own. The <style> elements the app adds at run time, which
 * need the nonce, are drawn elsewhere: TipTap's by /contact's message editor
 * (pages.spec.ts), Reka UI's by the select and combobox lists the smoke spec opens.
 */
const directives = (policy: string) =>
  new Map(
    policy.split(';').map((part) => {
      const [name = '', ...sources] = part.trim().split(/\s+/)
      return [name, sources]
    }),
  )

test('the shell carries the nonce its policy names, and no inline allowance', async ({ page }) => {
  const problems = watchForBreakage(page)

  const response = await page.goto('/')
  const policy = directives((await response?.headerValue('content-security-policy')) ?? '')
  const nonce = /^'nonce-(.+)'$/.exec(policy.get('style-src')?.[1] ?? '')?.[1]

  expect(policy.get('style-src')).toEqual(["'self'", `'nonce-${String(nonce)}'`])
  expect(policy.get('script-src')).toContain(`'nonce-${String(nonce)}'`)
  for (const sources of policy.values()) {
    expect(sources).not.toContain("'unsafe-inline'")
    expect(sources).not.toContain("'unsafe-eval'")
  }
  // The boot loader's <style> and the meta tag the app reads it from carry this
  // response's nonce, not the build's placeholder.
  const html = (await response?.text()) ?? ''
  expect(html).toContain(`<style nonce="${String(nonce)}">`)
  expect(html).not.toContain('__CSP_NONCE__')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  expect(problems).toEqual([])
})

test('an inline style without the nonce is refused and reported', async ({ page }) => {
  const problems = watchForBreakage(page)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

  // A script string: the e2e suite has no DOM types.
  await page.evaluate(`{
    const style = document.createElement('style')
    style.textContent = 'body { outline: 7px solid rgb(255 0 0); }'
    document.head.append(style)
  }`)

  await expect.poll(() => problems.length).toBe(1)
  await expect(page.locator('body')).not.toHaveCSS('outline-width', '7px')
  expect(problems[0]).toMatch(/^CSP: .*style-src/)
})

test('the API reference draws the spec and calls the API under its own policy', async ({
  page,
}) => {
  const problems = watchForBreakage(page)

  const response = await page.goto('/api/docs')
  const policy = (await response?.headerValue('content-security-policy')) ?? ''
  expect(policy).toContain("default-src 'none'")
  expect(policy).not.toContain('unsafe-')

  await expect(page.getByRole('heading', { name: /TarotGen\.io API/ })).toBeVisible()
  // Open an operation, try it, and read the answer the API sent. (Swagger UI's own
  // markup, found by its classes.)
  const operation = page.locator('.opblock').filter({ hasText: 'List usable decks' })
  await operation.getByText('List usable decks').click()
  await operation.getByRole('button', { name: 'Try it out' }).click()
  await operation.getByRole('button', { name: 'Execute' }).click()
  await expect(operation.locator('.live-responses-table')).toContainText('Smoke Test Deck')

  expect(problems).toEqual([])
})
