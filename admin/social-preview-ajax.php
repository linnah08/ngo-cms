<?php
/**
 * Social post previews from the editor's unsaved text. Writes nothing.
 *
 * POST body (JSON): csrf_token, title, content, title_en, content_en, slug, slug_en
 * Answers {ok:true, fb:{text,length,limit,cut}, insta:{…}, linkedin:{…}} or {ok:false, error}.
 * Facebook and Instagram use the Bulgarian text, LinkedIn the English one.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
admin_require_editorial();   // the same people as the article editor that calls it: admin or author
module_ajax_guard('social');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 500 * 1024 + 1);
if (strlen($raw) > 500 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'Текстът е твърде дълъг за преглед.']);
    exit;
}
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

if (!isset($_POST['csrf_token']) && isset($body['csrf_token'])) {
    $_POST['csrf_token'] = $body['csrf_token'];
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'error' => 'Сесията е изтекла. Презаредете страницата.']);
    exit;
}

$str = static fn(string $k): string => is_string($body[$k] ?? null) ? $body[$k] : '';
$title    = $str('title');
$content  = $str('content');
$title_en = $str('title_en');
$content_en = $str('content_en');

$slugs = article_compute_slugs($str('slug'), $str('slug_en'), $title, $title_en, '', article_slugs_latin());
$url_bg = article_public_url($slugs['bg'], 'bg');
$url_en = article_public_url($slugs['en'], 'en');

$en_empty = trim(strip_tags($content_en)) === '';

echo json_encode([
    'ok'       => true,
    'fb'       => social_post_text($title, $content, $url_bg, 'fb'),
    'insta'    => social_post_text($title, $content, $url_bg, 'insta'),
    'linkedin' => $en_empty
        ? ['text' => '', 'length' => 0, 'limit' => social_post_limit('linkedin'), 'cut' => false]
        : social_post_text($title_en, $content_en, $url_en, 'linkedin'),
], JSON_UNESCAPED_UNICODE);
