<?php
/**
 * AJAX endpoint: suggest SEO keywords via Claude AI.
 *
 * POST body (JSON):
 *   title_bg    string  — article title in Bulgarian
 *   content_bg  string  — article HTML content in Bulgarian
 *   title_en    string  — article title in English (optional)
 *   content_en  string  — article HTML content in English (optional)
 *
 * Response (JSON):
 *   { "ok": true,  "keywords": [{"bg": "...", "en": "..."}, ...] }
 *   { "ok": false, "error": "..." }
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ai_keywords.php';
admin_require_login();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$title_bg   = trim($body['title_bg']   ?? '');
$content_bg = trim($body['content_bg'] ?? '');
$title_en   = trim($body['title_en']   ?? '');
$content_en = trim($body['content_en'] ?? '');

if ($title_bg === '' && $content_bg === '') {
    echo json_encode(['ok' => false, 'error' => 'No content provided.']);
    exit;
}

if (!claude_is_configured()) {
    echo json_encode(['ok' => false, 'error' => 'Claude API key not configured. Add it in Плащания → AI настройки.']);
    exit;
}

$keywords = claude_suggest_keywords($title_bg, $content_bg, $title_en, $content_en);

if (empty($keywords)) {
    echo json_encode(['ok' => false, 'error' => 'No suggestions returned. Check your API key or try again.']);
    exit;
}

echo json_encode(['ok' => true, 'keywords' => $keywords]);
