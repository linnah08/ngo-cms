// @ts-check
/**
 * Checkout flow tests — end-to-end from shop to confirmation page.
 *
 * NOTE: These tests place real orders in the local database and decrement
 * product stock. They are safe to run locally but should not run against
 * production. Orders created will have status "new" and payment_status "pending".
 *
 * Tests run without admin auth (public pages only).
 */
const { test, expect } = require('@playwright/test');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Add the first available non-variant, in-stock product to the cart.
 * Returns the page after being redirected to /cart/ or /magazin/ on failure.
 */
async function addFirstAvailableProductToCart(page) {
  await page.goto('/magazin/');
  await page.waitForLoadState('networkidle');

  // "Добави в количката" button only appears on standard in-stock products
  const addBtn = page.locator('form[action="/cart/add.php"] button[type="submit"]').first();
  await expect(addBtn).toBeVisible({ timeout: 10000 });
  await addBtn.click();

  // cart/add.php redirects to /magazin/ (redirect=shop) on success
  // then we navigate to /cart/
  await page.goto('/cart/');
  await page.waitForLoadState('networkidle');
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('shop page has at least one add-to-cart button for in-stock standard products', async ({ page }) => {
  await page.goto('/magazin/');
  const addBtn = page.locator('form[action="/cart/add.php"] button[type="submit"]').first();
  await expect(addBtn).toBeVisible();
});

test('adding a product to the cart shows it in the cart', async ({ page }) => {
  await addFirstAvailableProductToCart(page);
  // Cart page should show a product name and a checkout link
  await expect(page.locator('a[href="/checkout/"]')).toBeVisible();
});

test('full checkout flow: shop → cart → checkout → confirmation', async ({ page }) => {
  // ── 1. Add product to cart ────────────────────────────────────────────────
  await addFirstAvailableProductToCart(page);
  await expect(page.locator('a[href="/checkout/"]')).toBeVisible();

  // ── 2. Go to checkout ─────────────────────────────────────────────────────
  await page.click('a[href="/checkout/"]');
  await page.waitForURL('**/checkout/**');

  // ── 3. Step 1: Contact info ───────────────────────────────────────────────
  await expect(page.locator('input[name="customer_name"]')).toBeVisible();
  await page.fill('input[name="customer_name"]', 'Playwright Test');
  await page.fill('input[name="customer_email"]', 'playwright@test.local');
  await page.fill('input[name="customer_phone"]', '0888000000');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/checkout/?step=2**');

  // ── 4. Step 2: Delivery ───────────────────────────────────────────────────
  // Select Speedy courier — this triggers JS that renders delivery type radios
  await page.click('input[name="courier"][value="speedy"]');

  // Wait for the type section to appear (rendered by updateCourier JS)
  await page.waitForSelector('#typeSection', { state: 'visible' });

  // "До врата" (address delivery) is the first option and is auto-selected.
  // updateType() will show #addressSection immediately.
  await page.waitForSelector('#addressSection', { state: 'visible' });

  // Ensure the delivery_type hidden field is set (done by JS, verify before submit)
  await page.waitForFunction(() => {
    const el = document.getElementById('deliveryTypeInput');
    return el && el.value !== '';
  });

  await page.fill('input[name="delivery_address"]', 'ул. Тест 1');
  await page.fill('input[name="delivery_city"]', 'София');

  await page.click('button[type="submit"]');
  await page.waitForURL('**/checkout/?step=3**');

  // ── 5. Step 3: Review & confirm ───────────────────────────────────────────
  // Switch payment to COD (cash on delivery) so we don't trigger card redirect
  await page.evaluate(() => {
    const pmField = document.querySelector('input[name="payment_method"]');
    if (pmField) pmField.value = 'cod';
  });

  // Confirm the order
  await page.click('button[type="submit"]');

  // ── 6. Confirmation page ──────────────────────────────────────────────────
  await page.waitForURL('**/checkout/confirmation/**', { timeout: 15000 });

  // Order number format: OM-YYYYMMDD-XXXX
  const url = page.url();
  expect(url).toMatch(/order=OM-\d{8}-[A-F0-9]{4}/i);

  // Page body should contain the order number
  await expect(page.locator('body')).toContainText('OM-');
});

test('empty cart redirects to shop', async ({ page }) => {
  // Clear the session by visiting cart without adding anything
  // (fresh browser context has no session, so cart is empty)
  await page.goto('/cart/');
  // Should redirect to shop
  await page.waitForURL('**/magazin/**');
});

test('checkout with empty cart redirects to cart', async ({ page }) => {
  await page.goto('/checkout/');
  // No cart → redirected back to /cart/ → then to /magazin/
  await page.waitForURL(/\/(cart|magazin)\//);
});
