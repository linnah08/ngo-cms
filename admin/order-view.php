<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/TicketGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_shipping.php';

admin_require_shop();

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/orders.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { header('Location: /admin/orders.php'); exit; }

$items          = json_decode($order['items'], true) ?? [];

// Pre-load product variants for print items so we can compute cm measurements
$_print_variants = [];
$_print_pids = array_unique(array_filter(array_column($items, 'product_id'), fn($p) => $p > 0));
if ($_print_pids) {
    $_in   = implode(',', array_fill(0, count($_print_pids), '?'));
    $_vstmt = $pdo->prepare("SELECT id, variants FROM products WHERE id IN ($_in)");
    $_vstmt->execute(array_values($_print_pids));
    foreach ($_vstmt->fetchAll() as $_vrow) {
        $_print_variants[(int)$_vrow['id']] = json_decode($_vrow['variants'], true) ?? [];
    }
}

$is_ticket      = $order['type'] === 'ticket';
$ticket_item    = $is_ticket ? ($items[0] ?? []) : [];

// For ticket orders, load the pledge to get all generated PDF paths
$ticket_pledge  = null;
$ticket_paths   = []; // array of relative paths
if ($is_ticket) {
    $ps = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
    $ps->execute([$order['order_number']]);
    $ticket_pledge = $ps->fetch() ?: null;
    if ($ticket_pledge) {
        $decoded = json_decode($ticket_pledge['ticket_path'] ?? '', true);
        if (is_array($decoded)) {
            $ticket_paths = $decoded;
        } elseif (!empty($ticket_pledge['ticket_path'])) {
            $ticket_paths = [$ticket_pledge['ticket_path']];
        }
    }
}

// For pledge (donation campaign) orders, load the pledge and its chosen reward
$pledge_row    = null;
$pledge_reward = null;
if ($order['type'] === 'pledge') {
    $ps = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
    $ps->execute([$order['order_number']]);
    $pledge_row = $ps->fetch() ?: null;
    if ($pledge_row && $pledge_row['reward_id']) {
        $rs = $pdo->prepare('SELECT * FROM campaign_rewards WHERE id = ?');
        $rs->execute([$pledge_row['reward_id']]);
        $pledge_reward = $rs->fetch() ?: null;
    }
}
$courier_labels = ['speedy'=>'Speedy','boxnow'=>'BoxNow'];
$type_labels    = ['office'=>'До офис','address'=>'До адрес','locker'=>'До автомат'];

// Load existing generated documents for this order
$doc_stmt = $pdo->prepare('SELECT * FROM documents WHERE order_id = ? ORDER BY generated_at ASC');
$doc_stmt->execute([$id]);
$existing_docs = $doc_stmt->fetchAll();
$docs_by_type  = array_column($existing_docs, null, 'type');

// Determine which document types are applicable
$has_physical   = $order['type'] === 'physical';
$has_donation   = $order['type'] === 'donation'
    || count(array_filter($items, fn($i) => ($i['type'] ?? '') === 'donation')) > 0;
$invoice_data   = json_decode($order['invoice_data'] ?? 'null', true);
$is_b2b         = !empty($invoice_data['needs_invoice']);

$success = '';
$errors  = [];

// Messages forwarded back via GET from generate-document.php
if (!empty($_GET['doc_success'])) {
    $success = $_GET['doc_success'];
}
if (!empty($_GET['doc_error'])) {
    $errors[] = $_GET['doc_error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    // Reward shipping label actions (pledge orders) — shared with pledge-view
    if ($pledge_row) {
        $psc = pledge_shipping_handle_post($pdo, $pledge_row);
        $errors = array_merge($errors, $psc['errors']);
        if ($psc['success']) $success = $psc['success'];
    }

    if ($action === 'update_status') {
        $new_status = $_POST['status'] ?? '';
        $tracking   = trim($_POST['tracking_number'] ?? '');
        $notes      = trim($_POST['notes']           ?? '');
        $valid_statuses = ['new','confirmed','shipped','delivered','cancelled'];

        if (!in_array($new_status, $valid_statuses)) {
            $errors[] = 'Невалиден статус.';
        } elseif ($new_status === 'shipped' && !$tracking) {
            $errors[] = 'Въведете номер за проследяване преди да маркирате като изпратена.';
        } else {
            $pdo->prepare('UPDATE orders SET status=?, tracking_number=?, notes=?, updated_at=NOW() WHERE id=?')
                ->execute([$new_status, $tracking ?: null, $notes ?: null, $id]);

            // Sync reward_shipped on the pledge when a pledge order is marked shipped / un-shipped
            if ($order['type'] === 'pledge' && $pledge_row) {
                $shipped_flag = ($new_status === 'shipped') ? 1 : 0;
                $pdo->prepare('UPDATE campaign_pledges SET reward_shipped=? WHERE id=?')
                    ->execute([$shipped_flag, (int)$pledge_row['id']]);
            }

            // Cancel BoxNow parcel when order is cancelled
            if ($new_status === 'cancelled' && $order['status'] !== 'cancelled'
                && $order['courier'] === 'boxnow' && !empty($order['boxnow_parcel_id'])) {
                try {
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';
                    (new BoxNowCourier())->cancelShipment($order['boxnow_parcel_id']);
                } catch (Throwable $e) {
                    error_log('BoxNow cancel on order cancel: ' . $e->getMessage());
                }
            }

            // Cancel Speedy shipment when order is cancelled
            if ($new_status === 'cancelled' && $order['status'] !== 'cancelled'
                && $order['courier'] === 'speedy' && !empty($order['speedy_shipment_id'])) {
                try {
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
                    (new SpeedyCourier())->cancelShipment($order['speedy_shipment_id']);
                    $pdo->prepare(
                        'UPDATE orders SET speedy_shipment_id=NULL, tracking_number=NULL, updated_at=NOW() WHERE id=?'
                    )->execute([$id]);
                } catch (Throwable $e) {
                    error_log('Speedy cancel on order cancel: ' . $e->getMessage());
                }
            }

            // Initiate DSK Bank refund when cancelling a paid order
            if ($new_status === 'cancelled' && $order['status'] !== 'cancelled'
                && $order['payment_status'] === 'paid' && !empty($order['dsk_order_id'])) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
                try {
                    (new DSKBankPayment())->refund($order['dsk_order_id'], (float)$order['total_eur']);
                    $pdo->prepare('UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE id = ?')
                        ->execute(['refunded', $id]);
                    $lang = $order['lang'] ?? 'bg';
                    $tpl  = email_tpl_get('order-cancelled-customer', $lang, [
                        'customer_name' => $order['customer_name'],
                        'order_number'  => $order['order_number'],
                    ]);
                    send_mail(
                        $order['customer_email'],
                        $tpl['subject'],
                        render_email('order-cancelled-customer', ['order' => $order, 'tpl' => $tpl])
                    );
                } catch (Throwable $e) {
                    error_log('Refund failed for order ' . $id . ': ' . $e->getMessage());
                    $success = 'Поръчката е отменена, но автоматичното връщане на сумата не успя. Моля, обработете го ръчно в DSK Bank.';
                }
            }

            // Send shipped email
            if ($new_status === 'shipped' && $order['status'] !== 'shipped') {
                $courier_label = $courier_labels[$order['courier']] ?? ucfirst($order['courier']);
                send_mail(
                    $order['customer_email'],
                    render_email_subject('order-shipped-customer', $order['lang'] ?? 'bg', ['customer_name' => $order['customer_name'], 'order_number' => $order['order_number'], 'courier' => $courier_label]),
                    render_email('order-shipped-customer', [
                        'order'          => $order,
                        'tracking_number'=> $tracking,
                        'courier_label'  => $courier_label,
                    ])
                );
            }

            // Re-fetch updated order
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            $success = $success ?: 'Поръчката е обновена.';
        }
    }

    if ($action === 'create_boxnow_label') {
        if ($order['courier'] !== 'boxnow' || empty($order['courier_office_code'])) {
            $errors[] = 'Невалидна поръчка за BoxNow.';
        } elseif (!empty($order['boxnow_parcel_id'])) {
            $errors[] = 'Товарителницата вече е създадена.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';
                $boxnow = new BoxNowCourier();

                // Build description from items
                $desc = implode(', ', array_map(fn($i) => ($i['name_bg'] ?? '') . ' x' . ($i['quantity'] ?? 1), $items));

                $result = $boxnow->createShipment([
                    'weight'          => 1.0,
                    'description'     => $desc ?: 'Поръчка #' . $order['order_number'],
                    'order_number'    => $order['order_number'],
                    'receiver_name'   => $order['customer_name'],
                    'receiver_phone'  => $order['customer_phone'],
                    'receiver_email'  => $order['customer_email'],
                    'locker_id'       => $order['courier_office_code'],
                    'compartment_size' => (int)($_POST['compartment_size'] ?? 1),
                ]);

                $parcel_id = $result['parcel_id'];
                if (!$parcel_id) throw new RuntimeException('BoxNow не върна parcel ID.');

                $pdo->prepare('UPDATE orders SET boxnow_parcel_id=?, tracking_number=?, updated_at=NOW() WHERE id=?')
                    ->execute([$parcel_id, $parcel_id, $id]);

                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е създадена: ' . $parcel_id;
            } catch (Throwable $e) {
                $errors[] = 'Грешка при създаване: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'cancel_boxnow_label') {
        $parcel_id = $order['boxnow_parcel_id'] ?? '';
        if (!$parcel_id) {
            $errors[] = 'Няма активна товарителница.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';
                $boxnow = new BoxNowCourier();
                $boxnow->cancelShipment($parcel_id);
                $pdo->prepare('UPDATE orders SET boxnow_parcel_id=NULL, updated_at=NOW() WHERE id=?')
                    ->execute([$id]);
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е анулирана.';
            } catch (Throwable $e) {
                $errors[] = 'Грешка при анулиране: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'create_speedy_label') {
        if ($order['courier'] !== 'speedy' || $order['type'] !== 'physical') {
            $errors[] = 'Невалидна поръчка за Speedy.';
        } elseif (!empty($order['speedy_shipment_id'])) {
            $errors[] = 'Товарителницата вече е създадена.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
                $speedy = new SpeedyCourier();

                $weight     = max(0.1, (float)($_POST['weight']     ?? 1.0));
                $pack_count = max(1,   (int)  ($_POST['pack_count'] ?? 1));
                $delivery_type = $order['delivery_type'] === 'office' ? 'office' : 'door';

                $desc = implode(', ', array_map(
                    fn($i) => ($i['name_bg'] ?? '') . ' x' . ($i['quantity'] ?? 1),
                    $items
                ));

                $result = $speedy->createShipment([
                    'weight'           => $weight,
                    'pack_count'       => $pack_count,
                    'description'      => $desc ?: 'Поръчка #' . $order['order_number'],
                    'receiver_name'    => $order['customer_name'],
                    'receiver_phone'   => $order['customer_phone'],
                    'receiver_city'    => $order['delivery_city'] ?? '',
                    'receiver_address' => $order['delivery_address'] ?? '',
                    'receiver_office'  => $order['delivery_type'] === 'office'
                                         ? (int)$order['courier_office_code']
                                         : null,
                    'delivery_type'    => $delivery_type,
                ]);

                $shipment_id = $result['shipment_number'];
                if (!$shipment_id) throw new RuntimeException('Speedy не върна номер на пратката.');

                $pdo->prepare(
                    'UPDATE orders SET speedy_shipment_id=?, tracking_number=?, updated_at=NOW() WHERE id=?'
                )->execute([$shipment_id, $shipment_id, $id]);

                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е създадена: ' . $shipment_id;
            } catch (Throwable $e) {
                $errors[] = 'Грешка при създаване: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'cancel_speedy_label') {
        $shipment_id = $order['speedy_shipment_id'] ?? '';
        if (!$shipment_id) {
            $errors[] = 'Няма активна товарителница.';
        } else {
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
                (new SpeedyCourier())->cancelShipment($shipment_id);
                $pdo->prepare(
                    'UPDATE orders SET speedy_shipment_id=NULL, tracking_number=NULL, updated_at=NOW() WHERE id=?'
                )->execute([$id]);
                $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                $success = 'Товарителницата е анулирана.';
            } catch (Throwable $e) {
                $errors[] = 'Грешка при анулиране: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'resend_ticket') {
        if ($is_ticket) {
            $qty = max(1, (int)($ticket_pledge['ticket_qty'] ?? count($ticket_paths) ?: 1));

            // Regenerate if: count doesn't match qty, any file is missing, or no paths at all
            $all_exist = count($ticket_paths) === $qty
                && count(array_filter($ticket_paths, fn($p) => file_exists($_SERVER['DOCUMENT_ROOT'] . $p))) === $qty;
            if (!$all_exist) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/TicketGenerator.php';
                $year = date('Y');
                $dir  = $_SERVER['DOCUMENT_ROOT'] . "/documents/tickets/{$year}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $new_paths = [];
                for ($i = 0; $i < $qty; $i++) {
                    $tc   = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $suffix = $qty > 1 ? '-' . ($i + 1) : '';
                    $fname = $tc . '_' . $order['order_number'] . $suffix . '.pdf';
                    $fpath = $dir . $fname;
                    $price_per = $qty > 1 ? round((float)$order['total_eur'] / $qty, 2) : (float)$order['total_eur'];
                    $order_row = [
                        'name'          => $order['customer_name'],
                        'email'         => $order['customer_email'],
                        'pledge_number' => $order['order_number'],
                        'amount_eur'    => $price_per,
                        'created_at'    => $order['created_at'],
                    ];
                    $doc_row = [
                        'ticket_code'  => $tc,
                        'event_name'   => setting_get('event_name',  'Събитие'),
                        'event_date'   => setting_get('event_date',  ''),
                        'event_time'   => setting_get('event_time',  ''),
                        'event_place'  => setting_get('event_place', ''),
                    ];
                    file_put_contents($fpath, (new TicketGenerator())->generate($order_row, [], $doc_row));
                    $new_paths[] = "/documents/tickets/{$year}/{$fname}";
                    if ($i === 0) {
                        $pdo->prepare("UPDATE campaign_pledges SET ticket_code=? WHERE pledge_number=?")
                            ->execute([$tc, $order['order_number']]);
                    }
                }
                $ticket_paths = $new_paths;
                $pdo->prepare("UPDATE campaign_pledges SET ticket_path=? WHERE pledge_number=?")
                    ->execute([json_encode($ticket_paths), $order['order_number']]);
            }

            $attachments = [];
            foreach ($ticket_paths as $i => $rel_path) {
                $ffile = $_SERVER['DOCUMENT_ROOT'] . $rel_path;
                if (file_exists($ffile)) {
                    $n = $i + 1;
                    $attachments[] = [
                        'path' => $ffile,
                        'name' => 'ticket-' . $order['order_number'] . ($qty > 1 ? "-{$n}" : '') . '.pdf',
                    ];
                }
            }

            // Build a pledge-shaped array for the email template
            $pledge_for_email = [
                'name'          => $order['customer_name'],
                'email'         => $order['customer_email'],
                'pledge_number' => $order['order_number'],
                'amount_eur'    => $order['total_eur'],
                'ticket_code'   => $ticket_pledge['ticket_code'] ?? ($ticket_item['ticket_code'] ?? ''),
                'created_at'    => $order['created_at'],
                'lang'          => $ticket_pledge['lang'] ?? 'bg',
            ];
            $_pledge_lang = $pledge_for_email['lang'];
            $ok = send_mail(
                $order['customer_email'],
                render_email_subject('campaign-ticket', $_pledge_lang, ['event_name' => setting_get('event_name', 'събитието'), 'pledge_number' => $order['order_number']]),
                render_email('campaign-ticket', ['pledge' => $pledge_for_email, 'lang' => $_pledge_lang]),
                '',
                $attachments
            );
            $success = $ok ? 'Билетът е изпратен отново.' : 'Грешка при изпращане.';
            // Re-fetch
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            $items = json_decode($order['items'], true) ?? [];
            $ticket_item = $items[0] ?? [];
        }
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        header('Location: /admin/orders.php?deleted=1');
        exit;
    }


    if ($action === 'resend_cert') {
        if ($has_donation) {
            $existing_cert = $docs_by_type['donation_cert'] ?? null;
            if (!$existing_cert) {
                $errors[] = 'Сертификатът не е генериран. Генерирайте го първо.';
            } else {
                $cert_file = $_SERVER['DOCUMENT_ROOT'] . '/' . $existing_cert['file_path'];
                if (!file_exists($cert_file)) {
                    $errors[] = 'Файлът на сертификата липсва. Генерирайте го отново.';
                } else {
                    // Load pledge so we have lang + all fields the template needs
                    $ps = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
                    $ps->execute([$order['order_number']]);
                    $pledge_row = $ps->fetch() ?: null;

                    $pledge_for_email = $pledge_row ?: [
                        'name'          => $order['customer_name'],
                        'email'         => $order['customer_email'],
                        'pledge_number' => $order['order_number'],
                        'amount_eur'    => $order['total_eur'],
                        'created_at'    => $order['created_at'],
                        'delivery_address' => null,
                        'lang'          => 'bg',
                    ];
                    $_cert_lang = $pledge_for_email['lang'] ?? 'bg';

                    $attachments = [[
                        'path' => $cert_file,
                        'name' => 'certificate-' . $order['order_number'] . '.pdf',
                    ]];
                    $ok = send_mail(
                        $order['customer_email'],
                        render_email_subject('campaign-confirmation', $_cert_lang, ['name' => $pledge_for_email['name'], 'pledge_number' => $order['order_number']]),
                        render_email('campaign-confirmation', ['pledge' => $pledge_for_email, 'lang' => $_cert_lang]),
                        '',
                        $attachments
                    );
                    if ($ok) {
                        $pdo->prepare('UPDATE documents SET emailed_at = NOW() WHERE id = ?')
                            ->execute([$existing_cert['id']]);
                    }
                    $success = $ok ? 'Сертификатът е изпратен отново.' : 'Грешка при изпращане.';
                }
            }
        }
    }

    if ($action === 'update_payment') {
        $payment_status = $_POST['payment_status'] ?? '';
        if (in_array($payment_status, ['pending','paid','refunded'])) {
            $pdo->prepare('UPDATE orders SET payment_status=?, updated_at=NOW() WHERE id=?')
                ->execute([$payment_status, $id]);
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            $success = 'Статусът на плащане е обновен.';
        }
    }
}

// Pre-load saved signature for the signing UI (only for the authorised user)
$can_sign       = admin_can_sign();
$signer_has_sig = false;
if ($can_sign) {
    $sig_check = $pdo->prepare('SELECT 1 FROM admin_signatures WHERE admin_user_id = ?');
    $sig_check->execute([(int) admin_user()['id']]);
    $signer_has_sig = (bool) $sig_check->fetchColumn();
}

$page_title_admin = 'Поръчка #' . $order['order_number'];
$active_nav       = 'orders';

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Поръчка #<?= h($order['order_number']) ?></h1>
  <a href="/admin/orders.php" class="btn btn--outline">← Назад</a>
</div>

<?php if ($success): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;"><?= h($success) ?></div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1rem;"><?= h($e) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start;">

  <!-- Left: order details -->
  <div>

    <!-- Items -->
    <div class="admin-table-wrap" style="margin-bottom:1.5rem;">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Продукт</th>
            <th style="text-align:center;">Бр.</th>
            <th style="text-align:right;">Сума</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item_idx => $item):
            $is_don = $order['type'] === 'donation' || ($item['type'] ?? '') === 'donation';
            $name   = $item['name_bg'] ?? $item['name'] ?? ($is_don ? 'Дарение' : '');
            $qty    = (int)($item['quantity'] ?? 1);
            $amount = (float)($item['subtotal_eur'] ?? $item['amount_eur']
                      ?? (($item['price_eur'] ?? 0) * $qty));
            if ($is_don && $amount == 0) $amount = (float)$order['total_eur'];
          ?>
          <tr>
            <td>
              <?php $pid_link = (int)($item['product_id'] ?? 0); ?>
              <?php if ($pid_link && !$is_don): ?>
                <a href="/admin/product-edit.php?id=<?= $pid_link ?>" style="color:inherit;text-decoration:none;font-weight:inherit;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= h($name) ?></a>
              <?php else: ?>
                <?= h($name) ?>
              <?php endif; ?>
              <?php if (!empty($item['colour']) || !empty($item['size'])): ?>
                <div style="font-size:.8rem;color:var(--text-muted);margin-top:.2rem;">
                  <?php
                    $parts = [];
                    if (!empty($item['colour'])) $parts[] = 'Цвят: ' . h(ucfirst($item['colour']));
                    if (!empty($item['size']))   $parts[] = 'Размер: ' . h($item['size']);
                    echo implode(' · ', $parts);
                  ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($item['variant_label'])): ?>
                <div style="font-size:.8rem;color:var(--text-muted);margin-top:.2rem;"><?= h($item['variant_label']) ?></div>
              <?php endif; ?>
              <?php if (!empty($item['design_file']) && !empty($item['design_position'])): ?>
                <?php
                  $pos_v    = $item['design_position'];
                  $spec_str = null;
                  if (is_array($pos_v)) {
                      $dpath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($item['design_file'], '/');
                      $dim   = @getimagesize($dpath);
                      $ar    = ($dim && $dim[0] > 0) ? ($dim[1] / $dim[0]) : 1.0;
                      $ordered_size = $item['size'] ?? '';
                      $pvars_v   = $_print_variants[(int)($item['product_id'] ?? 0)] ?? [];
                      $sdims     = $pvars_v['size_dims'][$ordered_size] ?? null;
                      if ($sdims && ($sdims['w'] ?? 0) > 0) {
                          $dw_cm   = round($pos_v['scale'] * $sdims['w'], 1);
                          $dh_cm   = round($pos_v['scale'] * $ar * $sdims['h'], 1);
                          $left_cm = round(max(0, $pos_v['x'] - $pos_v['scale'] / 2) * $sdims['w'], 1);
                          $top_cm  = round(max(0, $pos_v['y'] - ($pos_v['scale'] * $ar) / 2) * $sdims['h'], 1);
                          $spec_str = "{$dw_cm} × {$dh_cm} cm · {$left_cm} cm от ляво · {$top_cm} cm от горе"
                                    . ($ordered_size ? " · Размер: {$ordered_size}" : '');
                      } else {
                          $spec_str = 'Добавете размери на тениската в продукта за да изчислим cm'
                                    . ($ordered_size ? " · Размер: {$ordered_size}" : '');
                      }
                  }
                ?>
                <div style="margin-top:.35rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                  <a href="/admin/download-print-composite.php?order_id=<?= $id ?>&item=<?= $item_idx ?>"
                     style="font-size:.8rem;color:var(--teal);text-decoration:none;">
                    ⬇ Свали дизайн
                  </a>
                  <a href="/admin/download-print-raw.php?order_id=<?= $id ?>&item=<?= $item_idx ?>"
                     style="font-size:.8rem;color:var(--teal);text-decoration:none;">
                    ⬇ Свали принт
                  </a>
                </div>
                <?php if ($spec_str): ?>
                  <div style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem;font-family:monospace;">
                    📐 <?= h($spec_str) ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="text-align:center;"><?= $is_don ? '—' : $qty ?></td>
            <td style="text-align:right;"><?= number_format($amount, 2) ?> €</td>
          </tr>
          <?php endforeach; ?>
          <?php
            // Collect indices of items that have a design (for the single email send)
            $_print_item_indices = [];
            foreach ($items as $_pi => $_pitem) {
                if (!empty($_pitem['design_file']) && !empty($_pitem['design_position'])) {
                    $_print_item_indices[] = $_pi;
                }
            }
          ?>
          <?php if ($_print_item_indices): ?>
          <tr>
            <td colspan="3" style="padding:.6rem 1rem;border-top:1px solid var(--border);background:var(--off-white);">
              <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
                <span style="font-size:.78rem;color:var(--text-muted);margin-right:.25rem;">Изпрати файловете за печат:</span>
                <input type="email" id="printEmailAll"
                       placeholder="имейл"
                       style="font-size:.78rem;padding:.25rem .5rem;border:1px solid var(--border);border-radius:4px;width:200px;">
                <button type="button"
                        onclick="sendPrintFiles(<?= $id ?>, <?= json_encode($_print_item_indices) ?>, this)"
                        style="font-size:.78rem;padding:.25rem .6rem;border:1px solid var(--teal);border-radius:4px;background:none;color:var(--teal);cursor:pointer;">
                  Изпрати
                </button>
                <span id="printEmailMsg" style="font-size:.75rem;"></span>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="2" style="text-align:right;color:var(--text-muted);">Доставка:</td>
            <td style="text-align:right;"><?= format_eur((float)$order['shipping_eur']) ?></td>
          </tr>
          <tr style="background:var(--off-white);">
            <td colspan="2" style="text-align:right;font-weight:700;">Общо:</td>
            <td style="text-align:right;font-weight:700;color:var(--teal);"><?= format_eur((float)$order['total_eur']) ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Customer + delivery -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
      <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;">
        <strong style="display:block;margin-bottom:.75rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Клиент</strong>
        <?= h($order['customer_name']) ?><br>
        <a href="mailto:<?= h($order['customer_email']) ?>"><?= h($order['customer_email']) ?></a><br>
        <?php if ($order['customer_phone']): ?>
          <a href="tel:<?= h($order['customer_phone']) ?>"><?= h($order['customer_phone']) ?></a>
        <?php endif; ?>
      </div>
      <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;">
        <strong style="display:block;margin-bottom:.75rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Доставка</strong>
        <?php if ($order['type'] === 'physical'): ?>
          <?= h($courier_labels[$order['courier']] ?? '') ?> — <?= h($type_labels[$order['delivery_type']] ?? '') ?><br>
          <?php if ($order['delivery_type'] === 'address'): ?>
            <?= h($order['delivery_address'] . ', ' . $order['delivery_city']) ?>
          <?php else: ?>
            <?= h($order['courier_office_name'] ?? '') ?>
          <?php endif; ?>
          <?php if ($order['tracking_number']): ?>
            <br><strong>Tracking:</strong> <?= h($order['tracking_number']) ?>
          <?php endif; ?>
        <?php elseif ($is_ticket): ?>
          Дигитален билет (по имейл)
        <?php else: ?>
          <?php
            $pm_labels = ['card' => 'Онлайн с карта', 'bank_transfer' => 'Банков превод', 'cod' => 'Наложен платеж'];
            echo h($pm_labels[$order['payment_method'] ?? ''] ?? ucfirst($order['payment_method'] ?? '—'));
          ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($is_b2b && $invoice_data): ?>
    <div style="background:var(--teal-light);border:1px solid var(--teal);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;">
      <strong style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--teal);">Фирмени данни за фактура</strong>
      <div style="margin-top:.6rem;font-size:.875rem;line-height:1.7;">
        <strong><?= h($invoice_data['company_name'] ?? '') ?></strong><br>
        <?php if (!empty($invoice_data['mol'])): ?>МОЛ: <?= h($invoice_data['mol']) ?><br><?php endif; ?>
        <?php if (!empty($invoice_data['eik'])): ?>ЕИК: <?= h($invoice_data['eik']) ?><?php endif; ?>
        <?php if (!empty($invoice_data['vat_number'])): ?> &nbsp;·&nbsp; ДДС №: <?= h($invoice_data['vat_number']) ?><?php endif; ?>
        <?php if (!empty($invoice_data['company_address'])): ?><br><?= h($invoice_data['company_address']) ?><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($order['donation_message']): ?>
    <div style="background:var(--warm-grey);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;">
      <strong style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Послание / посвещение</strong>
      <p style="margin:.5rem 0 0;"><?= h($order['donation_message']) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($order['type'] === 'pledge'): ?>
    <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.5rem;">
      <strong style="display:block;margin-bottom:.75rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">Награда от кампанията</strong>
      <?php if ($pledge_reward): ?>
        <div style="font-weight:600;margin-bottom:.25rem;"><?= h($pledge_reward['title']) ?></div>
        <?php if (!empty($pledge_reward['icebreaker_qty'])): ?>
          <div style="font-size:.85rem;margin-bottom:.25rem;">
            <span style="font-weight:600;">Бройки в наградата:</span> <?= (int)$pledge_reward['icebreaker_qty'] ?> бр.
          </div>
        <?php endif; ?>
        <?php if (!empty($pledge_row['delivery_courier'])): ?>
          <?php
            $del_addr_raw = $pledge_row['delivery_address'] ? json_decode($pledge_row['delivery_address'], true) : [];
            $courier_lbl  = ['speedy' => 'Speedy', 'boxnow' => 'BoxNow'][$pledge_row['delivery_courier']] ?? ucfirst($pledge_row['delivery_courier']);
            $del_type_lbl = ['office' => 'До офис', 'address' => 'До адрес', 'locker' => 'До автомат'][$pledge_row['delivery_type'] ?? ''] ?? '';
          ?>
          <div style="font-size:.85rem;color:var(--text-muted);margin-top:.4rem;">
            <?= h($courier_lbl) ?> — <?= h($del_type_lbl) ?>
            <?php if ($pledge_row['delivery_type'] === 'address' && !empty($del_addr_raw)): ?>
              <br><?= h(implode(', ', array_filter([
                $del_addr_raw['address'] ?? '', $del_addr_raw['city'] ?? '',
                $del_addr_raw['postcode'] ?? '',
              ]))) ?>
              <?php if (!empty($del_addr_raw['phone'])): ?> · <?= h($del_addr_raw['phone']) ?><?php endif; ?>
            <?php elseif (!empty($pledge_row['office_name'])): ?>
              <br><?= h(implode(', ', array_filter([$pledge_row['office_name'], $pledge_row['office_city'] ?? '']))) ?>
            <?php endif; ?>
          </div>
          <div style="font-size:.8rem;margin-top:.5rem;">
            <span style="font-weight:600;">Изпратена:</span>
            <?= $order['status'] === 'shipped' ? '<span style="color:#2d6a35;">Да ✓</span>' : '<span style="color:#b5870a;">Не</span>' ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <span style="color:var(--text-muted);">Без избрана награда (само дарение)</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right: actions -->
  <div>

    <!-- Status update -->
    <div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Обнови статус</h3>
      <form method="POST"
            data-cancel-warn="<?= ($order['payment_status'] === 'paid') ? h('Поръчката ще бъде отменена и сумата от ' . number_format((float)$order['total_eur'], 2, ',', '.') . ' EUR ще бъде върната автоматично на картата на клиента. Продължи?') : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <div class="form-group" style="margin-bottom:.75rem;">
          <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.35rem;">Статус</label>
          <select name="status" onchange="toggleTracking(this.value)"
                  style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
            <?php
            $statuses = ['new'=>'Нова','confirmed'=>'Потвърдена','shipped'=>'Изпратена','delivered'=>'Доставена','cancelled'=>'Отменена'];
            foreach ($statuses as $v => $l): ?>
              <option value="<?= $v ?>" <?= $order['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="trackingField" style="margin-bottom:.75rem;<?= $order['status'] !== 'shipped' ? 'display:none;' : '' ?>">
          <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.35rem;">Номер за проследяване *</label>
          <input type="text" name="tracking_number" value="<?= h($order['tracking_number'] ?? '') ?>"
                 style="width:100%;box-sizing:border-box;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
        </div>
        <div class="form-group" style="margin-bottom:.75rem;">
          <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.35rem;">Бележки (вътрешни)</label>
          <textarea name="notes" rows="3"
                    style="width:100%;box-sizing:border-box;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;resize:vertical;"><?= h($order['notes'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Запази</button>
      </form>
    </div>

    <?php if ($order['type'] === 'pledge' && $pledge_row && !empty($pledge_row['delivery_courier'])): ?>
    <!-- Reward shipping label -->
    <?php
    $psc_pledge = $pledge_row;
    require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/pledge-shipping-card.php';
    ?>
    <?php endif; ?>

    <?php if ($is_ticket): ?>
    <!-- Ticket card -->
    <div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--teal);border-radius:var(--radius-lg);background:var(--teal-light);">
      <h3 style="margin-top:0;font-size:1rem;color:var(--teal);">Билет</h3>
      <?php if ($ticket_item['ticket_code'] ?? ''): ?>
      <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.4rem;">Код за вход</div>
      <div style="font-family:monospace;font-size:1rem;font-weight:700;color:var(--teal);letter-spacing:.08em;margin-bottom:1rem;">
        <?= h($ticket_item['ticket_code']) ?>
      </div>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend_ticket">
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
          Изпрати билет отново
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($order['type'] === 'donation'): ?>
    <!-- Payment status for donations -->
    <div class="admin-card" style="padding:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Плащане</h3>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_payment">
        <select name="payment_status"
                style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;margin-bottom:.75rem;">
          <option value="pending"  <?= $order['payment_status'] === 'pending'  ? 'selected' : '' ?>>Чакащо</option>
          <option value="paid"     <?= $order['payment_status'] === 'paid'     ? 'selected' : '' ?>>Платено</option>
          <option value="refunded" <?= $order['payment_status'] === 'refunded' ? 'selected' : '' ?>>Върнато</option>
        </select>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">Запази плащане</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($order['courier'] === 'boxnow' && $order['type'] === 'physical'): ?>
    <!-- BoxNow label -->
    <div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">BoxNow товарителница</h3>
      <?php if (!empty($order['boxnow_parcel_id'])): ?>
        <p style="font-size:.85rem;margin:.5rem 0;">
          <strong>Parcel ID:</strong><br>
          <code style="font-size:.8rem;"><?= h($order['boxnow_parcel_id']) ?></code>
        </p>
        <a href="/admin/boxnow-label.php?order_id=<?= $id ?>" target="_blank"
           class="btn btn--primary" style="width:100%;justify-content:center;margin-bottom:.5rem;display:flex;">
          📄 Печат на етикет (PDF)
        </a>
        <form method="POST" data-confirm="Анулиране на товарителницата. Сигурни ли сте?" data-confirm-ok="Анулирай"">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel_boxnow_label">
          <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;color:#c0392b;border-color:#c0392b;">
            ✕ Анулирай пратката
          </button>
        </form>
      <?php else: ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.25rem 0 1rem;">
          Автомат: <?= h($order['courier_office_name'] ?: $order['courier_office_code']) ?>
        </p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_boxnow_label">
          <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Размер на отделението</label>
          <select name="compartment_size" style="width:100%;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;margin-bottom:.65rem;">
            <option value="1">Малко (до 8 см височина)</option>
            <option value="2">Средно (до 17 см)</option>
            <option value="3">Голямо (до 36 см)</option>
          </select>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
            📦 Създай товарителница
          </button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($order['courier'] === 'speedy' && $order['type'] === 'physical'): ?>
    <!-- Speedy label -->
    <div class="admin-card" style="padding:1.5rem;margin-bottom:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Speedy товарителница</h3>
      <?php if (!empty($order['speedy_shipment_id'])): ?>
        <p style="font-size:.85rem;margin:.5rem 0;">
          <strong>Номер:</strong><br>
          <code style="font-size:.8rem;"><?= h($order['speedy_shipment_id']) ?></code>
        </p>
        <a href="/admin/speedy-label.php?order_id=<?= $id ?>" target="_blank"
           class="btn btn--primary" style="width:100%;justify-content:center;margin-bottom:.5rem;display:flex;">
          📄 Печат на етикет (PDF)
        </a>
        <form method="POST" data-confirm="Анулиране на товарителницата. Сигурни ли сте?" data-confirm-ok="Анулирай">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel_speedy_label">
          <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;color:#c0392b;border-color:#c0392b;">
            ✕ Анулирай пратката
          </button>
        </form>
      <?php else: ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.25rem 0 1rem;">
          <?= $order['delivery_type'] === 'office'
              ? 'Офис: ' . h($order['courier_office_name'] ?: $order['courier_office_code'])
              : 'Адрес: ' . h($order['delivery_address'] . ', ' . $order['delivery_city']) ?>
        </p>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_speedy_label">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.65rem;">
            <div>
              <label for="speedy_weight" style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Тегло (кг)</label>
              <input type="number" id="speedy_weight" name="weight" value="1.0" min="0.1" step="0.1"
                     style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
            </div>
            <div>
              <label for="speedy_pack_count" style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem;">Брой пакети</label>
              <input type="number" id="speedy_pack_count" name="pack_count" value="1" min="1" step="1"
                     style="width:100%;box-sizing:border-box;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
            </div>
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center;">
            📦 Създай товарителница
          </button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Documents -->
    <?php if ($is_ticket): ?>
    <div class="admin-card" style="padding:1.5rem;margin-top:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Документи</h3>
      <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
        Билети (PDF)
      </div>
      <?php if (!empty($ticket_paths)): ?>
        <?php foreach ($ticket_paths as $i => $rel_path): ?>
          <?php
            $n       = $i + 1;
            $total   = count($ticket_paths);
            $label   = $total > 1 ? "Билет {$n}/{$total}" : 'Билет';
            $fexists = file_exists($_SERVER['DOCUMENT_ROOT'] . $rel_path);
            $dl_url  = '/admin/download-ticket.php?pledge=' . urlencode($order['order_number']) . '&n=' . $n;
          ?>
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
            <?php if ($fexists): ?>
              <a href="<?= h($dl_url) ?>" target="_blank"
                 class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;">
                📄 <?= h($label) ?>
              </a>
            <?php else: ?>
              <span style="font-size:.8rem;color:var(--text-muted);"><?= h($label) ?> — файлът липсва</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin:0 0 .75rem;">Билетите не са генерирани.</p>
      <?php endif; ?>
    </div>
    <?php elseif (!$is_ticket): ?>
    <div class="admin-card" style="padding:1.5rem;margin-top:1.5rem;border:1px solid var(--border);border-radius:var(--radius-lg);">
      <h3 style="margin-top:0;font-size:1rem;">Документи</h3>

      <?php
        // Invoice or receipt (physical orders only)
        if ($has_physical):
          if ($is_b2b):
            $doc_type = 'invoice';
            $doc_label = 'Фактура';
          else:
            $doc_type = 'receipt';
            $doc_label = 'Електронна бележка';
          endif;
          $existing_sale_doc = $docs_by_type[$doc_type] ?? null;
      ?>
      <div style="margin-bottom:1rem;">
        <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
          <?= $doc_label ?>
          <?php if ($is_b2b): ?>
            <span style="background:var(--teal-light);color:var(--teal);font-size:.7rem;padding:.1rem .4rem;border-radius:3px;margin-left:.4rem;">Фирма</span>
          <?php endif; ?>
        </div>
        <?php if ($existing_sale_doc): ?>
          <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.4rem;">
            № <?= h($existing_sale_doc['formatted_number']) ?> &nbsp;·&nbsp;
            <?= h(substr($existing_sale_doc['generated_at'], 0, 16)) ?>
          </div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <a href="/admin/download-document.php?id=<?= (int)$existing_sale_doc['id'] ?>" target="_blank"
               class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;">
              📄 Преглед
            </a>
            <form method="POST" action="/admin/generate-document.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= $id ?>">
              <input type="hidden" name="doc_type" value="<?= $doc_type ?>">
              <button type="submit" class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;color:var(--text-muted);">
                ↺ Генерирай отново
              </button>
            </form>
          </div>
        <?php else: ?>
          <form method="POST" action="/admin/generate-document.php">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <input type="hidden" name="doc_type" value="<?= $doc_type ?>">
            <button type="submit" class="btn btn--primary" style="font-size:.85rem;width:100%;justify-content:center;">
              + Генерирай <?= $doc_label ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($has_donation):
        $existing_cert = $docs_by_type['donation_cert'] ?? null;
      ?>
      <?php if ($has_physical): ?>
        <hr style="border:none;border-top:1px solid var(--border);margin:.75rem 0;">
      <?php endif; ?>
      <div>
        <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
          Сертификат за дарение
        </div>
        <?php if ($existing_cert): ?>
          <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.4rem;">
            № <?= h($existing_cert['formatted_number']) ?> &nbsp;·&nbsp;
            <?= h(substr($existing_cert['generated_at'], 0, 16)) ?>
            <?php if (!empty($existing_cert['signed_at'])): ?>
              &nbsp;<span style="background:#d1fae5;color:#065f46;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                ✓ Подписан <?= h(substr($existing_cert['signed_at'], 0, 10)) ?>
              </span>
              <?php if (!empty($existing_cert['emailed_at'])): ?>
                &nbsp;<span style="background:#dbeafe;color:#1e40af;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                  ✉ Изпратен <?= h(substr($existing_cert['emailed_at'], 0, 16)) ?>
                </span>
              <?php else: ?>
                &nbsp;<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                  ✉ Имейлът не беше изпратен
                </span>
              <?php endif; ?>
            <?php else: ?>
              &nbsp;<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:600;">
                Без подпис
              </span>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <a href="/admin/download-document.php?id=<?= (int)$existing_cert['id'] ?>" target="_blank"
               class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;">
              📄 Преглед
            </a>
            <?php if ($can_sign && empty($existing_cert['signed_at'])): ?>
              <button type="button"
                      onclick="openSignModal(<?= (int)$existing_cert['id'] ?>)"
                      class="btn btn--primary" style="font-size:.8rem;padding:.3rem .75rem;">
                ✍ Подпиши
              </button>
            <?php endif; ?>
            <form method="POST" action="/admin/generate-document.php" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= $id ?>">
              <input type="hidden" name="doc_type" value="donation_cert">
              <button type="submit" class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;color:var(--text-muted);">
                ↺ Генерирай отново
              </button>
            </form>
            <form method="POST" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="resend_cert">
              <button type="submit" class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;">
                ✉ Изпрати отново
              </button>
            </form>
          </div>
        <?php else: ?>
          <form method="POST" action="/admin/generate-document.php">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <input type="hidden" name="doc_type" value="donation_cert">
            <button type="submit" class="btn btn--primary" style="font-size:.85rem;width:100%;justify-content:center;">
              + Генерирай сертификат
            </button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (!$has_physical && !$has_donation): ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin:0;">Няма приложими документи за тази поръчка.</p>
      <?php endif; ?>

      <?php
        // Credit note button — only for cancelled B2B orders with an invoice but no credit note yet
        $credit_doc_issued = isset($docs_by_type['credit_note']);
        if ($order['status'] === 'cancelled' && isset($docs_by_type['invoice']) && !$credit_doc_issued && $has_physical):
      ?>
      <hr style="border:none;border-top:1px solid var(--border);margin:.75rem 0;">
      <div>
        <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
          Кредитен документ
        </div>
        <form method="POST" action="/admin/generate-document.php">
          <?= csrf_field() ?>
          <input type="hidden" name="order_id" value="<?= $id ?>">
          <input type="hidden" name="doc_type" value="credit_note">
          <button type="submit" class="btn btn--primary" style="font-size:.85rem;width:100%;justify-content:center;">
            + Издай кредитно известие
          </button>
        </form>
      </div>
      <?php elseif ($order['status'] === 'cancelled' && $credit_doc_issued && $has_physical): ?>
      <hr style="border:none;border-top:1px solid var(--border);margin:.75rem 0;">
      <div>
        <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
          Кредитен документ
        </div>
        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.4rem;">
          № <?= h($docs_by_type['credit_note']['formatted_number']) ?> &nbsp;·&nbsp;
          <?= h(substr($docs_by_type['credit_note']['generated_at'], 0, 16)) ?>
        </div>
        <a href="/admin/download-document.php?id=<?= (int)$docs_by_type['credit_note']['id'] ?>" target="_blank"
           class="btn btn--outline" style="font-size:.8rem;padding:.3rem .75rem;">
          📄 Преглед
        </a>
      </div>
      <?php endif; ?>

    </div>
    <?php endif; // !$is_ticket ?>

    <!-- Meta -->
    <div style="margin-top:1.5rem;font-size:.8rem;color:var(--text-muted);line-height:1.8;">
      Създадена: <?= h(substr($order['created_at'], 0, 16)) ?><br>
      Обновена: <?= h(substr($order['updated_at'], 0, 16)) ?><br>
      Плащане: <?= h($order['payment_method']) ?> — <?= h($order['payment_status']) ?>
    </div>

    <!-- Delete -->
    <div style="margin-top:2rem;border-top:1px solid var(--border);padding-top:1.5rem;">
      <form method="POST" data-confirm="Изтриване на поръчка #<?= h($order['order_number']) ?>? Това е необратимо."">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn--outline" style="width:100%;justify-content:center;color:#c0392b;border-color:#c0392b;font-size:.85rem;">
          Изтрий поръчката
        </button>
      </form>
    </div>
  </div>
</div>

<script>
function sendPrintFiles(orderId, items, btn) {
    var emailInput = document.getElementById('printEmailAll');
    var msg        = document.getElementById('printEmailMsg');
    var email      = emailInput.value.trim();
    if (!email) { emailInput.focus(); return; }
    btn.disabled = true;
    btn.textContent = '…';
    msg.textContent = '';
    fetch('/admin/email-print-files.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            order_id:   orderId,
            items:      items,
            email:      email,
            csrf_token: '<?= csrf_token() ?>',
        }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Изпрати';
        if (data.ok) {
            msg.textContent = '✓ Изпратено';
            msg.style.color = 'green';
        } else {
            msg.textContent = data.error || 'Грешка';
            msg.style.color = '#c0392b';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Изпрати';
        msg.textContent = 'Грешка при връзка';
        msg.style.color = '#c0392b';
    });
}

function toggleTracking(status) {
    document.getElementById('trackingField').style.display = (status === 'shipped') ? '' : 'none';
    var form = document.querySelector('form[data-cancel-warn]');
    if (!form) return;
    var warn = form.dataset.cancelWarn;
    if (status === 'cancelled' && warn) {
        form.dataset.confirm   = warn;
        form.dataset.confirmOk = 'Отмени и върни сумата';
    } else {
        delete form.dataset.confirm;
        delete form.dataset.confirmOk;
    }
}
<?php if ($can_sign): ?>
function openSignModal(docId) {
    document.getElementById('signDocId').value = docId;
    var overlay = document.getElementById('signModalOverlay');
    overlay.style.display = 'flex';
    overlay.querySelector('button[data-dismiss]').focus();
}
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('signModalOverlay');
    document.getElementById('signModalCancel').addEventListener('click', function () {
        overlay.style.display = 'none';
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.style.display = 'none';
    });
    document.addEventListener('keydown', function (e) {
        if (overlay.style.display !== 'none' && e.key === 'Escape') overlay.style.display = 'none';
    });
});
<?php endif; ?>
</script>

<?php if ($can_sign): ?>
<!-- Sign certificate modal -->
<div id="signModalOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;"
     role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:10px;padding:2rem;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.25);">
    <h3 style="margin-top:0;font-size:1.05rem;">Подпис на сертификата</h3>
    <?php if ($signer_has_sig): ?>
      <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
        Ще бъде приложен вашият запазен подпис, PDF-ът ще бъде презаписан и сертификатът ще бъде изпратен автоматично на <?= h($order['customer_email']) ?>.
      </p>
      <form method="POST" action="/admin/sign-document.php">
        <?= csrf_field() ?>
        <input type="hidden" name="doc_id" id="signDocId" value="">
        <div style="display:flex;gap:.75rem;justify-content:flex-end;">
          <button type="button" id="signModalCancel" data-dismiss
                  style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;">
            Отказ
          </button>
          <button type="submit"
                  style="padding:.55rem 1.25rem;border:none;border-radius:6px;background:var(--teal);color:#fff;cursor:pointer;font-size:.9rem;font-weight:600;">
            ✍ Приложи подписа
          </button>
        </div>
      </form>
    <?php else: ?>
      <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem;">
        Нямате запазен подпис. Преди да подпишете сертификат, трябва да нарисувате подписа си.
      </p>
      <div style="display:flex;gap:.75rem;justify-content:flex-end;">
        <button type="button" id="signModalCancel" data-dismiss
                style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:.9rem;">
          Затвори
        </button>
        <a href="/admin/signature.php"
           style="padding:.55rem 1.25rem;border:none;border-radius:6px;background:var(--teal);color:#fff;text-decoration:none;font-size:.9rem;font-weight:600;display:inline-block;">
          Добави подпис →
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
