<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/boxnow_bulk.php';

admin_require_shop();

$pdo = get_pdo();
$page_title_admin = 'BoxNow етикети';
$active_nav       = 'orders';

$result = null;   // set after a successful POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $ids = array_slice($ids, 0, BOXNOW_BULK_LIMIT);

    // Only one batch at a time, so a double click can't create two labels for the same order.
    $locked = (bool)$pdo->query("SELECT GET_LOCK('boxnow_bulk_labels', 0)")->fetchColumn();
    if (!$locked) {
        flash_set('error', 'Друг процес вече създава етикети. Изчакайте минутка и опитайте пак.');
        header('Location: /admin/boxnow-bulk-labels.php');
        exit;
    }

    try {
        $orders = boxnow_eligible_orders($pdo, $ids);   // re-checked server-side, never trust the form
        $boxnow = new BoxNowCourier();
        $ready  = [];   // [order, parcel_id, pdf]
        $failed = [];   // [order_number, customer_name, reason]

        foreach ($orders as $o) {
            try {
                $parcel = trim((string)($o['boxnow_parcel_id'] ?? ''));
                if ($parcel === '') $parcel = boxnow_create_parcel($pdo, $boxnow, $o);
                $ready[] = ['order' => $o, 'parcel' => $parcel, 'pdf' => boxnow_fetch_label_pdf($boxnow, $parcel)];
            } catch (Throwable $e) {
                error_log('BoxNow bulk ' . $o['order_number'] . ': ' . $e->getMessage());
                $failed[] = ['number' => $o['order_number'], 'name' => $o['customer_name'], 'reason' => $e->getMessage()];
            }
        }

        $pdf_file = null; $mail_ok = null; $shipped = 0;
        if ($ready) {
            $sheets = boxnow_a4_sheets(array_column($ready, 'pdf'));
            $dir = $_SERVER['DOCUMENT_ROOT'] . '/documents/boxnow_batches';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $pdf_file = 'boxnow-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.pdf';
            if (file_put_contents($dir . '/' . $pdf_file, $sheets) === false) {
                throw new RuntimeException('Файлът с етикетите не можа да бъде записан.');
            }

            // Packing list goes by email (saves paper).
            $rows = array_map(fn($r) => [
                'order_number'  => $r['order']['order_number'],
                'customer_name' => $r['order']['customer_name'],
                'parcel_id'     => $r['parcel'],
                'lines'         => boxnow_pack_lines($r['order']),
            ], $ready);
            $to = admin_user()['email'] ?? SIGNING_ADMIN_EMAIL;
            try {
                $mail_ok = send_mail($to, 'Списък за опаковане — ' . count($rows) . ' BoxNow пратки (' . date('d.m.Y') . ')',
                                     render_email('boxnow-packing-list', ['rows' => $rows]));
            } catch (Throwable $e) {
                error_log('BoxNow packing list mail: ' . $e->getMessage());
                $mail_ok = false;
            }

            // Only now that the sheet exists: mark shipped + notify customers.
            foreach ($ready as $r) {
                try { boxnow_mark_shipped($pdo, $r['order'], $r['parcel']); $shipped++; }
                catch (Throwable $e) {
                    error_log('BoxNow bulk mark shipped ' . $r['order']['order_number'] . ': ' . $e->getMessage());
                    $failed[] = ['number' => $r['order']['order_number'], 'name' => $r['order']['customer_name'],
                                 'reason' => 'Етикетът е готов, но статусът не се смени — маркирайте я като изпратена ръчно.'];
                }
            }
        }
        $result = ['file' => $pdf_file, 'labels' => count($ready), 'shipped' => $shipped,
                   'failed' => $failed, 'mail_ok' => $mail_ok, 'mail_to' => $to ?? '', 'skipped' => count($ids) - count($orders)];
    } catch (Throwable $e) {
        error_log('BoxNow bulk: ' . $e->getMessage());
        flash_set('error', 'Нещо се обърка: ' . $e->getMessage());
        header('Location: /admin/boxnow-bulk-labels.php');
        exit;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('boxnow_bulk_labels')");
    }
}

$eligible = $result ? [] : boxnow_eligible_orders($pdo);

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>BoxNow етикети за печат</h1>
  <a href="/admin/orders.php" class="btn btn--outline">← Поръчки</a>
</div>

<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" style="margin-bottom:1.5rem;"><?= h($_flash['message']) ?></div>
<?php endforeach; ?>

<?php if ($result): ?>
  <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;">
    <?php if ($result['file']): ?>
      <p style="font-size:1.1rem;margin-top:0;"><strong>Готово: <?= (int)$result['labels'] ?> етикета.</strong></p>
      <p>
        <a href="/admin/boxnow-bulk-download.php?f=<?= h(urlencode($result['file'])) ?>" target="_blank" class="btn btn--primary">Отвори PDF за печат</a>
      </p>
      <ul style="line-height:1.7;">
        <li>Наредени са по <?= BOXNOW_LABELS_PER_PAGE ?> на лист А4, в реален размер — печатайте на <strong>100% / „Действителен размер“</strong>, не „Побери в страницата“.</li>
        <li><?= (int)$result['shipped'] ?> поръчки са маркирани като изпратени и клиентите са известени по имейл.</li>
        <li><?php if ($result['mail_ok']): ?>Списъкът за опаковане е изпратен на <strong><?= h($result['mail_to']) ?></strong>.
            <?php else: ?><strong style="color:#b42318;">Списъкът за опаковане не можа да бъде изпратен по имейл.</strong> Етикетите са наред, но ще трябва да отворите поръчките, за да видите съдържанието.<?php endif; ?></li>
      </ul>
    <?php else: ?>
      <p style="margin:0;"><strong>Не бяха създадени етикети.</strong></p>
    <?php endif; ?>
  </div>

  <?php if ($result['failed']): ?>
  <div style="background:#fdecea;border:1px solid #f5c2c0;border-radius:8px;padding:1.25rem;margin-bottom:1rem;">
    <strong style="color:#b42318;">Тези поръчки не бяха обработени (<?= count($result['failed']) ?>):</strong>
    <ul style="margin:.5rem 0 0;line-height:1.7;">
      <?php foreach ($result['failed'] as $f): ?>
        <li><strong><?= h($f['number']) ?></strong> — <?= h($f['name']) ?>: <?= h($f['reason']) ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:.75rem 0 0;font-size:.9rem;">Поправете проблема в поръчката и натиснете бутона отново — успешните вече няма да се повтарят.</p>
  </div>
  <?php endif; ?>

  <a href="/admin/boxnow-bulk-labels.php" class="btn btn--outline">Към останалите поръчки</a>

<?php elseif (!$eligible): ?>
  <p style="color:var(--text-muted);">Няма платени BoxNow поръчки, които чакат изпращане. 🎉</p>

<?php else: $over = count($eligible) > BOXNOW_BULK_LIMIT; ?>
  <form method="POST" id="bnForm" onsubmit="return bnSubmit(this);">
    <?= csrf_field() ?>
    <p style="max-width:44rem;">
      Това са платените BoxNow поръчки, които още не са изпратени. За всяка ще се създаде етикет в BoxNow,
      после ще получите <strong>един PDF</strong> с етикетите по <?= BOXNOW_LABELS_PER_PAGE ?> на лист А4.
      Списъкът за опаковане идва по имейл. Поръчките се маркират като <strong>изпратени</strong> и клиентите получават имейл с номера за проследяване.
    </p>
    <?php if ($over): ?>
      <p style="padding:.6rem 1rem;background:#fff8e1;border-radius:6px;max-width:44rem;">
        Наведнъж се обработват най-много <?= BOXNOW_BULK_LIMIT ?> поръчки (първите по ред). След това натиснете бутона пак за останалите.
      </p>
    <?php endif; ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th style="width:2.5rem;"><input type="checkbox" id="bnAll" checked onchange="document.querySelectorAll('.bn-cb').forEach(c=>{c.checked=this.checked});bnCount();" aria-label="Избери всички"></th>
          <th>Поръчка №</th><th>Дата</th><th>Клиент</th><th>Какво да се опакова</th><th>Етикет</th>
        </tr></thead>
        <tbody>
        <?php foreach ($eligible as $i => $o):
              $problem = trim((string)$o['customer_phone']) === '' ? 'Липсва телефон' : (empty($o['courier_office_code']) ? 'Няма избран автомат' : ''); ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$o['id'] ?>" class="bn-cb" <?= $i < BOXNOW_BULK_LIMIT ? 'checked' : '' ?> onchange="bnCount()"></td>
            <td><a href="/admin/order-view.php?id=<?= (int)$o['id'] ?>"><strong><?= h($o['order_number']) ?></strong></a></td>
            <td style="white-space:nowrap;"><?= h(substr($o['created_at'], 0, 16)) ?></td>
            <td><?= h($o['customer_name']) ?></td>
            <td style="font-size:.9rem;">
              <?php foreach (boxnow_pack_lines($o) as $l): ?>
                <div><?= h($l['name']) ?> × <?= (int)$l['qty'] ?><?= $l['detail'] !== '' ? ' <span style="color:var(--text-muted);">(' . h($l['detail']) . ')</span>' : '' ?></div>
              <?php endforeach; ?>
            </td>
            <td style="font-size:.85rem;">
              <?php if ($problem): ?><span style="color:#b42318;font-weight:600;">⚠ <?= h($problem) ?></span>
              <?php elseif (!empty($o['boxnow_parcel_id'])): ?><span style="color:#2d6a35;">Вече създаден</span>
              <?php else: ?><span style="color:var(--text-muted);">Ще се създаде</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p style="margin-top:1rem;">
      <button type="submit" id="bnBtn" class="btn btn--primary" style="padding:.7rem 1.4rem;font-size:1rem;">Създай етикетите и подготви PDF</button>
      <span id="bnHint" style="margin-left:.75rem;color:var(--text-muted);"></span>
    </p>
  </form>
  <script>
  function bnCount() {
    var n = document.querySelectorAll('.bn-cb:checked').length;
    document.getElementById('bnBtn').disabled = n === 0;
    document.getElementById('bnHint').textContent = n + ' избрани';
  }
  function bnSubmit(f) {
    var b = document.getElementById('bnBtn');
    b.disabled = true; b.textContent = 'Създавам етикетите… моля изчакайте';
    return true;
  }
  bnCount();
  </script>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
