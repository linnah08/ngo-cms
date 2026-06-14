<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
admin_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body  = file_get_contents('php://input');
$data  = json_decode($body, true);

if (!isset($data['csrf_token']) || !hash_equals(csrf_token(), $data['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$order = $data['order'] ?? [];

if (!is_array($order)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$partners = load_json(PARTNERS_FILE);
$indexed  = [];
foreach ($partners as $p) {
    $indexed[$p['id']] = $p;
}

$reordered = [];
foreach ($order as $id) {
    if (isset($indexed[$id])) {
        $reordered[] = $indexed[$id];
    }
}
// Append any partners not in the order list
foreach ($partners as $p) {
    if (!in_array($p['id'], $order)) {
        $reordered[] = $p;
    }
}

save_json(PARTNERS_FILE, $reordered);
echo json_encode(['success' => true]);
