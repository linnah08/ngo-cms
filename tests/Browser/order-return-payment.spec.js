// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const AUTH_FILE = path.join(__dirname, '../../.playwright-auth.json');
const ROOT = path.join(__dirname, '../..');

test.use({ storageState: AUTH_FILE });

/** Runs PHP with the site loaded and returns what it prints. */
function php(code) {
  return execFileSync('php', ['-r',
    `$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(ROOT)};
     require ${JSON.stringify(ROOT + '/config.php')};
     require_once ${JSON.stringify(ROOT + '/admin/includes/db.php')};
     ${code}`], { cwd: ROOT }).toString().trim();
}

/**
 * A cancelled card order the site still shows as paid (the refund call failed)
 * gets a "Върни сумата на клиента" button. The bank number here is made up, so
 * DSK can't confirm anything: the admin must see that plainly and the order
 * must stay "paid" — never be marked returned on a guess.
 */
test('cancelled order still marked paid offers the return button', async ({ page }) => {
  const id = php(`
    $n = 'PW-RET-' . bin2hex(random_bytes(3));
    get_pdo()->prepare("INSERT INTO orders (order_number, type, status, lang, customer_name, customer_email, items,
        subtotal_eur, shipping_eur, total_eur, payment_method, payment_status, dsk_order_id)
        VALUES (?, 'physical', 'cancelled', 'bg', 'PW Return', 'pw-return@test.local', '[]',
        10, 0, 10, 'card', 'paid', '00000000-0000-0000-0000-000000000000')")->execute([$n]);
    echo get_pdo()->lastInsertId();`);

  try {
    await page.goto(`/admin/order-view.php?id=${id}`);
    const button = page.getByRole('button', { name: 'Върни сумата на клиента' });
    await expect(button).toBeVisible();

    await button.click();
    await expect(page.locator('#adminConfirmOverlay')).toBeVisible();
    await page.click('#adminConfirmOk');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Моля, върнете я ръчно в DSK Bank')).toBeVisible();
    expect(php(`$s = get_pdo()->prepare('SELECT payment_status FROM orders WHERE id = ?'); $s->execute([${id}]); echo $s->fetchColumn();`))
      .toBe('paid');
    await expect(button).toBeVisible();
  } finally {
    php(`get_pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([${id}]);`);
  }
});
