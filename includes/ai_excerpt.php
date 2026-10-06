<?php
/**
 * Automatic article excerpts via Claude API, with a plain-truncation fallback.
 *
 * Used at article-save time (admin/article-edit.php) so new articles always
 * get an excerpt without the author needing to know it exists as a field, and
 * by cron/backfill-article-excerpts.php so both share the same
 * generation logic. API key is stored in the encrypted settings table under
 * key 'claude_api_key' (see includes/ai_keywords.php).
 *
 * Usage:
 *   $excerpt = article_auto_excerpt($title, $content, 'bg');
 *   // '' only when $content itself has no text to summarize.
 */

if (!function_exists('claude_is_configured')) {
    require_once __DIR__ . '/ai_keywords.php';
}
require_once __DIR__ . '/articles.php'; // article_excerpt_from_content()

function claude_suggest_excerpt(string $title, string $content, string $lang = 'bg'): string
{
    $api_key = setting_get('claude_api_key');
    if ($api_key === '') return '';

    $text = mb_substr(strip_tags($content), 0, 3000);
    if ($text === '') return '';

    $lang_name = $lang === 'en' ? 'English' : 'Bulgarian';
    $prompt  = "Write a single short excerpt (one sentence, about 120-170 characters) in {$lang_name} that summarizes the article below. ";
    $prompt .= "It will be shown on a blog listing page and used as the meta description, so it must read naturally and stand on its own.\n\n";
    $prompt .= "Title: {$title}\n";
    $prompt .= "Content: {$text}\n\n";
    $prompt .= "Return ONLY the excerpt text. No quotes, no markdown, no repeating the title.";

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 200,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        _om_log('Claude excerpt', 'cURL error: ' . $curl_err);
        return '';
    }
    if ($http_code !== 200) {
        _om_log('Claude excerpt', "HTTP {$http_code}: " . substr((string)$response, 0, 200));
        return '';
    }

    $data = json_decode((string)$response, true);
    $text = trim($data['content'][0]['text'] ?? '');
    return trim($text, "\"' \t\n\r\0\x0B");
}

/**
 * Generate an excerpt for content that doesn't have one yet: Claude when
 * configured, otherwise a plain truncation. Never blocks article saving —
 * returns '' only when the content itself is empty.
 */
function article_auto_excerpt(string $title, string $content, string $lang = 'bg'): string
{
    if (trim(strip_tags($content)) === '') return '';

    if (claude_is_configured()) {
        $excerpt = claude_suggest_excerpt($title, $content, $lang);
        if ($excerpt !== '') return $excerpt;
    }

    return article_excerpt_from_content($content);
}
