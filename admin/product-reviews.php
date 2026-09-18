<?php
$page_title_admin = 'Отзиви за продукти';
$active_nav       = 'products';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_shop();

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && in_array($action, ['approve', 'reject', 'delete'], true)) {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM product_reviews WHERE id = ?')->execute([$id]);
        } else {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE product_reviews SET status = ? WHERE id = ?')->execute([$status, $id]);
        }
    }

    if (in_array($action, ['bulk_approve', 'bulk_reject', 'bulk_delete'], true)) {
        $ids = array_values(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            fn($v) => $v > 0
        ));
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_delete') {
                $pdo->prepare("DELETE FROM product_reviews WHERE id IN ($in)")->execute($ids);
            } else {
                $status = $action === 'bulk_approve' ? 'approved' : 'rejected';
                $pdo->prepare("UPDATE product_reviews SET status = ? WHERE id IN ($in)")
                    ->execute(array_merge([$status], $ids));
            }
        }
        header('Location: /admin/product-reviews.php?' . http_build_query(array_filter($_GET)));
        exit;
    }

    header('Location: /admin/product-reviews.php?' . http_build_query(array_filter($_GET)));
    exit;
}

$filter = in_array($_GET['filter'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_GET['filter'] : 'pending';

$product_id = (int)($_GET['product_id'] ?? 0);

$product = null;
if ($product_id > 0) {
    $ps = $pdo->prepare('SELECT id, name_bg, slug FROM products WHERE id = ?');
    $ps->execute([$product_id]);
    $product = $ps->fetch() ?: null;
}

$sql = "SELECT r.id, r.product_id, r.lang, r.author_name, r.author_email, r.rating, r.content, r.status,
               r.verified_purchase, r.created_at, p.name_bg AS product_name
        FROM product_reviews r
        LEFT JOIN products p ON p.id = r.product_id
        WHERE r.status = ?";
$params = [$filter];
if ($product_id > 0) { $sql .= " AND r.product_id = ?"; $params[] = $product_id; }
$sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

if ($product_id > 0) {
    $cs = $pdo->prepare("SELECT status, COUNT(*) n FROM product_reviews WHERE product_id = ? GROUP BY status");
    $cs->execute([$product_id]);
    $counts = $cs->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $counts = $pdo->query("SELECT status, COUNT(*) n FROM product_reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Отзиви<?php if ($product): ?> — <?= h($product['name_bg']) ?><?php endif; ?></h1>
  <a href="/admin/products.php" class="btn btn--outline">← Към продуктите</a>
</div>

<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;">
  <?php foreach (['pending' => 'Чакащи', 'approved' => 'Одобрени', 'rejected' => 'Отхвърлени'] as $k => $lbl): ?>
    <a href="?<?= h(http_build_query(array_filter(['product_id' => $product_id, 'filter' => $k]))) ?>"
       class="btn <?= $filter === $k ? 'btn--primary' : 'btn--outline' ?>" style="font-size:.85rem;">
      <?= $lbl ?> (<?= (int)($counts[$k] ?? 0) ?>)
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$reviews): ?>
  <p style="color:var(--text-muted);">Няма отзиви в тази категория.</p>
<?php else: ?>
  <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;cursor:pointer;">
    <input type="checkbox" id="select-all" aria-label="Избери всички" style="cursor:pointer;width:16px;height:16px;"> Избери всички
  </label>
  <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach ($reviews as $r): ?>
      <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem;">
          <div>
            <input type="checkbox" class="row-cb"
                   value="<?= (int)$r['id'] ?>"
                   data-status="<?= h($r['status']) ?>"
                   style="cursor:pointer;width:16px;height:16px;margin-right:.5rem;vertical-align:middle;">
            <strong><?= h($r['author_name']) ?></strong>
            <span style="font-size:.8rem;color:var(--text-muted);margin-left:.5rem;"><?= h($r['author_email']) ?></span>
            <?php if (!empty($r['verified_purchase'])): ?>
              <span style="font-size:.72rem;font-weight:600;color:#2d6a35;background:#e6f4ea;border-radius:4px;padding:.1rem .4rem;margin-left:.5rem;">✓ Потвърдена покупка</span>
            <?php endif; ?>
            <?php $rating = max(0, min(5, (int)$r['rating'])); ?>
            <span style="color:#f5a623;margin-left:.5rem;"><?= str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) ?></span>
            <span style="font-size:.8rem;color:var(--text-muted);margin-left:.5rem;"><?= h(substr($r['created_at'], 0, 16)) ?> · <?= h($r['lang']) ?></span>
          </div>
          <div style="font-size:.85rem;color:var(--text-muted);">
            <?= h($r['product_name'] ?? ('#' . $r['product_id'])) ?>
          </div>
        </div>
        <p style="margin:.25rem 0 1rem;white-space:pre-wrap;line-height:1.6;"><?= h($r['content']) ?></p>
        <form method="POST" style="display:inline-flex;gap:.5rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['status'] !== 'approved'): ?>
            <button name="action" value="approve" class="btn btn--primary" style="font-size:.8rem;">Одобри</button>
          <?php endif; ?>
          <?php if ($r['status'] !== 'rejected'): ?>
            <button name="action" value="reject" class="btn btn--outline" style="font-size:.8rem;">Отхвърли</button>
          <?php endif; ?>
          <button name="action" value="delete" class="btn btn--outline" style="font-size:.8rem;color:#c0392b;"
                  data-confirm="Изтриване на отзива?" data-confirm-ok="Изтрий">Изтрий</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($reviews)): ?>
<div id="bulk-bar" style="
  position:fixed;bottom:0;left:0;right:0;
  background:#1a1a2e;color:#fff;
  padding:1rem 2rem;
  display:flex;align-items:center;gap:1rem;
  transform:translateY(100%);transition:transform 0.2s ease;
  z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,0.3);">
  <span id="bulk-count" style="font-weight:500;">0 избрани</span>
  <button id="bulk-approve" type="button" class="btn btn--primary">Одобри избраните</button>
  <button id="bulk-reject" type="button" class="btn btn--outline" style="background:transparent;color:#fff;border-color:#fff;">Отхвърли избраните</button>
  <button id="bulk-delete" type="button" class="btn btn--danger">Изтрий избраните</button>
  <button id="bulk-clear" type="button" class="btn-link" style="color:#aaa;margin-left:auto;">✕ Изчисти</button>
</div>

<form id="form-bulk-approve" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_approve">
</form>

<form id="form-bulk-reject" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_reject">
</form>

<form id="form-bulk-delete" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>

<script>
(function () {
    const selectAll  = document.getElementById('select-all');
    const bulkBar    = document.getElementById('bulk-bar');
    const countEl    = document.getElementById('bulk-count');
    const approveBtn = document.getElementById('bulk-approve');
    const rejectBtn  = document.getElementById('bulk-reject');
    const deleteBtn  = document.getElementById('bulk-delete');
    const clearBtn   = document.getElementById('bulk-clear');
    const formApprove = document.getElementById('form-bulk-approve');
    const formReject  = document.getElementById('form-bulk-reject');
    const formDelete  = document.getElementById('form-bulk-delete');

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

    approveBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        injectIds(formApprove, selected);
        formApprove.submit();
    });

    rejectBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        injectIds(formReject, selected);
        formReject.submit();
    });

    deleteBtn.addEventListener('click', function () {
        const selected = getSelected();
        if (!selected.length) return;
        const count = selected.length;
        const label = count === 1 ? '1 отзив' : count + ' отзива';
        _adminConfirm(
            'Изтриване на ' + label + '. Веднъж изтрити, отзивите не могат да бъдат възстановени.',
            'Изтрий'
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

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
