// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');

test.use({ storageState: AUTH_FILE });

/**
 * Regression: deleting a contact-form submission in admin/comments.php.
 *
 * The delete button is <button name="action" value="csub_delete" data-confirm>
 * inside a plain form. The shared confirm handler used to call form.submit(),
 * which drops the clicked button's name/value — so the POST arrived with no
 * `action` and the server silently did nothing (no row deleted, no error).
 * The fix preserves the submitter; this test locks that in end-to-end.
 */
test('contact submission can be deleted (data-confirm preserves the submitter)', async ({ page }) => {
  const email = `pw-delete-${Date.now()}@test.local`;

  // 1. Create a submission via the public contact form.
  await page.goto('/kontakti/');
  await page.fill('#name', 'PW Delete Test');
  await page.fill('#email', email);
  await page.selectOption('#topic', { index: 1 }); // index 0 is the disabled placeholder
  await page.fill('#message', 'Regression check: deleting this must work.');
  await page.locator('form[action="/kontakti/"] button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  // 2. Open the admin contacts view; the new submission must be listed.
  await page.goto('/admin/comments.php?source=contacts&cfilter=new');
  const card = page.locator('.admin-card', { hasText: email });
  await expect(card).toHaveCount(1);

  // 3. Click delete → the data-confirm modal appears → confirm it.
  await card.locator('button[value="csub_delete"]').click();
  await expect(page.locator('#adminConfirmOverlay')).toBeVisible();
  await page.click('#adminConfirmOk');
  await page.waitForLoadState('networkidle');

  // 4. The submission must be gone. (Failed before the submitter-preserving fix.)
  await expect(page.locator('.admin-card', { hasText: email })).toHaveCount(0);
});
