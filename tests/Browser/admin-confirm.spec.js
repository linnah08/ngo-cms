// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
test.use({ storageState: AUTH_FILE });

/**
 * The shared confirm dialog (_adminConfirm) guards deletes and overwrites, so
 * Enter must not carry them out by accident: focus starts on "Отказ", Tab stays
 * on the dialog's two buttons, and closing hands focus back to the trigger.
 */
test('confirm dialog starts on Отказ, keeps focus inside and returns it', async ({ page }) => {
  await page.goto('/admin/dashboard.php');
  await page.evaluate(() => {
    const b = document.createElement('button');
    b.id = 'pwTrigger'; b.type = 'button'; b.textContent = 'trigger';
    document.body.appendChild(b);
    b.focus();
    // @ts-ignore
    window._pwResult = window._adminConfirm('Да изтрием ли?', 'Изтрий');
  });
  await expect(page.locator('#adminConfirmCancel')).toBeFocused();

  await page.keyboard.press('Tab');
  await expect(page.locator('#adminConfirmOk')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('#adminConfirmCancel')).toBeFocused();

  await page.keyboard.press('Enter'); // on Отказ: nothing is confirmed
  // @ts-ignore
  expect(await page.evaluate(() => window._pwResult)).toBe(false);
  await expect(page.locator('#pwTrigger')).toBeFocused();

  await page.evaluate(() => {
    // @ts-ignore
    window._pwResult = window._adminConfirm('Да изтрием ли?', 'Изтрий');
  });
  await page.keyboard.press('Escape');
  // @ts-ignore
  expect(await page.evaluate(() => window._pwResult)).toBe(false);
  await expect(page.locator('#pwTrigger')).toBeFocused();
});
