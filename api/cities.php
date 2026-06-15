<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';

header('Content-Type: application/json; charset=utf-8');

$courier = strtolower(trim($_GET['courier'] ?? ''));
$q       = trim($_GET['q'] ?? '');

if (!in_array($courier, ['speedy', 'boxnow'])) {
    echo json_encode([]);
    exit;
}

// Live city search by partial name (used for autocomplete and office-search hints)
if ($q !== '' && $courier === 'speedy') {
    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
        echo json_encode((new SpeedyCourier())->searchCities($q));
    } catch (Throwable $e) {
        error_log('api/cities.php search: ' . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// For Speedy: use the live API city list (all Bulgarian sites, including villages).
// Cached for 24 hours so we don't hit the Speedy API on every page load.
if ($courier === 'speedy') {
    $cache_file = sys_get_temp_dir() . '/ngo_speedy_cities.json';
    $cache_ttl  = 86400; // 24 hours

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        echo file_get_contents($cache_file);
        exit;
    }

    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/SpeedyCourier.php';
        $cities = (new SpeedyCourier())->getCities();
        $json   = json_encode($cities);
        file_put_contents($cache_file, $json);
        echo $json;
    } catch (Throwable $e) {
        error_log('api/cities.php speedy: ' . $e->getMessage());
        // Fall back to static list if Speedy API is unreachable
        $fallback = $_SERVER['DOCUMENT_ROOT'] . '/data/bg-cities.json';
        echo file_exists($fallback) ? file_get_contents($fallback) : '[]';
    }
    exit;
}

// Static city list for other couriers (BoxNow uses same Bulgarian cities)
$file   = $_SERVER['DOCUMENT_ROOT'] . '/data/bg-cities.json';
$cities = json_decode(file_get_contents($file), true) ?: [];
echo json_encode($cities);
