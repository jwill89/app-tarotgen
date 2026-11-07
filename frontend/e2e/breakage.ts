import type { Page } from '@playwright/test'

/**
 * What would blank or break the app without failing a click: an uncaught error,
 * or og.php's Content-Security-Policy refusing a script or style. (Missing card
 * images are expected — the seeded deck has none.) Call it before the page loads;
 * the list it returns fills as the test goes.
 */
export function watchForBreakage(page: Page): string[] {
  const problems: string[] = []
  page.on('pageerror', (error) => problems.push(`page error: ${error.message}`))
  page.on('console', (message) => {
    if (message.type() === 'error' && /Content Security Policy|Refused to/i.test(message.text())) {
      problems.push(`CSP: ${message.text()}`)
    }
  })
  return problems
}
