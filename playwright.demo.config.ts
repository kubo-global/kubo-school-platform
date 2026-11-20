import { defineConfig } from '@playwright/test';

// Browser tests that need nothing but PHP: they start their own demo school
// (tests/DemoBrowser/serve.sh) and click through it. Run with `npm run test:browser`.
const port = process.env.DEMO_BROWSER_PORT || '8791';

export default defineConfig({
  testDir: './tests/DemoBrowser',
  timeout: 30_000,
  retries: 0,
  // One demo school, one PHP worker: tests take turns.
  workers: 1,
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
  ],
  webServer: {
    command: 'bash tests/DemoBrowser/serve.sh',
    url: `http://127.0.0.1:${port}/login`,
    timeout: 180_000,
    reuseExistingServer: false,
  },
});
