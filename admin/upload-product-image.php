<?php
// Standalone image upload endpoint — called via fetch(), returns JSON.
// The main product form never includes the file, so post_max_size is never hit.
ob_start(); // catch any stray output so the JSON response stays clean
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_shop();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg  = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
        ? 'Файлът е прекалено голям.'
        : 'Грешка при качване (код ' . $code . ').';
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$ftype   = mime_content_type($_FILES['image']['tmp_name']);
if (!in_array($ftype, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Позволени формати: JPG, PNG, WebP, GIF.']);
    exit;
}

if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Снимката е прекалено голяма (макс. 8 MB).']);
    exit;
}

$ext      = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
$filename = 'upload-' . bin2hex(random_bytes(8)) . '.' . $ext;
$dir      = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/products/';

if (!is_dir($dir)) mkdir($dir, 0755, true);

if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . $filename)) {
    http_response_code(500);
    echo json_encode(['error' => 'Грешка при запис на файла.']);
    exit;
}

echo json_encode(['filename' => $filename]);
