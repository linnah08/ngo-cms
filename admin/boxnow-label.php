<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/BoxNowCourier.php';

admin_require_login();

$pdo      = get_pdo();
$order_id = (int)($_GET['order_id'] ?? 0);
if (!$order_id) { http_response_code(400); exit('Missing order_id'); }

$stmt = $pdo->prepare('SELECT boxnow_parcel_id, courier FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order || $order['courier'] !== 'boxnow' || empty($order['boxnow_parcel_id'])) {
    http_response_code(404);
    exit('Товарителницата не е намерена.');
}

$parcel_id = $order['boxnow_parcel_id'];

try {
    $boxnow = new BoxNowCourier();
    $token  = $boxnow->getAccessToken();
    $url    = $boxnow->getLabelUrl($parcel_id);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/pdf',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $pdf  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)       { throw new RuntimeException('cURL грешка: ' . $err); }
    if ($code >= 400) { throw new RuntimeException('BoxNow върна HTTP ' . $code); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="boxnow-' . $parcel_id . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;

} catch (Throwable $e) {
    http_response_code(500);
    exit('Грешка: ' . $e->getMessage());
}
