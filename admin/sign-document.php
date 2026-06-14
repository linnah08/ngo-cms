<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_admin();

if (!admin_can_sign()) {
    http_response_code(403);
    exit('Нямате права да подписвате документи.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    exit('Невалидна заявка.');
}

$doc_id = (int) ($_POST['doc_id'] ?? 0);
if (!$doc_id) {
    header('Location: /admin/orders.php');
    exit;
}

$pdo = get_pdo();

// Fetch document
$doc_stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
$doc_stmt->execute([$doc_id]);
$doc = $doc_stmt->fetch();

if (!$doc || $doc['type'] !== 'donation_cert') {
    header('Location: /admin/orders.php?doc_error=' . urlencode('Документът не е намерен или не е сертификат за дарение.'));
    exit;
}

if (!empty($doc['signed_at'])) {
    header('Location: /admin/order-view.php?id=' . (int)$doc['order_id'] . '&doc_error=' . urlencode('Сертификатът вече е подписан.'));
    exit;
}

$order_id = (int) $doc['order_id'];

// Fetch order
$ord_stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$ord_stmt->execute([$order_id]);
$order = $ord_stmt->fetch();
if (!$order) {
    header('Location: /admin/orders.php?doc_error=' . urlencode('Поръчката не е намерена.'));
    exit;
}

$items = json_decode($order['items'] ?? '[]', true) ?? [];

// Fetch saved signature for this admin
$user_id  = (int) admin_user()['id'];
$sig_stmt = $pdo->prepare('SELECT signature_data FROM admin_signatures WHERE admin_user_id = ?');
$sig_stmt->execute([$user_id]);
$saved = $sig_stmt->fetchColumn();

if (!$saved) {
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Нямате запазен подпис. Добавете подпис от страницата „Моят подпис".'));
    exit;
}

// Re-generate the PDF with the embedded signature
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DonationCertGenerator.php';

$document_arr = [
    'number'           => $doc['number'],
    'formatted_number' => $doc['formatted_number'],
    'type'             => 'donation_cert',
];

try {
    $generator = new DonationCertGenerator();
    $pdf_bytes = $generator->generateSigned($order, $items, $document_arr, $saved);
} catch (Throwable $e) {
    _om_log('ERROR', 'sign-document: PDF generation failed: ' . $e->getMessage());
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка при генериране на подписания PDF: ' . $e->getMessage()));
    exit;
}

$filepath = $_SERVER['DOCUMENT_ROOT'] . '/' . $doc['file_path'];
$written  = file_put_contents($filepath, $pdf_bytes);
if ($written === false || $written === 0) {
    _om_log('ERROR', 'sign-document: file_put_contents failed for ' . $filepath);
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('PDF е генериран, но не може да бъде записан.'));
    exit;
}

// Record signature in DB
$pdo->prepare('UPDATE documents SET signed_at = NOW(), signed_by = ? WHERE id = ?')
    ->execute([$user_id, $doc_id]);

// Auto-email the signed cert to the donor
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

$ps = $pdo->prepare('SELECT * FROM campaign_pledges WHERE pledge_number = ?');
$ps->execute([$order['order_number']]);
$pledge_row = $ps->fetch() ?: null;

$pledge_for_email = $pledge_row ?: [
    'name'             => $order['customer_name'],
    'email'            => $order['customer_email'],
    'pledge_number'    => $order['order_number'],
    'amount_eur'       => $order['total_eur'],
    'created_at'       => $order['created_at'],
    'delivery_address' => null,
    'lang'             => 'bg',
];
$_cert_lang = $pledge_for_email['lang'] ?? 'bg';

$email_ok = send_mail(
    $order['customer_email'],
    render_email_subject('campaign-confirmation', $_cert_lang, [
        'name'          => $pledge_for_email['name'],
        'pledge_number' => $order['order_number'],
    ]),
    render_email('campaign-confirmation', ['pledge' => $pledge_for_email, 'lang' => $_cert_lang]),
    '',
    [['path' => $filepath, 'name' => 'certificate-' . $order['order_number'] . '.pdf']]
);

if ($email_ok) {
    $pdo->prepare('UPDATE documents SET emailed_at = NOW() WHERE id = ?')->execute([$doc_id]);
    _om_log('INFO', 'sign-document: cert emailed to ' . $order['customer_email'] . ' (doc ' . $doc_id . ')');
} else {
    _om_log('ERROR', 'sign-document: cert email failed for doc ' . $doc_id . ' / order ' . $order_id);
}

$sign_msg = $email_ok
    ? 'Сертификатът е подписан и изпратен по имейл.'
    : 'Сертификатът е подписан, но имейлът не беше изпратен.';

// For pledge-type orders, redirect to the pledge detail page instead
if (($order['type'] ?? '') === 'pledge') {
    $pledge_stmt = $pdo->prepare("SELECT id FROM campaign_pledges WHERE pledge_number = ?");
    $pledge_stmt->execute([$order['order_number']]);
    $pledge_id = $pledge_stmt->fetchColumn();
    if ($pledge_id) {
        header('Location: /admin/pledge-view.php?id=' . (int)$pledge_id . '&doc_success=' . urlencode($sign_msg));
        exit;
    }
}

header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode($sign_msg));
exit;
