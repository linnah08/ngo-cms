// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/Browser',
  globalSetup: './tests/Browser/admin.setup.js',
  timeout: 30000,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'http://localhost:8080',
    headless: true,
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  // Start a local PHP server before running tests
  webServer: {
    command: 'php -S localhost:8080 -t .',
    url: 'http://localhost:8080',
    reuseExistingServer: true,
    timeout: 5000,
  },
});
