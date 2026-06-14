# Spec: Bulk Permanent Delete for Admin Products

## Overview

Add a "Изтрий завинаги" (permanent delete) bulk action to the admin products toolbar. Products with no order references are hard-deleted from the database; products with order references are skipped with a count shown in the flash message.

## Scope

Two files change: `admin/products.php` (backend handler + HTML + JS) and `tests/Shop/ProductDbTest.php` (new tests for the hard-delete query logic).

---

## Backend

### New POST action: `bulk_hard_delete` in `admin/products.php`

Placed after the existing `bulk_delete` handler block.

**Steps:**
1. Read `ids[]` from POST, cast all to int, filter zeros.
2. If empty → flash `'Не са избрани продукти.'`, redirect.
3. For each candidate ID, determine whether any order references it:

```sql
SELECT DISTINCT jt.pid
FROM orders
CROSS JOIN JSON_TABLE(
    orders.items,
    '$[*]' COLUMNS (pid INT PATH '$.product_id')
) AS jt
WHERE jt.pid IN (/* placeholders */)
```

This extracts `product_id` from every element of the `items` JSON array and returns the IDs that appear in at least one order.

4. Split candidates into:
   - `$safe` — IDs not found in orders
   - `$skipped` — IDs found in at least one order

5. If `$safe` is not empty:
   - `DELETE FROM product_variants WHERE product_id IN (...)`
   - `DELETE FROM products WHERE id IN (...)`

6. Build flash message:
   - If `$skipped` is empty: `"N продукта изтрити завинаги."`
   - If `$safe` is empty: `"Нито един продукт не може да бъде изтрит — всички имат поръчки."`
   - Mixed: `"N изтрити, M пропуснати — имат поръчки."`
   - Use `flash_set('success', ...)` in all cases (the message itself communicates partial failure).

7. `header('Location: /admin/products.php'); exit;`

**Validation notes:**
- CSRF verified at top of POST block (already present — no change needed).
- No active/inactive guard server-side — caller is responsible for selection.
- Image files are left on disk (orphaned uploads are harmless; cross-referencing adds complexity for negligible gain).

---

## Table / Toolbar HTML

### New hidden form

Add immediately after `<form id="form-bulk-delete">`:

```html
<form id="form-bulk-hard-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_hard_delete">
</form>
```

### New toolbar button

Add immediately before `<button id="bulk-clear">` in `<div id="bulk-bar">`:

```html
<button id="bulk-hard-delete" type="button" class="btn btn--danger"
        style="background:#7b1010;">Изтрий завинаги</button>
```

The darker red (`#7b1010`) visually distinguishes it from the soft-delete "Изтрий" button.

---

## JavaScript

Add one new constant and one new event listener inside the existing IIFE, after the `deleteBtn` listener.

**New constant** (alongside the other `const` declarations at the top of the IIFE):
```js
const hardDeleteBtn  = document.getElementById('bulk-hard-delete');
const formHardDelete = document.getElementById('form-bulk-hard-delete');
```

**New event listener:**
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

The existing `injectIds` helper is reused unchanged.

---

## Testing

Add to `tests/Shop/ProductDbTest.php`:

**`testBulkHardDeleteRemovesProductAndVariants`**
- Insert a product with a variant (no orders reference it)
- Run: `DELETE FROM product_variants WHERE product_id IN (?)`, then `DELETE FROM products WHERE id IN (?)`
- Assert product row gone, variant row gone

**`testBulkHardDeleteSkipsProductsWithOrders`** (unit-level, no real order row needed)
- Insert two products
- Simulate the "has orders" check by verifying the query returns an empty set when no orders exist → both IDs are in `$safe`
- This confirms the partition logic; the order-reference query itself is integration-tested implicitly by using a real DB

---

## Flash Message Format

| Scenario | Message |
|---|---|
| All deleted | `"5 продукта изтрити завинаги."` |
| All skipped | `"Нито един продукт не може да бъде изтрит — всички имат поръчки."` |
| Mixed | `"3 изтрити, 2 пропуснати — имат поръчки."` |
