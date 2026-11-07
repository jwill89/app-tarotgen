import { defineConfig } from '@playwright/test'
import { playwrightDefaults } from '../shared/test/e2e/playwright.js'
import { BASE_URL, DATA_DIR, PORT } from './e2e/env.ts'

// The end-to-end run (`npm run test:e2e`, AUDIT §8.6), on shared-tooling's Playwright
// settings: the SPA built, then served with the real API by PHP's built-in server,
// routed as production's .htaccess routes them (e2e/server/router.php), on a throwaway
// database the backend's migrations build and e2e/server/prepare.php seeds. Locally on
// Windows it drives the installed Edge and CI the runner's Chrome, so no browser is
// downloaded.
export default defineConfig({
  ...playwrightDefaults,
  use: { ...playwrightDefaults.use, baseURL: BASE_URL },
  webServer: {
    // The rate limiters keep their counts in PHP's temp dir: the run's own, so
    // reports and sign-ins from earlier local runs can't trip them. PHP's server
    // logs every request to stderr, which goes to server.log in the data dir.
    command: [
      'npx vite build --logLevel warn',
      `php e2e/server/prepare.php "${DATA_DIR}" ${BASE_URL}`,
      `php -d sys_temp_dir="${DATA_DIR}/tmp" -S 127.0.0.1:${String(PORT)} -t dist e2e/server/router.php 2> "${DATA_DIR}/server.log"`,
    ].join(' && '),
    url: `${BASE_URL}/api/config`,
    env: { TAROT_DATA_DIR: DATA_DIR },
    reuseExistingServer: false,
    timeout: 180_000,
  },
})
