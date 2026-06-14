<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
$page_title_admin = 'Продукти';
$active_nav       = 'products';

admin_require_shop();

$pdo = get_pdo();

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

        $hasOrders = [];
        $stmt = $pdo->query('SELECT items FROM orders');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (json_decode($json, true) ?: [] as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                if ($pid > 0 && in_array($pid, $ids, true) && !in_array($pid, $hasOrders, true)) {
                    $hasOrders[] = $pid;
                }
            }
        }

        $safe    = array_values(array_diff($ids, $hasOrders));
        $skipped = array_values(array_intersect($ids, $hasOrders));

        if (!empty($safe)) {
            $in = implode(',', array_fill(0, count($safe), '?'));
            $pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute($safe);
            $pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute($safe);
        }

        $nSafe = count($safe); $nSkipped = count($skipped);
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
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$products = $pdo->query(
    "SELECT p.*,
            CASE WHEN p.type = 'variant'
                 THEN COALESCE((SELECT SUM(pv.stock) FROM product_variants pv WHERE pv.product_id = p.id AND pv.active = 1), 0)
                 ELSE p.stock
            END AS effective_stock
     FROM products p
     ORDER BY p.id DESC"
)->fetchAll();
?>

<div class="admin-page-header">
  <h1>Продукти</h1>
  <a href="/admin/product-edit.php" class="btn btn--primary">+ Нов продукт</a>
</div>

<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" style="margin-bottom:1.5rem;"><?= h($_flash['message']) ?></div>
<?php endforeach; ?>

<?php if (empty($products)): ?>
  <p style="color:var(--text-muted);">Няма продукти. <a href="/admin/product-edit.php">Добавете първия</a>.</p>
<?php else: ?>
<div class="admin-table-wrap" style="overflow-x:hidden;">
  <table class="admin-table" style="table-layout:fixed;width:100%;">
    <colgroup>
      <col style="width:4%;">
      <col style="width:7%;">
      <col style="width:33%;">
      <col style="width:12%;">
      <col style="width:14%;">
      <col style="width:15%;">
      <col style="width:15%;">
    </colgroup>
    <thead>
      <tr>
        <th style="overflow:hidden;"><input type="checkbox" id="select-all" aria-label="Избери всички" style="cursor:pointer;width:16px;height:16px;"></th>
        <th style="overflow:hidden;">Снимка</th>
        <th data-sort style="overflow:hidden;">Наименование</th>
        <th data-sort style="overflow:hidden;">Цена</th>
        <th data-sort style="overflow:hidden;">Наличност</th>
        <th data-sort style="overflow:hidden;">Статус</th>
        <th style="overflow:hidden;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $p): ?>
      <tr>
        <td style="text-align:center;vertical-align:middle;">
          <input type="checkbox" class="row-cb"
                 value="<?= (int)$p['id'] ?>"
                 data-active="<?= $p['active'] ? '1' : '0' ?>"
                 style="cursor:pointer;width:16px;height:16px;">
        </td>
        <td style="width:60px;">
          <a href="/admin/product-edit.php?id=<?= (int)$p['id'] ?>">
            <?php if ($p['image']): ?>
              <img src="/assets/images/products/<?= h($p['image']) ?>" alt=""
                   style="width:48px;height:48px;object-fit:cover;border-radius:4px;">
            <?php else: ?>
              <div style="width:48px;height:48px;background:var(--off-white);border-radius:4px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:1.2rem;">🖼</div>
            <?php endif; ?>
          </a>
        </td>
        <td>
          <a href="/admin/product-edit.php?id=<?= (int)$p['id'] ?>" style="color:inherit;text-decoration:none;">
            <strong><?= h($p['name_bg']) ?></strong>
          </a>
          <?php if ($p['name_en']): ?>
            <br><small style="color:var(--text-muted);"><?= h($p['name_en']) ?></small>
          <?php endif; ?>
          <br><small style="color:var(--text-light);font-size:0.75rem;">/magazin/#<?= h($p['slug']) ?></small>
        </td>
        <td><?= format_eur((float)$p['price_eur']) ?></td>
        <td>
          <?php if ($p['effective_stock'] > 0): ?>
            <span style="color:#2d6a35;font-weight:500;"><?= (int)$p['effective_stock'] ?></span>
          <?php else: ?>
            <span style="color:#c0392b;font-weight:500;">0</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?= $p['active'] ? 'badge--published' : 'badge--draft' ?>">
            <?= $p['active'] ? 'Активен' : 'Неактивен' ?>
          </span>
        </td>
        <td>
          <a href="/admin/product-edit.php?id=<?= (int)$p['id'] ?>" class="btn-link">Редакция</a>

          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn-link">
              <?= $p['active'] ? 'Деактивирай' : 'Активирай' ?>
            </button>
          </form>

          <form method="POST" style="display:inline;"
                data-confirm="Деактивиране на продукта. Продължавате?" data-confirm-ok="Деактивирай">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn-link btn-link--danger">Изтрий</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

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
  <button id="bulk-hard-delete" type="button" class="btn btn--danger"
          style="background:#7b1010;">Изтрий завинаги</button>
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

<form id="form-bulk-hard-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_hard_delete">
</form>

<script>
(function () {
    const selectAll  = document.getElementById('select-all');
    const bulkBar    = document.getElementById('bulk-bar');
    const countEl    = document.getElementById('bulk-count');
    const toggleBtn  = document.getElementById('bulk-toggle');
    const deleteBtn  = document.getElementById('bulk-delete');
    const clearBtn   = document.getElementById('bulk-clear');
    const formToggle     = document.getElementById('form-bulk-toggle');
    const formDelete     = document.getElementById('form-bulk-delete');
    const hardDeleteBtn  = document.getElementById('bulk-hard-delete');
    const formHardDelete = document.getElementById('form-bulk-hard-delete');

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

    hardDeleteBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        const count = selected.length;
        const label = count === 1 ? '1 продукт' : count + ' продукта';
        _adminConfirm(
            'Потвърдете изтриването на ' + label + '. Веднъж изтрити, продуктите не могат да бъдат възстановени.',
            'Изтрий завинаги'
        ).then(function (confirmed) {
            if (!confirmed) return;
            injectIds(formHardDelete, selected);
            formHardDelete.submit();
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

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
