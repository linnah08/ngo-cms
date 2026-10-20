<?php
/**
 * Uploads one PDF to link to — from the editor's link dialog or a section's link field.
 * POST with `file` and `csrf_token`; admins and authors. Answers JSON:
 *   {ok: true, path: "/assets/files/…pdf", text: "Name (PDF, 320 KB)", size: "320 KB"}
 *   {ok: false, reason: "size|type|empty|server", message: "…plain words…"}
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/files.php';

admin_require_editorial();
header('Content-Type: application/json');

function upload_file_json(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_file_json(['ok' => false, 'reason' => 'server', 'message' => 'Неразрешена заявка.'], 405);
}
// A file over post_max_size empties $_POST and $_FILES, so CSRF would fail with a
// confusing message — say it is too big instead.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    upload_file_json(['ok' => false, 'reason' => 'size', 'message' => file_upload_message('size')]);
}
if (!csrf_verify()) {
    upload_file_json(['ok' => false, 'reason' => 'server', 'message' => 'Сесията е изтекла. Презаредете страницата и опитайте отново.'], 403);
}

$f = $_FILES['file'] ?? null;
$result = file_upload_store(
    is_array($f) ? (string) ($f['tmp_name'] ?? '') : '',
    is_array($f) ? (string) ($f['name'] ?? '') : '',
    is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
    $_SERVER['DOCUMENT_ROOT']
);
upload_file_json($result);
