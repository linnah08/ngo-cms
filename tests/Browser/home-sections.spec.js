// @ts-check
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
const HOME_JSON = path.join(__dirname, '../../content/home.json');
let backup = null;

test.use({ storageState: AUTH_FILE });
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => { backup = fs.existsSync(HOME_JSON) ? fs.readFileSync(HOME_JSON) : null; });
test.afterAll(() => {
  if (backup === null) { if (fs.existsSync(HOME_JSON)) fs.unlinkSync(HOME_JSON); }
  else fs.writeFileSync(HOME_JSON, backup);
});

test('add a text + image block, drag it up and back, hide it', async ({ page }) => {
  const stamp = 'PW блок ' + Date.now();

  await page.goto('/admin/home-sections.php');
  await page.getByRole('link', { name: '+ Добави секция' }).click();
  await page.getByRole('link', { name: /Текст и снимка/ }).click();
  await page.fill('#f_heading_bg', stamp);
  await page.fill('#f_heading_en', stamp + ' EN');
  await page.getByRole('button', { name: 'Запази' }).first().click();
  await expect(page.locator('#hsFlash')).toContainText('добавена');

  const rows = page.locator('#list > li');
  const count = await rows.count();
  await expect(rows.nth(count - 1)).toContainText(stamp);

  // Keyboard: pick up, one step up, drop. Saved straight away, focus stays on the grip.
  const grip = rows.nth(count - 1).getByRole('button', { name: /^Премести/ });
  await grip.focus();
  await page.keyboard.press('Space');
  await expect(grip).toHaveAttribute('aria-pressed', 'true');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Space');
  await expect(page.locator('#hsSortMsg')).toContainText(`е преместена на място ${count - 1} от ${count}`);
  await expect(rows.nth(count - 2)).toContainText(stamp);
  await expect(page.locator(':focus')).toHaveAttribute('data-hs-sort-handle', '');

  // Mouse: drag it back to the bottom.
  const moved = rows.nth(count - 2).getByRole('button', { name: /^Премести/ });
  await rows.nth(count - 1).scrollIntoViewIfNeeded();
  const box = await moved.boundingBox();
  const last = await rows.nth(count - 1).boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, last.y + last.height - 4, { steps: 8 });
  await page.mouse.up();
  await expect(page.locator('#hsSortMsg')).toContainText(`на място ${count} от ${count}`);
  await page.reload();
  await expect(rows.nth(count - 1)).toContainText(stamp);

  await page.goto('/');
  await expect(page.getByRole('heading', { name: stamp, exact: true })).toBeVisible();
  await page.goto('/en/');
  await expect(page.getByRole('heading', { name: stamp + ' EN' })).toBeVisible();

  await page.goto('/admin/home-sections.php');
  await rows.filter({ hasText: stamp }).getByRole('button', { name: /^Скрий/ }).click();
  await expect(page.locator('#hsFlash')).toContainText('скрита');
  await page.goto('/');
  await expect(page.getByRole('heading', { name: stamp, exact: true })).toHaveCount(0);

  await page.goto('/admin/home-sections.php');
  await rows.filter({ hasText: stamp }).getByRole('button', { name: /^Изтрий/ }).click();
  await page.getByRole('button', { name: 'Да, изтрий' }).click();
  await expect(page.locator('#hsFlash')).toContainText('изтрита');
  await expect(rows.filter({ hasText: stamp })).toHaveCount(0);
});

test('a bad video link keeps the form and explains why', async ({ page }) => {
  await page.goto('/admin/home-sections.php?add=video');
  await page.fill('#f_video', 'https://example.org/not-a-video');
  await page.getByRole('button', { name: 'Запази' }).first().click();
  await expect(page.locator('#hsErrSummary')).toBeFocused();
  await expect(page.locator('#f_video_err')).toContainText('YouTube или Vimeo');
  await expect(page.locator('#f_video')).toHaveValue('https://example.org/not-a-video');
});
