# Bulk Permanent Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Изтрий завинаги" button to the admin products bulk toolbar that permanently hard-deletes selected products (and their variants) only when they have no order references, skipping the rest with a count in the flash message.

**Architecture:** One new POST action (`bulk_hard_delete`) in `admin/products.php` checks each candidate product against the `orders.items` JSON column using `JSON_CONTAINS`, splits into safe/skipped sets, hard-deletes the safe set, and flashes a human-readable summary. A new toolbar button and hidden form are added to the HTML, and a new JS event listener (reusing the existing `injectIds` helper) wires the button to the form.

**Tech Stack:** PHP 8.4, PDO, MariaDB 10.5 (`JSON_CONTAINS` + `JSON_EXTRACT` with wildcard path), vanilla JS, existing `_adminConfirm` Promise-based modal.

---

## Files

| Action | File |
|--------|------|
| Modify | `admin/products.php` — backend handler + HTML + JS |
| Modify | `tests/Shop/ProductDbTest.php` — new tests + order cleanup |

---

### Task 1: Write tests for bulk hard-delete query logic

**Files:**
- Modify: `tests/Shop/ProductDbTest.php`

- [ ] **Step 1: Add `$order_ids` static property and `insertOrder` helper to `ProductDbTest`**

Open `tests/Shop/ProductDbTest.php`. Add `private static array $order_ids = [];` after the existing `private static array $created_ids = [];` declaration.

Then add this private helper method after `insertProduct()`:

```php
private function insertOrder(int $product_id): int
{
    $items = json_encode([[
        'product_id' => $product_id,
        'name'       => 'Test Product',
        'quantity'   => 1,
        'price_eur'  => 5.00,
    ]]);
    self::$pdo->prepare(
        "INSERT INTO orders
             (order_number, type, status, customer_name, customer_email,
              items, subtotal_eur, shipping_eur, total_eur)
         VALUES (?, 'physical', 'new', 'Test', 'test@test.com', ?, 5.00, 0.00, 5.00)"
    )->execute(['OM-TEST-' . uniqid(), $items]);
    $id = (int)self::$pdo->lastInsertId();
    self::$order_ids[] = $id;
    return $id;
}
```

- [ ] **Step 2: Update `tearDownAfterClass` to clean up orders and variants**

Replace the existing `tearDownAfterClass` method with:

```php
public static function tearDownAfterClass(): void
{
    if (!self::$pdo) return;
    if (!empty(self::$order_ids)) {
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")
                  ->execute(self::$order_ids);
    }
    if (!empty(self::$created_ids)) {
        $in = implode(',', array_fill(0, count(self::$created_ids), '?'));
        self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")
                  ->execute(self::$created_ids);
        self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                  ->execute(self::$created_ids);
    }
}
```

- [ ] **Step 3: Add three test methods after `testBulkDeleteSoftDeletesAll()`**

```php
public function testBulkHardDeleteRemovesProduct(): void
{
    $id = $this->insertProduct(['active' => 0]);

    self::$pdo->prepare('DELETE FROM product_variants WHERE product_id IN (?)')->execute([$id]);
    self::$pdo->prepare('DELETE FROM products WHERE id IN (?)')->execute([$id]);

    $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $this->assertSame(0, (int)$stmt->fetchColumn(), "Product $id should be hard-deleted");

    // Remove from cleanup list since we already deleted it
    self::$created_ids = array_values(array_filter(self::$created_ids, fn($i) => $i !== $id));
}

public function testBulkHardDeleteAlsoRemovesVariants(): void
{
    $id = $this->insertProduct(['active' => 0]);
    self::$pdo->prepare(
        "INSERT INTO product_variants (product_id, label_bg, label_en, attributes, stock, active, sort_order)
         VALUES (?, 'Тест', 'Test', '[]', 5, 1, 0)"
    )->execute([$id]);

    self::$pdo->prepare('DELETE FROM product_variants WHERE product_id IN (?)')->execute([$id]);
    self::$pdo->prepare('DELETE FROM products WHERE id IN (?)')->execute([$id]);

    $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $this->assertSame(0, (int)$stmt->fetchColumn(), "Product $id should be hard-deleted");

    $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
    $stmt->execute([$id]);
    $this->assertSame(0, (int)$stmt->fetchColumn(), "Variants for product $id should also be deleted");

    self::$created_ids = array_values(array_filter(self::$created_ids, fn($i) => $i !== $id));
}

public function testBulkHardDeleteDetectsOrderReference(): void
{
    $id = $this->insertProduct(['active' => 0]);
    $this->insertOrder($id);

    $stmt = self::$pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE JSON_CONTAINS(JSON_EXTRACT(items, '\$[*].product_id'), JSON_ARRAY(?))"
    );
    $stmt->execute([$id]);

    $this->assertGreaterThan(
        0,
        (int)$stmt->fetchColumn(),
        "Product $id should be detected as having orders via JSON_CONTAINS"
    );
}
```

- [ ] **Step 4: Run the new tests**

```bash
php vendor/bin/phpunit --filter 'testBulkHardDeleteRemovesProduct|testBulkHardDeleteAlsoRemovesVariants|testBulkHardDeleteDetectsOrderReference' tests/Shop/ProductDbTest.php --testdox
```

Expected: 3 tests pass. If `testBulkHardDeleteDetectsOrderReference` fails, the `JSON_CONTAINS`/`JSON_EXTRACT` approach is not working on this MariaDB version — in that case, replace the query with a PHP-side check (see note below).

> **Fallback if JSON_CONTAINS fails:** Replace the detection query with a PHP-side approach: fetch `SELECT items FROM orders` and decode each row in PHP to check for the product_id. The test would then verify that the PHP loop correctly identifies the product_id.

- [ ] **Step 5: Run the full shop test suite**

```bash
php vendor/bin/phpunit --group shop --testdox
```

Expected: all passing (existing tests unaffected by the teardown change).

- [ ] **Step 6: Commit**

```bash
git add tests/Shop/ProductDbTest.php
git commit -m "test: add bulk hard-delete query tests and order cleanup"
```

---

### Task 2: Implement the `bulk_hard_delete` POST handler

**Files:**
- Modify: `admin/products.php` — add handler after the `bulk_delete` block (currently lines 51–63)

- [ ] **Step 1: Add the handler block**

Open `admin/products.php`. After the closing `}` of the `bulk_delete` handler (after line 63, before line 64 `}`), add:

```php
    if ($action === 'bulk_hard_delete') {
        $ids = array_values(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            fn($v) => $v > 0
        ));
        if (empty($ids)) {
            flash_set('success', 'Не са избрани продукти.');
            header('Location: /admin/products.php');
            exit;
        }

        // Detect which IDs appear in any order's items JSON
        $hasOrders  = [];
        $checkStmt  = $pdo->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE JSON_CONTAINS(JSON_EXTRACT(items, '\$[*].product_id'), JSON_ARRAY(?))"
        );
        foreach ($ids as $pid) {
            $checkStmt->execute([$pid]);
            if ((int)$checkStmt->fetchColumn() > 0) {
                $hasOrders[] = $pid;
            }
        }

        $safe    = array_values(array_diff($ids, $hasOrders));
        $skipped = array_values(array_intersect($ids, $hasOrders));

        if (!empty($safe)) {
            $in = implode(',', array_fill(0, count($safe), '?'));
            $pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute($safe);
            $pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute($safe);
        }

        $nSafe    = count($safe);
        $nSkipped = count($skipped);
        if ($nSkipped === 0) {
            $msg = $nSafe . ($nSafe === 1 ? ' продукт изтрит' : ' продукта изтрити') . ' завинаги.';
        } elseif ($nSafe === 0) {
            $msg = 'Нито един продукт не може да бъде изтрит — всички имат поръчки.';
        } else {
            $msg = $nSafe . ' изтрити, ' . $nSkipped . ' пропуснати — имат поръчки.';
        }

        flash_set('success', $msg);
        header('Location: /admin/products.php');
        exit;
    }
```

- [ ] **Step 2: Run shop tests**

```bash
php vendor/bin/phpunit --group shop --testdox
```

Expected: all passing.

- [ ] **Step 3: Commit**

```bash
git add admin/products.php
git commit -m "feat: add bulk_hard_delete POST handler — permanent delete with order guard"
```

---

### Task 3: Add toolbar button and hidden form

**Files:**
- Modify: `admin/products.php` — the `bulk-bar` div (around line 181–193) and the hidden forms block (around lines 195–203)

- [ ] **Step 1: Add the "Изтрий завинаги" button to the toolbar**

Find this line in the `bulk-bar` div:
```html
  <button id="bulk-clear" type="button" class="btn-link" style="color:#aaa;margin-left:auto;">✕ Изчисти</button>
```

Insert the new button immediately BEFORE it (so it appears between "Изтрий" and "✕ Изчисти"):

```html
  <button id="bulk-hard-delete" type="button" class="btn btn--danger"
          style="background:#7b1010;">Изтрий завинаги</button>
```

The full toolbar div should now read (in order): bulk-count span, bulk-toggle button, bulk-delete button, **bulk-hard-delete button**, bulk-clear button.

- [ ] **Step 2: Add the hidden form**

Find:
```html
<form id="form-bulk-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>
```

Add this block immediately after its closing `</form>`:

```html
<form id="form-bulk-hard-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_hard_delete">
</form>
```

- [ ] **Step 3: Run shop tests**

```bash
php vendor/bin/phpunit --group shop --testdox
```

Expected: all passing.

- [ ] **Step 4: Commit**

```bash
git add admin/products.php
git commit -m "feat: add bulk hard-delete button and hidden form to products toolbar"
```

---

### Task 4: Add JavaScript event listener

**Files:**
- Modify: `admin/products.php` — the `<script>` block (currently around lines 205–286)

- [ ] **Step 1: Add two new constants**

Find the existing const declarations at the top of the IIFE:
```js
    const formToggle = document.getElementById('form-bulk-toggle');
    const formDelete = document.getElementById('form-bulk-delete');
```

Add two new lines immediately after:
```js
    const hardDeleteBtn  = document.getElementById('bulk-hard-delete');
    const formHardDelete = document.getElementById('form-bulk-hard-delete');
```

- [ ] **Step 2: Add the event listener**

Find the `clearBtn.addEventListener` block:
```js
    clearBtn.addEventListener('click', function () {
```

Insert the following new listener immediately BEFORE it:

```js
    hardDeleteBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        _adminConfirm(
            'Постоянно изтриване на ' + selected.length + ' продукта. Не може да се отмени. Продължавате?',
            'Изтрий завинаги'
        ).then(function (confirmed) {
            if (!confirmed) return;
            injectIds(formHardDelete, selected);
            formHardDelete.submit();
        });
    });

```

- [ ] **Step 3: Manual verification**

Open `admin/products.php` in the browser. Check:
1. The toolbar now shows: `Активирай | Изтрий | Изтрий завинаги (dark red) | ✕ Изчисти`
2. Clicking "Изтрий завинаги" with rows selected → `_adminConfirm` modal appears with correct text
3. Cancelling → nothing happens
4. Confirming with products that have no orders → they disappear from the list, flash says "N продукта изтрити завинаги."
5. Confirming with a mix → flash shows "N изтрити, M пропуснати — имат поръчки."

- [ ] **Step 4: Run full test suite**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all passing.

- [ ] **Step 5: Commit**

```bash
git add admin/products.php
git commit -m "feat: wire bulk hard-delete JS — confirm modal, inject IDs, submit"
```
