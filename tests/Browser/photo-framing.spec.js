// @ts-check
// Framing a front-page photo on the live page ("Нагласи снимката", assets/js/inline-cms.js).
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
const HOME_JSON = path.join(__dirname, '../../content/home.json');
let backup = null;

test.use({ storageState: AUTH_FILE });
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  backup = fs.existsSync(HOME_JSON) ? fs.readFileSync(HOME_JSON) : null;
  fs.writeFileSync(HOME_JSON, JSON.stringify({ version: 2, rev: 1, sections: [
    { id: 's_hero', type: 'hero', visible: true, fields: {
      title: { bg: 'Добре дошли', en: 'Welcome' }, image: '/assets/images/hero.webp' } },
  ] }));
});
test.afterAll(() => {
  if (backup === null) { if (fs.existsSync(HOME_JSON)) fs.unlinkSync(HOME_JSON); }
  else fs.writeFileSync(HOME_JSON, backup);
});

// The page loads the editor and posts saves under SITE_URL, which a local config may point
// elsewhere: serve the front page with SITE_URL swapped for this server.
async function openHome(page, baseURL) {
  await page.route(url => url.pathname === '/', async route => {
    const res  = await route.fetch();
    const body = (await res.text()).replace(/https?:\/\/[^"'\s]+?(?=\/(assets|admin)\/)/g, baseURL);
    await route.fulfill({ response: res, body });
  });
  await page.goto('/');
  await page.waitForFunction(() => !!window.OmCMS);
  await page.evaluate(base => { window._omAdminBase = base + '/admin'; }, baseURL);   // printed JSON-escaped
  // The cookie banner sits over the bottom of the page, where the resize corner can be.
  const essential = page.getByRole('button', { name: 'Само необходими' });
  if (await essential.isVisible()) await essential.click();
}

test('frame the hero photo with mouse and keys, save, and see it after a reload', async ({ page, baseURL }) => {
  await openHome(page, baseURL);
  await page.locator('#om-edit-toggle').click();

  // The photo is a button in edit mode: Enter opens its options.
  const wrap = page.locator('.hero .om-img-wrap');
  await expect(wrap).toHaveAttribute('role', 'button');
  await expect(wrap).not.toHaveAttribute('contenteditable', /.*/);
  await wrap.focus();
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: /Нагласи снимката/ }).click();

  const panel = page.getByRole('dialog', { name: 'Нагласяне на снимката' });
  await expect(panel).toBeVisible();
  await expect(panel).toBeFocused();

  // Keys: zoom in, move the photo, make the frame wider.
  for (const k of ['+', '+', '+', '+', '+', 'ArrowRight', 'ArrowRight', 'Shift+ArrowRight', 'Shift+ArrowRight']) {
    await page.keyboard.press(k);
  }
  const img = page.locator('.hero img');
  await expect(img).toHaveCSS('transform', /matrix\(1\.5, 0, 0, 1\.5/);
  await expect(panel.getByRole('status')).toContainText('увеличение 150%');

  // Mouse: drag the corner (it faces the text, on the left) further left and up.
  const grid = page.locator('[data-om-fgrid="s_hero"]');
  const handle = page.locator('[data-om-fbox="s_hero"] > div[aria-hidden] > div');
  await handle.scrollIntoViewIfNeeded();
  const before = await page.locator('[data-om-fbox="s_hero"]').boundingBox();
  const h = await handle.boundingBox();
  await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
  await page.mouse.down();
  await page.mouse.move(h.x - 60, h.y - 80, { steps: 6 });
  await page.mouse.up();
  const after = await page.locator('[data-om-fbox="s_hero"]').boundingBox();
  expect(after.width).toBeGreaterThan(before.width);
  expect(after.height / after.width).toBeLessThan(before.height / before.width);

  await panel.getByRole('button', { name: 'Готово' }).click();
  await expect(panel).toHaveCount(0);
  await expect(wrap).toBeFocused();

  const saved = page.waitForResponse(r => r.url().includes('/admin/inline-save.php'));
  await page.locator('#om-save-btn').click();
  expect((await (await saved).json()).ok).toBe(true);

  await page.reload();
  await expect(page.locator('style[data-om-frame-css="s_hero"]')).toHaveCount(1);
  await expect(img).toHaveCSS('transform', /matrix\(1\.5, 0, 0, 1\.5/);
  const cols = await grid.evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').map(parseFloat));
  expect(cols[1]).toBeGreaterThan(cols[0]);   // the photo column is now the wider one
});

test('Escape leaves the framing as it was and saves nothing', async ({ page, baseURL }) => {
  await openHome(page, baseURL);
  await page.locator('#om-edit-toggle').click();
  const img = page.locator('.hero img');
  const style = await img.getAttribute('style');
  await page.locator('.hero .om-img-wrap').click();
  await page.getByRole('button', { name: /Нагласи снимката/ }).click();
  await page.keyboard.press('+');
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Нагласяне на снимката' })).toHaveCount(0);
  await expect(page.locator('#om-save-btn')).not.toHaveClass(/om-dirty/);
  expect((await img.getAttribute('style') || '').replace(/\s/g, '')).toBe((style || '').replace(/\s/g, ''));
});
