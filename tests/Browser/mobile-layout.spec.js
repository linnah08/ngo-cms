// @ts-check
/**
 * Most visitors are on phones. Every main public page, BG and EN, must fit the
 * viewport width — no sideways scroll from a fixed-width table, image or grid.
 * Runs in both projects; the "mobile" one (Pixel 5, 393px) is the one that
 * catches regressions. Public pages only, nothing is written.
 */
const { test, expect } = require('@playwright/test');

const PAGES = [
  '/', '/za-nas/', '/proekti/', '/novini/', '/kontakti/', '/kak-da-pomogna/', '/magazin/', '/donation/', '/usloviya/',
  '/en/', '/en/about/', '/en/projects/', '/en/news/', '/en/contacts/', '/en/how-to-help/', '/en/shop/', '/en/terms/',
];

for (const path of PAGES) {
  test(`no horizontal scroll on ${path}`, async ({ page }) => {
    const res = await page.goto(path);
    expect(res?.status(), `${path} should load`).toBeLessThan(400);
    await page.waitForLoadState('networkidle');

    // resize/device emulation can silently not apply — confirm the real width.
    const viewport = page.viewportSize();
    expect(await page.evaluate(() => window.innerWidth)).toBe(viewport?.width);

    const overflow = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const wide = [...document.querySelectorAll('body *')]
        .filter(el => {
          const r = el.getBoundingClientRect();
          return r.width > 0 && r.right > vw + 1 && getComputedStyle(el).position !== 'fixed';
        })
        .slice(0, 5)
        .map(el => `${el.tagName.toLowerCase()}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''} (right edge ${Math.round(el.getBoundingClientRect().right)}px)`);
      return { scrollWidth: document.documentElement.scrollWidth, vw, wide };
    });
    expect(overflow.scrollWidth, `${path} scrolls sideways at ${overflow.vw}px; widest elements: ${overflow.wide.join(', ')}`)
      .toBeLessThanOrEqual(overflow.vw);
  });
}
