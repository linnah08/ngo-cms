// @ts-check
// The Social tab's Instagram previews: each photo shown cut exactly as Instagram gets
// it, and a click on one lets the author choose which part is shown (saved at once).
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT      = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const SLUG      = 'pw-insta-crop';
const IMG_DIR   = path.join(ROOT, 'assets/images/articles/_pw-insta-crop');
const BG_FILE   = path.join(ROOT, 'content/articles/bg', SLUG + '.json');
const TALL      = '/assets/images/articles/_pw-insta-crop/tall.jpg';   // 900×2000, a phone photo
const WIDE      = '/assets/images/articles/_pw-insta-crop/wide.jpg';   // 1200×800
const WIDE2     = '/assets/images/articles/_pw-insta-crop/wide2.jpg';  // 1500×1000

test.use({ storageState: AUTH_FILE });

function jpeg(file, w, h) {
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(${w},${h});imagefilledrectangle($i,0,0,${w},${h / 4},imagecolorallocate($i,200,80,60));imagejpeg($i,'${file}');`]);
}

test.beforeEach(() => {
  fs.mkdirSync(IMG_DIR, { recursive: true });
  jpeg(path.join(IMG_DIR, 'tall.jpg'), 900, 2000);
  jpeg(path.join(IMG_DIR, 'wide.jpg'), 1200, 800);
  jpeg(path.join(IMG_DIR, 'wide2.jpg'), 1500, 1000);
  fs.mkdirSync(path.dirname(BG_FILE), { recursive: true });
  fs.writeFileSync(BG_FILE, JSON.stringify({
    title: 'PW Instagram', slug: SLUG, slug_en: SLUG, date: '2026-10-06', author: 'PW', status: 'draft',
    excerpt: '', image: WIDE, tags: [], content: '<p>x</p>', scheduled: false,
    photos: [{ src: TALL, caption: '' }, { src: WIDE, caption: '' }],
  }));
});

test.afterAll(() => {
  if (fs.existsSync(BG_FILE)) fs.unlinkSync(BG_FILE);
  if (fs.existsSync(IMG_DIR)) fs.rmSync(IMG_DIR, { recursive: true });
});

const rectOf = (loc) => loc.evaluate((el) => JSON.parse(el.dataset.rect));

test('previews show the carousel shape, a picked shape redraws them, and a chosen crop is saved and kept', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  test.skip(await page.locator('#socialPanel').count() === 0, 'Social panels need a Claude key in this database');

  const post  = page.getByRole('list', { name: 'Снимки за публикацията в Instagram' });
  const thumbs = post.locator('.ig-crop-thumb');
  const wholeBtns = post.getByRole('button', { name: /покажи цялата снимка/ });
  await expect(thumbs).toHaveCount(2);
  // The wide photo fills the 3:2 frame; the very tall one doesn't fit, so it is shown whole on white.
  await expect(wholeBtns.nth(0)).toHaveAttribute('aria-pressed', 'false');
  await expect(wholeBtns.nth(1)).toHaveAttribute('aria-pressed', 'true');
  expect((await rectOf(thumbs.nth(1))).w).toBeGreaterThan(1);
  await expect(thumbs.nth(0)).toHaveAttribute('data-src', WIDE);   // main photo first
  await expect(thumbs.nth(0)).toHaveAccessibleName(/Снимка 1 от 2 \(главна\)/);
  // Automatic: one wide and one very tall photo — wide cuts less, so both go 3:2.
  const shapes = page.getByRole('group', { name: 'Форма на публикацията в Instagram' });
  await expect(shapes.getByRole('button', { name: 'Автоматично' })).toHaveAttribute('aria-pressed', 'true');
  let box = await thumbs.nth(1).locator('span').boundingBox();
  expect(box && box.width / box.height).toBeCloseTo(1.5, 1);
  // Like Instagram's own app: pick a shape and every photo is cut to it, nothing refused.
  await shapes.getByRole('button', { name: /Вертикална/ }).click();
  await expect(page.locator('#igCropStatus')).toHaveText(/Запазено/);
  await expect(shapes.getByRole('button', { name: /Вертикална/ })).toHaveAttribute('aria-pressed', 'true');
  expect(JSON.parse(fs.readFileSync(BG_FILE, 'utf8')).insta_shape).toBe('portrait');
  box = await thumbs.nth(1).locator('span').boundingBox();
  expect(box && box.width / box.height).toBeCloseTo(0.8, 1);
  // Still too tall for 4:5, so still whole; "Цялата снимка" off makes it fill the frame.
  await expect(wholeBtns.nth(1)).toHaveAttribute('aria-pressed', 'true');
  await wholeBtns.nth(1).click();
  await expect(page.locator('#igCropStatus')).toHaveText(/запълни рамката/);
  await expect(wholeBtns.nth(1)).toHaveAttribute('aria-pressed', 'false');
  expect(JSON.parse(fs.readFileSync(BG_FILE, 'utf8')).insta_fit.post[TALL]).toBe('fill');
  // Automatic crop of the tall photo starts 20% down the spare height, not mid-way.
  expect((await rectOf(thumbs.nth(1))).y).toBeCloseTo(175 / 2000, 4);
  // And back to whole, then a chosen crop switches it to fill by itself.
  await wholeBtns.nth(1).click();
  await expect(wholeBtns.nth(1)).toHaveAttribute('aria-pressed', 'true');

  // Keyboard: open, move the frame down, done.
  await thumbs.nth(1).focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Изберете коя част да се вижда' });
  await expect(dialog).toBeVisible();
  await page.waitForFunction(() => !!document.querySelector('.cropper-crop-box'));
  // As a person does it: click the photo with the mouse, then use the keys — no Tab first.
  await page.locator('.cropper-crop-box').click();
  const before = await page.evaluate(() => document.querySelector('.cropper-crop-box').getBoundingClientRect().height);
  await page.keyboard.press('-');
  const after = await page.evaluate(() => document.querySelector('.cropper-crop-box').getBoundingClientRect().height);
  expect(after).toBeLessThan(before);
  await page.keyboard.press('Shift+ArrowDown');
  await dialog.getByRole('button', { name: 'Готово' }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.locator('#igCropStatus')).toHaveText(/Запазено/);
  await expect(thumbs.nth(1)).toBeFocused();   // focus comes back to the photo
  await expect(wholeBtns.nth(1)).toHaveAttribute('aria-pressed', 'false');

  const saved = JSON.parse(fs.readFileSync(BG_FILE, 'utf8')).insta_crops.post[TALL];
  expect(saved.ar).toBeCloseTo(0.8, 5);
  expect(saved.y).toBeGreaterThan(175 / 2000 + 0.01);

  await page.reload();
  expect((await rectOf(thumbs.nth(1))).y).toBeCloseTo(saved.y, 4);
});

test('Story mode shows the main photo alone at 9:16', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  test.skip(await page.locator('#socialPanel').count() === 0, 'Social panels need a Claude key in this database');

  await expect(page.locator('#igCropStory')).toBeHidden();
  await page.locator('#igModeStoryBtn').click();
  await expect(page.locator('#igCropPost')).toBeHidden();
  const story = page.getByRole('list', { name: 'Снимка за Instagram Story' }).getByRole('button');
  await expect(story).toHaveCount(1);
  await expect(story).toHaveAttribute('data-src', WIDE);
  const box = await story.locator('span').boundingBox();
  expect(box && box.width / box.height).toBeCloseTo(9 / 16, 1);
});

test('landscape photos make a landscape carousel with nothing to fix', async ({ page }) => {
  const a = JSON.parse(fs.readFileSync(BG_FILE, 'utf8'));
  a.image = WIDE;
  a.photos = [{ src: WIDE, caption: '' }, { src: WIDE2, caption: '' }];
  fs.writeFileSync(BG_FILE, JSON.stringify(a));
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  test.skip(await page.locator('#socialPanel').count() === 0, 'Social panels need a Claude key in this database');

  const thumbs = page.getByRole('list', { name: 'Снимки за публикацията в Instagram' }).locator('.ig-crop-thumb');
  await expect(thumbs).toHaveCount(2);
  for (const i of [0, 1]) {
    const box = await thumbs.nth(i).locator('span').boundingBox();
    expect(box && box.width / box.height).toBeCloseTo(1.5, 1);
  }
  // Square: both redrawn square at once, and back to automatic.
  const shapes = page.getByRole('group', { name: 'Форма на публикацията в Instagram' });
  await shapes.getByRole('button', { name: /Квадрат/ }).click();
  await expect(page.locator('#igCropStatus')).toHaveText(/Запазено/);
  const sq = await thumbs.nth(0).locator('span').boundingBox();
  expect(sq && sq.width / sq.height).toBeCloseTo(1, 1);
  await shapes.getByRole('button', { name: 'Автоматично' }).click();
  await expect(shapes.getByRole('button', { name: 'Автоматично' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('.ig-cut-problem')).toHaveCount(0);
});
