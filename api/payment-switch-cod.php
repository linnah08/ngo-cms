<?php
/**
 * Switch a pending card order to COD, then redirect to confirmation.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    exit('Bad request');
}

$order_number = trim($_POST['order_number'] ?? '');
if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? AND type = ?');
$stmt->execute([$order_number, 'physical']);
$order = $stmt->fetch();

if (!$order || $order['payment_status'] !== 'pending' || $order['payment_method'] !== 'card') {
    header('Location: /');
    exit;
}

$pdo->prepare("UPDATE orders SET payment_method = 'cod', status = 'new', updated_at = NOW() WHERE id = ?")
    ->execute([$order['id']]);

// Now safe to send emails (payment is confirmed as COD)
send_mail(
    $order['customer_email'],
    render_email_subject('order-confirmation-customer', $order['lang'] ?? 'bg', ['order_number' => $order['order_number'], 'customer_name' => $order['customer_name']]),
    render_email('order-confirmation-customer', ['order' => $order])
);
send_mail(
    SITE_EMAIL,
    'Нова поръчка #' . $order['order_number'],
    render_email('order-notification-admin', [
        'order'     => $order,
        'admin_url' => SITE_URL . '/admin/order-view.php?id=' . $order['id'],
    ])
);

header('Location: /checkout/confirmation/?order=' . urlencode($order_number));
exit;
