<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['alive' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    admin_session_refresh();
}

$sess = admin_user();
echo json_encode([
    'alive'     => true,
    'expiresAt' => ($sess['time'] ?? 0) + ADMIN_SESSION_HOURS * 3600,
]);
