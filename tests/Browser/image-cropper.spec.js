// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
test.use({ storageState: AUTH_FILE });

// 1x1 red PNG — enough to open the cropper without writing anything server-side.
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

test('selecting a featured image opens the crop modal', async ({ page }) => {
  await page.goto('/admin/article-edit.php');
  await page.waitForLoadState('networkidle');

  // Shared cropper must be loaded on the page.
  await page.waitForFunction(() => !!window.OMCrop);

  await page.setInputFiles('#imageFile', { name: 'test.png', mimeType: 'image/png', buffer: PNG });

  const dialog = page.locator('div[role="dialog"][aria-label="Изрязване на снимка"]');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('Квадрат 1:1')).toBeVisible();
  await expect(dialog.getByText('Готово')).toBeVisible();

  // Cancel — leaves no server-side upload behind.
  await dialog.getByText('Отказ').click();
  await expect(dialog).toHaveCount(0);
});
