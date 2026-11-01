<?php
// admin/inline-upload.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/images.php';
header('Content-Type: application/json');

function upload_json(array $data): never {
    echo json_encode($data);
    exit;
}

// Every failure says why in `reason` (size | type | server) and in plain words in
// `message`, so the editor can tell people what to do instead of guessing.
function upload_fail(string $error, string $reason): never {
    $messages = [
        'size'   => 'Снимката е твърде голяма — най-много ' . upload_max_label() . '. Изберете по-малка снимка.',
        'type'   => 'Този файл не е снимка във формат JPEG, PNG или WebP.',
        'server' => 'Снимката не можа да се качи. Опитайте отново.',
    ];
    upload_json(['ok' => false, 'error' => $error, 'reason' => $reason, 'message' => $messages[$reason]]);
}

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') upload_json(['ok' => false, 'error' => 'method']);
// A file over post_max_size empties $_POST and $_FILES, so CSRF would fail
// with a confusing 403 — say it is too big instead.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    upload_fail('too large', 'size');
}
csrf_verify(); // exits with 403 on failure — wrap in try for tests

$upload_err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
if (in_array($upload_err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    upload_fail('too large', 'size');
}
if ($upload_err !== UPLOAD_ERR_OK) {
    upload_fail('no file', 'server');
}

$file   = $_FILES['image'];
$ext    = image_upload_ext($file['tmp_name']);
if ($ext === null) {
    upload_fail('invalid file type', 'type');
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
    upload_fail('upload failed', 'server');
}

image_resize_to_fit($dest);

upload_json(['ok' => true, 'path' => '/' . $dir . '/' . $filename]);
