const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/blackbox',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  globalTimeout: 4 * 60 * 1000,
  timeout: 30 * 1000,
  expect: {
    timeout: 5 * 1000,
  },
  outputDir: 'test-results/artifacts',
  reporter: [
    ['line'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['junit', { outputFile: 'test-results/junit.xml' }],
  ],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8080',
    actionTimeout: 10 * 1000,
    navigationTimeout: 15 * 1000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      testMatch: /browser\.spec\.cjs$/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
      },
    },
    {
      name: 'mobile',
      testMatch: /mobile\.spec\.cjs$/,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 320, height: 800 },
        screen: { width: 320, height: 800 },
        hasTouch: true,
        isMobile: true,
      },
    },
    {
      name: 'api',
      testMatch: /api\.spec\.cjs$/,
    },
  ],
});
