// @ts-check
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const ROOT      = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');

test.use({ storageState: AUTH_FILE });

// Its own published BG/EN article, so the article and news checks don't depend on
// whatever content this site happens to have (a fresh checkout has none).
const SLUG    = 'pw-admin-ui';
const BG_FILE = path.join(ROOT, 'content/articles/bg', SLUG + '.json');
const EN_FILE = path.join(ROOT, 'content/articles/en', SLUG + '.json');

test.beforeAll(() => {
  const post = (title) => ({
    title, slug: SLUG, slug_en: SLUG, date: '2026-10-04', author: 'PW', status: 'published',
    excerpt: '', image: '', tags: [], content: '<p>x</p>', scheduled: false,
  });
  for (const [file, title] of [[BG_FILE, 'PW админ'], [EN_FILE, 'PW admin']]) {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(post(title)));
  }
});

test.afterAll(() => {
  for (const f of [BG_FILE, EN_FILE]) if (fs.existsSync(f)) fs.unlinkSync(f);
});

// ── Articles ──────────────────────────────────────────────────────────────────

test('articles list loads and has table', async ({ page }) => {
  await page.goto('/admin/articles.php');
  await expect(page.locator('table.admin-table')).toBeVisible();
});

test('article edit page has save button above and below form', async ({ page }) => {
  await page.goto('/admin/articles.php');

  await page.locator(`a[href*="article-edit.php?slug=${SLUG}"]`).first().click();
  await page.waitForLoadState('networkidle');

  // Save button in page header (top)
  await expect(page.locator('.admin-page-header button[type="submit"]')).toBeVisible();

  // Save button at bottom of page
  const bottomSave = page.locator('button[type="submit"][form="articleForm"]').last();
  await expect(bottomSave).toBeVisible();
  await bottomSave.scrollIntoViewIfNeeded();
  await expect(bottomSave).toBeInViewport();
});

test('article edit has TinyMCE toolbar with link button', async ({ page }) => {
  await page.goto('/admin/articles.php');
  await page.locator(`a[href*="article-edit.php?slug=${SLUG}"]`).first().click();

  // Wait for TinyMCE to initialise
  await page.waitForFunction(() => typeof window.tinymce !== 'undefined' && !!tinymce.activeEditor);
  await page.waitForTimeout(500);

  // Link button should be present in the toolbar (inside TinyMCE iframe)
  const frame = page.frameLocator('.tox-edit-area__iframe').first();
  // Check toolbar in host page (not inside iframe)
  const toolbar = page.locator('.tox-toolbar__group').first();
  await expect(toolbar).toBeVisible();

  // Link button aria-label
  const linkBtn = page.locator('button[aria-label="Insert/edit link"]').first();
  await expect(linkBtn).toBeVisible();
});

test('new article page has save button', async ({ page }) => {
  await page.goto('/admin/article-edit.php');
  await expect(page.locator('button[type="submit"]').first()).toBeVisible();
});

// ── Products ──────────────────────────────────────────────────────────────────

test('products list loads and has sortable columns', async ({ page }) => {
  await page.goto('/admin/products.php');
  await expect(page.locator('table.admin-table')).toBeVisible();
  await expect(page.locator('th[data-sort]').first()).toBeVisible();
});

test('product edit has save button and TinyMCE', async ({ page }) => {
  await page.goto('/admin/products.php');
  const firstEdit = page.locator('a[href*="product-edit.php?id"]').first();
  await firstEdit.click();
  await page.waitForLoadState('networkidle');

  await expect(page.locator('button[type="submit"]').first()).toBeVisible();
  await page.waitForFunction(() => typeof window.tinymce !== 'undefined' && !!tinymce.activeEditor);
});

// ── Orders ────────────────────────────────────────────────────────────────────

test('orders list loads', async ({ page }) => {
  const res = await page.goto('/admin/orders.php');
  expect(res?.status()).toBe(200);
  // Orders live in the database, which a test doesn't write to: a site with none
  // shows its empty-state line instead of the table.
  await expect(page.locator('table.admin-table').or(page.getByText('Няма поръчки.'))).toBeVisible();
});

// ── Public pages ──────────────────────────────────────────────────────────────

test('homepage loads with hero section', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('h1')).toBeVisible();
});

test('shop page loads with product cards', async ({ page }) => {
  await page.goto('/magazin/');
  await expect(page.locator('.card').first()).toBeVisible();
});

test('news page loads with article cards', async ({ page }) => {
  await page.goto('/novini/');
  await expect(page.locator('.article-grid')).toBeVisible();
  await expect(page.locator(`.article-grid a[href="/novini/${SLUG}/"]`).first()).toBeVisible();
});

test('English news page loads with article cards', async ({ page }) => {
  await page.goto('/en/news/');
  await expect(page.locator(`.article-grid a[href="/en/news/${SLUG}/"]`).first()).toBeVisible();
});

// ── Navigation ────────────────────────────────────────────────────────────────

test('admin sidebar navigation links are present', async ({ page }) => {
  await page.goto('/admin/dashboard.php');
  await expect(page.locator('.admin-sidebar')).toBeVisible();
  await expect(page.locator('.admin-nav__link').first()).toBeVisible();
});

// ── Organisation → Модули ─────────────────────────────────────────────────────

test('switching a module off asks first, and Cancel saves nothing', async ({ page }) => {
  await page.goto('/admin/organisation.php');
  const sw = page.locator('#mod-donations');
  await expect(sw).toBeVisible();
  await expect(page.getByLabel('Дарения', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Кампании', { exact: true })).toBeVisible();

  // Needs the module on to begin with (no save is made to get there).
  if (!(await sw.isChecked())) test.skip(true, 'Donations are switched off on this site.');

  await sw.uncheck();
  await expect(page.locator('#state-donations')).toContainText('ще се изключи');
  await page.locator('#orgForm button[type="submit"]').click();

  const modal = page.locator('#adminConfirmOverlay');
  await expect(modal).toBeVisible();
  await expect(page.locator('#adminConfirmMsg')).toContainText('Изключвате даренията');
  await page.locator('#adminConfirmCancel').click();
  await expect(modal).toBeHidden();
  await expect(page).toHaveURL(/\/admin\/organisation\.php$/);
  // Still the unsaved page: no success message.
  await expect(page.locator('.admin-alert--success')).toHaveCount(0);
});
