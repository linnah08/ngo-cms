// @ts-check
// The post editor: the Socials panel (Facebook, Instagram, LinkedIn previews) opens
// beside the article, and the English section starts folded away.
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

test('the Socials toggle opens the panel beside the article and remembers it', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  const toggle = page.locator('#socialToggle');
  await expect(page.locator('#articleForm')).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('#socialPanel')).toBeHidden();

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#socialPanel')).toBeVisible();
  await expect(page.locator('#articleForm')).toBeVisible();
  await expect(page.locator('#fbStatusBadge')).toContainText('FB: 02.01.2030 11:00');
  await expect(page.locator('#socStatus')).toHaveText('Прегледът е обновен');
  await expect(page.locator('#socPreview-fb')).toContainText('PW табове');

  await page.reload();
  await expect(page.locator('#socialPanel')).toBeVisible();
  await page.locator('#socialClose').click();
  await expect(page.locator('#socialPanel')).toBeHidden();
  await expect(toggle).toBeFocused();
});

test('the platform tabs move with the arrow keys', async ({ page }) => {
  await page.goto('/admin/article-edit.php?slug=' + SLUG);
  await page.locator('#socialToggle').click();
  await page.getByRole('tab', { name: 'Facebook' }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Instagram' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('#socTabpanel-insta')).toBeVisible();
  await expect(page.locator('#instaStoryStatusBadge')).toContainText('IG Story: 03.01.2030 12:30');
});

test('a new article shows previews while it is written', async ({ page }) => {
  await page.goto('/admin/article-edit.php');
  await page.locator('#socialToggle').click();
  await expect(page.locator('#socPreview-fb')).toHaveText('Започнете да пишете — тук ще видите публикациите.');
  await page.locator('#title').fill('Нова PW статия');
  await expect(page.locator('#socPreview-fb')).toContainText('Нова PW статия');
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
  // Saving keeps the editor open, with a success message.
  await page.waitForURL(/\/admin\/article-edit\.php\?slug=/);
  await expect(page.getByRole('status').filter({ hasText: 'Статията е запазена.' })).toBeVisible();
  const en = JSON.parse(fs.readFileSync(EN_FILE, 'utf8'));
  expect(en.title).toBe('PW tabs');
  expect(en.content).toContain('x');
  // A post saved without an excerpt gets one (Claude, or the start of the text).
  expect(en.excerpt).not.toBe('');
  expect(JSON.parse(fs.readFileSync(BG_FILE, 'utf8')).excerpt).not.toBe('');
});
