// @ts-check
// Yearly reports end to end: add a year and a document in the admin, read it in the
// in-page viewer on the BG and EN pages, then delete it.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const DATA = path.join(ROOT, 'content/financial-reports.json');
let backup = null;

// A real 2-page PDF, built by hand: enough for PDF.js to render page 1 and page 2.
function twoPagePdf() {
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
  ];
  let out = '%PDF-1.4\n', offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

test.use({ storageState: AUTH_FILE });
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => { backup = fs.existsSync(DATA) ? fs.readFileSync(DATA) : null; if (backup) fs.unlinkSync(DATA); });
test.afterAll(() => { if (backup) fs.writeFileSync(DATA, backup); else if (fs.existsSync(DATA)) fs.unlinkSync(DATA); });

test('no reports: no footer link, plain empty page', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('footer a[href="/finansovi-otcheti/"]')).toHaveCount(0);
  await page.goto('/finansovi-otcheti/');
  await expect(page.getByText('Още няма публикувани отчети.')).toBeVisible();
});

test('add a year and a document, read it in the viewer, delete it', async ({ page }) => {
  await page.goto('/admin/financial-reports.php');
  await page.fill('#newYear', '2025');
  await page.getByRole('button', { name: '+ Нова година' }).click();
  await expect(page.getByRole('status')).toContainText('Годината е добавена');

  await page.getByRole('link', { name: '+ Добави документ' }).click();
  await page.fill('#title_bg', 'PW отчет "2025" & <b>');
  await page.fill('#title_en', 'PW report');
  await page.setInputFiles('#pdf', { name: 'otchet.pdf', mimeType: 'application/pdf', buffer: twoPagePdf() });
  await page.getByRole('button', { name: 'Запази' }).click();
  await expect(page.getByRole('status')).toContainText('Документът е добавен');

  await page.goto('/finansovi-otcheti/');
  await expect(page.locator('footer a[href="/finansovi-otcheti/"]')).toHaveCount(1);
  await expect(page.getByRole('heading', { name: 'PW отчет "2025" & <b>' })).toBeVisible();
  const open = page.getByRole('button', { name: 'Отвори' });
  await open.click();
  await expect(open).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('[data-report-viewer] canvas')).toBeVisible();
  await expect(page.locator('[data-report-page]')).toHaveText('1 / 2');
  await page.getByRole('button', { name: 'Следваща страница' }).click();
  await expect(page.locator('[data-report-page]')).toHaveText('2 / 2');
  await page.getByRole('button', { name: 'Затвори' }).click();
  await expect(open).toBeFocused();
  await expect(open).toHaveAttribute('aria-expanded', 'false');

  await page.goto('/en/financial-reports/');
  await expect(page.getByRole('heading', { name: 'PW report' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Download \(PDF, .*, in Bulgarian\)/ })).toBeVisible();

  await page.goto('/admin/financial-reports.php');
  await page.getByRole('button', { name: /Изтрий „PW отчет/ }).click();
  await page.getByRole('button', { name: 'Да, изтрий' }).click();
  await expect(page.getByRole('status')).toContainText('Документът е изтрит');
});

/** Review Focus 5 */
test('a PDF the viewer cannot read says so and points to the download', async ({ page }) => {
  fs.mkdirSync(path.join(ROOT, 'assets/files/reports'), { recursive: true });
  fs.writeFileSync(path.join(ROOT, 'assets/files/reports/2025-0badf00d.pdf'), '%PDF-1.4 not really a pdf');
  fs.writeFileSync(DATA, JSON.stringify({ years: [{ year: 2025, documents: [
    { id: '0badf00d', title_bg: 'Счупен', title_en: '', description_bg: '', description_en: '', file: '2025-0badf00d.pdf' }] }] }));
  await page.goto('/finansovi-otcheti/');
  await page.getByRole('button', { name: 'Отвори' }).click();
  await expect(page.locator('[data-report-viewer]')).toContainText('Документът не може да се покаже тук');
  await expect(page.getByRole('link', { name: /Изтегли/ })).toBeVisible();
  fs.unlinkSync(path.join(ROOT, 'assets/files/reports/2025-0badf00d.pdf'));
});
