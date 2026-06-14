<?php
/**
 * DSK Bank return URL — customer lands here after paying (or cancelling) on the bank page.
 * Also handles the "Try again" retry flow: ?retry=1&order=...
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/DSKBankPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_payment.php';
start_session();

$pdo          = get_pdo();
$order_number = trim($_GET['order'] ?? '');

if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order || $order['payment_method'] !== 'card') {
    header('Location: /');
    exit;
}

$confirm_url = $order['type'] === 'donation'
    ? '/donation/confirmation/?order=' . urlencode($order_number)
    : '/checkout/confirmation/?order=' . urlencode($order_number);

$failed_url = '/checkout/payment-failed/?order=' . urlencode($order_number);

// ── Retry: re-register the same order with DSK Bank ──────────────────────────
if (isset($_GET['retry'])) {
    if ($order['payment_status'] !== 'pending') {
        header('Location: ' . $confirm_url);
        exit;
    }
    try {
        $dsk        = new DSKBankPayment();
        $ref        = $order_number . '_' . time();
        $returnUrl  = SITE_URL . '/api/payment-return.php?order=' . urlencode($order_number);
        $result     = $dsk->register($ref, (float)$order['total_eur'], $returnUrl);

        $pdo->prepare('UPDATE orders SET dsk_order_id = ? WHERE id = ?')
            ->execute([$result['dsk_order_id'], $order['id']]);

        header('Location: ' . $result['formUrl']);
        exit;
    } catch (Throwable $e) {
        error_log('payment-return retry: ' . $e->getMessage());
        header('Location: ' . $failed_url . '&err=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Normal return: verify payment status ─────────────────────────────────────
$dsk_order_id = $_GET['mdOrder'] ?? $_GET['orderId'] ?? $order['dsk_order_id'] ?? '';

if (!$dsk_order_id) {
    header('Location: ' . $failed_url);
    exit;
}

// Already processed (e.g. callback beat the return URL)
if ($order['payment_status'] === 'paid') {
    header('Location: ' . $confirm_url);
    exit;
}

try {
    $dsk    = new DSKBankPayment();
    $status = $dsk->getStatus($dsk_order_id);
    process_dsk_result($pdo, $order, $dsk_order_id, $status);
} catch (Throwable $e) {
    error_log('payment-return: ' . $e->getMessage());
    header('Location: ' . $failed_url);
    exit;
}

$orderStatus = (int)($status['orderStatus'] ?? -1);
if ($orderStatus === 2 || $orderStatus === 1) {
    header('Location: ' . $confirm_url);
} else {
    header('Location: ' . $failed_url);
}
exit;
