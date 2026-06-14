// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');

test.use({ storageState: AUTH_FILE });

// ── Articles ──────────────────────────────────────────────────────────────────

test('articles list loads and has table', async ({ page }) => {
  await page.goto('/admin/articles.php');
  await expect(page.locator('table.admin-table')).toBeVisible();
});

test('article edit page has save button above and below form', async ({ page }) => {
  await page.goto('/admin/articles.php');

  // Click the first article's edit link
  const firstEditLink = page.locator('a[href*="article-edit.php?slug"]').first();
  await firstEditLink.click();
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
  const firstEditLink = page.locator('a[href*="article-edit.php?slug"]').first();
  await firstEditLink.click();

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
  await page.goto('/admin/orders.php');
  await expect(page.locator('table.admin-table')).toBeVisible();
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
});

// ── Navigation ────────────────────────────────────────────────────────────────

test('admin sidebar navigation links are present', async ({ page }) => {
  await page.goto('/admin/dashboard.php');
  await expect(page.locator('.admin-sidebar')).toBeVisible();
  await expect(page.locator('.admin-nav__link').first()).toBeVisible();
});
