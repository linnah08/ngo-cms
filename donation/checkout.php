<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
start_session();

$shop_url = ($_POST['_lang'] ?? 'bg') === 'en' ? '/en/shop/' : '/magazin/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $shop_url);
    exit;
}

if (!csrf_verify()) {
    flash_set('error', 'Невалидна заявка.');
    header('Location: ' . $shop_url);
    exit;
}

$amount    = (float) ($_POST['amount']            ?? 0);
$name      = trim($_POST['donor_name']           ?? '');
$email     = trim($_POST['donor_email']          ?? '');
$message   = trim($_POST['donation_message']     ?? '');
$donor_type = in_array($_POST['donor_type'] ?? '', ['individual', 'company'], true)
    ? $_POST['donor_type']
    : 'individual';

$errors = [];
if ($amount < 1)                                $errors[] = 'Сумата трябва да е поне 1 €.';
if ($amount > 50000)                            $errors[] = 'Максималната сума за онлайн дарение е 50 000 €.';
if (!$name)                                     $errors[] = 'Моля въведете вашите имена.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Невалиден имейл адрес.';

if (!DSKBankPayment::isEnabled()) {
    flash_set('error', 'Онлайн плащането не е налично в момента. Моля свържете се с нас.');
    header('Location: ' . $shop_url . '#donation');
    exit;
}

// Build invoice_data for donation certificate
$invoice_data = ['donor_type' => $donor_type];
if ($donor_type === 'company') {
    $company   = trim($_POST['invoice_company']  ?? '');
    $mol       = trim($_POST['invoice_mol']      ?? '');
    $eik       = trim($_POST['invoice_eik']      ?? '');
    $vat       = trim($_POST['invoice_vat']      ?? '');
    $c_address = trim($_POST['invoice_address']  ?? '');
    if (!$company) $errors[] = 'Въведете наименование на фирмата.';
    if (!$eik)     $errors[] = 'Въведете ЕИК / Булстат.';
    $invoice_data += [
        'company_name'    => $company,
        'mol'             => $mol,
        'eik'             => $eik,
        'vat_number'      => $vat ?: null,
        'company_address' => $c_address,
    ];
}

if ($errors) {
    foreach ($errors as $e) flash_set('error', $e);
    header('Location: ' . $shop_url . '#donation');
    exit;
}

$pdo          = get_pdo();
$order_number = generate_order_number();

$items_json = json_encode([
    ['type' => 'donation', 'amount_eur' => $amount]
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("
    INSERT INTO orders
      (order_number,type,status,customer_name,customer_email,items,
       subtotal_eur,shipping_eur,total_eur,donation_message,
       payment_method,payment_status,invoice_data)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    $order_number, 'donation', 'new',
    $name, $email, $items_json,
    $amount, 0, $amount,
    $message ?: null,
    'card', 'pending',
    json_encode($invoice_data, JSON_UNESCAPED_UNICODE),
]);
$order_id = $pdo->lastInsertId();

// Register with DSK Bank and redirect to payment page
try {
    $dsk       = new DSKBankPayment();
    $ref       = $order_number . '_' . time();
    $returnUrl = SITE_URL . '/api/payment-return.php?order=' . urlencode($order_number);
    $result    = $dsk->register($ref, $amount, $returnUrl);

    $pdo->prepare('UPDATE orders SET dsk_order_id = ? WHERE id = ?')
        ->execute([$result['dsk_order_id'], $order_id]);

    header('Location: ' . $result['formUrl']);
    exit;
} catch (Throwable $e) {
    error_log('donation checkout DSK register: ' . $e->getMessage());
    header('Location: /checkout/payment-failed/?order=' . urlencode($order_number) . '&err=' . urlencode($e->getMessage()));
    exit;
}
