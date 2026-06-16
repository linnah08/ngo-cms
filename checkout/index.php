<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
start_session();

$lang = get_lang();
$pdo  = get_pdo();

// Redirect if cart is empty (pledge mode bypasses cart check)
$cart = $_SESSION['cart'] ?? [];
$_is_pledge_mode = !empty($_SESSION['checkout_data']['_pledge_number']);
if (empty($cart) && !$_is_pledge_mode) {
    header('Location: /cart/');
    exit;
}

// Load cart products
function load_cart_products(array $cart, \PDO $pdo): array {
    if (empty($cart)) return [];
    $ids  = array_column($cart, 'product_id');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($in) AND active=1");
    $stmt->execute($ids);
    $by_id = [];
    foreach ($stmt->fetchAll() as $p) $by_id[$p['id']] = $p;
    $rows = [];
    $sub  = 0.0;
    foreach ($cart as $i => $item) {
        $pid = $item['product_id'];
        if (!isset($by_id[$pid])) continue;
        $p    = $by_id[$pid];
        $qty  = $p['type'] === 'variant'
            ? (int)$item['quantity']
            : min((int)$item['quantity'], (int)$p['stock']);
        $line = $qty * (float)$p['price_eur'];
        $sub += $line;
        $rows[] = [
            'cart_index'      => $i,
            'product'         => $p,
            'quantity'        => $qty,
            'line_eur'        => $line,
            'colour'          => $item['colour']          ?? null,
            'size'            => $item['size']            ?? null,
            'design_file'     => $item['design_file']     ?? null,
            'design_position' => $item['design_position'] ?? null,
            'variant_id'      => $item['variant_id']      ?? null,
            'variant_label'   => $item['variant_label']   ?? null,
        ];
    }
    return ['rows' => $rows, 'subtotal' => $sub];
}

// Update $_SESSION['cart'] quantities from POST data (quantity[pid] => qty)
function update_cart_from_post(): void {
    $qtys = $_POST['quantity'] ?? [];
    if (!is_array($qtys)) return;
    $cart = $_SESSION['cart'] ?? [];
    $new  = [];
    foreach ($cart as $i => $item) {
        $pid  = $item['product_id'];
        $qty  = isset($qtys[$i]) ? max(1, (int)$qtys[$i]) : (int)$item['quantity'];
        $entry = ['product_id' => $pid, 'quantity' => $qty];
        // Preserve print-specific fields
        foreach (['colour', 'size', 'design_file', 'design_position', 'variant_id', 'variant_label'] as $k) {
            if (isset($item[$k])) $entry[$k] = $item[$k];
        }
        $new[] = $entry;
    }
    $_SESSION['cart'] = $new;
}

// Load shipping rates for JS
$rates_raw = $pdo->query('SELECT courier, delivery_type, rate_eur FROM shipping_rates')->fetchAll();
$rates     = [];
foreach ($rates_raw as $r) {
    $rates[$r['courier']][$r['delivery_type']] = (float)$r['rate_eur'];
}

$step   = (int)($_GET['step'] ?? 1);
$data   = $_SESSION['checkout_data'] ?? [];
$errors = [];

// ── POST handlers ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $action = $_POST['action'] ?? '';

    // Step 1 → save contact
    if ($action === 'contact') {
        update_cart_from_post();
        $name  = trim($_POST['customer_name']  ?? '');
        $email = trim($_POST['customer_email'] ?? '');
        $phone = trim($_POST['customer_phone'] ?? '');
        if (!$name)  $errors[] = 'Моля въведете имена.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Невалиден имейл.';
        if (!$phone) {
            $errors[] = $lang === 'en' ? 'Phone number is required.' : 'Телефонът е задължителен.';
        } else {
            $phone_digits = preg_replace('/\D/', '', $phone);
            $digit_count  = strlen($phone_digits);
            if ($digit_count < 7 || $digit_count > 15) {
                $errors[] = $lang === 'en' ? 'Please enter a valid phone number.' : 'Моля въведете валиден телефонен номер.';
            }
        }

        // Optional invoice data (B2B customers only)
        $invoice_data = null;
        if (!empty($_POST['needs_invoice'])) {
            $company   = trim($_POST['invoice_company']  ?? '');
            $mol       = trim($_POST['invoice_mol']      ?? '');
            $eik       = trim($_POST['invoice_eik']      ?? '');
            $vat       = trim($_POST['invoice_vat']      ?? '');
            $c_address = trim($_POST['invoice_address']  ?? '');
            if (!$company)   $errors[] = 'Въведете наименование на фирмата.';
            if (!$mol)       $errors[] = 'Въведете МОЛ (отговорно лице).';
            if (!$eik)       $errors[] = 'Въведете ЕИК / Булстат.';
            if (!$c_address) $errors[] = 'Въведете адрес на фирмата.';
            if (!$errors) {
                $invoice_data = [
                    'needs_invoice'   => true,
                    'company_name'    => $company,
                    'mol'             => $mol,
                    'eik'             => $eik,
                    'vat_number'      => $vat ?: null,
                    'company_address' => $c_address,
                ];
            }
        }

        if (!$errors) {
            $_SESSION['checkout_data'] = array_merge($data, [
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'invoice_data'   => $invoice_data,
            ]);
            header('Location: /checkout/?step=2');
            exit;
        }
        $step = 1;
    }

    // Step 2 → save delivery
    if ($action === 'delivery') {
        $courier       = $_POST['courier']        ?? '';
        $delivery_type = $_POST['delivery_type']  ?? '';
        $office_code   = trim($_POST['courier_office_code']  ?? '');
        $office_name   = trim($_POST['courier_office_name']  ?? '');
        $office_city   = trim($_POST['courier_office_city']  ?? '');
        $address       = trim($_POST['delivery_address']     ?? '');
        $city          = trim($_POST['delivery_city']        ?? '');
        $shipping_eur  = (float)($_POST['shipping_eur']      ?? 0);

        if (!in_array($courier, ['speedy','boxnow'])) $errors[] = 'Изберете куриер.';

        $valid_types = ['speedy' => ['office','apt','address'], 'boxnow' => ['locker']];
        if (!isset($valid_types[$courier]) || !in_array($delivery_type, $valid_types[$courier] ?? [])) {
            $errors[] = 'Изберете тип доставка.';
        }

        if (!$errors) {
            if ($delivery_type === 'address') {
                if (!$address) $errors[] = 'Въведете адрес.';
                if (!$city)    $errors[] = 'Въведете град.';
            } else {
                if (!$office_code) $errors[] = 'Изберете офис/автомат.';
            }
        }

        if (!$errors) {
            // Re-calculate shipping server-side to avoid trusting the client-submitted value
            if ($courier !== 'boxnow') {
                try {
                    $class_map = [
                        'speedy' => ['includes/couriers/SpeedyCourier.php', 'SpeedyCourier'],
                    ];
                    [$cf, $cc] = $class_map[$courier];
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/' . $cf;
                    $totalWeight = 1.0; $packCount = 1;
                    if ($cart) {
                        $packCount = max(1, count($cart));
                        $w = 0.0;
                        foreach ($cart as $item) {
                            $w += 0.5 * max(1, (int)($item['quantity'] ?? 1));
                        }
                        if ($w > 0) $totalWeight = $w;
                    }
                    $parcel = ['weight' => $totalWeight, 'pack_count' => $packCount];
                    if ($office_code !== '') $parcel['office_id'] = $office_code;
                    $calcCity = ($delivery_type === 'address') ? $city : $office_city;
                    $_SESSION['_debug_shipping'] = [
                        'courier' => $courier, 'delivery_type' => $delivery_type,
                        'city' => $calcCity, 'parcel' => $parcel,
                        'speedy_client_id_resolved' => setting_get('speedy_client_id'),
                        'speedy_test_mode' => setting_get('speedy_test_mode'),
                    ];
                    $price = (new $cc())->calculateShipping($parcel, $calcCity, $delivery_type);
                    if ($price > 0) $shipping_eur = $price;
                    $_SESSION['_debug_shipping']['result'] = $price;
                } catch (Throwable $e) {
                    error_log('checkout delivery calc (' . $courier . '): ' . $e->getMessage());
                    $_SESSION['_debug_shipping']['error'] = $e->getMessage();
                }
            }
            // Fallback to DB flat rate if API failed or returned 0
            if ($shipping_eur <= 0) {
                $stmt = $pdo->prepare('SELECT rate_eur FROM shipping_rates WHERE courier=? AND delivery_type=?');
                $stmt->execute([$courier, $delivery_type]);
                $rate = $stmt->fetchColumn();
                if ($rate !== false) $shipping_eur = (float)$rate;
            }

            $_SESSION['checkout_data'] = array_merge($data, [
                'courier'             => $courier,
                'delivery_type'       => $delivery_type,
                'courier_office_code' => $office_code,
                'courier_office_name' => $office_name,
                'courier_office_city' => $office_city,
                'delivery_address'    => $address,
                'delivery_city'       => $city,
                'shipping_eur'        => $shipping_eur,
            ]);
            // Pledge mode: save delivery to the pledge and go straight to DSK Bank
            if (!empty($_SESSION['checkout_data']['_pledge_number'])) {
                $cd             = $_SESSION['checkout_data'];
                $pledge_number  = $cd['_pledge_number'];
                $pledge_id      = (int)$cd['_pledge_id'];
                $pledge_amount  = (float)$cd['_pledge_amount'];

                $phone = trim($cd['customer_phone'] ?? '');

                $del_address = null;
                if ($delivery_type === 'address') {
                    $del_address = json_encode(['address' => $address, 'city' => $city, 'phone' => $phone]);
                }

                $pdo->prepare("UPDATE campaign_pledges SET
                    phone=?, delivery_courier=?, delivery_type=?, office_code=?, office_name=?, office_city=?,
                    delivery_address=?
                    WHERE id=?"
                )->execute([
                    $phone ?: null,
                    $courier, $delivery_type,
                    $office_code ?: null, $office_name ?: null, $office_city ?: null,
                    $del_address,
                    $pledge_id,
                ]);

                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
                try {
                    $dsk       = new DSKBankPayment();
                    $ref       = $pledge_number . '_' . time();
                    $returnUrl = SITE_URL . '/api/campaign-payment-return.php?pledge=' . urlencode($pledge_number);
                    $result    = $dsk->register($ref, $pledge_amount, $returnUrl);
                    $pdo->prepare('UPDATE campaign_pledges SET dsk_order_id=? WHERE id=?')
                        ->execute([$result['dsk_order_id'], $pledge_id]);
                    unset($_SESSION['checkout_data'], $_SESSION['cart']);
                    header('Location: ' . $result['formUrl']);
                } catch (Throwable $e) {
                    error_log('checkout/pledge DSK register failed: ' . $e->getMessage());
                    header('Location: /campaign/payment-failed/?pledge=' . urlencode($pledge_number) . '&err=' . urlencode($e->getMessage()));
                }
                exit;
            }

            header('Location: /checkout/?step=3');
            exit;
        }
        $step = 2;
    }

    // Step 3 → confirm order
    if ($action === 'confirm') {
        $d = $_SESSION['checkout_data'] ?? [];
        if (empty($d['customer_name']) || empty($d['courier'])) {
            header('Location: /checkout/?step=1');
            exit;
        }

        $order_lang_raw = $_POST['lang'] ?? 'bg';
        $order_lang     = in_array($order_lang_raw, ['bg', 'en'], true) ? $order_lang_raw : 'bg';

        // Online payment only — COD is not offered. Resolve to an enabled provider.
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
        $enabled_methods = [];
        if (DSKBankPayment::isEnabled()) $enabled_methods[] = 'card';
        if (IRISPayment::isEnabled())    $enabled_methods[] = 'iris';

        $payment_method = $_POST['payment_method'] ?? '';
        if (!in_array($payment_method, $enabled_methods, true)) {
            $payment_method = $enabled_methods[0] ?? '';
        }
        if ($payment_method === '') {
            flash_set('error', 'Онлайн плащането не е налично в момента. Моля свържете се с нас.');
            header('Location: /cart/');
            exit;
        }

        update_cart_from_post();
        $cart_info = load_cart_products($_SESSION['cart'], $pdo);
        $rows      = $cart_info['rows'];
        $subtotal  = $cart_info['subtotal'];
        $shipping  = (float)($d['shipping_eur'] ?? 0);
        $total     = $subtotal + $shipping;

        if (empty($rows)) {
            flash_set('error', 'Количката е празна или продуктите не са налични.');
            header('Location: /cart/');
            exit;
        }

        // Build items JSON
        $items_json = [];
        foreach ($rows as $row) {
            $p = $row['product'];
            $entry = [
                'product_id'   => (int)$p['id'],
                'name_bg'      => $p['name_bg'],
                'name_en'      => $p['name_en'],
                'price_eur'    => (float)$p['price_eur'],
                'quantity'     => $row['quantity'],
                'subtotal_eur' => $row['line_eur'],
            ];
            // Print-specific fields
            if (!empty($row['colour']))          $entry['colour']          = $row['colour'];
            if (!empty($row['size']))            $entry['size']            = $row['size'];
            if (!empty($row['design_file']))     $entry['design_file']     = $row['design_file'];
            if (!empty($row['design_position'])) $entry['design_position'] = $row['design_position'];
            // Variant fields
            if (!empty($row['variant_id']))    $entry['variant_id']    = (int)$row['variant_id'];
            if (!empty($row['variant_label'])) $entry['variant_label'] = $row['variant_label'];
            $items_json[] = $entry;
        }

        // Optional donation add-on
        $donation_amount = round(max(0.0, (float)($_POST['donation_amount'] ?? 0)), 2);
        if ($donation_amount < 1.0) { $donation_amount = 0.0; }
        if ($donation_amount > 0) {
            $items_json[] = [
                'type'         => 'donation',
                'name_bg'      => 'Дарение за ' . SITE_NAME_BG,
                'amount_eur'   => $donation_amount,
                'subtotal_eur' => $donation_amount,
            ];
            $total += $donation_amount;
        }

        // Transactionally check stock, decrement, insert order
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $p = $row['product'];
                if ($p['type'] === 'variant' && !empty($row['variant_id'])) {
                    $pdo->prepare(
                        'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND product_id = ? AND stock >= ?'
                    )->execute([$row['quantity'], $row['variant_id'], $p['id'], $row['quantity']]);
                } else {
                    $pdo->prepare(
                        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
                    )->execute([$row['quantity'], $p['id'], $row['quantity']]);
                }
                $affected = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
                if ((int)$affected === 0) {
                    throw new RuntimeException($p['name_bg'] . ' вече не е в наличност.');
                }
            }

            $order_number = generate_order_number();
            $inv_data_json = isset($d['invoice_data'])
                ? json_encode($d['invoice_data'], JSON_UNESCAPED_UNICODE)
                : null;
            $pdo->prepare("
                INSERT INTO orders
                  (order_number,type,status,lang,customer_name,customer_email,customer_phone,
                   delivery_type,courier,courier_office_code,courier_office_name,
                   delivery_address,delivery_city,items,subtotal_eur,shipping_eur,total_eur,
                   payment_method,payment_status,invoice_data)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $order_number, 'physical', 'new', $order_lang,
                $d['customer_name'], $d['customer_email'], $d['customer_phone'],
                $d['delivery_type'], $d['courier'],
                $d['courier_office_code'] ?? null, $d['courier_office_name'] ?? null,
                $d['delivery_address'] ?? null, $d['delivery_city'] ?? null,
                json_encode($items_json, JSON_UNESCAPED_UNICODE),
                $subtotal, $shipping, $total,
                $payment_method, 'pending',
                $inv_data_json,
            ]);
            $order_id = $pdo->lastInsertId();
            $pdo->commit();

        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
            $step = 3;
            goto render;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Checkout error: ' . $e->getMessage());
            $errors[] = 'Грешка при обработка на поръчката. Моля опитайте отново.';
            $step = 3;
            goto render;
        }

        // Clear cart + checkout session regardless of payment method
        unset($_SESSION['cart'], $_SESSION['checkout_data']);

        if ($payment_method === 'card') {
            // Redirect to DSK Bank payment page
            try {
                $dsk       = new DSKBankPayment();
                $ref       = $order_number . '_' . time();
                $returnUrl = SITE_URL . '/api/payment-return.php?order=' . urlencode($order_number);
                $result    = $dsk->register($ref, $total, $returnUrl);

                $pdo->prepare('UPDATE orders SET dsk_order_id = ? WHERE id = ?')
                    ->execute([$result['dsk_order_id'], $order_id]);

                header('Location: ' . $result['formUrl']);
                exit;
            } catch (Throwable $e) {
                error_log('DSK Bank register error: ' . $e->getMessage());
                header('Location: /checkout/payment-failed/?order=' . urlencode($order_number) . '&err=' . urlencode($e->getMessage()));
                exit;
            }
        }

        if ($payment_method === 'iris') {
            // Redirect to IRIS Pay by Bank.
            // The callback token authenticates the server-to-server confirmation;
            // it goes only in the hookUrl, never in the browser-facing redirectUrl.
            try {
                $iris  = new IRISPayment();
                $token = bin2hex(random_bytes(32));

                $redirectUrl = SITE_URL . '/api/iris-payment-return.php?order=' . urlencode($order_number);
                $hookUrl     = SITE_URL . '/api/iris-payment-callback.php?id=' . urlencode($order_number) . '&token=' . $token;

                $result = $iris->register([
                    'currency'    => 'EUR',
                    'amountEur'   => $total,
                    'name'        => 'Поръчка ' . $order_number,
                    'description' => SITE_NAME_BG . ' — поръчка ' . $order_number,
                    'orderId'     => $order_number,
                    'redirectUrl' => $redirectUrl,
                    'hookUrl'     => $hookUrl,
                    'lang'        => $order_lang,
                ]);

                $pdo->prepare('UPDATE orders SET iris_payment_hash = ?, iris_callback_token = ? WHERE id = ?')
                    ->execute([$result['paymentHash'], $token, $order_id]);

                header('Location: ' . $result['paymentLink']);
                exit;
            } catch (Throwable $e) {
                error_log('IRIS register error: ' . $e->getMessage());
                header('Location: /checkout/payment-failed/?order=' . urlencode($order_number) . '&err=' . urlencode($e->getMessage()));
                exit;
            }
        }

        // COD: send emails and go to confirmation
        $order = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $order->execute([$order_id]);
        $order = $order->fetch();

        send_mail(
            $d['customer_email'],
            render_email_subject('order-confirmation-customer', $order['lang'] ?? 'bg', ['order_number' => $order_number]),
            render_email('order-confirmation-customer', ['order' => $order, 'lang' => $order['lang'] ?? 'bg'])
        );
        send_mail(
            SITE_EMAIL,
            'Нова поръчка #' . $order_number,
            render_email('order-notification-admin', [
                'order'     => $order,
                'admin_url' => SITE_URL . '/admin/order-view.php?id=' . $order_id,
            ])
        );

        header('Location: /checkout/confirmation/?order=' . urlencode($order_number));
        exit;
    }
    render:
}

$d = $_SESSION['checkout_data'] ?? [];
$page_title = $lang === 'bg' ? 'Поръчка' : 'Checkout';
$page_head_extra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/css/intlTelInput.min.css"><style>@media(max-width:640px){table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch;}.checkout-form-row{grid-template-columns:1fr!important;}}.iti{width:100%;}.iti__flag-container+input{width:100%;}</style>';
require $_SERVER['DOCUMENT_ROOT'] . '/templates/header.php';

$cart_info = load_cart_products($cart, $pdo);
$subtotal  = $cart_info['subtotal'];
?>

<!-- Progress -->
<section class="section section--grey" style="padding-bottom:1rem;">
  <div class="container">
    <h1 style="margin-bottom:1rem;"><?= $lang === 'bg' ? 'Поръчка' : 'Checkout' ?></h1>
    <div style="display:flex;gap:.5rem;font-size:.85rem;">
      <?php foreach ([1=>'Контакти',2=>'Доставка',3=>'Преглед'] as $s=>$label): ?>
      <div style="padding:.35rem .9rem;border-radius:20px;
        <?= $step === $s ? 'background:var(--teal);color:#fff;font-weight:600;' : ($step > $s ? 'background:var(--teal-light);color:var(--teal);' : 'background:var(--warm-grey);color:var(--text-muted);') ?>">
        <?= $s ?>. <?= $label ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:700px;">

    <?php foreach ($errors as $e): ?>
    <div style="padding:.9rem 1.25rem;border-radius:6px;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;margin-bottom:1.5rem;">
      <?= h($e) ?>
    </div>
    <?php endforeach; ?>

    <!-- ── STEP 1: Contact ──────────────────────────────────────────────────── -->
    <?php if ($step === 1): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="contact">
      <h2 style="margin-bottom:1.5rem;">1. Данни за контакт</h2>

      <!-- Editable cart summary -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--off-white);">
              <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Продукт</th>
              <th style="padding:.6rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Бр.</th>
              <th style="padding:.6rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Сума</th>
              <th style="padding:.6rem 1rem;width:40px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cart_info['rows'] as $row): $p = $row['product']; ?>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.65rem 1rem;font-size:.9rem;">
                <?= h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg'])) ?>
                <?php if (!empty($row['variant_label'])): ?>
                  <div style="font-size:.78rem;color:var(--text-muted);"><?= h($row['variant_label']) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding:.65rem 1rem;text-align:center;">
                <input type="number" name="quantity[<?= $row['cart_index'] ?>]"
                       value="<?= $row['quantity'] ?>" min="1"
                       data-max="<?= $p['type'] === 'variant' ? 999 : (int)$p['stock'] ?>"
                       data-price="<?= (float)$p['price_eur'] ?>"
                       oninput="onStep1QtyChange(this)"
                       style="width:60px;text-align:center;padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.875rem;">
                <?php if ($p['type'] !== 'variant'): ?>
                <div class="checkout-stock-msg" style="display:none;font-size:.75rem;color:#c0392b;font-weight:600;margin-top:.3rem;white-space:nowrap;">
                  Налични: <?= (int)$p['stock'] ?> бр.
                </div>
                <?php endif; ?>
              </td>
              <td style="padding:.65rem 1rem;text-align:right;font-weight:600;font-size:.9rem;" class="s1-line">
                <?= (float)$row['line_eur'] ?>
              </td>
              <td style="padding:.65rem 1rem;text-align:center;">
                <button type="button" onclick="checkoutRemoveItem(<?= $row['cart_index'] ?>)"
                        style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:1.1rem;padding:0;"
                        title="Премахни">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);background:var(--off-white);">
              <td colspan="3" style="padding:.65rem 1rem;text-align:right;font-weight:700;font-size:.9rem;">Сума:</td>
              <td style="padding:.65rem 1rem;text-align:right;font-weight:700;color:var(--teal);font-size:.95rem;" id="s1Subtotal">
                <?= (float)$subtotal ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="admin-form-grid">
        <label style="font-size:.875rem;font-weight:600;">Имена *
          <input type="text" name="customer_name" value="<?= h($d['customer_name'] ?? '') ?>" required
                 autocomplete="name"
                 style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;margin-top:.35rem;">
        </label>
        <label style="font-size:.875rem;font-weight:600;">Имейл *
          <input type="email" name="customer_email" value="<?= h($d['customer_email'] ?? '') ?>" required
                 autocomplete="email"
                 style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;margin-top:.35rem;">
        </label>
        <label style="font-size:.875rem;font-weight:600;"><?= $lang === 'en' ? 'Phone *' : 'Телефон *' ?>
          <div style="margin-top:.35rem;">
            <input type="tel" id="customer_phone" name="customer_phone"
                   value="<?= h($d['customer_phone'] ?? '') ?>" required autocomplete="tel"
                   style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;box-sizing:border-box;">
          </div>
          <div id="phone_error" style="color:#dc2626;font-size:.8rem;margin-top:.3rem;display:none;">
            <?= $lang === 'en' ? 'Please enter a valid phone number.' : 'Моля въведете валиден телефонен номер.' ?>
          </div>
        </label>
      </div>
      <!-- Optional invoice section (B2B) -->
      <?php
        $inv_data   = $d['invoice_data'] ?? null;
        $needs_inv  = !empty($inv_data['needs_invoice']);
        $field_style = 'padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;margin-top:.35rem;';
      ?>
      <div style="margin-top:1.5rem;">
        <label style="display:flex;align-items:flex-start;gap:.75rem;padding:1rem 1.25rem;border:2px solid <?= $needs_inv ? 'var(--teal)' : 'var(--border)' ?>;border-radius:var(--radius-lg);cursor:pointer;" id="lbl-invoice">
          <input type="checkbox" name="needs_invoice" id="needsInvoice" value="1"
                 <?= $needs_inv ? 'checked' : '' ?>
                 onchange="toggleInvoiceSection(this.checked)"
                 style="width:1.1rem;height:1.1rem;accent-color:var(--teal);margin-top:.15rem;flex-shrink:0;">
          <div>
            <div style="font-weight:600;">Нуждая се от фактура</div>
            <div style="font-size:.85rem;color:var(--text-muted);">За юридически лица с ЕИК — попълнете данните на фирмата</div>
          </div>
        </label>
        <div id="invoiceSection" style="display:<?= $needs_inv ? '' : 'none' ?>;padding:1.25rem;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius-lg) var(--radius-lg);background:var(--off-white);">
          <div class="admin-form-grid">
            <label style="font-size:.875rem;font-weight:600;">Наименование на фирмата *
              <input type="text" name="invoice_company" value="<?= h($inv_data['company_name'] ?? '') ?>"
                     style="<?= $field_style ?>">
            </label>
            <label style="font-size:.875rem;font-weight:600;">МОЛ (отговорно лице) *
              <input type="text" name="invoice_mol" value="<?= h($inv_data['mol'] ?? '') ?>"
                     style="<?= $field_style ?>">
            </label>
            <label style="font-size:.875rem;font-weight:600;">ЕИК / Булстат *
              <input type="text" name="invoice_eik" value="<?= h($inv_data['eik'] ?? '') ?>"
                     style="<?= $field_style ?>">
            </label>
            <label style="font-size:.875rem;font-weight:600;">ДДС номер <span style="font-weight:400;color:var(--text-muted);">(ако сте регистрирани)</span>
              <input type="text" name="invoice_vat" value="<?= h($inv_data['vat_number'] ?? '') ?>"
                     style="<?= $field_style ?>">
            </label>
            <label style="font-size:.875rem;font-weight:600;grid-column:span 2;">Адрес на фирмата *
              <input type="text" name="invoice_address" value="<?= h($inv_data['company_address'] ?? '') ?>"
                     style="<?= $field_style ?>">
            </label>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn--primary" style="margin-top:1.5rem;">
        Продължи към доставка →
      </button>
    </form>
    <script>
    function toggleInvoiceSection(show) {
        document.getElementById('invoiceSection').style.display = show ? '' : 'none';
        document.getElementById('lbl-invoice').style.borderColor = show ? 'var(--teal)' : 'var(--border)';
    }
    </script>
    <!-- Hidden remove form (shared by both steps) -->
    <form id="checkoutRemoveForm" method="POST" action="/cart/remove.php" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" id="checkoutRemoveIndex" name="cart_index" value="">
    </form>
    <script>
    function checkoutRemoveItem(cartIndex) {
        document.getElementById('checkoutRemoveIndex').value = cartIndex;
        document.getElementById('checkoutRemoveForm').submit();
    }
    function onStep1QtyChange(inp) {
        var val = parseInt(inp.value, 10) || 1;
        var max = parseInt(inp.dataset.max, 10);
        var msg = inp.parentNode.querySelector('.checkout-stock-msg');
        if (max && val > max) {
            inp.style.borderColor = '#c0392b';
            if (msg) msg.style.display = '';
            return;
        }
        inp.style.borderColor = '';
        if (msg) msg.style.display = 'none';
        var qty   = Math.max(1, val);
        inp.value = qty;
        var price = parseFloat(inp.dataset.price);
        var lineCells = inp.closest('tr').querySelectorAll('.s1-line');
        if (lineCells.length) lineCells[0].textContent = (qty * price).toFixed(2) + ' €';
        var sub = 0;
        document.querySelectorAll('input[name^="quantity["]').forEach(function(i) {
            sub += parseFloat(i.dataset.price) * (parseInt(i.value) || 1);
        });
        document.getElementById('s1Subtotal').textContent = sub.toFixed(2) + ' €';
    }
    // Format initial values
    document.querySelectorAll('.s1-line').forEach(function(td) {
        td.textContent = parseFloat(td.textContent).toFixed(2) + ' €';
    });
    document.getElementById('s1Subtotal').textContent =
        parseFloat(document.getElementById('s1Subtotal').textContent).toFixed(2) + ' €';
    </script>

    <!-- ── STEP 2: Delivery ─────────────────────────────────────────────────── -->
    <?php elseif ($step === 2): ?>
    <form method="POST" id="deliveryForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delivery">
      <input type="hidden" name="shipping_eur" id="shippingInput" value="0">

      <h2 style="margin-bottom:1.5rem;">2. Доставка</h2>

      <!-- Courier selection -->
      <fieldset style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;">
        <legend style="font-weight:600;padding:0 .5rem;">Куриер</legend>
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:.5rem;">
          <?php foreach (['speedy'=>'Speedy','boxnow'=>'BoxNow'] as $c=>$cl): ?>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
            <input type="radio" name="courier" value="<?= $c ?>"
                   <?= ($d['courier'] ?? '') === $c ? 'checked' : '' ?>
                   onchange="updateCourier(this.value)">
            <strong><?= $cl ?></strong>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <!-- Delivery type — shown/hidden by JS -->
      <fieldset id="typeSection" style="border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;display:none;">
        <legend style="font-weight:600;padding:0 .5rem;">Начин на доставка</legend>
        <div id="typeOptions" style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:.5rem;"></div>
      </fieldset>

      <!-- Office selection (Econt / Speedy) — autocomplete city then office dropdown -->
      <div id="officeSection" style="display:none;margin-bottom:1.5rem;">
        <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.4rem;">Град</label>
        <div style="position:relative;margin-bottom:1rem;">
          <input type="text" id="cityInput" placeholder="Въведете град..." autocomplete="off"
                 oninput="onCityInput(this.value)"
                 style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;box-sizing:border-box;">
          <div id="citySuggestions"
               style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;background:#fff;border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);">
          </div>
        </div>

        <div id="officeWrap" style="display:none;">
          <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.4rem;">Офис</label>
          <input type="text" id="officeSearch" placeholder="Търси офис по адрес или квартал…"
                 oninput="filterOffices(this.value)"
                 style="width:100%;padding:.45rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;box-sizing:border-box;margin-bottom:.4rem;">
          <div id="officeCitySuggest" style="display:none;margin-bottom:.4rem;font-size:.8rem;color:var(--text-muted);"></div>
          <select id="officeSelect" size="8" onchange="selectOffice(this)"
                  style="width:100%;padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;height:200px;overflow-y:auto;">
          </select>
          <div id="officeSelected" style="margin-top:.5rem;font-size:.875rem;color:var(--teal);font-weight:600;"></div>
        </div>
        <div id="officeLoading" style="display:none;font-size:.875rem;color:var(--text-muted);">Зарежда офиси...</div>

        <input type="hidden" name="courier_office_code" id="officeCode" value="<?= h($d['courier_office_code'] ?? '') ?>">
        <input type="hidden" name="courier_office_name" id="officeName" value="<?= h($d['courier_office_name'] ?? '') ?>">
        <input type="hidden" name="courier_office_city" id="savedCity" value="<?= h($d['courier_office_city'] ?? '') ?>">
      </div>

      <!-- BoxNow locker picker (official widget) -->
      <div id="boxnowSection" style="display:none;margin-bottom:1.5rem;">
        <label style="font-size:.875rem;font-weight:600;display:block;margin-bottom:.5rem;">Изберете автомат BoxNow</label>
        <button type="button" id="boxnowPickerBtn" onclick="openBoxnowWidget()"
                style="padding:.55rem 1.25rem;background:#6CD04E;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:600;">
          📦 Изберете автомат от картата
        </button>
        <div id="boxnowSelected" style="display:none;margin-top:.75rem;padding:.75rem 1rem;border-radius:6px;font-size:.875rem;
             background:#f0ffeb;border:2px solid #6CD04E;">
        </div>
        <input type="hidden" id="boxnowCode" value="<?= h(($d['courier'] ?? '') === 'boxnow' ? ($d['courier_office_code'] ?? '') : '') ?>">
        <input type="hidden" id="boxnowName" value="<?= h(($d['courier'] ?? '') === 'boxnow' ? ($d['courier_office_name'] ?? '') : '') ?>">
      </div>

      <!-- Address fields -->
      <div id="addressSection" style="display:none;margin-bottom:1.5rem;">
        <div class="admin-form-grid">
          <label style="font-size:.875rem;font-weight:600;">Адрес *
            <input type="text" name="delivery_address" value="<?= h($d['delivery_address'] ?? '') ?>"
                   autocomplete="street-address"
                   style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;margin-top:.35rem;">
          </label>
          <label style="font-size:.875rem;font-weight:600;">Град *
            <input type="text" name="delivery_city" id="deliveryCityInput" value="<?= h($d['delivery_city'] ?? '') ?>"
                   autocomplete="address-level2"
                   onchange="calculateShipping(this.value.trim())"
                   style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;width:100%;box-sizing:border-box;margin-top:.35rem;">
          </label>
        </div>
      </div>

      <!-- Shipping cost display -->
      <div id="shippingDisplay" style="display:none;padding:1rem 1.25rem;background:var(--teal-light);border-radius:var(--radius-lg);margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;">
          <span>Цена за доставка:</span>
          <strong id="shippingCost" style="color:var(--teal);">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.5rem;font-weight:700;">
          <span>Общо:</span>
          <strong id="totalCost" style="color:var(--teal);font-size:1.1rem;">—</strong>
        </div>
      </div>

      <input type="hidden" name="delivery_type" id="deliveryTypeInput">

      <button type="submit" class="btn btn--primary">
        <?= !empty($d['_pledge_number']) ? 'Продължи към плащане →' : 'Продължи към преглед →' ?>
      </button>
      <?php if (empty($d['_pledge_number'])): ?>
      <a href="/checkout/?step=1" style="margin-left:1rem;font-size:.9rem;color:var(--text-muted);">← Назад</a>
      <?php endif; ?>
    </form>

    <!-- ── STEP 3: Review ───────────────────────────────────────────────────── -->
    <?php elseif ($step === 3):
      $courier_labels = ['econt'=>'Econt','speedy'=>'Speedy','boxnow'=>'BoxNow'];
      $type_labels    = ['office'=>'До офис','apt'=>'До автомат','address'=>'До врата','locker'=>'До автомат (BoxNow)'];
      $shipping = (float)($d['shipping_eur'] ?? 0);
      $total    = $subtotal + $shipping;
    ?>

    <?php
      require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
      require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
      // Build the list of available payment methods (order = display order).
      $pay_methods = [];
      if (DSKBankPayment::isEnabled()) $pay_methods['card'] = ['💳', 'Плащане с карта', 'Visa / Mastercard през DSK Bank'];
      if (IRISPayment::isEnabled())    $pay_methods['iris'] = ['🏦', 'Банков превод (Pay by Bank)', 'Директно от сметката ви през IRIS'];
      $pay_default = array_key_first($pay_methods);
    ?>

    <form method="POST" id="confirmForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <input type="hidden" name="donation_amount" id="donationAmountHidden" value="0">

      <h2 style="margin-bottom:1.5rem;">3. Преглед и потвърждение</h2>

      <!-- Order summary with editable quantities -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1.5rem;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--off-white);">
              <th style="padding:.65rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Продукт</th>
              <th style="padding:.65rem 1rem;text-align:center;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Бр.</th>
              <th style="padding:.65rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;color:var(--text-muted);">Сума</th>
              <th style="padding:.65rem 1rem;width:40px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cart_info['rows'] as $row): $p = $row['product']; ?>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:.75rem 1rem;">
                <?= h($lang === 'bg' ? $p['name_bg'] : ($p['name_en'] ?: $p['name_bg'])) ?>
                <?php if (!empty($row['variant_label'])): ?>
                  <div style="font-size:.78rem;color:var(--text-muted);"><?= h($row['variant_label']) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding:.75rem 1rem;text-align:center;">
                <input type="number" name="quantity[<?= $row['cart_index'] ?>]"
                       value="<?= $row['quantity'] ?>" min="1"
                       data-max="<?= $p['type'] === 'variant' ? 999 : (int)$p['stock'] ?>"
                       data-price="<?= (float)$p['price_eur'] ?>"
                       oninput="onStep3QtyChange(this)"
                       style="width:60px;text-align:center;padding:.3rem .4rem;border:1px solid var(--border);border-radius:4px;font-size:.875rem;">
                <?php if ($p['type'] !== 'variant'): ?>
                <div class="checkout-stock-msg" style="display:none;font-size:.75rem;color:#c0392b;font-weight:600;margin-top:.3rem;white-space:nowrap;">
                  Налични: <?= (int)$p['stock'] ?> бр.
                </div>
                <?php endif; ?>
              </td>
              <td style="padding:.75rem 1rem;text-align:right;font-weight:600;" class="s3-line">
                <?= (float)$row['line_eur'] ?>
              </td>
              <td style="padding:.75rem 1rem;text-align:center;">
                <button type="button" onclick="checkoutRemoveItem(<?= $row['cart_index'] ?>)"
                        style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:1.1rem;padding:0;"
                        title="Премахни">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
            <tr style="border-top:1px solid var(--border);">
              <td colspan="3" style="padding:.75rem 1rem;text-align:right;color:var(--text-muted);">Доставка (<?= h($courier_labels[$d['courier']] ?? '') ?> – <?= h($type_labels[$d['delivery_type']] ?? '') ?>):</td>
              <td style="padding:.75rem 1rem;text-align:right;"><?= price_html($shipping) ?></td>
            </tr>
            <tr id="donationRow" style="border-top:1px solid var(--border);display:none;">
              <td colspan="3" style="padding:.75rem 1rem;text-align:right;color:var(--text-muted);">Дарение:</td>
              <td style="padding:.75rem 1rem;text-align:right;" id="donationRowAmount"></td>
            </tr>
            <tr style="border-top:2px solid var(--border);background:var(--off-white);">
              <td colspan="3" style="padding:.75rem 1rem;text-align:right;font-weight:700;">Общо:</td>
              <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:var(--teal);font-size:1.1rem;" id="summaryTotal">
                <?= (float)$total ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Contact + delivery summary -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;" class="checkout-form-row">
        <div style="padding:1rem;background:var(--off-white);border-radius:var(--radius-lg);">
          <strong style="display:block;margin-bottom:.5rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Контакти</strong>
          <?= h($d['customer_name']) ?><br>
          <?= h($d['customer_email']) ?><br>
          <?= h($d['customer_phone']) ?>
        </div>
        <div style="padding:1rem;background:var(--off-white);border-radius:var(--radius-lg);">
          <strong style="display:block;margin-bottom:.5rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Доставка</strong>
          <?= h($courier_labels[$d['courier']] ?? '') ?> — <?= h($type_labels[$d['delivery_type']] ?? '') ?><br>
          <?php if ($d['delivery_type'] === 'address'): ?>
            <?= h(($d['delivery_address'] ?? '') . ', ' . ($d['delivery_city'] ?? '')) ?>
          <?php else: ?>
            <?= h($d['courier_office_name'] ?? '') ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Donation add-on -->
      <div style="margin-bottom:1.5rem;">
        <div style="font-size:.85rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.75rem;">Добави дарение (по избор)</div>
        <label style="display:flex;align-items:center;gap:.75rem;padding:1rem 1.25rem;border:2px solid var(--border);border-radius:var(--radius-lg);cursor:pointer;" id="lbl-donation">
          <input type="checkbox" id="donationToggle" onchange="toggleDonation(this.checked)"
                 style="width:1.1rem;height:1.1rem;accent-color:var(--teal);">
          <div>
            <div style="font-weight:600;">Искам да добавя дарение към поръчката</div>
            <div style="font-size:.85rem;color:var(--text-muted);">Сумата ще бъде добавена към общата стойност</div>
          </div>
        </label>
        <div id="donationPanel" style="display:none;padding:1.25rem;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius-lg) var(--radius-lg);background:var(--off-white);">
          <div>
            <div style="font-size:.875rem;font-weight:600;margin-bottom:.5rem;">Сума</div>
            <input type="number" id="donationAmountInput" min="1" step="1" placeholder="Въведете сума (€)"
                   oninput="updateDonationSummary()"
                   style="width:220px;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;">
          </div>
        </div>
      </div>

      <!-- Payment method -->
      <fieldset style="border:none;padding:0;margin:0 0 1.5rem;">
        <legend style="font-size:.85rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.75rem;padding:0;">Начин на плащане</legend>
        <?php if (empty($pay_methods)): ?>
        <p style="padding:1rem 1.25rem;border:2px solid var(--border);border-radius:var(--radius-lg);color:#c0392b;">
          Онлайн плащането не е налично в момента. Моля свържете се с нас.
        </p>
        <?php endif; ?>
        <?php foreach ($pay_methods as $pm => $info): ?>
        <label style="display:flex;align-items:center;gap:.75rem;padding:1rem 1.25rem;border:2px solid var(--border);border-radius:var(--radius-lg);cursor:pointer;margin-bottom:.6rem;">
          <input type="radio" name="payment_method" value="<?= $pm ?>" <?= $pm === $pay_default ? 'checked' : '' ?>
                 style="width:1.1rem;height:1.1rem;accent-color:var(--teal);">
          <span aria-hidden="true" style="font-size:1.3rem;line-height:1;"><?= $info[0] ?></span>
          <span>
            <span style="display:block;font-weight:600;"><?= h($info[1]) ?></span>
            <span style="display:block;font-size:.85rem;color:var(--text-muted);"><?= h($info[2]) ?></span>
          </span>
        </label>
        <?php endforeach; ?>
      </fieldset>

      <div style="display:flex;gap:1rem;align-items:center;">
        <button type="submit" class="btn btn--primary" style="padding:.9rem 2rem;font-size:1rem;">
          Потвърди поръчката →
        </button>
        <a href="/checkout/?step=2" style="font-size:.9rem;color:var(--text-muted);">← Промени доставката</a>
      </div>
    </form>

    <script>
    const s3Shipping = <?= json_encode($shipping) ?>;

    // Format initial raw-float cell values on page load
    document.querySelectorAll('.s3-line').forEach(function(td) {
        td.textContent = parseFloat(td.textContent).toFixed(2) + ' €';
    });
    document.getElementById('summaryTotal').textContent =
        parseFloat(document.getElementById('summaryTotal').textContent).toFixed(2) + ' €';

    function getS3Subtotal() {
        var sum = 0;
        document.querySelectorAll('input[name^="quantity["]').forEach(function(inp) {
            sum += parseFloat(inp.dataset.price) * (parseInt(inp.value) || 1);
        });
        return sum;
    }

    function onStep3QtyChange(inp) {
        var val = parseInt(inp.value, 10) || 1;
        var max = parseInt(inp.dataset.max, 10);
        var msg = inp.parentNode.querySelector('.checkout-stock-msg');
        if (max && val > max) {
            inp.style.borderColor = '#c0392b';
            if (msg) msg.style.display = '';
            return;
        }
        inp.style.borderColor = '';
        if (msg) msg.style.display = 'none';
        var qty   = Math.max(1, val);
        inp.value = qty;
        var price = parseFloat(inp.dataset.price);
        inp.closest('tr').querySelector('.s3-line').textContent = (qty * price).toFixed(2) + ' €';
        var donation = parseFloat(document.getElementById('donationAmountHidden').value) || 0;
        updateTotalDisplay(donation);
    }

    function toggleDonation(checked) {
        document.getElementById('donationPanel').style.display = checked ? '' : 'none';
        document.getElementById('lbl-donation').style.borderColor = checked ? 'var(--teal)' : 'var(--border)';
        if (!checked) {
            document.getElementById('donationAmountInput').value = '';
            document.getElementById('donationAmountHidden').value = '0';
            document.getElementById('donationRow').style.display = 'none';
            updateTotalDisplay(0);
        } else {
            updateDonationSummary();
        }
    }

    function updateDonationSummary() {
        const amount = parseFloat(document.getElementById('donationAmountInput').value) || 0;
        if (amount >= 1) {
            document.getElementById('donationAmountHidden').value = amount.toFixed(2);
            document.getElementById('donationRowAmount').textContent = amount.toFixed(2) + ' €';
            document.getElementById('donationRow').style.display = '';
            updateTotalDisplay(amount);
        } else {
            document.getElementById('donationAmountHidden').value = '0';
            document.getElementById('donationRow').style.display = 'none';
            updateTotalDisplay(0);
        }
    }

    function updateTotalDisplay(donationAmount) {
        const total = getS3Subtotal() + s3Shipping + donationAmount;
        document.getElementById('summaryTotal').textContent = total.toFixed(2) + ' €';
    }
    </script>
    <?php endif; ?>

  </div>
</section>

<?php
// Resolve BOXNOW_PARTNER_ID from DB settings or constant (same pattern as BoxNowCourier)
$boxnow_partner_id = function_exists('setting_resolve')
    ? setting_resolve('boxnow_partner_id', 'BOXNOW_PARTNER_ID')
    : (defined('BOXNOW_PARTNER_ID') ? BOXNOW_PARTNER_ID : '');
require $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php';
?>

<script src="/assets/js/boxnow-widget.js"></script>
<script>
const rates    = <?= json_encode($rates) ?>;
const subtotal = <?= json_encode($subtotal) ?>;
let currentCourier = '<?= h($d['courier'] ?? '') ?>';
let currentType    = '<?= h($d['delivery_type'] ?? '') ?>';

const typeOptions = {
    speedy: [{value:'address',label:'До врата'},{value:'office',label:'До офис'},{value:'apt',label:'До автомат'}],
    boxnow: [{value:'locker',label:'До автомат (BoxNow)'}],
};

// ── BoxNow widget ─────────────────────────────────────────────────────────────
const boxnowPartnerId = <?= json_encode($boxnow_partner_id) ?>;

function openBoxnowWidget() {
    BoxNowWidget.open(boxnowPartnerId, function (locker) {
        const name = [locker.name, locker.address, locker.postalCode].filter(Boolean).join(' — ');

        document.getElementById('boxnowCode').value = locker.id;
        document.getElementById('boxnowName').value = name;
        document.getElementById('officeCode').value = locker.id;
        document.getElementById('officeName').value = name;

        const info = document.getElementById('boxnowSelected');
        info.innerHTML = '<strong style="color:#3a8a2e;">✓ Избран автомат:</strong><br>' +
            escHtml(locker.name) + (locker.address ? '<br><span style="color:#555;">' + escHtml(locker.address) + '</span>' : '');
        info.style.display = '';
    });
}

function escHtml(str) {
    return (str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Courier / type selection ──────────────────────────────────────────────────

function updateCourier(courier) {
    currentCourier = courier;
    const opts = typeOptions[courier] || [];
    const section = document.getElementById('typeSection');
    const optsDiv = document.getElementById('typeOptions');
    optsDiv.innerHTML = '';
    opts.forEach((o, i) => {
        const checked = (currentType === o.value || (i === 0 && !currentType)) ? 'checked' : '';
        optsDiv.innerHTML += `<label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
            <input type="radio" name="delivery_type_radio" value="${o.value}" ${checked} onchange="updateType(this.value)">
            <span>${o.label}</span></label>`;
    });
    section.style.display = '';
    if (opts.length === 1) currentType = opts[0].value;
    updateType(currentType || opts[0]?.value);
    if (courier !== 'boxnow') loadCities(courier);
}

function updateType(dtype) {
    const prev = currentType;
    currentType = dtype;
    document.getElementById('deliveryTypeInput').value = dtype;
    document.getElementById('officeSection').style.display  = (dtype === 'office' || dtype === 'apt') ? '' : 'none';
    document.getElementById('boxnowSection').style.display  = (dtype === 'locker') ? '' : 'none';
    document.getElementById('addressSection').style.display = (dtype === 'address') ? '' : 'none';
    // Reload office list when switching between office/apt so the type filter applies
    const savedCity = document.getElementById('savedCity').value;
    if (savedCity && (dtype === 'office' || dtype === 'apt') && (prev === 'office' || prev === 'apt') && prev !== dtype) {
        loadOffices(savedCity, null);
    }
    updateShipping();
}

function updateShipping() {
    // Price is only shown after city/office is selected (via calculateShipping)
    // BoxNow uses a flat rate from DB; door delivery shows price after city is entered
    if (currentCourier === 'boxnow') {
        const r = rates['boxnow']?.['locker'];
        if (r !== undefined) showShippingPrice(r);
    } else {
        hideShippingPrice();
    }
}

function hideShippingPrice() {
    document.getElementById('shippingDisplay').style.display = 'none';
    document.getElementById('shippingInput').value = '0';
}

function showShippingPrice(price) {
    document.getElementById('shippingInput').value = price;
    document.getElementById('shippingCost').textContent = price.toFixed(2) + ' €';
    document.getElementById('totalCost').textContent    = (subtotal + price).toFixed(2) + ' €';
    document.getElementById('shippingDisplay').style.display = '';
}

async function calculateShipping(city) {
    if (!city || !currentCourier || currentCourier === 'boxnow') return;
    try {
        const officeId = document.getElementById('officeCode').value;
        let url = `/api/calculate.php?courier=${encodeURIComponent(currentCourier)}&delivery_type=${encodeURIComponent(currentType)}&city=${encodeURIComponent(city)}`;
        if (officeId) url += `&office_id=${encodeURIComponent(officeId)}`;
        const res  = await fetch(url);
        const data = await res.json();
        if (data.price !== undefined) {
            showShippingPrice(data.price);
        }
    } catch(e) { /* leave price hidden on error */ }
}

let _citiesCache  = {};  // courier → array of city name strings
let _cityFetching = {};  // courier → promise (deduplicate concurrent fetches)
let _allOffices   = [];  // full office list for current city/type (used by filterOffices)
let _cityDebounce = null;

async function loadCities(courier) {
    document.getElementById('citySuggestions').style.display = 'none';

    if (!_citiesCache[courier]) {
        if (!_cityFetching[courier]) {
            _cityFetching[courier] = fetch(`/api/cities.php?courier=${encodeURIComponent(courier)}`)
                .then(r => r.json())
                .then(data => { _citiesCache[courier] = data; });
        }
        await _cityFetching[courier];
    }

    // Only restore saved city if the user hasn't already typed something
    const cityInput = document.getElementById('cityInput');
    const savedCity = document.getElementById('savedCity').value;
    if (savedCity && cityInput.value === '') {
        cityInput.value = savedCity;
        document.getElementById('officeWrap').style.display = 'none';
        await loadOffices(savedCity, document.getElementById('officeCode').value);
    }
}

function clearOfficeSelection() {
    document.getElementById('officeWrap').style.display = 'none';
    document.getElementById('officeSelected').textContent = '';
    document.getElementById('officeCode').value = '';
    document.getElementById('officeName').value = '';
    document.getElementById('officeSearch').value = '';
    document.getElementById('savedCity').value = '';
    _allOffices = [];
    hideShippingPrice();
}

function renderCitySuggestions(cities, qLower) {
    const suggestions = document.getElementById('citySuggestions');
    suggestions.innerHTML = '';
    cities.forEach(city => {
        const div = document.createElement('div');
        div.textContent = city;
        div.style.cssText = 'padding:.45rem .75rem;cursor:pointer;font-size:.9rem;border-bottom:1px solid #f0f0f0;';
        div.onmousedown = () => selectCity(city);
        div.onmouseover = () => div.style.background = 'var(--teal-light, #e8f5f3)';
        div.onmouseout  = () => div.style.background = '';
        suggestions.appendChild(div);
    });
    suggestions.style.display = cities.length ? '' : 'none';

    const exact = cities.find(c => c.toLowerCase() === qLower);
    if (exact) {
        _cityDebounce = setTimeout(() => selectCity(exact), 400);
    }
}

function onCityInput(val) {
    const q      = val.trim();
    const qLower = q.toLowerCase();

    clearTimeout(_cityDebounce);
    document.getElementById('citySuggestions').style.display = 'none';

    if (!q) {
        clearOfficeSelection();
        return;
    }

    // Always clear stale offices the moment the city text changes
    if (document.getElementById('savedCity').value.toLowerCase() !== qLower) {
        clearOfficeSelection();
    }

    if (q.length < 2) return;

    // For Speedy, the bulk city list only returns ~10 test cities — always use live search.
    // For other couriers, filter the cached list.
    if (currentCourier === 'speedy') {
        _cityDebounce = setTimeout(async () => {
            try {
                const res  = await fetch(`/api/cities.php?courier=speedy&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                // Check the input hasn't changed while we were fetching
                if (document.getElementById('cityInput').value.trim() !== q) return;
                renderCitySuggestions(data, qLower);
            } catch(e) { /* ignore */ }
        }, 300);
    } else {
        const cities  = _citiesCache[currentCourier] || [];
        const matches = cities.filter(c => c.toLowerCase().includes(qLower)).slice(0, 50);
        renderCitySuggestions(matches, qLower);
    }
}

async function selectCity(city) {
    document.getElementById('cityInput').value = city;
    document.getElementById('citySuggestions').style.display = 'none';
    document.getElementById('savedCity').value = city;
    await loadOffices(city, null);
}

async function loadOffices(city, restoreCode) {
    const loading = document.getElementById('officeLoading');
    const wrap    = document.getElementById('officeWrap');
    loading.textContent   = 'Зарежда офиси...';
    loading.style.display = '';
    wrap.style.display    = 'none';
    document.getElementById('officeSearch').value = '';
    document.getElementById('officeCitySuggest').style.display = 'none';
    _allOffices = [];
    try {
        const res = await fetch(`/api/offices.php?courier=${encodeURIComponent(currentCourier)}&city=${encodeURIComponent(city)}`);
        const all = await res.json();
        // Filter by type: 'apt' → APT machines, 'office' → regular offices
        _allOffices = (currentType === 'office' || currentType === 'apt')
            ? all.filter(o => o.type === currentType)
            : all;
        if (!_allOffices.length) {
            loading.textContent = 'Няма намерени офиси за този град.';
            return;
        }
        renderOfficeOptions(_allOffices, restoreCode);
        loading.style.display = 'none';
        wrap.style.display    = '';
    } catch(e) {
        loading.textContent = 'Грешка при зареждане на офиси. Опитайте отново.';
    }
}

function filterOffices(q) {
    const lower    = q.trim().toLowerCase();
    const filtered = lower
        ? _allOffices.filter(o =>
            o.name.toLowerCase().includes(lower) ||
            (o.address || '').toLowerCase().includes(lower))
        : _allOffices;
    renderOfficeOptions(filtered, null);

    // If the query matches a city name (other than the current one), offer a quick-switch link.
    // Use live API search so villages not in the bulk list (e.g. Лозен) are also found.
    const citySuggest = document.getElementById('officeCitySuggest');
    if (!lower || lower.length < 2) { citySuggest.style.display = 'none'; return; }
    if (currentCourier !== 'speedy') { citySuggest.style.display = 'none'; return; }
    const current = (document.getElementById('savedCity').value || '').toLowerCase();
    fetch(`/api/cities.php?courier=speedy&q=${encodeURIComponent(q.trim())}`)
        .then(r => r.json())
        .then(cities => {
            const matches = cities.filter(c => c.toLowerCase() !== current).slice(0, 4);
            if (!matches.length) { citySuggest.style.display = 'none'; return; }
            citySuggest.innerHTML = 'Търси в друг град: ' + matches.map(c =>
                `<a href="#" style="margin-left:.4rem;color:var(--teal);text-decoration:underline;"
                    onmousedown="event.preventDefault();switchCityFromOfficeSearch('${c.replace(/'/g, "\\'")}')">${c}</a>`
            ).join('');
            citySuggest.style.display = '';
        })
        .catch(() => { citySuggest.style.display = 'none'; });
}

async function switchCityFromOfficeSearch(city) {
    document.getElementById('officeSearch').value = '';
    document.getElementById('officeCitySuggest').style.display = 'none';
    document.getElementById('cityInput').value = city;
    await selectCity(city);
}

function renderOfficeOptions(data, restoreCode) {
    const select = document.getElementById('officeSelect');
    select.innerHTML = '';
    data.forEach(o => {
        const opt = document.createElement('option');
        opt.value        = o.code;
        opt.dataset.name = o.name;
        opt.textContent  = o.name;
        select.appendChild(opt);
    });
    if (restoreCode) select.value = restoreCode;
    if (!select.value && select.options.length) select.selectedIndex = 0;
    if (select.options.length) selectOffice(select);
}

function selectOffice(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    document.getElementById('officeCode').value = opt.value;
    document.getElementById('officeName').value = opt.dataset.name;
    document.getElementById('officeSelected').textContent = '✓ ' + opt.dataset.name;
    calculateShipping(document.getElementById('savedCity').value);
}

// Hide suggestions when clicking outside
document.addEventListener('click', e => {
    if (!e.target.closest('#officeSection')) {
        document.getElementById('citySuggestions').style.display = 'none';
    }
});

// Init on page load
if (currentCourier) {
    updateCourier(currentCourier);
    if (currentType) updateType(currentType);
}

// Restore BoxNow label if returning to step 2
const savedBoxnowName = document.getElementById('boxnowName')?.value;
if (savedBoxnowName) {
    const info = document.getElementById('boxnowSelected');
    if (info) { info.innerHTML = '<strong style="color:#3a8a2e;">✓ Избран автомат:</strong><br>' + escHtml(savedBoxnowName); info.style.display = ''; }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/js/intlTelInput.min.js"></script>
<script>
(function () {
    const input = document.getElementById('customer_phone');
    if (!input) return;
    const errEl = document.getElementById('phone_error');

    const iti = window.intlTelInput(input, {
        initialCountry: <?= json_encode($lang === 'en' ? 'auto' : 'bg') ?>,
        separateDialCode: true,
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/js/utils.js',
        <?php if ($lang === 'en'): ?>
        geoIpLookup: function(cb) {
            fetch('https://ipapi.co/json').then(r => r.json()).then(d => cb(d.country_code)).catch(() => cb('us'));
        },
        <?php endif; ?>
    });

    function showError() {
        input.style.borderColor = '#dc2626';
        if (errEl) errEl.style.display = '';
    }
    function clearError() {
        input.style.borderColor = '#d1d5db';
        if (errEl) errEl.style.display = 'none';
    }
    function markValid() {
        input.style.borderColor = '#1b998b';
        if (errEl) errEl.style.display = 'none';
    }

    input.addEventListener('blur', function () {
        if (input.value.trim() === '') { clearError(); return; }
        iti.isValidNumber() ? markValid() : showError();
    });
    input.addEventListener('input', clearError);

    // Block form submission if number is invalid
    const form = input.closest('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (input.value.trim() !== '' && !iti.isValidNumber()) {
                e.preventDefault();
                showError();
                input.focus();
            }
        });
    }
})();
</script>
