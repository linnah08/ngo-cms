<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/payment_errors.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
start_session();

// Donations switched off in Admin → Организация → Модули — nothing to post to.
if (!feature_enabled('donations')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/errors/404.php';
    exit;
}

// This URL has no /en/ prefix, so the form says which language it came from.
$order_lang = post_lang();
$form_url   = donation_path('form', $order_lang);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $form_url);
    exit;
}

if (!csrf_verify()) {
    donation_form_fail([t_or('donation.err.csrf', 'Формата изтече. Моля, опитайте отново.', 'The form expired. Please try again.', $order_lang)], $_POST);
    header('Location: ' . $form_url);
    exit;
}

$amount    = (float) ($_POST['amount']            ?? 0);
$name      = trim($_POST['donor_name']           ?? '');
$email     = trim($_POST['donor_email']          ?? '');
$message   = trim($_POST['donation_message']     ?? '');
$donor_type = in_array($_POST['donor_type'] ?? '', ['individual', 'company'], true)
    ? $_POST['donor_type']
    : 'individual';

$enabled_methods = [];
if (DSKBankPayment::isEnabled()) $enabled_methods[] = 'card';
if (IRISPayment::isEnabled())    $enabled_methods[] = 'iris';

$payment_method = $_POST['payment_method'] ?? '';
if (!in_array($payment_method, $enabled_methods, true)) {
    $payment_method = $enabled_methods[0] ?? '';
}
if ($payment_method === '') {
    donation_form_fail([t_or('donation.pay.unavailable', 'Онлайн плащането не е налично в момента. Моля свържете се с нас.', 'Online payment is not available right now. Please get in touch with us.', $order_lang)], $_POST);
    header('Location: ' . $form_url);
    exit;
}

// Build invoice_data for donation certificate
$invoice_data = ['donor_type' => $donor_type];
$company = trim($_POST['invoice_company'] ?? '');
$eik     = trim($_POST['invoice_eik']     ?? '');
if ($donor_type === 'company') {
    $invoice_data += [
        'company_name'    => $company,
        'mol'             => trim($_POST['invoice_mol'] ?? ''),
        'eik'             => $eik,
        'vat_number'      => trim($_POST['invoice_vat'] ?? '') ?: null,
        'company_address' => trim($_POST['invoice_address'] ?? ''),
    ];
}

$errors = donation_validate($amount, $name, $email, $donor_type, $company, $eik, $order_lang);
if ($errors) {
    donation_form_fail($errors, $_POST);
    header('Location: ' . $form_url);
    exit;
}

$pdo          = get_pdo();
$order_number = generate_order_number();

$items_json = json_encode([
    ['type' => 'donation', 'amount_eur' => $amount]
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("
    INSERT INTO orders
      (order_number,type,status,lang,customer_name,customer_email,items,
       subtotal_eur,shipping_eur,total_eur,donation_message,
       payment_method,payment_status,invoice_data)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    $order_number, 'donation', 'new', $order_lang,
    $name, $email, $items_json,
    $amount, 0, $amount,
    $message ?: null,
    $payment_method, 'pending',
    json_encode($invoice_data, JSON_UNESCAPED_UNICODE),
]);
$order_id = $pdo->lastInsertId();

if ($payment_method === 'card') {
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
        payment_error_report('DSK не създаде плащане с карта (дарение)', $order_number, $e);
        header('Location: ' . shop_path('payment-failed', $order_lang) . '?order=' . urlencode($order_number) . '&err=1');
        exit;
    }
}

if ($payment_method === 'iris') {
    // Register with IRIS Pay by Bank and redirect to the payment link.
    // The callback token authenticates the server-to-server confirmation;
    // it goes only in the hookUrl, never in the browser-facing redirectUrl.
    try {
        $iris  = new IRISPayment();
        $token = bin2hex(random_bytes(32));

        $redirectUrl = SITE_URL . '/api/iris-payment-return.php?order=' . urlencode($order_number);
        $hookUrl     = SITE_URL . '/api/iris-payment-callback.php?id=' . urlencode($order_number) . '&token=' . $token;

        $result = $iris->register([
            'currency'    => 'EUR',
            'amountEur'   => $amount,
            'name'        => donation_payment_title($order_number, $order_lang),
            'description' => donation_payment_description($order_number, $order_lang),
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
        payment_error_report('IRIS не създаде плащане (дарение)', $order_number, $e);
        header('Location: ' . shop_path('payment-failed', $order_lang) . '?order=' . urlencode($order_number) . '&err=1');
        exit;
    }
}
