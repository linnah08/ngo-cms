<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/print_helpers.php';

admin_require_shop();

$file = basename($_GET['file'] ?? '');

if (!print_filename_safe($file)) {
    http_response_code(400);
    exit('Invalid filename.');
}

$path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/print-designs/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found.');
}

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$ct = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: '        . $ct);
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: '      . filesize($path));
readfile($path);
exit;
