<?php
// admin/inline-save-article.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
header('Content-Type: application/json');

function art_json(array $d): never { echo json_encode($d); exit; }

admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') art_json(['ok' => false, 'error' => 'method']);

$raw  = $GLOBALS['_om_raw_input'] ?? file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if (!hash_equals(csrf_token(), $body['csrf_token'] ?? '')) art_json(['ok' => false, 'error' => 'csrf']);

$fields = $body['fields'] ?? [];

$allowed = ['title', 'excerpt', 'content', 'image'];

$slug_bg = trim($fields['slug']['bg'] ?? '');
$slug_en = trim($fields['slug']['en'] ?? '');

foreach (['bg' => $slug_bg, 'en' => $slug_en] as $lang => $slug) {
    if (empty($slug)) continue;
    $slug = basename(str_replace(['..', "\0"], '', $slug));
    $path = "/content/articles/{$lang}/{$slug}.json";
    $full = $_SERVER['DOCUMENT_ROOT'] . $path;
    if (!file_exists($full)) continue;

    // $full, not $path: load_json/save_json take a filesystem path (the old code read and
    // wrote "/content/…" at the filesystem root, so nothing was ever saved).
    if (!article_inline_save($full, $lang, $fields)) {
        art_json(['ok' => false, 'error' => 'save']);
    }
}

art_json(['ok' => true]);
