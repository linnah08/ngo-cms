// @ts-check
// The post editor is split into a Content tab (the form) and a Social tab (Facebook,
// Instagram, LinkedIn), and the English section starts folded away.
const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const ROOT      = path.join(__dirname, '../..');
const AUTH_FILE = path.join(ROOT, '.playwright-auth.json');
const SLUG      = 'pw-editor-tabs';
const BG_FILE   = path.join(ROOT, 'content/articles/bg', SLUG + '.json');
const EN_FILE   = path.join(ROOT, 'content/articles/en', SLUG + '.json');

test.use({ storageState: AUTH_FILE });

test.beforeEach(async ({ page }) => {
  const post = (title, extra) => Object.assign({
    title, slug: SLUG, slug_en: SLUG, date: '2026-10-03', author: 'PW', status: 'draft',
    excerpt: '', image: '', tags: [], content: '<p>x</p>', scheduled: false, photos: [],
  }, extra);
  fs.mkdirSync(path.dirname(BG_FILE), { recursive: true });
  fs.mkdirSync(path.dirname(EN_FILE), { recursive: true });
  fs.writeFileSync(BG_FILE, JSON.stringify(post('PW табове', {
    fb_scheduled_at: '2030-01-02T11:00', insta_story_scheduled_at: '2030-01-03T12:30',
  })));
  fs.writeFileSync(EN_FILE, JSON.stringify(post('PW tabs', {})));
  // Start every test from a clean slate: no remembered EN state, no unsaved draft.
  await page.addInitScript(() => {
    try {
      if (!sessionStorage.getItem('pwClean')) { localStorage.clear(); sessionStorage.setItem('pwClean', '1'); }
    } catch (e) {}
  });
});

test.afterAll(() => {
  for (const f of [BG_FILE, EN_FILE]) if (fs.existsSync(f)) fs.unlinkSync(f);
});

test('the Content tab is the default: the form, the save button and social badges', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await expect(page.getByRole('navigation', { name: 'Части на статията' }).getByRole('link', { name: 'Съдържание' })).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('#articleForm')).toBeVisible();
  await expect(page.locator('.admin-page-header button[type="submit"]')).toBeVisible();
  await expect(page.locator('#socialPanel')).toHaveCount(0);
  const badges = page.getByRole('list', { name: 'Социални мрежи' });
  await expect(badges).toContainText('FB 02.01 11:00');
  await expect(badges).toContainText('IG Story 03.01 12:30');
});

test('the Social tab has no article form and no save button', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=social');
  await expect(page.getByRole('navigation', { name: 'Части на статията' }).getByRole('link', { name: 'Социални мрежи' })).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('#articleForm')).toHaveCount(0);
  await expect(page.locator('button[type="submit"]')).toHaveCount(0);
  // Either the panels (Claude configured) or the note saying how to turn them on.
  await expect(page.locator('#socialPanel, a[href="/admin/payment.php"]').first()).toBeVisible();
});

test('an unknown tab falls back to Content', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG + '&tab=<script>');
  await expect(page.locator('#articleForm')).toBeVisible();
});

test('a new article asks to be saved before the Social tab', async ({ page }) => {
  await page.goto('/admin/article-edit.php?tab=social');
  await expect(page.getByText('Запазете статията, за да публикувате в социалните мрежи.')).toBeVisible();
  await page.getByRole('link', { name: '← Към съдържанието' }).click();
  await expect(page.locator('#articleForm')).toBeVisible();
});

test('the English section starts folded, opens with its editor, and keeps its text on save', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  const toggle = page.locator('#enToggleBtn');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('#title_en')).toBeHidden();

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#title_en')).toHaveValue('PW tabs');
  await page.waitForFunction(() => !!(window.tinymce && tinymce.get('content_en')));

  // Remembered for this article on the next visit.
  await page.reload();
  await expect(page.locator('#title_en')).toBeVisible();
});

test('saving with the English section folded keeps the English version', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await expect(page.locator('#title_en')).toBeHidden();
  await page.locator('.admin-page-header button[type="submit"]').click();
  await page.waitForURL('**/admin/articles.php**');
  const en = JSON.parse(fs.readFileSync(EN_FILE, 'utf8'));
  expect(en.title).toBe('PW tabs');
  expect(en.content).toContain('x');
});
