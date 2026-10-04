// @ts-check
// The report viewer under stress, and the admin when a save fails.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const DATA = path.join(ROOT, 'content/financial-reports.json');
const DIR = path.join(ROOT, 'assets/files/reports');
let backup = null;

function pdf(pages) {
  const kids = Array.from({ length: pages }, (_, i) => `${i + 3} 0 R`).join(' ');
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', `<< /Type /Pages /Kids [${kids}] /Count ${pages} >>`]
    .concat(Array.from({ length: pages }, () => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>'));
  let out = '%PDF-1.4\n', offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}
const doc = (id, t) => ({ id, title_bg: t, title_en: t, description_bg: '', description_en: '', file: `2025-${id}.pdf` });

test.use({ storageState: AUTH_FILE });
test.beforeAll(() => { backup = fs.existsSync(DATA) ? fs.readFileSync(DATA) : null; });
test.afterAll(() => {
  if (fs.existsSync(DATA)) fs.chmodSync(DATA, 0o644);
  if (backup) fs.writeFileSync(DATA, backup); else if (fs.existsSync(DATA)) fs.unlinkSync(DATA);
  for (const f of ['2025-aaaa0001.pdf', '2025-aaaa0002.pdf']) { const p = path.join(DIR, f); if (fs.existsSync(p)) fs.unlinkSync(p); }
});
test.beforeEach(() => {
  fs.mkdirSync(DIR, { recursive: true });
  fs.writeFileSync(path.join(DIR, '2025-aaaa0001.pdf'), pdf(3));
  fs.writeFileSync(path.join(DIR, '2025-aaaa0002.pdf'), pdf(2));
  if (fs.existsSync(DATA)) fs.chmodSync(DATA, 0o644);
  fs.writeFileSync(DATA, JSON.stringify({ years: [{ year: 2025, documents: [doc('aaaa0001', 'Първи'), doc('aaaa0002', 'Втори')] }] }));
});

/** Review #1: a page that fails to draw says so and the viewer keeps working. */
test('a page that fails to draw says so instead of freezing', async ({ page }) => {
  await page.goto('/finansovi-otcheti/');
  await page.getByRole('button', { name: 'Отвори' }).first().click();
  await expect(page.locator('[data-report-page]')).toHaveText('1 / 3');
  // Break drawing from now on, then ask for the next page.
  await page.evaluate(() => { HTMLCanvasElement.prototype.getContext = () => { throw new Error('boom'); }; });
  await page.getByRole('button', { name: 'Следваща страница' }).click();
  await expect(page.locator('[data-report-viewer] [role=alert]')).toContainText('не може да се покаже');
});

/** Review #2: zooming in on a 3x phone never builds a canvas over the browser limit. */
test.describe('phone', () => {
  test.use({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 });
  test('zooming in stays under the canvas size limit and still grows', async ({ page }) => {
    await page.goto('/finansovi-otcheti/');
    await page.getByRole('button', { name: 'Отвори' }).first().click();
    await expect(page.locator('[data-report-page]')).toHaveText('1 / 3');
    const w0 = await page.locator('[data-report-viewer] canvas').evaluate((c) => c.getBoundingClientRect().width);
    for (let i = 0; i < 8; i++) await page.getByRole('button', { name: 'Увеличи' }).click();
    await page.waitForTimeout(1500);
    const [px, w1] = await page.locator('[data-report-viewer] canvas').evaluate((c) => [c.width * c.height, c.getBoundingClientRect().width]);
    expect(px).toBeLessThanOrEqual(16_777_216);
    expect(w1).toBeGreaterThan(w0 * 3);
  });
});

/** Review #3: closing the viewer releases its worker. */
test('closing the viewer releases its PDF worker', async ({ page }) => {
  await page.addInitScript(() => {
    const W = window.Worker; window.__made = 0; window.__ended = 0;
    window.Worker = class extends W { constructor(...a) { super(...a); window.__made++; } terminate() { window.__ended++; super.terminate(); } };
  });
  await page.goto('/finansovi-otcheti/');
  const open = page.getByRole('button', { name: 'Отвори' });
  for (const i of [0, 1, 0]) {
    await open.nth(i).click();
    await expect(page.locator('[data-report-page]')).toHaveText(/1 \/ \d/);
    await page.getByRole('button', { name: 'Затвори' }).click();
  }
  await expect.poll(() => page.evaluate(() => [window.__made, window.__ended])).toEqual([3, 3]);
});

/** Review #4: opening a second document keeps focus on it. */
test('opening a second document keeps focus on that document', async ({ page }) => {
  await page.goto('/finansovi-otcheti/');
  const open = page.getByRole('button', { name: 'Отвори' });
  await open.nth(0).click();
  await expect(page.locator('[data-report-page]')).toHaveText('1 / 3');
  await open.nth(1).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-report-page]')).toHaveText('1 / 2');
  await expect(open.nth(1)).toBeFocused();
});

/** Review #6: a save that fails says so, and a replaced file is not lost. */
test('a save that cannot be written says so and keeps the old file', async ({ page }) => {
  const before = new Set(fs.readdirSync(DIR));
  fs.chmodSync(DATA, 0o444);
  await page.goto('/admin/financial-reports.php?edit=aaaa0001');
  await page.fill('#title_bg', 'Ново заглавие');
  await page.setInputFiles('#pdf', { name: 'n.pdf', mimeType: 'application/pdf', buffer: pdf(1) });
  await page.getByRole('button', { name: 'Запази' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'не можаха да се запазят' })).toBeVisible();
  expect(fs.existsSync(path.join(DIR, '2025-aaaa0001.pdf'))).toBe(true);
  // The just-uploaded file was removed again: nothing new is left behind.
  expect(fs.readdirSync(DIR).filter((f) => !before.has(f))).toEqual([]);
});

/** A year deleted meanwhile (another tab, or a deploy that wiped the data): say so, keep nothing. */
test('adding a document to a year that no longer exists says so and stores nothing', async ({ page }) => {
  const before = new Set(fs.readdirSync(DIR));
  await page.goto('/admin/financial-reports.php?add=2025');
  // The year disappears after the form was opened.
  fs.writeFileSync(DATA, JSON.stringify({ years: [] }));
  await page.fill('#title_bg', 'Изгубен');
  await page.setInputFiles('#pdf', { name: 'n.pdf', mimeType: 'application/pdf', buffer: pdf(1) });
  await page.getByRole('button', { name: 'Запази' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Годината 2025 вече не съществува' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveCount(0);
  expect(fs.readdirSync(DIR).filter((f) => !before.has(f))).toEqual([]);
});

test('editing a document that no longer exists says so and stores nothing', async ({ page }) => {
  const before = new Set(fs.readdirSync(DIR));
  await page.goto('/admin/financial-reports.php?edit=aaaa0001');
  fs.writeFileSync(DATA, JSON.stringify({ years: [{ year: 2025, documents: [] }] }));
  await page.fill('#title_bg', 'Изгубен');
  await page.setInputFiles('#pdf', { name: 'n.pdf', mimeType: 'application/pdf', buffer: pdf(1) });
  await page.getByRole('button', { name: 'Запази' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Документът вече не съществува' })).toBeVisible();
  expect(fs.readdirSync(DIR).filter((f) => !before.has(f))).toEqual([]);
});
