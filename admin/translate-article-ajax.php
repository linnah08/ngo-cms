<?php
/**
 * Translates a full BG article to EN and saves it to content/articles/en/{slug}.json.
 *
 * POST JSON body:
 *   { "slug": "article-slug", "csrf_token": "..." }
 *
 * Response JSON:
 *   { "ok": true,  "en_title": "..." }
 *   { "ok": false, "error": "..." }
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $body['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token']); exit;
}

$slug = basename(str_replace(['..', "\0"], '', $body['slug'] ?? ''));
if (!$slug) {
    echo json_encode(['ok' => false, 'error' => 'No slug']); exit;
}

if (!deepl_is_configured()) {
    echo json_encode(['ok' => false, 'error' => 'DeepL API key not configured']); exit;
}

$bg_file = ARTICLES_PATH . '/bg/' . $slug . '.json';
if (!file_exists($bg_file)) {
    echo json_encode(['ok' => false, 'error' => 'BG article not found']); exit;
}

$article = load_json($bg_file);

// Translate fields — content may contain HTML, the rest is plain text
$err     = null;
$title   = deepl_translate($article['title']   ?? '', 'EN-GB', false, $err);
if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
$excerpt = deepl_translate($article['excerpt'] ?? '', 'EN-GB', false, $err);
if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
$content = deepl_translate($article['content'] ?? '', 'EN-GB', true,  $err);
if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }

$en_data = [
    'title'   => $title   ?: $article['title'],
    'slug'    => $slug,
    'date'    => $article['date']   ?? date('Y-m-d'),
    'author'  => $article['author'] ?? '',
    'status'  => $article['status'] ?? 'draft',
    'excerpt' => $excerpt ?: '',
    'image'   => $article['image']  ?? '',
    'tags'    => $article['tags']   ?? [],
    'content' => $content ?: '',
];

$en_dir = ARTICLES_PATH . '/en';
if (!is_dir($en_dir)) mkdir($en_dir, 0755, true);

if (!save_json($en_dir . '/' . $slug . '.json', $en_data)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save EN article file']); exit;
}

echo json_encode(['ok' => true, 'en_title' => $en_data['title']]);
