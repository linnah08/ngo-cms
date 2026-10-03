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
    // Most traffic is mobile: the public flows run again at phone width.
    // Admin specs stay desktop-only.
    {
      name: 'mobile',
      use: { ...devices['Pixel 5'] },
      testMatch: ['checkout.spec.js', 'mobile-layout.spec.js'],
    },
  ],
  // Start a local PHP server before running tests
  webServer: {
    command: 'php -S localhost:8080 -t .',
    url: 'http://localhost:8080',
    reuseExistingServer: true,
    timeout: 5000,
  },
});
