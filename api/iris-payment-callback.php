<?php
/**
 * IRIS Pay by Bank server-to-server callback ("hookUrl").
 * IRIS calls the exact hookUrl we registered, appending ?status=CONFIRMED.
 *
 * IRIS provides no status API and no callback signature, so we authenticate
 * the callback with the per-order capability token we embedded in the hookUrl.
 * The token never reaches the customer's browser (only the redirectUrl does),
 * so a valid token proves the call genuinely came from IRIS for this order.
 *
 * Must respond HTTP 200 on success.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_iris_result.php';

$pdo = get_pdo();

$order_number = $_GET['id']     ?? $_POST['id']     ?? '';
$token        = $_GET['token']  ?? $_POST['token']  ?? '';
$status       = $_GET['status'] ?? $_POST['status'] ?? '';

if ($order_number === '' || $token === '') {
    http_response_code(400);
    exit('missing params');
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order || empty($order['iris_callback_token'])) {
    // Unknown order or one that never started an IRIS payment.
    // Respond 200 so IRIS doesn't retry forever; reveal nothing.
    http_response_code(200);
    exit('ok');
}

// ── Callback authentication: constant-time token comparison ──────────────────
if (!hash_equals((string)$order['iris_callback_token'], (string)$token)) {
    error_log('iris-callback: token mismatch for order ' . $order_number);
    http_response_code(403);
    exit('forbidden');
}

if (strtoupper((string)$status) === 'CONFIRMED') {
    try {
        process_iris_result($pdo, $order);
    } catch (Throwable $e) {
        error_log('iris-callback: ' . $e->getMessage());
        http_response_code(500);
        exit('error');
    }
}

http_response_code(200);
echo 'ok';
