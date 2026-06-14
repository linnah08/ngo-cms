<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/print_helpers.php';
start_session();

$file = basename($_GET['file'] ?? '');

if (!print_filename_safe($file)) {
    http_response_code(400);
    exit;
}

// Only serve files that are in this session's cart
$allowed = [];
foreach ($_SESSION['cart'] ?? [] as $item) {
    if (!empty($item['design_file'])) {
        $allowed[] = basename($item['design_file']);
    }
}

if (!in_array($file, $allowed, true)) {
    http_response_code(403);
    exit;
}

$path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/print-designs/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
exit;
