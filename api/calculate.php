<?php
/**
 * Calculate shipping price via the courier API.
 * GET params: courier, delivery_type, city
 * Returns: {"price": float} or {"error": "..."}
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';

header('Content-Type: application/json; charset=utf-8');

$courier       = strtolower(trim($_GET['courier']       ?? ''));
$delivery_type = strtolower(trim($_GET['delivery_type'] ?? ''));
$city          = trim($_GET['city'] ?? '');
$office_id     = trim($_GET['office_id'] ?? '');

if (!in_array($courier, ['speedy']) || !$city) {
    echo json_encode(['error' => 'missing params']);
    exit;
}

try {
    $class_map = [
        'speedy' => ['includes/couriers/SpeedyCourier.php', 'SpeedyCourier'],
    ];

    [$file, $class] = $class_map[$courier];
    require_once $_SERVER['DOCUMENT_ROOT'] . '/' . $file;

    // Determine total cart weight from session
    start_session();
    $totalWeight = 1.0;
    $packCount   = 1;
    $cart = $_SESSION['cart'] ?? [];

    if ($cart) {
        $packCount = max(1, count($cart));
        // No weight_kg column in products yet — use 0.5 kg per item as default
        $w = 0.0;
        foreach ($cart as $item) {
            $w += 0.5 * max(1, (int)($item['quantity'] ?? 1));
        }
        if ($w > 0) $totalWeight = $w;
    }

    $obj = new $class();
    $apiError = null;
    try {
        $parcel = ['weight' => $totalWeight, 'pack_count' => $packCount];
        if ($office_id !== '') $parcel['office_id'] = $office_id;
        $price = $obj->calculateShipping($parcel, $city, $delivery_type);
        if ($price > 0) {
            echo json_encode(['price' => round($price, 2)]);
            exit;
        }
        $apiError = 'API returned 0';
    } catch (Throwable $inner) {
        $apiError = $inner->getMessage();
        error_log('calculate.php courier API failed (' . $courier . '): ' . $apiError);
    }

    // Fallback: flat rate from shipping_rates table
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT rate_eur FROM shipping_rates WHERE courier=? AND delivery_type=?');
    $stmt->execute([$courier, $delivery_type]);
    $rate = $stmt->fetchColumn();
    if ($rate !== false) {
        echo json_encode(['price' => (float)$rate, 'debug_api_error' => $apiError]);
    } else {
        echo json_encode(['error' => 'no rate available', 'debug_api_error' => $apiError]);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
