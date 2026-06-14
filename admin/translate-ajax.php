<?php
/**
 * AJAX endpoint: translate a piece of text via DeepL.
 *
 * POST body (JSON or form):
 *   text      string  — text or HTML to translate
 *   is_html   bool    — true if the text contains HTML tags (default false)
 *
 * Response (JSON):
 *   { "ok": true,  "translated": "..." }
 *   { "ok": false, "error": "..." }
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Accept both JSON body and form POST
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$text    = trim($body['text'] ?? '');
$is_html = !empty($body['is_html']);

if ($text === '') {
    echo json_encode(['ok' => false, 'error' => 'No text provided']);
    exit;
}

if (!deepl_is_configured()) {
    echo json_encode(['ok' => false, 'error' => 'DeepL API key not configured. Add it in Translations settings.']);
    exit;
}

$err        = null;
$translated = deepl_translate($text, 'EN-GB', $is_html, $err);

if ($err || $translated === '') {
    echo json_encode(['ok' => false, 'error' => $err ?? 'Translation returned empty.']);
    exit;
}

echo json_encode(['ok' => true, 'translated' => $translated]);
