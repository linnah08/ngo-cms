// @ts-check
// Adding a photo to a post shows a progress bar while it uploads, instead of a few
// seconds where nothing seems to happen (assets/js/upload-progress.js).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs   = require('fs');
const path = require('path');

const ROOT      = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const SLUG      = 'pw-upload-progress';
const BG_FILE   = path.join(ROOT, 'content/articles/bg', SLUG + '.json');
const EN_FILE   = path.join(ROOT, 'content/articles/en', SLUG + '.json');
const PHOTO     = path.join(ROOT, 'tests/Browser/.pw-upload-progress.jpg');
const uploaded  = [];

test.use({ storageState: AUTH_FILE });

test.beforeAll(() => {
  // A noisy photo, so the JPEG is big enough (~400 KB) to watch it go up on a slow line.
  execFileSync('php', ['-r', `$i = imagecreatetruecolor(700, 700);
    for ($y = 0; $y < 700; $y++) for ($x = 0; $x < 700; $x++) imagesetpixel($i, $x, $y, mt_rand(0, 0xFFFFFF));
    imagejpeg($i, $argv[1], 95);`, PHOTO]);
  const post = (title) => ({
    title, slug: SLUG, slug_en: SLUG, date: '2026-11-01', author: 'PW', status: 'draft',
    excerpt: '', image: '', tags: [], content: '<p>x</p>', scheduled: false, photos: [],
  });
  fs.mkdirSync(path.dirname(BG_FILE), { recursive: true });
  fs.mkdirSync(path.dirname(EN_FILE), { recursive: true });
  fs.writeFileSync(BG_FILE, JSON.stringify(post('PW качване')));
  fs.writeFileSync(EN_FILE, JSON.stringify(post('PW upload')));
});

test.afterAll(() => {
  for (const f of [BG_FILE, EN_FILE, PHOTO]) if (fs.existsSync(f)) fs.unlinkSync(f);
  for (const p of uploaded) { const f = path.join(ROOT, p); if (fs.existsSync(f)) fs.unlinkSync(f); }
});

test('a photo added to a post shows its upload progress, then appears', async ({ page, context }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);

  // ~150 KB/s up: the 400 KB photo takes a few seconds, as on a weak phone signal.
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions',
    { offline: false, latency: 50, downloadThroughput: 1e6, uploadThroughput: 150 * 1024 });

  const seen = [];
  await page.exposeFunction('pwSeen', (v) => seen.push(v));
  await page.evaluate(() => {
    new MutationObserver(() => {
      const bar = document.querySelector('#apProgress [role="progressbar"]');
      if (bar) window.pwSeen(bar.getAttribute('aria-valuetext'));
    }).observe(document.getElementById('apProgress'), { subtree: true, attributes: true, childList: true });
  });

  await page.setInputFiles('#apFiles', PHOTO);
  const bar = page.locator('#apProgress [role="progressbar"]');
  await expect(bar).toBeVisible();
  await expect(page.locator('#apProgress')).toContainText(/Качване… \d+%/);

  await expect(page.locator('#apGrid .ap-photo')).toHaveCount(1, { timeout: 15000 });
  await expect(bar).toHaveCount(0);   // the bar goes once the photo is in
  uploaded.push(await page.locator('#apGrid input[name="photo_src[]"]').inputValue());

  // It counted up, with a value between empty and full on the way, then said it was being processed.
  const pcts = seen.map((v) => parseInt(v, 10)).filter((n) => !isNaN(n));
  expect(pcts.some((n) => n > 0 && n < 100)).toBe(true);
  expect(seen).toContain('Обработва се');
});

test('without a page spot, the bar shows in a panel and an error stays until closed', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  // A 404 that isn't JSON: the upload fails, as a broken server would.
  const r = await page.evaluate(() => {
    const fd = new FormData(); fd.append('x', new Blob(['x']));
    const sent = window.OMUpload.send('/admin/no-such-upload.php', fd);
    const shown = !!document.querySelector('.om-upload-panel [role="progressbar"]');
    return sent.then(() => ({ shown, result: 'ok' }), () => ({ shown, result: 'failed',
      gone: !document.querySelector('.om-upload-panel') }));
  });
  expect(r).toEqual({ shown: true, result: 'failed', gone: true });

  await page.evaluate(() => window.OMUpload.notice('Снимката не можа да се качи.'));
  const alert = page.locator('.om-upload-panel [role="alert"]');
  await expect(alert).toContainText('Снимката не можа да се качи.');
  await page.getByRole('button', { name: 'Затвори' }).click();
  await expect(page.locator('.om-upload-panel')).toHaveCount(0);
});
