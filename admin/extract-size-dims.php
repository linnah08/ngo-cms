<?php
/**
 * AJAX endpoint: extract per-size shirt dimensions from a size chart image
 * using the Claude vision API.
 *
 * POST (JSON or form):
 *   filename  string  — filename in /assets/images/products/ (already uploaded)
 *
 * Response (JSON):
 *   { "ok": true,  "dims": { "S": {"w": 49, "h": 66}, "M": {...}, ... } }
 *   { "ok": false, "error": "..." }
 *
 * "w" = half-chest width in cm, "h" = body length in cm.
 * Only sizes that appear in the image are returned.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';

admin_require_shop();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$filename = basename(trim($body['filename'] ?? ''));

if (!$filename || !preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $filename)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid filename.']);
    exit;
}

$path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/products/' . $filename;
if (!file_exists($path)) {
    echo json_encode(['ok' => false, 'error' => 'File not found.']);
    exit;
}

$api_key = setting_get('claude_api_key');
if ($api_key === '') {
    echo json_encode(['ok' => false, 'error' => 'Claude API key not configured.']);
    exit;
}

// Encode image as base64
$image_data  = base64_encode(file_get_contents($path));
$ext         = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$media_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$media_type  = $media_types[$ext] ?? 'image/jpeg';

$prompt = <<<'PROMPT'
This is a t-shirt size chart. Extract the chest width (or half-chest) and body length in centimetres for each size shown.

Return ONLY a JSON object. Keys are size labels (e.g. "XS", "S", "M", "L", "XL", "XXL"). Each value has:
  "w": chest width in cm (use half-chest if the chart shows half-chest; use full chest divided by 2 if it shows full chest)
  "h": body length in cm

Example: {"S": {"w": 49, "h": 66}, "M": {"w": 51.5, "h": 68}}

Only include sizes that appear in the chart. No explanation, no markdown, just raw JSON.
PROMPT;

$request_body = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 256,
    'messages'   => [[
        'role'    => 'user',
        'content' => [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $media_type,
                    'data'       => $image_data,
                ],
            ],
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ],
    ]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $request_body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: '          . $api_key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curl_err]);
    exit;
}

if ($http_code !== 200) {
    echo json_encode(['ok' => false, 'error' => "API error (HTTP {$http_code})."]);
    exit;
}

$data = json_decode((string)$response, true);
$text = trim($data['content'][0]['text'] ?? '');

// Strip markdown fences if model added them
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);

$dims = json_decode($text, true);

if (!is_array($dims) || empty($dims)) {
    echo json_encode(['ok' => false, 'error' => 'Could not parse size table from image. Try a clearer screenshot.']);
    exit;
}

// Sanitise: keep only numeric w/h, round to 1dp
$clean = [];
foreach ($dims as $size => $vals) {
    $size = strtoupper(trim((string)$size));
    if (!preg_match('/^(XS|S|M|L|XL|XXL|XXXL|2XL|3XL|3-4|5-6|7-8|9-11|10-11|12-13|14-15|3\/4|5\/6|7\/8|9\/10|10\/11|11\/12|12\/13|14\/15|Tol|ONE SIZE|ONESIZE)$/i', $size)) continue;
    $w = round((float)($vals['w'] ?? 0), 1);
    $h = round((float)($vals['h'] ?? 0), 1);
    if ($w > 0 && $h > 0) $clean[$size] = ['w' => $w, 'h' => $h];
}

if (empty($clean)) {
    echo json_encode(['ok' => false, 'error' => 'No valid sizes found in image.']);
    exit;
}

echo json_encode(['ok' => true, 'dims' => $clean]);
