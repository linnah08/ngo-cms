<?php
/**
 * DSK Bank server-to-server callback.
 * The bank POSTs here when payment status changes (fires even if browser redirect failed).
 * Must respond with HTTP 200.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_payment.php';

$pdo = get_pdo();

$dsk_order_id = $_POST['mdOrder'] ?? $_GET['mdOrder'] ?? '';
if (!$dsk_order_id) {
    http_response_code(400);
    exit('missing mdOrder');
}

// Look up our order by DSK's UUID
$stmt = $pdo->prepare('SELECT * FROM orders WHERE dsk_order_id = ?');
$stmt->execute([$dsk_order_id]);
$order = $stmt->fetch();

if (!$order) {
    // Unknown order — could be a timing issue, respond OK so bank doesn't retry forever
    http_response_code(200);
    exit('ok');
}

try {
    $dsk    = new DSKBankPayment();
    $status = $dsk->getStatus($dsk_order_id);

    process_dsk_result($pdo, $order, $dsk_order_id, $status);
} catch (Throwable $e) {
    error_log('payment-callback: ' . $e->getMessage());
    http_response_code(500);
    exit('error');
}

http_response_code(200);
echo 'ok';
