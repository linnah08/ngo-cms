<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';

header('Content-Type: application/json; charset=utf-8');

$courier = strtolower(trim($_GET['courier'] ?? ''));
$city    = trim($_GET['city'] ?? '');

// BoxNow map view fetches all lockers with no city filter
$city_required = ($courier !== 'boxnow');
if (($city_required && !$city) || !in_array($courier, ['speedy', 'boxnow'])) {
    echo json_encode([]);
    exit;
}

try {
    $class_map = [
        'speedy' => ['includes/couriers/SpeedyCourier.php', 'SpeedyCourier'],
        'boxnow' => ['includes/couriers/BoxNowCourier.php', 'BoxNowCourier'],
    ];

    [$file, $class] = $class_map[$courier];
    require_once $_SERVER['DOCUMENT_ROOT'] . '/' . $file;

    $obj    = new $class();
    $raw    = $obj->getOffices($city);


    // Normalize to [{code, name}] regardless of courier API format
    $offices = [];
    if ($courier === 'speedy') {
        foreach ($raw as $o) {
            if (!is_array($o)) continue;
            $parts = array_filter([
                $o['name']    ?? '',
                $o['city']    ?? '',
                $o['address'] ?? '',
            ]);
            $offices[] = [
                'code' => (string)($o['id'] ?? ''),
                'name' => implode(' — ', $parts),
                'type' => $o['type'] ?? 'office',  // 'office' | 'apt'
            ];
        }
    } elseif ($courier === 'boxnow') {
        // BoxNowCourier::getOffices() returns a flat array of lockers directly
        foreach ($raw as $o) {
            if (!is_array($o)) continue;
            $offices[] = [
                'code' => (string)($o['id'] ?? ''),
                'name' => ($o['name'] ?? '') . ($o['address'] ? ' — ' . $o['address'] : ''),
                'lat'  => $o['lat'] ?? null,
                'lng'  => $o['lng'] ?? null,
            ];
        }
    }

    echo json_encode(array_filter($offices, fn($o) => $o['code'] !== ''));
} catch (Throwable $e) {
    error_log('api/offices.php: ' . $e->getMessage());
    echo json_encode([]);
}
