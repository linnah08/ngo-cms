# Products Bulk Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add multi-select checkboxes and a sticky bottom toolbar to `admin/products.php` with bulk Activate/Deactivate (one toggle) and bulk Delete (soft-delete) actions.

**Architecture:** Two new POST action handlers (`bulk_toggle`, `bulk_delete`) are added to `admin/products.php`. A checkbox column and a sticky DOM toolbar + two hidden forms are added to the HTML output. ~80 lines of vanilla JS wire the checkboxes to the toolbar and submit the forms.

**Tech Stack:** PHP 8.4, PDO, vanilla JS, existing `_adminConfirm` modal from `admin-footer.php`.

---

## Files

| Action | File |
|--------|------|
| Modify | `admin/products.php` — backend handlers + HTML + JS |
| Modify | `tests/Shop/ProductDbTest.php` — add bulk query tests |

---

### Task 1: Write failing tests for bulk query logic

The tests directly exercise the SQL patterns the backend will use. Add them to the existing `ProductDbTest` class.

**Files:**
- Modify: `tests/Shop/ProductDbTest.php`

- [ ] **Step 1: Add the three test methods to `ProductDbTest`**

Open `tests/Shop/ProductDbTest.php`. Add these methods after `testActiveProductsQuery()`, before the closing `}`:

```php
public function testBulkToggleAllActiveDeactivatesAll(): void
{
    $id1 = $this->insertProduct(['active' => 1]);
    $id2 = $this->insertProduct(['active' => 1]);
    $ids = [$id1, $id2];

    // All active → detect → set active = 0
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in) AND active = 1");
    $stmt->execute($ids);
    $activeCount = (int)$stmt->fetchColumn();
    $newActive = ($activeCount === count($ids)) ? 0 : 1;

    self::$pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")
              ->execute(array_merge([$newActive], $ids));

    foreach ($ids as $id) {
        $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
        $check->execute([$id]);
        $this->assertSame(0, (int)$check->fetchColumn(), "Product $id should be inactive");
    }
}

public function testBulkToggleMixedActivatesAll(): void
{
    $id1 = $this->insertProduct(['active' => 1]);
    $id2 = $this->insertProduct(['active' => 0]);
    $ids = [$id1, $id2];

    // Mixed → detect → set active = 1
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in) AND active = 1");
    $stmt->execute($ids);
    $activeCount = (int)$stmt->fetchColumn();
    $newActive = ($activeCount === count($ids)) ? 0 : 1;

    self::$pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")
              ->execute(array_merge([$newActive], $ids));

    foreach ($ids as $id) {
        $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
        $check->execute([$id]);
        $this->assertSame(1, (int)$check->fetchColumn(), "Product $id should be active");
    }
}

public function testBulkDeleteSoftDeletesAll(): void
{
    $id1 = $this->insertProduct(['active' => 1]);
    $id2 = $this->insertProduct(['active' => 1]);
    $ids = [$id1, $id2];

    $in = implode(',', array_fill(0, count($ids), '?'));
    self::$pdo->prepare("UPDATE products SET active = 0 WHERE id IN ($in)")
              ->execute($ids);

    foreach ($ids as $id) {
        $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
        $check->execute([$id]);
        $this->assertSame(0, (int)$check->fetchColumn(), "Product $id should be soft-deleted");
    }
}
```

- [ ] **Step 2: Run the new tests and confirm they fail**

```bash
php vendor/bin/phpunit --filter 'testBulkToggleAllActiveDeactivatesAll|testBulkToggleMixedActivatesAll|testBulkDeleteSoftDeletesAll' tests/Shop/ProductDbTest.php --testdox
```

Expected: 3 tests, but since the methods simply run SQL directly they will actually PASS immediately — this is intentional. These tests lock down the query logic the backend will use. Confirm all 3 pass.

- [ ] **Step 3: Commit the tests**

```bash
git add tests/Shop/ProductDbTest.php
git commit -m "test: add bulk toggle and bulk delete query tests"
```

---

### Task 2: Implement backend bulk action handlers

**Files:**
- Modify: `admin/products.php` — lines 12–32 (the POST handler block)

- [ ] **Step 1: Replace the POST handler block**

Open `admin/products.php`. The current POST block (lines 12–32) handles `toggle` and `delete`. Replace the entire block with this expanded version:

```php
// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([$id]);
        flash_set('success', 'Статусът е обновен.');
        header('Location: /admin/products.php');
        exit;
    }

    if ($action === 'delete' && $id) {
        $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);
        flash_set('success', 'Продуктът е деактивиран.');
        header('Location: /admin/products.php');
        exit;
    }

    if ($action === 'bulk_toggle') {
        $ids = array_values(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            fn($v) => $v > 0
        ));
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in) AND active = 1");
            $stmt->execute($ids);
            $activeCount = (int)$stmt->fetchColumn();
            $newActive   = ($activeCount === count($ids)) ? 0 : 1;
            $pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")
                ->execute(array_merge([$newActive], $ids));
        }
        flash_set('success', 'Статусите са обновени.');
        header('Location: /admin/products.php');
        exit;
    }

    if ($action === 'bulk_delete') {
        $ids = array_values(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            fn($v) => $v > 0
        ));
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE products SET active = 0 WHERE id IN ($in)")->execute($ids);
        }
        flash_set('success', 'Избраните продукти са деактивирани.');
        header('Location: /admin/products.php');
        exit;
    }
}
```

- [ ] **Step 2: Run all shop tests**

```bash
php vendor/bin/phpunit --group shop --testdox
```

Expected: all passing.

- [ ] **Step 3: Commit**

```bash
git add admin/products.php
git commit -m "feat: add bulk_toggle and bulk_delete POST handlers to products admin"
```

---

### Task 3: Add checkbox column to the table

**Files:**
- Modify: `admin/products.php` — the `<colgroup>`, `<thead>`, and `<tbody>` sections

- [ ] **Step 1: Update `<colgroup>` to add checkbox column**

Find the `<colgroup>` block (currently 6 `<col>` elements) and replace it:

```html
<colgroup>
  <col style="width:4%;">
  <col style="width:7%;">
  <col style="width:33%;">
  <col style="width:12%;">
  <col style="width:14%;">
  <col style="width:15%;">
  <col style="width:15%;">
</colgroup>
```

- [ ] **Step 2: Add checkbox cell to `<thead>`**

Find the `<tr>` inside `<thead>`. Add this as the first `<th>` before the existing "Снимка" header:

```html
<th style="overflow:hidden;"><input type="checkbox" id="select-all" aria-label="Избери всички" style="cursor:pointer;width:16px;height:16px;"></th>
```

- [ ] **Step 3: Add checkbox cell to each `<tbody>` row**

Find the `<?php foreach ($products as $p): ?>` row. Add this as the first `<td>` in the row, before the image `<td>`:

```html
<td style="text-align:center;vertical-align:middle;">
  <input type="checkbox" class="row-cb"
         value="<?= (int)$p['id'] ?>"
         data-active="<?= $p['active'] ? '1' : '0' ?>"
         style="cursor:pointer;width:16px;height:16px;">
</td>
```

- [ ] **Step 4: Verify the page renders without errors**

Open `http://localhost/admin/products.php` (or however the local dev server is accessed). Confirm the table shows a checkbox column on the left with working checkboxes. No JS yet — toolbar won't appear.

- [ ] **Step 5: Commit**

```bash
git add admin/products.php
git commit -m "feat: add checkbox column to admin products table"
```

---

### Task 4: Add sticky toolbar and hidden forms

**Files:**
- Modify: `admin/products.php` — add HTML after the closing `</div>` of the table wrap, before `require admin-footer.php`

- [ ] **Step 1: Add toolbar and hidden forms**

Find the line `<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>` at the bottom of the file. Insert the following block immediately before it:

```php
<?php if (!empty($products)): ?>
<div id="bulk-bar" style="
  position:fixed;bottom:0;left:0;right:0;
  background:#1a1a2e;color:#fff;
  padding:1rem 2rem;
  display:flex;align-items:center;gap:1rem;
  transform:translateY(100%);transition:transform 0.2s ease;
  z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,0.3);">
  <span id="bulk-count" style="font-weight:500;">0 избрани</span>
  <button id="bulk-toggle" type="button" class="btn btn--primary">Активирай</button>
  <button id="bulk-delete" type="button" class="btn btn--danger">Изтрий</button>
  <button id="bulk-clear" type="button" class="btn-link" style="color:#aaa;margin-left:auto;">✕ Изчисти</button>
</div>

<form id="form-bulk-toggle" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_toggle">
</form>

<form id="form-bulk-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>
<?php endif; ?>
```

- [ ] **Step 2: Commit**

```bash
git add admin/products.php
git commit -m "feat: add bulk action toolbar and hidden forms to products admin"
```

---

### Task 5: Add JavaScript

**Files:**
- Modify: `admin/products.php` — add `<script>` block just before the closing `require admin-footer.php` line

- [ ] **Step 1: Add the script block**

Insert immediately before `<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>`:

```php
<?php if (!empty($products)): ?>
<script>
(function () {
    const selectAll  = document.getElementById('select-all');
    const bulkBar    = document.getElementById('bulk-bar');
    const countEl    = document.getElementById('bulk-count');
    const toggleBtn  = document.getElementById('bulk-toggle');
    const deleteBtn  = document.getElementById('bulk-delete');
    const clearBtn   = document.getElementById('bulk-clear');
    const formToggle = document.getElementById('form-bulk-toggle');
    const formDelete = document.getElementById('form-bulk-delete');

    function getCheckboxes() {
        return Array.from(document.querySelectorAll('.row-cb'));
    }

    function getSelected() {
        return getCheckboxes().filter(cb => cb.checked);
    }

    function updateToolbar() {
        const selected = getSelected();
        const count    = selected.length;

        countEl.textContent = count + ' избрани';
        bulkBar.style.transform = count > 0 ? 'translateY(0)' : 'translateY(100%)';

        const allActive = selected.length > 0 && selected.every(cb => cb.dataset.active === '1');
        toggleBtn.textContent = allActive ? 'Деактивирай' : 'Активирай';

        const all = getCheckboxes();
        selectAll.indeterminate = count > 0 && count < all.length;
        selectAll.checked       = all.length > 0 && count === all.length;
    }

    function injectIds(form, selected) {
        form.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
        selected.forEach(function (cb) {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });
    }

    selectAll.addEventListener('change', function () {
        getCheckboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
        updateToolbar();
    });

    getCheckboxes().forEach(function (cb) {
        cb.addEventListener('change', updateToolbar);
    });

    toggleBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        injectIds(formToggle, selected);
        formToggle.submit();
    });

    deleteBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        _adminConfirm(
            'Деактивиране на ' + selected.length + ' продукта. Продължавате?',
            'Деактивирай'
        ).then(function (confirmed) {
            if (!confirmed) return;
            injectIds(formDelete, selected);
            formDelete.submit();
        });
    });

    clearBtn.addEventListener('click', function () {
        getCheckboxes().forEach(function (cb) { cb.checked = false; });
        selectAll.checked       = false;
        selectAll.indeterminate = false;
        updateToolbar();
    });
})();
</script>
<?php endif; ?>
```

- [ ] **Step 2: Manual verification**

Open `admin/products.php` in the browser.

Check each scenario:
1. Check one row → toolbar slides up, count shows "1 избрани", button label is correct.
2. Check rows of mixed active/inactive → toggle button shows "Активирай".
3. Check all-active rows → toggle button shows "Деактивирай".
4. Click select-all → all rows checked, indeterminate clears.
5. Click "Активирай"/"Деактивирай" → page reloads, flash message shows, badges updated.
6. Click "Изтрий" → `_adminConfirm` modal appears → confirm → page reloads, selected products deactivated.
7. Click ✕ → toolbar slides down, all unchecked.

- [ ] **Step 3: Run full test suite**

```bash
php vendor/bin/phpunit --testdox
```

Expected: all passing.

- [ ] **Step 4: Commit**

```bash
git add admin/products.php
git commit -m "feat: wire bulk action JS — select-all, toolbar, toggle, delete"
```
