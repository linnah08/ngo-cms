// @ts-check
// The post editor's photo grid: captions stay with their photos, the main photo is
// visible, and nothing an author added can silently disappear.
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const ROOT      = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const SLUG      = 'pw-photo-grid';
const IMG_DIR   = path.join(ROOT, 'assets/images/articles/_pw-grid');
const BG_FILE   = path.join(ROOT, 'content/articles/bg', SLUG + '.json');
const EN_FILE   = path.join(ROOT, 'content/articles/en', SLUG + '.json');
// 1×1 white JPEG
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');

const src = (n) => `/assets/images/articles/_pw-grid/p${n}.jpg`;

test.use({ storageState: AUTH_FILE });

test.beforeEach(() => {
  fs.mkdirSync(IMG_DIR, { recursive: true });
  for (const n of [1, 2, 3]) fs.writeFileSync(path.join(IMG_DIR, `p${n}.jpg`), JPEG);
  const post = (title, caps) => ({
    title, slug: SLUG, slug_en: SLUG, date: '2026-10-03', author: 'PW', status: 'draft',
    excerpt: '', image: src(1), tags: [], content: '<p>x</p>', scheduled: false,
    photos: [1, 2, 3].map((n, i) => ({ src: src(n), caption: caps[i] })),
  });
  fs.mkdirSync(path.dirname(BG_FILE), { recursive: true });
  fs.mkdirSync(path.dirname(EN_FILE), { recursive: true });
  fs.writeFileSync(BG_FILE, JSON.stringify(post('PW снимки', ['Едно', 'Две', 'Три'])));
  fs.writeFileSync(EN_FILE, JSON.stringify(post('PW photos', ['One', 'Two', 'Three'])));
});

test.afterAll(() => {
  for (const f of [BG_FILE, EN_FILE]) if (fs.existsSync(f)) fs.unlinkSync(f);
  if (fs.existsSync(IMG_DIR)) fs.rmSync(IMG_DIR, { recursive: true });
});

const enCaps = (page) => page.$$eval('#apEnCaps input', (els) => els.map((e) => e.value));
const bgCaps = (page) => page.$$eval('#apGrid input[name^="photo_caption_bg"]', (els) => els.map((e) => e.value));

test('English captions stay with their photos through main, move and remove', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  expect(await enCaps(page)).toEqual(['One', 'Two', 'Three']);

  await page.getByRole('button', { name: 'Направи снимка 2 основна' }).click();
  expect(await enCaps(page)).toEqual(['One', 'Two', 'Three']);

  await page.getByRole('button', { name: 'Премести снимка 3 нагоре' }).click();
  expect(await bgCaps(page)).toEqual(['Едно', 'Три', 'Две']);
  expect(await enCaps(page)).toEqual(['One', 'Three', 'Two']);

  await page.getByRole('button', { name: 'Премахни снимка 1' }).click();
  expect(await enCaps(page)).toEqual(['Three', 'Two']);
});

test('the main photo is visibly marked and follows the choice', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  const cards = page.locator('#apGrid .ap-photo');
  await expect(cards.nth(0).locator('.ap-main')).toHaveText(/Основна снимка/);
  await page.getByRole('button', { name: 'Направи снимка 3 основна' }).click();
  await expect(cards.nth(2).locator('.ap-main')).toHaveText(/Основна снимка/);
  await expect(cards.nth(0).locator('.ap-main')).toHaveText('Направи основна');
  const border = await cards.nth(2).evaluate((el) => getComputedStyle(el).borderTopWidth);
  expect(border).toBe('3px');
});

test('removing the main photo makes the next one main', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await page.getByRole('button', { name: 'Направи снимка 2 основна' }).click();
  await page.getByRole('button', { name: 'Премахни снимка 2' }).click();
  // The old photo 3 is now photo 2 and becomes main.
  await expect(page.locator('#apGrid .ap-photo').nth(1).locator('.ap-main')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#apMain')).toHaveValue('1');
});

test('dropping a photo does not let the browser navigate away', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  const prevented = await page.evaluate(() => {
    const cards = document.querySelectorAll('#apGrid .ap-photo');
    const dt = new DataTransfer();
    cards[0].dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
    const drop = new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt });
    cards[2].dispatchEvent(drop);
    return drop.defaultPrevented;
  });
  expect(prevented).toBe(true);
});

test('saving waits while photos are still uploading', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  // Hold the upload open so Save is pressed mid-upload.
  let release;
  const held = new Promise((r) => { release = r; });
  await page.route('**/admin/inline-upload.php', async (route) => { await held; await route.continue(); });
  await page.setInputFiles('#apFiles', { name: 'x.jpg', mimeType: 'image/jpeg', buffer: JPEG });
  await expect(page.locator('#apLive')).toContainText('Качване');
  await page.getByRole('button', { name: 'Запази статията' }).click();
  await expect(page.locator('#apError')).toContainText('Изчакайте');
  await expect(page).toHaveURL(/article-edit\.php/);
  release();
  await expect(page.locator('#apGrid .ap-photo')).toHaveCount(4);
});

test('a library picture whose name the site cannot store is refused, not lost later', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await page.evaluate(() => { window.openMediaPicker = (cb) => cb('/assets/images/articles/тест снимка.jpg'); });
  await page.getByRole('button', { name: 'Избери от библиотека' }).click();
  await expect(page.locator('#apGrid .ap-photo')).toHaveCount(3);
  await expect(page.locator('#apError')).toContainText('не може да се използва');
});

test('cropping a photo keeps its English caption', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await page.evaluate(() => { window.OMCrop = { open: (f) => Promise.resolve(f) }; });
  await page.getByRole('button', { name: 'Изрежи снимка 2' }).click();
  await expect.poll(() => page.locator('#apGrid .ap-photo').nth(1).getAttribute('data-src')).not.toBe('/assets/images/articles/_pw-grid/p2.jpg');
  expect(await enCaps(page)).toEqual(['One', 'Two', 'Three']);
});
