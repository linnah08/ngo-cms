<?php
// admin/home-upload.php — one photo from the front-page editor, uploaded as soon as it is
// picked. The editor then saves only the path, so a section with many new photos never
// goes over the server's limit for one request. Answers in JSON.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';

admin_require_login();
admin_require_admin();
header('Content-Type: application/json; charset=UTF-8');

$answer = function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $answer(405, ['ok' => false, 'error' => 'Непозволена заявка.']);
if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP drops the whole request body when it exceeds post_max_size.
    $answer(413, ['ok' => false, 'error' => 'Снимката е твърде голяма. Опитайте с по-малък файл.']);
}
if (!csrf_verify()) $answer(403, ['ok' => false, 'error' => 'Сесията изтече. Презаредете страницата и опитайте отново.']);

$r = home_upload_one($_POST, $_FILES);
$answer($r['ok'] ? 200 : 422, $r);
