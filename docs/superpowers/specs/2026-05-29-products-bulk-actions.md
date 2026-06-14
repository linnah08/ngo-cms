# Spec: Bulk Actions on Admin Products List

## Overview

Add multi-select checkboxes to the admin products table and a sticky bottom toolbar with bulk Activate/Deactivate and Delete actions.

## Scope

One file changes: `admin/products.php`. No new files, no new endpoints, no JS files.

---

## Backend

### New POST actions on `products.php`

Both follow the existing pattern: `csrf_verify()` first, then redirect with `flash_set`.

**`bulk_toggle`**
- Input: `ids[]` — array of product IDs (cast to int, filter zeros)
- Logic: if ALL selected products are currently active → set all to `active = 0`; otherwise → set all to `active = 1`
- Query: `UPDATE products SET active = ? WHERE id IN (...)` with a parameterised `IN` clause
- Flash: `'Статусите са обновени.'`
- Redirect: `Location: /admin/products.php`

**`bulk_delete`**
- Input: `ids[]` — same format
- Logic: soft delete — set `active = 0` for all selected IDs
- Query: `UPDATE products SET active = 0 WHERE id IN (...)`
- Flash: `'Избраните продукти са деактивирани.'`
- Redirect: `Location: /admin/products.php`

Validation: if `ids` is empty or missing, redirect silently (no-op).

---

## Table Changes

### New checkbox column

Added as the **leftmost** column before the image column.

**`<colgroup>` widths (revised):**
| Col | Width |
|-----|-------|
| Checkbox | 4% |
| Снимка | 7% |
| Наименование | 33% |
| Цена | 12% |
| Наличност | 14% |
| Статус | 15% |
| Действия | 15% |

**`<thead>` checkbox cell:** `<input type="checkbox" id="select-all">` — no label, `aria-label="Избери всички"`.

**`<tbody>` checkbox cell per row:** `<input type="checkbox" class="row-cb" value="<?= $p['id'] ?>" data-active="<?= $p['active'] ? '1' : '0' ?>">`. The `data-active` attribute drives the toggle button label.

---

## Sticky Toolbar

Rendered as a `<div id="bulk-bar">` after the table, before `admin-footer.php`. Always present in the DOM, hidden by default.

**Inline styles** (per CLAUDE.md convention — no reliance on admin.css):
```
position: fixed; bottom: 0; left: 0; right: 0;
background: #1a1a2e; color: #fff;
padding: 1rem 2rem; display: flex; align-items: center; gap: 1rem;
transform: translateY(100%); transition: transform 0.2s ease;
z-index: 1000; box-shadow: 0 -2px 8px rgba(0,0,0,0.3);
```

**Contents (left to right):**
1. Count label: `<span id="bulk-count">0 избрани</span>`
2. Toggle button: `<button id="bulk-toggle" class="btn btn--primary">Активирай</button>` — label updates dynamically
3. Delete button: `<button id="bulk-delete" class="btn btn--danger">Изтрий</button>` — triggers `_adminConfirm` before submitting
4. Clear button: `<button id="bulk-clear" class="btn-link" style="color:#aaa;">✕ Изчисти</button>`

**Show/hide:** toolbar slides up (`translateY(0)`) when ≥1 checkbox is checked; slides back down when count returns to 0.

---

## Hidden Forms

Two `<form>` elements, `display:none`, after the toolbar. JS injects `<input type="hidden" name="ids[]" value="...">` for each selected ID before submit.

```html
<form id="form-bulk-toggle" method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_toggle">
</form>

<form id="form-bulk-delete" method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>
```

---

## JavaScript

~80 lines, vanilla JS, `<script>` block at end of page body.

**State:** `selectedIds = Set<number>`, derived from checked `.row-cb` inputs.

**select-all checkbox:**
- On change: check/uncheck all `.row-cb`, rebuild `selectedIds`, update toolbar.

**Individual `.row-cb`:**
- On change: add/remove from `selectedIds`, update select-all indeterminate state, update toolbar.

**Toolbar update function:**
- Sets count label text.
- Shows/hides toolbar via `translateY`.
- Recomputes toggle button label: count how many selected have `data-active="1"`. If all active → label "Деактивирай"; otherwise → label "Активирай".

**`bulk-toggle` button click:**
- Inject selected IDs into `#form-bulk-toggle`, submit.

**`bulk-delete` button click:**
- Call `_adminConfirm('Деактивиране на избраните продукти. Продължавате?', 'Деактивирай', () => { inject IDs into #form-bulk-delete; submit; })`.

**`bulk-clear` button click:**
- Uncheck all `.row-cb` and select-all, clear `selectedIds`, hide toolbar.

---

## Error Handling / Edge Cases

- Empty selection on POST: backend silently redirects (no DB call).
- Mixed active/inactive selection: toggle activates all (label reads "Активирай" when any are inactive).
- All active selected: toggle deactivates all.
- Single product selected: same flow, works identically to per-row action.

---

## Testing

No new PHPUnit test needed — bulk actions use the same `UPDATE products SET active` query pattern already exercised by existing tests. Manual verification: select multiple, toggle, verify flash + status badges; select multiple, delete, verify all deactivated.
