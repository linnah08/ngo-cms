<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_shop();

// Whitelist the exact generated filename shape — no paths, no traversal.
$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^boxnow-\d{8}-\d{6}-[0-9a-f]{6}\.pdf$/', $f)) {
    http_response_code(400);
    exit('Невалиден файл.');
}
$path = $_SERVER['DOCUMENT_ROOT'] . '/documents/boxnow_batches/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit('Файлът не е намерен.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $f . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
