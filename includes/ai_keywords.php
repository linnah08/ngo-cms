<?php
/**
 * AI keyword suggestions via Claude API.
 *
 * API key is stored in the encrypted settings table under key 'claude_api_key'.
 *
 * Usage:
 *   $keywords = claude_suggest_keywords($title_bg, $content_bg, $title_en, $content_en);
 *   // Returns: [['bg' => 'деца с аутизъм', 'en' => 'children with autism'], ...]
 *
 * Returns an empty array on failure (missing key, network error, API error).
 */

if (!function_exists('setting_get')) {
    require_once __DIR__ . '/../includes/settings.php';
}

function claude_suggest_keywords(
    string $title_bg,
    string $content_bg,
    string $title_en = '',
    string $content_en = ''
): array {
    $api_key = setting_get('claude_api_key');
    if ($api_key === '') return [];

    $text_bg = mb_substr(strip_tags($content_bg), 0, 2000);
    $text_en = mb_substr(strip_tags($content_en), 0, 2000);

    $prompt  = "You are an SEO expert. Analyze the article below and suggest 8–10 relevant SEO keywords or short phrases that people would realistically search for to find this content.\n\n";
    $prompt .= "Article title (BG): {$title_bg}\n";
    if ($text_bg) $prompt .= "Article content (BG excerpt): {$text_bg}\n\n";
    if ($title_en) $prompt .= "Article title (EN): {$title_en}\n";
    if ($text_en) $prompt .= "Article content (EN excerpt): {$text_en}\n\n";
    $prompt .= "Return ONLY a JSON array. Each element must be an object with two keys:\n";
    $prompt .= "  \"bg\": the keyword/phrase in Bulgarian\n";
    $prompt .= "  \"en\": the keyword/phrase in English\n\n";
    $prompt .= "Example: [{\"bg\": \"деца с аутизъм\", \"en\": \"children with autism\"}, ...]\n";
    $prompt .= "No explanation, no markdown fences, just the raw JSON array.";

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 512,
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
        _om_log('Claude keywords', 'cURL error: ' . $curl_err);
        return [];
    }

    if ($http_code !== 200) {
        _om_log('Claude keywords', "HTTP {$http_code}: " . substr((string)$response, 0, 200));
        return [];
    }

    $data = json_decode((string)$response, true);
    $text = $data['content'][0]['text'] ?? '';

    if (preg_match('/\[.*\]/s', $text, $m)) {
        $keywords = json_decode($m[0], true);
        if (is_array($keywords)) return $keywords;
    }

    return [];
}

function claude_is_configured(): bool
{
    return setting_get('claude_api_key') !== '';
}
