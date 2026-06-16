<?php
/**
 * IRIS Pay by Bank server-to-server callback ("hookUrl").
 * IRIS calls the exact hookUrl we registered, appending &status=CONFIRMED|FAILED.
 *
 * We do NOT trust that status param. We use it only to know a change happened,
 * authenticate the caller with the per-order capability token in the hookUrl,
 * then re-query the AUTHORITATIVE status from IRIS by paymentHash (which also
 * returns the settled amount + receiver IBAN for integrity checks).
 *
 * Must respond HTTP 200 on success.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_iris_result.php';

$pdo = get_pdo();

$order_number = $_GET['id']    ?? $_POST['id']    ?? '';
$token        = $_GET['token'] ?? $_POST['token'] ?? '';

if ($order_number === '' || $token === '') {
    http_response_code(400);
    exit('missing params');
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order || empty($order['iris_callback_token']) || empty($order['iris_payment_hash'])) {
    // Unknown order or one that never started an IRIS payment.
    // Respond 200 so IRIS doesn't retry forever; reveal nothing.
    http_response_code(200);
    exit('ok');
}

// ── Authenticate the caller: constant-time token comparison ──────────────────
if (!hash_equals((string)$order['iris_callback_token'], (string)$token)) {
    error_log('iris-callback: token mismatch for order ' . $order_number);
    http_response_code(403);
    exit('forbidden');
}

try {
    // Authoritative re-query — the callback's own status param is ignored.
    $iris   = new IRISPayment();
    $status = $iris->getStatus($order['iris_payment_hash']);
    process_iris_result($pdo, $order, $status);
} catch (Throwable $e) {
    error_log('iris-callback: ' . $e->getMessage());
    http_response_code(500);
    exit('error');
}

http_response_code(200);
echo 'ok';
