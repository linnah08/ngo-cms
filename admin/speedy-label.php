<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';

admin_require_login();

$pdo      = get_pdo();
$order_id = (int)($_GET['order_id'] ?? 0);
if (!$order_id) { http_response_code(400); exit('Missing order_id'); }

$stmt = $pdo->prepare('SELECT speedy_shipment_id, courier FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || $order['courier'] !== 'speedy' || empty($order['speedy_shipment_id'])) {
    http_response_code(404);
    exit('Товарителницата не е намерена.');
}

try {
    $speedy = new SpeedyCourier();
    $pdf    = $speedy->getLabel($order['speedy_shipment_id']);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="speedy-' . $order['speedy_shipment_id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Грешка: ' . $e->getMessage());
}
