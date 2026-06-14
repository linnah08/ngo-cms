<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
$page_title_admin = 'Поръчки';
$active_nav       = 'orders';

admin_require_shop();

$pdo = get_pdo();

// ── Bulk actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $ids    = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $action = $_POST['bulk_action'] ?? '';

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)")->execute($ids);
        } elseif (in_array($action, ['new','confirmed','shipped','delivered','cancelled'])) {
            $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id IN ($placeholders)")
                ->execute(array_merge([$action], $ids));
        }
    }

    // Redirect back preserving filters
    $qs = http_build_query(array_filter([
        'type'   => $_POST['filter_type']   ?? '',
        'status' => $_POST['filter_status'] ?? '',
    ]));
    header('Location: /admin/orders.php' . ($qs ? "?$qs" : ''));
    exit;
}

$filter_type   = $_GET['type']   ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

$where  = ['1=1'];
$params = [];
if ($filter_type !== 'all') {
    $where[]  = 'type = ?';
    $params[] = $filter_type;
}
if ($filter_status !== 'all') {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}

$sql    = 'SELECT * FROM orders WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 200';
$stmt   = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$status_labels = [
    'new'       => ['Нова',      'badge--published'],
    'confirmed' => ['Потвърдена','badge--published'],
    'shipped'   => ['Изпратена', 'badge--published'],
    'delivered' => ['Доставена', 'badge--published'],
    'cancelled' => ['Отменена',  'badge--draft'],
];
$type_labels = ['physical' => 'Продукт', 'donation' => 'Дарение', 'ticket' => 'Билет'];

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Поръчки</h1>
  <a href="/admin/manual-cert.php" class="btn btn--outline">+ Сертификат за дарение</a>
</div>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php
  $type_filters = ['all'=>'Всички','physical'=>'Продукти','donation'=>'Дарения','ticket'=>'Билети'];
  foreach ($type_filters as $val => $label): ?>
    <a href="?type=<?= $val ?>&status=<?= h($filter_status) ?>"
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter_type === $val ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
  <span style="color:var(--border);padding:.35rem 0;">|</span>
  <?php
  $status_filters = ['all'=>'Всички статуси','new'=>'Нови','confirmed'=>'Потвърдени','shipped'=>'Изпратени','delivered'=>'Доставени','cancelled'=>'Отменени'];
  foreach ($status_filters as $val => $label): ?>
    <a href="?type=<?= h($filter_type) ?>&status=<?= $val ?>"
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter_status === $val ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($orders)): ?>
  <p style="color:var(--text-muted);">Няма поръчки.</p>
<?php else: ?>

<form method="POST" id="bulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="filter_type"   value="<?= h($filter_type) ?>">
  <input type="hidden" name="filter_status" value="<?= h($filter_status) ?>">

  <!-- Bulk action bar -->
  <div id="bulkBar" style="display:none;align-items:center;gap:.75rem;background:var(--warm-grey);border:1px solid var(--border);border-radius:8px;padding:.6rem 1rem;margin-bottom:1rem;">
    <span id="bulkCount" style="font-size:.875rem;font-weight:600;white-space:nowrap;"></span>
    <select name="bulk_action" id="bulkAction" style="padding:.4rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
      <option value="">— Действие —</option>
      <option value="new">Статус: Нова</option>
      <option value="confirmed">Статус: Потвърдена</option>
      <option value="shipped">Статус: Изпратена</option>
      <option value="delivered">Статус: Доставена</option>
      <option value="cancelled">Статус: Отменена</option>
      <option value="delete">Изтрий избраните</option>
    </select>
    <button type="button" onclick="applyBulk()" class="btn btn--primary" style="padding:.4rem 1rem;font-size:.875rem;">Приложи</button>
    <button type="button" onclick="clearAll()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.875rem;padding:.25rem;">✕ Откажи</button>
  </div>

  <div class="admin-table-wrap" style="overflow-x:hidden;">
    <table class="admin-table" style="table-layout:fixed;width:100%;">
      <colgroup>
        <col style="width:2.5rem;">
        <col style="width:15%;">
        <col style="width:14%;">
        <col style="width:25%;">
        <col style="width:9%;">
        <col style="width:10%;">
        <col style="width:15%;">
        <col style="width:9%;">
      </colgroup>
      <thead>
        <tr>
          <th style="width:2.5rem;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
          <th data-sort style="overflow:hidden;">Поръчка №</th>
          <th data-sort style="overflow:hidden;">Дата</th>
          <th data-sort style="overflow:hidden;">Клиент</th>
          <th data-sort style="overflow:hidden;">Тип</th>
          <th data-sort style="overflow:hidden;">Куриер</th>
          <th data-sort style="overflow:hidden;">Статус</th>
          <th data-sort style="overflow:hidden;">Общо</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td onclick="event.stopPropagation();">
            <input type="checkbox" name="ids[]" value="<?= (int)$o['id'] ?>" class="row-check" onchange="updateBar()">
          </td>
          <td style="cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'"><strong><?= h($o['order_number']) ?></strong></td>
          <td style="white-space:nowrap;cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= h(substr($o['created_at'], 0, 16)) ?>
          </td>
          <td style="cursor:pointer;overflow:hidden;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($o['customer_name']) ?></div>
            <small style="color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;"><?= h($o['customer_email']) ?></small>
          </td>
          <td style="cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= h($type_labels[$o['type']] ?? $o['type']) ?>
          </td>
          <td style="cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= $o['courier'] ? h(ucfirst($o['courier'])) : '—' ?>
          </td>
          <td style="cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?php [$slabel, $sclass] = $status_labels[$o['status']] ?? [$o['status'], 'badge--draft']; ?>
            <span class="badge <?= $sclass ?>"><?= h($slabel) ?></span>
            <?php if ($o['type'] === 'donation'): ?>
              <br><span class="badge <?= $o['payment_status'] === 'paid' ? 'badge--published' : 'badge--draft' ?>" style="margin-top:2px;">
                <?= $o['payment_status'] === 'paid' ? 'Платено' : 'Чакащо' ?>
              </span>
            <?php endif; ?>
          </td>
          <td style="font-weight:600;cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= format_eur((float)$o['total_eur']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
    updateBar();
}

function updateBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('bulkBar');
    document.getElementById('checkAll').checked =
        checked.length === document.querySelectorAll('.row-check').length;
    if (checked.length > 0) {
        bar.style.display = 'flex';
        document.getElementById('bulkCount').textContent = checked.length + ' избрани';
    } else {
        bar.style.display = 'none';
    }
}

function applyBulk() {
    const action = document.getElementById('bulkAction').value;
    if (!action) { alert('Изберете действие.'); return; }
    const checked = document.querySelectorAll('.row-check:checked');
    if (!checked.length) { alert('Изберете поне една поръчка.'); return; }
    if (action === 'delete') {
        _adminConfirm('Изтриване на ' + checked.length + ' поръчки? Това е необратимо.').then(function(ok) {
            if (ok) document.getElementById('bulkForm').submit();
        });
        return;
    }
    document.getElementById('bulkForm').submit();
}

function clearAll() {
    document.querySelectorAll('.row-check, #checkAll').forEach(c => c.checked = false);
    document.getElementById('bulkBar').style.display = 'none';
}
</script>

<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
