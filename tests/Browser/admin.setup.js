// @ts-check
/**
 * Global setup: log in once and save auth state to a file.
 * All browser tests reuse this session instead of logging in per-test.
 *
 * Auth is self-provisioning: we run tests/seed-admin.php to guarantee a known
 * LOCAL admin (playwright@test.local) exists, then log in as it. No env var or
 * manually-synced password required.
 *
 * Optional override: set ADMIN_PASSWORD (and optionally ADMIN_EMAIL) to log in
 * as a different existing account instead of the seeded one.
 */
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
const SEED_SCRIPT = path.join(__dirname, '../seed-admin.php');

const SEED_EMAIL = 'playwright@test.local';
const SEED_PASSWORD = 'playwright-local-test';

module.exports = async function globalSetup() {
  // Use an explicit override if provided, otherwise seed + use the test account.
  let email = process.env.ADMIN_EMAIL || SEED_EMAIL;
  let password = process.env.ADMIN_PASSWORD;

  if (!password) {
    // Self-provision the known local admin so login never depends on a drifting
    // password. seed-admin.php refuses to touch a non-local database.
    execFileSync('php', [SEED_SCRIPT], { stdio: 'inherit' });
    email = process.env.ADMIN_EMAIL || SEED_EMAIL;
    password = SEED_PASSWORD;
  }

  const browser = await chromium.launch();
  const page = await browser.newPage();

  await page.goto('http://localhost:8080/admin/login.php');
  await page.fill('input[name="email"]',    email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');

  // Wait for the dashboard specifically — NOT '**/admin/**', which also matches
  // the re-rendered login page on a failed login and would silently save an
  // unauthenticated session.
  try {
    await page.waitForURL('**/admin/dashboard.php', { timeout: 10000 });
  } catch (e) {
    const err = await page.locator('.admin-alert--error, .login-error, .error, [role="alert"]').first().textContent().catch(() => null);
    await browser.close();
    throw new Error('Admin login did not reach the dashboard — login was rejected.' + (err ? ' Page said: ' + err.trim() : ''));
  }

  await page.context().storageState({ path: AUTH_FILE });
  await browser.close();
};
