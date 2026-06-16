<?php
/**
 * IRIS Pay browser return target (redirectUrl) + "try again" retry flow.
 *
 * IRIS redirects the customer here after they confirm OR reject the payment,
 * without telling us which. So we re-query the authoritative status by
 * paymentHash and route to confirmation / payment-failed accordingly. Payment
 * is still ultimately confirmed by the server-to-server callback; this just
 * gives the returning customer an accurate page (and processes the result early).
 *
 * Retry (?retry=1&order=...): re-register the same order with IRIS and send the
 * customer back to the bank.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/IRISPayment.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_iris_result.php';

$pdo          = get_pdo();
$order_number = trim($_GET['order'] ?? '');

if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order || $order['payment_method'] !== 'iris') {
    header('Location: /');
    exit;
}

$confirm_url = '/checkout/confirmation/?order=' . urlencode($order_number);
$failed_url  = '/checkout/payment-failed/?order=' . urlencode($order_number);

// ── Retry: re-register the same order with IRIS ──────────────────────────────
if (isset($_GET['retry'])) {
    if ($order['payment_status'] === 'paid') {
        header('Location: ' . $confirm_url);
        exit;
    }
    try {
        $iris  = new IRISPayment();
        $token = bin2hex(random_bytes(32));

        $redirectUrl = SITE_URL . '/api/iris-payment-return.php?order=' . urlencode($order_number);
        $hookUrl     = SITE_URL . '/api/iris-payment-callback.php?id=' . urlencode($order_number) . '&token=' . $token;

        $result = $iris->register([
            'currency'    => 'EUR',
            'amountEur'   => (float)$order['total_eur'],
            'name'        => 'Поръчка ' . $order_number,
            'description' => SITE_NAME_BG . ' — поръчка ' . $order_number,
            'orderId'     => $order_number,
            'redirectUrl' => $redirectUrl,
            'hookUrl'     => $hookUrl,
            'lang'        => $order['lang'] ?? 'bg',
        ]);

        $pdo->prepare("UPDATE orders SET iris_payment_hash = ?, iris_callback_token = ?, status = 'new' WHERE id = ?")
            ->execute([$result['paymentHash'], $token, $order['id']]);

        header('Location: ' . $result['paymentLink']);
        exit;
    } catch (Throwable $e) {
        error_log('iris-payment-return retry: ' . $e->getMessage());
        header('Location: ' . $failed_url . '&err=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Normal return: re-query authoritative status and route ───────────────────
if ($order['payment_status'] === 'paid') {
    header('Location: ' . $confirm_url);
    exit;
}

try {
    if (!empty($order['iris_payment_hash'])) {
        $iris   = new IRISPayment();
        $status = $iris->getStatus($order['iris_payment_hash']);
        process_iris_result($pdo, $order, $status);

        $state = strtoupper((string)($status['status'] ?? ''));
        if ($state === 'CONFIRMED') { header('Location: ' . $confirm_url); exit; }
        if ($state === 'FAILED')    { header('Location: ' . $failed_url);  exit; }
    }
} catch (Throwable $e) {
    error_log('iris-payment-return: ' . $e->getMessage());
}

// WAITING / unknown — the callback will settle it; confirmation shows pending.
header('Location: ' . $confirm_url);
exit;
