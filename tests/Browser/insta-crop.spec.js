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

test('previews show the shared carousel shape, flag big cuts and a chosen crop is saved and kept', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  test.skip(await page.locator('#socialPanel').count() === 0, 'Social panels need a Claude key in this database');

  const post  = page.getByRole('list', { name: 'Снимки за публикацията в Instagram' });
  const thumbs = post.getByRole('button');
  await expect(thumbs).toHaveCount(2);
  await expect(thumbs.nth(0)).toHaveAttribute('data-src', WIDE);   // main photo first
  await expect(thumbs.nth(0)).toHaveAccessibleName(/Снимка 1 от 2 \(главна\)/);
  const box = await thumbs.nth(1).locator('span').boundingBox();
  expect(box && box.width / box.height).toBeCloseTo(0.8, 1);
  // Automatic crop of the tall photo starts 20% down the spare height, not mid-way.
  expect((await rectOf(thumbs.nth(1))).y).toBeCloseTo(175 / 2000, 4);
  // A wide and a very tall photo can't share a shape without losing a lot of both: a red
  // note says so and what to do, and each photo carries a ⚠ with how much is cut.
  const note = page.locator('#igCropPost .ig-cut-problem');
  await expect(note).toBeVisible();
  await expect(note).toContainText('снимки 1 и 2');
  await expect(note.getByRole('listitem')).toHaveCount(3);
  await expect(thumbs.nth(1)).toHaveAccessibleName(/Внимание: Instagram ще отреже 44%/);

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
  // The chosen crop is fine; only the wide photo is still flagged.
  await expect(note).toContainText('снимка 1 ');
  await expect(thumbs.nth(1)).not.toHaveAccessibleName(/Внимание/);
  await expect(post.locator('.ig-cut-flag').nth(1)).toBeHidden();
  await expect(post.locator('.ig-cut-flag').nth(0)).toBeVisible();

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
  // A 3:2 photo loses 62% to 9:16 — the Story is held back until a part is chosen.
  await expect(page.locator('#igCropStory .ig-cut-problem')).toContainText('Story-то няма да бъде изпратено');
});

test('landscape photos make a landscape carousel with nothing to fix', async ({ page }) => {
  const a = JSON.parse(fs.readFileSync(BG_FILE, 'utf8'));
  a.image = WIDE;
  a.photos = [{ src: WIDE, caption: '' }, { src: WIDE2, caption: '' }];
  fs.writeFileSync(BG_FILE, JSON.stringify(a));
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  test.skip(await page.locator('#socialPanel').count() === 0, 'Social panels need a Claude key in this database');

  const thumbs = page.getByRole('list', { name: 'Снимки за публикацията в Instagram' }).getByRole('button');
  await expect(thumbs).toHaveCount(2);
  for (const i of [0, 1]) {
    const box = await thumbs.nth(i).locator('span').boundingBox();
    expect(box && box.width / box.height).toBeCloseTo(1.5, 1);
  }
  await expect(page.locator('#igCropPost .ig-cut-problem')).toBeHidden();
});
