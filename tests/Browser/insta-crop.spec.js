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

test.use({ storageState: AUTH_FILE });

function jpeg(file, w, h) {
  execFileSync('php', ['-r', `$i=imagecreatetruecolor(${w},${h});imagefilledrectangle($i,0,0,${w},${h / 4},imagecolorallocate($i,200,80,60));imagejpeg($i,'${file}');`]);
}

test.beforeEach(() => {
  fs.mkdirSync(IMG_DIR, { recursive: true });
  jpeg(path.join(IMG_DIR, 'tall.jpg'), 900, 2000);
  jpeg(path.join(IMG_DIR, 'wide.jpg'), 1200, 800);
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

test('previews show the 4:5 carousel and a chosen crop is saved and kept', async ({ page }) => {
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

  // Keyboard: open, move the frame down, done.
  await thumbs.nth(1).focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Изберете коя част да се вижда' });
  await expect(dialog).toBeVisible();
  await page.waitForFunction(() => !!document.querySelector('.cropper-crop-box'));
  await dialog.getByRole('group', { name: 'Рамка за изрязване' }).focus();
  await page.keyboard.press('Shift+ArrowDown');
  await dialog.getByRole('button', { name: 'Готово' }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.locator('#igCropStatus')).toHaveText(/Запазено/);
  await expect(thumbs.nth(1)).toBeFocused();   // focus comes back to the photo

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
