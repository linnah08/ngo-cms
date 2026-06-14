<?php
// admin/inline-upload.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
header('Content-Type: application/json');

function upload_json(array $data): never {
    echo json_encode($data);
    exit;
}

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') upload_json(['ok' => false, 'error' => 'method']);
csrf_verify(); // exits with 403 on failure — wrap in try for tests

$allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
$allowed_ext  = ['jpg', 'jpeg', 'png', 'webp'];

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    upload_json(['ok' => false, 'error' => 'no file']);
}

$file   = $_FILES['image'];
$finfo  = new finfo(FILEINFO_MIME_TYPE);
$mime   = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed_mime, true)) {
    upload_json(['ok' => false, 'error' => 'invalid file type']);
}

$ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext, true)) {
    upload_json(['ok' => false, 'error' => 'invalid file type']);
}

$section = trim($_POST['section'] ?? 'uploads');
$field   = trim($_POST['field'] ?? 'image');

// Map section+field to upload directory
$dir_map = [
    'home'    => 'assets/images/pages',
    'product' => 'assets/images/products',
    'article' => 'assets/images/articles',
];
$dir = $dir_map[$section] ?? 'assets/images/pages';
$dest_dir = $_SERVER['DOCUMENT_ROOT'] . '/' . $dir;

if (!is_dir($dest_dir)) {
    mkdir($dest_dir, 0755, true);
}

$filename = bin2hex(random_bytes(8)) . '.' . $ext;
$dest     = $dest_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    upload_json(['ok' => false, 'error' => 'upload failed']);
}

upload_json(['ok' => true, 'path' => '/' . $dir . '/' . $filename]);
