<?php
// admin/inline-save-product.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
header('Content-Type: application/json');

function prod_json(array $d): never { echo json_encode($d); exit; }

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') prod_json(['ok' => false, 'error' => 'method']);

$raw  = $GLOBALS['_om_raw_input'] ?? file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if (!hash_equals(csrf_token(), $body['csrf_token'] ?? '')) prod_json(['ok' => false, 'error' => 'csrf']);

$fields = $body['fields'] ?? [];
$id     = (int)($fields['id']['bg'] ?? 0);
if (!$id) prod_json(['ok' => false, 'error' => 'missing id']);

$pdo    = get_pdo();
$cols   = [];
$params = [];

if (isset($fields['name'])) {
    $cols[]   = 'name_bg = ?';
    $params[] = trim(strip_tags($fields['name']['bg'] ?? ''));
    $cols[]   = 'name_en = ?';
    $params[] = trim(strip_tags($fields['name']['en'] ?? ''));
}
if (isset($fields['description'])) {
    // Rich text — saved raw (HTML from TinyMCE, same as article content)
    $cols[]   = 'description_bg = ?';
    $params[] = $fields['description']['bg'] ?? '';
    $cols[]   = 'description_en = ?';
    $params[] = $fields['description']['en'] ?? '';
}
if (isset($fields['image'])) {
    $cols[]   = 'image = ?';
    $params[] = trim(strip_tags($fields['image']['bg'] ?? ''));
}

if (empty($cols)) prod_json(['ok' => true]);

$params[] = $id;
$pdo->prepare('UPDATE products SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($params);
prod_json(['ok' => true]);
