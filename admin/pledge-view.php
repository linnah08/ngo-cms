<?php
$page_title_admin = 'Поддръжник на кампанията';
$active_nav       = 'campaign';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_shipping.php';
admin_require_shop();

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/campaign-backers.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM campaign_pledges WHERE id = ?');
$stmt->execute([$id]);
$pledge = $stmt->fetch();
if (!$pledge) { header('Location: /admin/campaign-backers.php'); exit; }

// Load linked orders row
$ord_stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? AND type = 'pledge'");
$ord_stmt->execute([$pledge['pledge_number']]);
$order = $ord_stmt->fetch() ?: null;

// Load cert document — $doc_stmt always defined so regenerate_cert handler can re-fetch
$doc_stmt = $pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'donation_cert'");
$cert_doc = null;
if ($order) {
    $doc_stmt->execute([$order['id']]);
    $cert_doc = $doc_stmt->fetch() ?: null;
}

// Load reward
$reward = null;
if ($pledge['reward_id']) {
    $r = $pdo->prepare('SELECT * FROM campaign_rewards WHERE id = ?');
    $r->execute([$pledge['reward_id']]);
    $reward = $r->fetch() ?: null;
}

$errors  = [];
$success = '';
if (!empty($_GET['refund_ok']))     $success = 'Плащането е върнато успешно.';
if (!empty($_GET['cert_ok']))       $success = 'Сертификатът е регенериран.';
if (!empty($_GET['doc_success']))   $success = trim($_GET['doc_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    // ── Reward shipping label (Speedy / BoxNow) ────────────────────────────────
    $psc = pledge_shipping_handle_post($pdo, $pledge);
    $errors  = array_merge($errors, $psc['errors']);
    if ($psc['success']) $success = $psc['success'];

    // ── Refund ────────────────────────────────────────────────────────────────
    if ($action === 'refund') {
        if ($pledge['payment_status'] !== 'paid') {
            $errors[] = 'Тази вноска вече не е в статус "Платено".';
        } elseif (empty($pledge['dsk_order_id'])) {
            $errors[] = 'Няма DSK поръчка за връщане.';
        } else {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
            try {
                (new DSKBankPayment())->refund($pledge['dsk_order_id'], (float)$pledge['amount_eur']);
                pledge_update_refunded_statuses($pdo, (int)$pledge['id'], $pledge['pledge_number']);

                $tpl = email_tpl_get('pledge-reversed-customer', 'bg', [
                    'name'          => $pledge['name'],
                    'pledge_number' => $pledge['pledge_number'],
                    'amount_eur'    => number_format((float)$pledge['amount_eur'], 2, '.', ' '),
                ]);
                send_mail(
                    $pledge['email'],
                    $tpl['subject'],
                    render_email('pledge-reversed-customer', ['pledge' => $pledge, 'tpl' => $tpl])
                );

                header('Location: /admin/pledge-view.php?id=' . $id . '&refund_ok=1');
                exit;
            } catch (Throwable $e) {
                error_log('pledge refund id=' . $id . ': ' . $e->getMessage());
                $errors[] = 'Грешка при връщане на плащането. Моля, обработете го ръчно в DSK Bank.';
            }
        }
    }

    // ── Regenerate cert ───────────────────────────────────────────────────────
    if ($action === 'regenerate_cert') {
        try {
            $order_id = pledge_ensure_order_row($pdo, $pledge);

            $cert_number   = (int)($pledge['cert_number'] ?? 0);
            $cert_path_rel = $pledge['cert_path'] ?? '';
            $cert_path_abs = $cert_path_rel !== '' ? $_SERVER['DOCUMENT_ROOT'] . $cert_path_rel : '';

            if ($cert_number > 0 && $cert_path_abs !== '' && file_exists($cert_path_abs)) {
                // Existing file — just register it in documents
                $formatted = str_pad((string)$cert_number, 5, '0', STR_PAD_LEFT);
                pledge_insert_cert_document($pdo, $order_id, $cert_number, $formatted, ltrim($cert_path_rel, '/'));
            } else {
                // Generate a fresh cert with a new sequence number
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

                $pdo->beginTransaction();
                $lock = $pdo->prepare("SELECT last_number FROM document_sequences WHERE type = 'donation_cert' FOR UPDATE");
                $lock->execute();
                $last = $lock->fetchColumn();
                if ($last === false) {
                    throw new \RuntimeException("No sequence row found for type 'donation_cert'");
                }
                $num = (int)$last + 1;
                $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'donation_cert'")
                    ->execute([$num]);
                $pdo->commit();

                $formatted = str_pad((string)$num, 5, '0', STR_PAD_LEFT);
                $year      = date('Y', strtotime($pledge['created_at']));
                $dir       = $_SERVER['DOCUMENT_ROOT'] . "/documents/donation_certs/{$year}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = $formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $pledge['pledge_number']) . '.pdf';
                $filepath = $dir . $filename;
                $rel      = "documents/donation_certs/{$year}/{$filename}";

                $fake_order = [
                    'id'           => $order_id,
                    'order_number' => $pledge['pledge_number'],
                    'customer_name'  => $pledge['name'],
                    'customer_email' => $pledge['email'],
                    'total_eur'    => (float)$pledge['amount_eur'],
                    'payment_method' => 'card',
                    'created_at'   => $pledge['created_at'],
                    'invoice_data' => json_encode(['donor_type' => 'individual']),
                ];
                $fake_items = [['type' => 'donation', 'amount_eur' => (float)$pledge['amount_eur'], 'recipient' => 'foundation']];
                $fake_doc   = ['formatted_number' => $formatted];

                $pdf     = (new DonationCertGenerator())->generate($fake_order, $fake_items, $fake_doc);
                $written = file_put_contents($filepath, $pdf);
                if ($written === false) {
                    throw new RuntimeException('Failed to write cert PDF to ' . $filepath);
                }

                $pdo->prepare("UPDATE campaign_pledges SET cert_number=?, cert_path=? WHERE id=?")
                    ->execute([$num, "/{$rel}", $pledge['id']]);

                pledge_insert_cert_document($pdo, $order_id, $num, $formatted, $rel);

                // Re-fetch
                $stmt->execute([$id]);
                $pledge = $stmt->fetch();
            }

            header('Location: /admin/pledge-view.php?id=' . $id . '&cert_ok=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('pledge regenerate_cert id=' . $id . ': ' . $e->getMessage());
            $errors[] = 'Грешка при регенериране на сертификата.';
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$status_colors = [
    'paid'     => '#2d6a35',
    'reversed' => '#6b6560',
    'pending'  => '#b5870a',
    'failed'   => '#c0392b',
];
$status_labels = [
    'paid'     => 'Платено',
    'reversed' => 'Върнато',
    'pending'  => 'Чакащо',
    'failed'   => 'Неуспешно',
];
$addr = $pledge['delivery_address'] ? json_decode($pledge['delivery_address'], true) : null;
$pledge_phone = $pledge['phone'] ?? null;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
  <h1 style="margin:0;font-size:1.4rem;">
    Поддръжник
    <span style="font-family:monospace;font-size:1rem;color:#6b6560;margin-left:.5rem;"><?= h($pledge['pledge_number']) ?></span>
  </h1>
  <a href="/admin/campaign-backers.php" class="btn btn--secondary">← Назад</a>
</div>

<?php if (!empty($errors)): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.25rem;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;">
  <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;margin-bottom:1.25rem;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;">
  <?= h($success) ?>
</div>
<?php endif; ?>

<!-- Header card -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem 2rem;">
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Дарител</div>
      <div style="font-weight:600;"><?= h($pledge['name']) ?></div>
      <div style="font-size:.85rem;color:#6b6560;"><?= h($pledge['email']) ?></div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Сума</div>
      <div style="font-weight:600;font-size:1.1rem;"><?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR</div>
      <div style="font-size:.82rem;color:#6b6560;"><?= number_format((float)$pledge['amount_eur'] * EUR_BGN_RATE, 2, '.', ' ') ?> лв</div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Дата</div>
      <div><?= substr($pledge['created_at'], 0, 10) ?></div>
    </div>
    <div>
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Статус плащане</div>
      <div style="display:inline-block;padding:.25rem .65rem;border-radius:20px;font-size:.8rem;font-weight:600;
                  background:<?= h($status_colors[$pledge['payment_status']] ?? '#888') ?>22;
                  color:<?= h($status_colors[$pledge['payment_status']] ?? '#888') ?>;">
        <?= h($status_labels[$pledge['payment_status']] ?? $pledge['payment_status']) ?>
      </div>
    </div>
    <?php if ($reward): ?>
    <div style="grid-column:1/-1;">
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Награда</div>
      <div><?= h($reward['title']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($pledge['delivery_courier']): ?>
    <div style="grid-column:1/-1;">
      <div style="font-size:.72rem;text-transform:uppercase;color:#9b9590;margin-bottom:.25rem;">Доставка</div>
      <div style="font-size:.88rem;display:flex;flex-direction:column;gap:.2rem;">
        <?php
          $courier_label = strtoupper($pledge['delivery_courier'] ?? '');
          $type_label    = match($pledge['delivery_type'] ?? '') {
              'office'  => 'до офис',
              'address' => 'до адрес',
              'locker'  => 'до автомат',
              default   => $pledge['delivery_type'] ?? '',
          };
          echo '<span>' . h($courier_label) . ($type_label ? ' — ' . h($type_label) : '') . '</span>';

          if ($pledge['delivery_type'] === 'address' && $addr) {
              $parts = array_filter([$addr['address'] ?? '', $addr['city'] ?? '', $addr['postcode'] ?? '']);
              if ($parts) echo '<span>' . h(implode(', ', $parts)) . '</span>';
          } elseif (!empty($pledge['office_name'])) {
              $parts = array_filter([$pledge['office_name'], $pledge['office_city'] ?? '']);
              echo '<span>' . h(implode(', ', $parts)) . '</span>';
          }

          if ($pledge_phone) {
              echo '<span style="color:#6b6560;">' . h($pledge_phone) . '</span>';
          }
        ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Documents section -->
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem;">
  <h2 style="margin:0 0 1rem;font-size:1rem;font-weight:600;">Документи</h2>

  <?php if ($cert_doc): ?>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <div>
        <div style="font-weight:600;font-size:.9rem;">Сертификат за дарение № <?= h($cert_doc['formatted_number']) ?></div>
        <div style="font-size:.8rem;color:#6b6560;"><?= h(substr($cert_doc['generated_at'], 0, 16)) ?></div>
        <?php if (!empty($cert_doc['signed_at'])): ?>
        <div style="font-size:.78rem;color:#2d6a35;margin-top:.25rem;">Подписан на <?= h(substr($cert_doc['signed_at'], 0, 10)) ?></div>
        <?php if (!empty($cert_doc['emailed_at'])): ?>
        <div style="font-size:.78rem;color:#1e40af;margin-top:.2rem;">✉ Изпратен <?= h(substr($cert_doc['emailed_at'], 0, 16)) ?></div>
        <?php else: ?>
        <div style="font-size:.78rem;color:#b5870a;margin-top:.2rem;">✉ Имейлът не беше изпратен</div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <a href="/admin/download-document.php?id=<?= (int)$cert_doc['id'] ?>" target="_blank"
         class="btn btn--secondary" style="font-size:.85rem;">Свали PDF</a>
      <?php if (empty($cert_doc['signed_at']) && function_exists('admin_can_sign') && admin_can_sign()): ?>
      <button type="button" class="btn btn--primary" style="font-size:.85rem;"
              onclick="openPledgeSignModal(<?= (int)$cert_doc['id'] ?>)">Подпиши</button>
      <?php endif; ?>
    </div>

  <?php elseif (!empty($pledge['cert_path'])): ?>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <div>
        <div style="font-weight:600;font-size:.9rem;">Сертификат № <?= h(str_pad((string)(int)$pledge['cert_number'], 5, '0', STR_PAD_LEFT)) ?></div>
        <div style="font-size:.78rem;color:#b5870a;">Генериран преди новата система — натисни Регенерирай за подписване.</div>
      </div>
      <a href="<?= h($pledge['cert_path']) ?>" target="_blank"
         class="btn btn--secondary" style="font-size:.85rem;">Свали PDF</a>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate_cert">
        <button type="submit" class="btn btn--outline" style="font-size:.85rem;">Регенерирай</button>
      </form>
    </div>

  <?php else: ?>
    <p style="color:#9b9590;margin:0;">Сертификатът ще бъде генериран автоматично при плащане.</p>
  <?php endif; ?>
</div>

<!-- Reward shipping label -->
<?php
$psc_pledge = $pledge;
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/pledge-shipping-card.php';
?>

<!-- Refund section -->
<?php if ($pledge['payment_status'] === 'paid' && !empty($pledge['dsk_order_id'])): ?>
<div style="background:#fff;border:1px solid #e8ddd5;border-radius:8px;padding:1.5rem;">
  <h2 style="margin:0 0 .5rem;font-size:1rem;font-weight:600;">Връщане на плащането</h2>
  <p style="margin:0 0 1rem;font-size:.88rem;color:#6b6560;">
    Ще бъде инициирано автоматично връщане на <?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR
    по картата на дарителя и ще бъде изпратен имейл за потвърждение.
  </p>
  <form method="POST" id="refundForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="refund">
    <button type="button" id="refundBtn" class="btn btn--danger">Върни плащането</button>
  </form>
  <script>
  document.getElementById('refundBtn').addEventListener('click', function() {
    _adminConfirm('Сигурен ли си? Това ще върне <?= number_format((float)$pledge['amount_eur'], 2, '.', ' ') ?> EUR на дарителя. Действието е необратимо.').then(function(ok) {
      if (ok) document.getElementById('refundForm').submit();
    });
  });
  </script>
</div>
<?php endif; ?>

<?php if (function_exists('admin_can_sign') && admin_can_sign()): ?>
<div id="pledgeSignModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;"
     role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:10px;padding:2rem;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.25);">
    <h3 style="margin-top:0;font-size:1.05rem;">Подпис на сертификата</h3>
    <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
      Ще бъде приложен вашият запазен подпис, PDF-ът ще бъде презаписан и сертификатът ще бъде изпратен автоматично на <?= h($pledge['email']) ?>.
    </p>
    <form method="POST" action="/admin/sign-document.php" id="pledgeSignForm">
      <?= csrf_field() ?>
      <input type="hidden" name="doc_id" id="pledgeSignDocId" value="">
      <div style="display:flex;gap:.75rem;justify-content:flex-end;">
        <button type="button" id="pledgeSignModalCancel"
                style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;">
          Отказ
        </button>
        <button type="submit"
                style="padding:.55rem 1.25rem;border:none;border-radius:6px;background:var(--teal);color:#fff;cursor:pointer;font-size:.9rem;font-weight:600;">
          ✍ Приложи подписа
        </button>
      </div>
    </form>
  </div>
</div>
<script>
function openPledgeSignModal(docId) {
    document.getElementById('pledgeSignDocId').value = docId;
    var overlay = document.getElementById('pledgeSignModalOverlay');
    overlay.style.display = 'flex';
    document.getElementById('pledgeSignModalCancel').focus();
}
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('pledgeSignModalOverlay');
    document.getElementById('pledgeSignModalCancel').addEventListener('click', function () {
        overlay.style.display = 'none';
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.style.display = 'none';
    });
    document.addEventListener('keydown', function (e) {
        if (overlay.style.display !== 'none' && e.key === 'Escape') overlay.style.display = 'none';
    });
});
</script>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
