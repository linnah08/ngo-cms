// @ts-check
// Product variant photo strip: starring a photo as main moves it to the front,
// so the order in the editor is the order customers see.
const { test, expect } = require('@playwright/test');
const path = require('path');

test.use({ storageState: path.join(__dirname, '../../.playwright-auth.json') });

test('starring a variant photo moves it to the front', async ({ page }) => {
  await page.goto('/admin/product-edit.php');
  const row = await page.evaluate(() => {
    document.getElementById('productVariantsPanel').style.display = '';
    addPvRow();
    const rows = document.querySelectorAll('#pvBody .pv-row');
    const r = rows[rows.length - 1];
    pvSet(r, { images: ['a.jpg', 'b.jpg', 'c.jpg'], primary: 'a.jpg' });
    return rows.length - 1;
  });
  const strip = page.locator('#pvBody .pv-row').nth(row);
  await strip.locator('.pv-photo[data-fn="c.jpg"] .pv-star').click();
  const data = await strip.locator('.pv-images-val').inputValue();
  expect(JSON.parse(data)).toEqual({ images: ['c.jpg', 'a.jpg', 'b.jpg'], primary: 'c.jpg' });
  await expect(strip.locator('.pv-photo').first()).toHaveAttribute('data-fn', 'c.jpg');
});
