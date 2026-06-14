<?php
/**
 * DeepL translation helper.
 *
 * API key is stored in the encrypted settings table under key 'deepl_api_key'.
 * Free-tier keys end with ':fx' and use api-free.deepl.com.
 * Paid keys use api.deepl.com.
 *
 * Usage:
 *   require_once '/path/to/includes/translator.php';
 *   $en = deepl_translate('Здравейте, свят!');          // returns 'Hello, world!'
 *   $en = deepl_translate('<p>текст</p>', 'EN-GB', true); // preserves HTML tags
 *
 * Returns an empty string on failure (missing key, network error, quota exceeded).
 */

if (!function_exists('setting_get')) {
    require_once __DIR__ . '/../includes/settings.php';
}

/**
 * Translate a string from Bulgarian to the target language via DeepL.
 *
 * @param  string      $text        Text (or HTML) to translate
 * @param  string      $target_lang DeepL target language code, e.g. 'EN-GB'
 * @param  bool        $is_html     Pass true to enable DeepL HTML tag preservation
 * @param  string|null &$error      Populated with an error message on failure
 * @return string                   Translated text, or '' on any error
 */
function deepl_translate(string $text, string $target_lang = 'EN-GB', bool $is_html = false, ?string &$error = null): string
{
    $error = null;

    if (trim($text) === '') return '';

    $api_key = setting_get('deepl_api_key');
    if ($api_key === '') {
        $error = 'DeepL API key not configured.';
        return '';
    }

    // Free-tier keys end with ':fx'; paid keys use the standard host
    $host = str_ends_with($api_key, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';
    $url  = "https://{$host}/v2/translate";

    // Apply glossary: swap known source terms for opaque tokens so DeepL
    // leaves them alone, then restore the target terms after translation.
    $glossary   = deepl_load_glossary();
    $token_map  = [];   // token → target_en
    foreach ($glossary as $i => $pair) {
        [$source, $target] = $pair;
        if (trim($source) === '') continue;
        $token              = "DGLSTK{$i}DGLSTK";
        $token_map[$token]  = $target;
        $text               = preg_replace('/' . preg_quote($source, '/') . '/u', $token, $text);
    }

    // Wrap HTML in <div> to avoid DeepL "text without parent" 400 error.
    // DeepL's HTML tag handling requires all text to be inside HTML elements.
    if ($is_html) {
        $text = '<div>' . $text . '</div>';
    }

    $params = [
        'text'        => $text,
        'source_lang' => 'BG',
        'target_lang' => $target_lang,
    ];
    if ($is_html) {
        $params['tag_handling']      = 'html';
        // Prevent DeepL from splitting sentences at inline tag boundaries,
        // which causes <a> hrefs to get separated from their link text.
        $params['non_splitting_tags'] = 'a,span,em,strong,b,i,u,s';
        $params['outline_detection']  = '0';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        // DeepL v2 uses Authorization header; auth_key in body is legacy/deprecated
        CURLOPT_HTTPHEADER     => [
            'Authorization: DeepL-Auth-Key ' . $api_key,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        $error = 'cURL error: ' . $curl_err;
        _om_log('DeepL', $error);
        return '';
    }

    $data = json_decode((string)$response, true);

    if ($http_code !== 200) {
        $api_msg = $data['message'] ?? (string)$response;
        $error   = "DeepL HTTP {$http_code}: " . substr($api_msg, 0, 200);
        _om_log('DeepL', $error);
        return '';
    }

    $translated = $data['translations'][0]['text'] ?? null;
    if ($translated === null) {
        $error = 'Unexpected DeepL response: ' . substr((string)$response, 0, 200);
        _om_log('DeepL', $error);
        return '';
    }

    // Strip the wrapping <div> we added before sending
    if ($is_html) {
        $translated = preg_replace('/^\s*<div>(.*)<\/div>\s*$/s', '$1', $translated);
    }

    // Restore glossary targets
    foreach ($token_map as $token => $target) {
        $translated = str_replace($token, $target, $translated);
    }

    return $translated;
}

/**
 * Check whether a DeepL API key is configured.
 */
function deepl_is_configured(): bool
{
    return setting_get('deepl_api_key') !== '';
}

/**
 * Load the BG→EN glossary pairs from settings.
 * Returns array of [source_bg, target_en] pairs.
 */
function deepl_load_glossary(): array
{
    $json = setting_get('deepl_glossary_bg_en', '');
    if ($json === '') return [];
    $pairs = json_decode($json, true);
    return is_array($pairs) ? $pairs : [];
}

/**
 * Persist the glossary pairs to settings.
 */
function deepl_save_glossary(array $pairs): void
{
    setting_set('deepl_glossary_bg_en', json_encode(array_values($pairs), JSON_UNESCAPED_UNICODE));
}
