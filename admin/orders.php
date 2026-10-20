<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';   // recipients and site filters
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/unpaid_orders.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_view.php';
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
            // Give back items the orders still hold before their rows are gone.
            $sel = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $restocked = [];
            foreach ($ids as $oid) {
                $sel->execute([$oid]);
                if (($o = $sel->fetch()) && order_stock_on_delete($pdo, $o)) $restocked[] = $o['order_number'];
            }
            $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)")->execute($ids);
            if ($restocked) {
                flash_set('success', 'Продуктите от изтритите поръчки ' . implode(', ', $restocked) . ' са върнати в наличност.');
            }
        } elseif (in_array($action, ['new','confirmed','shipped','delivered','cancelled'], true)) {
            // One by one so each order's stock moves with its status (cancel → back in stock,
            // reopen → taken out again). Orders that can't be reopened for lack of stock are skipped.
            $sel = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $upd = $pdo->prepare('UPDATE orders SET status=?, updated_at=NOW() WHERE id=?');
            $problems = [];
            foreach ($ids as $oid) {
                $sel->execute([$oid]);
                if (!$o = $sel->fetch()) continue;
                $change = order_stock_on_status_change($pdo, $o, $action);
                if (!$change['ok']) { $problems[] = $change['message']; continue; }
                $upd->execute([$action, $oid]);
            }
            if ($problems) flash_set('error', implode(' ', $problems));
        }
    }

    // Redirect back preserving filters and search
    $back = orders_list_filter([
        'type'   => $_POST['filter_type']   ?? '',
        'status' => $_POST['filter_status'] ?? '',
        'rec'    => $_POST['filter_rec']    ?? '',
        'x'      => $_POST['filter_x']      ?? '',
        'q'      => $_POST['filter_q']      ?? '',
    ]);
    header('Location: ' . orders_list_url($back));
    exit;
}

// Type / status filters and the search box (order number, name, email, phone).
$filter        = orders_list_filter($_GET);
$filter_type   = $filter['type'];
$filter_status = $filter['status'];
$search        = $filter['q'];

$sql    = 'SELECT *, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_minutes FROM orders WHERE ' . $filter['where'] . ' ORDER BY created_at DESC LIMIT 200';
$stmt   = $pdo->prepare($sql);
$stmt->execute($filter['params']);
$orders = $stmt->fetchAll();

$type_labels = ['physical' => 'Продукт', 'donation' => 'Дарение', 'ticket' => 'Билет'];

$boxnow_ready = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE courier='boxnow' AND type='physical' AND payment_status='paid' AND status IN ('new','confirmed')")->fetchColumn();

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Поръчки</h1>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
    <a href="/admin/boxnow-bulk-labels.php" class="btn btn--primary">Печат на BoxNow етикети<?= $boxnow_ready ? ' (' . $boxnow_ready . ')' : '' ?></a>
    <a href="/admin/manual-invoice.php" class="btn btn--outline">+ Фактура</a>
    <?php if (module_admin_page_visible('manual-cert.php')): ?>
    <a href="/admin/manual-cert.php" class="btn btn--outline">+ Сертификат за дарение</a>
    <?php endif; ?>
  </div>
</div>

<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" style="margin-bottom:1.5rem;"><?= h($_flash['message']) ?></div>
<?php endforeach; ?>

<!-- Search -->
<form method="GET" action="/admin/orders.php" role="search" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem;max-width:640px;margin-bottom:1rem;">
  <?php if ($filter_type !== 'all'): ?><input type="hidden" name="type" value="<?= h($filter_type) ?>"><?php endif; ?>
  <?php if ($filter_status !== 'all'): ?><input type="hidden" name="status" value="<?= h($filter_status) ?>"><?php endif; ?>
  <?php if ($filter['rec'] !== ''): ?><input type="hidden" name="rec" value="<?= h($filter['rec']) ?>"><?php endif; ?>
  <?php if ($filter['x'] !== ''): ?><input type="hidden" name="x" value="<?= h($filter['x']) ?>"><?php endif; ?>
  <div style="flex:1 1 16rem;min-width:0;">
    <label for="ordersSearch" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;">Търсене</label>
    <input type="search" id="ordersSearch" name="q" value="<?= h($search) ?>" maxlength="100"
           aria-describedby="ordersSearchHelp"
           style="width:100%;box-sizing:border-box;min-height:44px;padding:.5rem .75rem;border:1px solid #6b7280;border-radius:8px;font-size:1rem;font-family:inherit;">
  </div>
  <button type="submit" class="btn btn--primary" style="min-height:44px;padding:.5rem 1.1rem;">Търси</button>
  <?php if ($search !== ''): ?>
    <a href="<?= h(orders_list_url($filter, ['q' => ''])) ?>" class="btn btn--outline" style="min-height:44px;padding:.5rem 1.1rem;box-sizing:border-box;display:inline-flex;align-items:center;">Изчисти търсенето</a>
  <?php endif; ?>
  <p id="ordersSearchHelp" style="flex-basis:100%;font-size:.8rem;color:var(--text-muted);margin:0;">По номер на поръчката, име, имейл или телефон на клиента. Търси в избраните отдолу тип и статус.</p>
</form>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php
  $type_filters = ['all'=>'Всички','physical'=>'Продукти','donation'=>'Дарения','ticket'=>'Билети'];
  // Only the kinds of order this site can take (Админ → Модули).
  if (!module_certificates_enabled())           unset($type_filters['donation']);
  if (!module_any_enabled('events', 'campaign')) unset($type_filters['ticket']);
  foreach ($type_filters as $val => $label): ?>
    <a href="<?= h(orders_list_url($filter, ['type' => $val])) ?>"<?= $filter_type === $val ? ' aria-current="true"' : '' ?>
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter_type === $val ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
  <span style="color:var(--border);padding:.35rem 0;">|</span>
  <?php
  $status_filters = ['all'=>'Всички статуси','new'=>'Нови','confirmed'=>'Потвърдени','shipped'=>'Изпратени','delivered'=>'Доставени','cancelled'=>'Отменени','unpaid'=>'Неплатени'];
  foreach ($status_filters as $val => $label): ?>
    <a href="<?= h(orders_list_url($filter, ['status' => $val])) ?>"<?= $filter_status === $val ? ' aria-current="true"' : '' ?>
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter_status === $val ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php // Who the donations were for — only when the site has several recipients (includes/site_extensions.php). ?>
<?php if ($filter_type === 'donation' && donation_recipients_choosable()): ?>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:-.75rem 0 1.5rem;" aria-label="Дарения за">
  <?php foreach (['' => 'Всички дарения'] + array_map(fn($r) => $r['label_bg'], donation_recipients()) as $rk => $rl): ?>
    <a href="<?= h(orders_list_url($filter, ['rec' => $rk])) ?>"<?= $filter['rec'] === $rk ? ' aria-current="true"' : '' ?>
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter['rec'] === $rk ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= h($rl) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (site_order_filters()): ?>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:-.75rem 0 1.5rem;" aria-label="Още филтри">
  <?php foreach (['' => 'Без допълнителен филтър'] + array_map(fn($f) => $f['label'], site_order_filters()) as $xk => $xl): ?>
    <a href="<?= h(orders_list_url($filter, ['x' => $xk])) ?>"<?= $filter['x'] === $xk ? ' aria-current="true"' : '' ?>
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $filter['x'] === $xk ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= h($xl) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($orders)): ?>
  <p role="status" style="color:var(--text-muted);"><?= $search !== ''
      ? 'Няма поръчки, които отговарят на „' . h($search) . '“' . ($filter_type !== 'all' || $filter_status !== 'all' ? ' при избраните тип и статус' : '') . '.'
      : 'Няма поръчки.' ?></p>
<?php else: ?>

<?php if ($search !== ''): ?>
  <p role="status" style="font-size:.875rem;margin:0 0 1rem;">
    Намерени за „<?= h($search) ?>“: <strong><?= count($orders) ?><?= count($orders) >= 200 ? '+' : '' ?></strong><?= count($orders) >= 200 ? ' (показани са първите 200 — уточнете търсенето)' : '' ?>
  </p>
<?php endif; ?>

<form method="POST" id="bulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="filter_type"   value="<?= h($filter_type) ?>">
  <input type="hidden" name="filter_rec"    value="<?= h($filter['rec']) ?>">
  <input type="hidden" name="filter_x"      value="<?= h($filter['x']) ?>">
  <input type="hidden" name="filter_status" value="<?= h($filter_status) ?>">
  <input type="hidden" name="filter_q"      value="<?= h($search) ?>">

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
            <?php if (donation_recipients_choosable()):
                  $_recips = [];
                  foreach ((array) json_decode((string) $o['items'], true) as $_it) {
                      if (is_array($_it) && ($_it['type'] ?? '') === 'donation') $_recips[donation_line_recipient($_it)] = true;
                  }
                  foreach (array_keys($_recips) as $_rk): ?>
              <small style="display:block;color:var(--text-muted);"><?= h(donation_recipient_label($_rk)) ?></small>
            <?php endforeach; endif; ?>
          </td>
          <td style="cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= $o['courier'] ? h(ucfirst($o['courier'])) : '—' ?>
          </td>
          <td style="cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?php [$skey, $slabel] = order_status_display($o, (int)$o['age_minutes']); ?>
            <span class="badge <?= in_array($skey, ['cancelled', 'awaiting_payment'], true) ? 'badge--draft' : 'badge--published' ?>"><?= h($slabel) ?></span>
            <?php if (order_is_unpaid($o, (int)$o['age_minutes'])): ?>
              <br><span class="badge" style="margin-top:2px;background:#fdecea;color:#b42318;border:1px solid #f5c2c0;font-weight:600;"
                        title="Клиентът не е платил<?= $o['unpaid_cancelled_at'] ? ' — отменена автоматично след ' . unpaid_cancel_days() . ' дни, продуктите са върнати в наличност' : '' ?>">
                Неплатена
              </span>
            <?php elseif ($o['type'] === 'donation' || isset(UNPAID_AFTER_MINUTES[$o['payment_method'] ?? ''])): ?>
              <br><span class="badge <?= $o['payment_status'] === 'paid' ? 'badge--published' : 'badge--draft' ?>" style="margin-top:2px;">
                <?= $o['payment_status'] === 'paid' ? 'Платено' : 'Чака плащане' ?>
              </span>
            <?php endif; ?>
          </td>
          <td style="font-weight:600;cursor:pointer;" onclick="location='/admin/order-view.php?id=<?= (int)$o['id'] ?>'">
            <?= format_eur((float)$o['total_eur']) ?>
            <?php if ($o['type'] !== 'ticket' && $o['payment_method']): ?>
              <small style="display:block;font-weight:400;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(payment_method_label($o['payment_method'])) ?></small>
            <?php endif; ?>
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
