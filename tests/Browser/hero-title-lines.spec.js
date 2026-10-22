// @ts-check
// The front-page title keeps line breaks typed on the live page (data-cms-type="lines", assets/js/inline-cms.js).
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
    { id: 's_hero', type: 'hero', visible: true, fields: { title: { bg: '- Какво правиш?', en: 'Welcome' } } },
  ] }));
});
test.afterAll(() => {
  if (backup === null) { if (fs.existsSync(HOME_JSON)) fs.unlinkSync(HOME_JSON); }
  else fs.writeFileSync(HOME_JSON, backup);
});

// Same as photo-framing.spec.js: serve the front page with SITE_URL swapped for this server.
async function openHome(page, baseURL) {
  await page.route(url => url.pathname === '/', async route => {
    const res  = await route.fetch();
    const body = (await res.text()).replace(/https?:\/\/[^"'\s]+?(?=\/(assets|admin)\/)/g, baseURL);
    await route.fulfill({ response: res, body });
  });
  await page.goto('/');
  await page.waitForFunction(() => !!window.OmCMS);
  await page.evaluate(base => { window._omAdminBase = base + '/admin'; }, baseURL);
  const essential = page.getByRole('button', { name: 'Само необходими' });
  if (await essential.isVisible()) await essential.click();
}

test('Enter in the hero title starts a new line, which is saved and shown after a reload', async ({ page, baseURL }) => {
  await openHome(page, baseURL);
  await page.locator('#om-edit-toggle').click();

  const title = page.locator('.hero h1');
  await title.click();
  await title.evaluate(el => { const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); getSelection().removeAllRanges(); getSelection().addRange(r); });
  await page.keyboard.press('Enter');
  await page.keyboard.type('- Нищо');
  await expect(title).toBeFocused();   // Enter did not end the edit

  await page.locator('.hero p').first().click();   // leave the title
  const saved = page.waitForResponse(r => r.url().includes('/admin/inline-save.php'));
  await page.locator('#om-save-btn').click();
  expect((await (await saved).json()).ok).toBe(true);
  expect(JSON.parse(fs.readFileSync(HOME_JSON, 'utf8')).sections[0].fields.title.bg).toBe('- Какво правиш?\n- Нищо');

  await page.reload();
  await expect(title).toHaveText('- Какво правиш?\n- Нищо', { useInnerText: true });
  const lineHeight = await title.evaluate(el => parseFloat(getComputedStyle(el).lineHeight) || 0);
  const height     = await title.evaluate(el => el.getBoundingClientRect().height);
  expect(height).toBeGreaterThan(lineHeight * 1.5);   // two lines on screen, not one
});
