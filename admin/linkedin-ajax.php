<?php
/**
 * LinkedIn panel AJAX handler.
 *
 * POST body (JSON):
 *   action      'generate' | 'save_post'
 *   csrf_token  string
 *
 * For 'generate':
 *   slug  string  — article slug (BG)
 *
 * For 'save_post':
 *   slug          string
 *   linkedin_url  string  — URL of the LinkedIn post (can be '' to clear)
 *   linkedin_text string  — generated text to store
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
admin_require_login();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : $_POST;

// csrf_verify() reads $_POST, so backfill it from the JSON body
if (!isset($_POST['csrf_token']) && isset($body['csrf_token'])) {
    $_POST['csrf_token'] = $body['csrf_token'];
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
$action = $body['action'] ?? '';
$slug   = basename(str_replace(['..', "\0"], '', $body['slug'] ?? ''));

if (!$slug) {
    echo json_encode(['ok' => false, 'error' => 'Missing slug']);
    exit;
}

$file = ARTICLES_PATH . '/bg/' . $slug . '.json';
if (!file_exists($file)) {
    echo json_encode(['ok' => false, 'error' => 'Article not found']);
    exit;
}

// ── Generate ──────────────────────────────────────────────────────────────────
if ($action === 'generate') {
    $api_key = setting_get('claude_api_key');
    if ($api_key === '') {
        echo json_encode(['ok' => false, 'error' => 'Claude API key not configured (Плащания → Claude).']);
        exit;
    }

    $generate_type = $body['generate_type'] ?? 'news';
    $article = load_json($file);

    // Prefer the EN version of the article if it exists (LinkedIn audience is international)
    $slug_en = $article['slug_en'] ?? $slug;
    $en_file = ARTICLES_PATH . '/en/' . $slug_en . '.json';
    $source  = file_exists($en_file) ? load_json($en_file) : $article;
    $title   = $source['title'] ?? ($article['title'] ?? '');
    $content = mb_substr(strip_tags($source['content'] ?? ($article['content'] ?? '')), 0, 3000);

    if ($generate_type === 'post') {
        $article_url = rtrim(SITE_URL, '/') . '/en/news/' . rawurlencode($slug_en) . '/';
        $prompt = "You write LinkedIn posts for Odd Minds Foundation (Фондация Различни Умове) — a Bulgarian NGO supporting children with developmental differences and children without parental care.

VOICE:
- Warm, human, first-person plural — use \"we\", \"us\", \"our team\"
- Radically honest — share setbacks and uncertainties openly, never spin bad news
- Grounded and practical — celebrate small, concrete milestones with real numbers and names
- Emotionally intelligent, not sentimental — let specific details carry the emotion
- Playful and self-aware — light authentic humour is welcome
- Transparent about process — share behind-the-scenes decisions

STRUCTURE:
- Open with a hook: striking contrast, surprising fact, or short declarative statement
- Short punchy paragraphs (2–4 sentences each), generous white space
- Use bold headings for named sub-sections where appropriate (e.g. **Section Title 🏆**)
- Close with a soft, authentic CTA — not salesy
- No hashtags. Never.

FORMATTING:
- English only
- Plain text with bold for emphasis/headings only
- 1–3 emojis max, aligned to emotional beat (celebrations: 🏆 🎉, warmth: ❤️ 🙏, neutral: 👉)
- No em dashes — use full stops or new sentences instead
- Link to the article at the end of the body, before hashtags: {$article_url}

DO NOT:
- Write in third person (\"The Foundation does...\")
- Use charity clichés: \"changing lives\", \"making a difference\", \"at-risk youth\", \"vulnerable populations\"
- Use hyperbolic language: \"incredible\", \"amazing\", \"life-changing\"
- Use heavy passive voice
- Write long unbroken walls of text
- Use formal press release language

Write a LinkedIn post for this article.
Title: {$title}
Content: {$content}

Return only the post text. No explanations, no alternatives.";
    } else {
        $prompt = "Write a LinkedIn post for Odd Minds Foundation, a Bulgarian NGO supporting children with developmental differences and children without parental care.

Voice: warm, honest, professional but not corporate. Real stories, real impact.

Based on this article:
Title: {$title}
Content: {$content}

Write the LinkedIn post in English. Guidelines:
- 3–5 short paragraphs
- Lead with impact or insight, not just \"we did X\"
- Warm and authentic, suitable for donors, partners, institutional audiences
- End with a brief call to action or reflection
- Maximum 3 hashtags, professional only
- Plain text, no markdown formatting

Return only the post text, nothing else.";
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 600,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
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
        _om_log('linkedin-ajax generate', 'cURL: ' . $curl_err);
        echo json_encode(['ok' => false, 'error' => 'API connection failed.']);
        exit;
    }
    if ($http_code !== 200) {
        $d = json_decode((string)$response, true);
        _om_log('linkedin-ajax generate', "HTTP {$http_code}: " . ($d['error']['message'] ?? ''));
        echo json_encode(['ok' => false, 'error' => 'Claude API error (HTTP ' . $http_code . ').']);
        exit;
    }

    $d    = json_decode((string)$response, true);
    $text = trim($d['content'][0]['text'] ?? '');

    if ($text === '') {
        echo json_encode(['ok' => false, 'error' => 'Empty response from Claude.']);
        exit;
    }

    // Persist the generated text immediately so it survives page reloads
    $data = load_json($file);
    $data['linkedin_text'] = $text;
    save_json($file, $data);

    echo json_encode(['ok' => true, 'text' => $text]);
    exit;
}

// ── Save post URL ─────────────────────────────────────────────────────────────
if ($action === 'save_post') {
    $url          = trim($body['linkedin_url']  ?? '');
    $linkedin_text = trim($body['linkedin_text'] ?? '');

    // Basic URL validation if non-empty
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Невалиден URL.']);
        exit;
    }

    $data = load_json($file);
    $data['linkedin_url']       = $url;
    $data['linkedin_posted_at'] = $url !== '' ? date('Y-m-d H:i:s') : '';
    if ($linkedin_text !== '') {
        $data['linkedin_text'] = $linkedin_text;
    }

    if (save_json($file, $data)) {
        echo json_encode(['ok' => true, 'posted_at' => $data['linkedin_posted_at']]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Грешка при запис.']);
    }
    exit;
}

// ── Schedule via Buffer ───────────────────────────────────────────────────────
if ($action === 'schedule') {
    $linkedin_text = trim($body['linkedin_text'] ?? '');
    $scheduled_at  = trim($body['scheduled_at']  ?? '');

    if ($linkedin_text === '') {
        echo json_encode(['ok' => false, 'error' => 'Няма текст за публикуване.']);
        exit;
    }
    if ($scheduled_at === '') {
        echo json_encode(['ok' => false, 'error' => 'Изберете дата и час.']);
        exit;
    }

    // datetime-local sends local (Sofia) time with no TZ suffix — parse explicitly as Europe/Sofia
    $publish_now = ($scheduled_at === 'now');
    if ($publish_now) {
        $ts = time() + 120; // 2 minutes from now
    } else {
        try {
            $dt = new DateTimeImmutable($scheduled_at, new DateTimeZone('Europe/Sofia'));
            $ts = $dt->getTimestamp();
        } catch (Exception) {
            $ts = 0;
        }
        if (!$ts || $ts <= time()) {
            echo json_encode(['ok' => false, 'error' => 'Датата трябва да е в бъдещето.']);
            exit;
        }
    }

    $channel_id = setting_resolve('buffer_linkedin_channel_id', 'BUFFER_LINKEDIN_CHANNEL_ID');
    if ($channel_id === '') {
        echo json_encode(['ok' => false, 'error' => 'Buffer LinkedIn Channel ID не е конфигуриран (Плащания → Buffer).']);
        exit;
    }

    $api_key = setting_resolve('buffer_api_key', 'BUFFER_API_KEY');
    if ($api_key === '') {
        echo json_encode(['ok' => false, 'error' => 'Buffer API ключ не е конфигуриран (Плащания → Buffer).']);
        exit;
    }

    $due_at = gmdate('Y-m-d\TH:i:s\Z', $ts);

    // If there's an existing scheduled Buffer post, delete it first.
    // We must confirm deletion before creating — otherwise we get duplicates.
    $existing_data      = load_json($file);
    $old_buffer_post_id = $existing_data['buffer_post_id'] ?? '';
    if ($old_buffer_post_id !== '') {
        // Reschedule: update the existing post's time instead of delete+create.
        // Buffer's dedup rejects the same text even after deletion for a period,
        // so updating is the only safe way to change the schedule.
        $update_query = 'mutation { updatePost(input: {
            id: ' . json_encode($old_buffer_post_id) . ',
            text: ' . json_encode($linkedin_text) . ',
            dueAt: ' . json_encode($due_at) . '
        }) {
            ... on PostActionSuccess { post { id } }
            ... on MutationError { message }
        } }';

        $uch = curl_init('https://api.buffer.com');
        curl_setopt_array($uch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_POSTFIELDS     => json_encode(['query' => $update_query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $uraw = curl_exec($uch);
        $uerr = curl_error($uch);
        curl_close($uch);

        if ($uerr) {
            echo json_encode(['ok' => false, 'error' => 'Грешка при свързване с Buffer: ' . $uerr]);
            exit;
        }

        $uresp        = json_decode((string)$uraw, true);
        $update_error = $uresp['data']['updatePost']['message'] ?? ($uresp['errors'][0]['message'] ?? '');

        if ($update_error !== '') {
            // Stale ID (post was deleted in Buffer) — clear it and fall through to create
            _om_log('ERROR', "linkedin-ajax schedule: stale post ID, falling through to create: " . $update_error);
            $existing_data['buffer_post_id'] = '';
            save_json($file, $existing_data);
            $old_buffer_post_id = ''; // fall through to createPost below
        } else {
            // Updated successfully — save and return
            $existing_data['linkedin_scheduled_at'] = $scheduled_at;
            $existing_data['linkedin_due_at']        = $due_at;
            $existing_data['linkedin_text']          = $linkedin_text;
            save_json($file, $existing_data);

            echo json_encode([
                'ok'             => true,
                'buffer_post_id' => $old_buffer_post_id,
                'due_at'         => $due_at,
                'scheduled_at'   => $scheduled_at,
            ]);
            exit;
        }
    }

    // No existing post — create fresh
    $article_data = load_json($file);
    $image_path   = $article_data['image'] ?? '';
    $image_url    = '';
    if ($image_path !== '' && is_file($_SERVER['DOCUMENT_ROOT'] . $image_path)) {
        $encoded   = implode('/', array_map('rawurlencode', explode('/', ltrim($image_path, '/'))));
        $image_url = SITE_URL . '/' . $encoded;
    }
    _om_log('INFO', "linkedin-ajax schedule: image_path={$image_path} image_url={$image_url}");

    // Resize image if wider than 4800px (Buffer max is 5000px)
    if ($image_url !== '') {
        $abs_path = $_SERVER['DOCUMENT_ROOT'] . $image_path;
        $size     = @getimagesize($abs_path);
        if ($size && $size[0] > 4800) {
            $ext     = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
            $ig_path = preg_replace('/\.' . preg_quote($ext, '/') . '$/', '-ig.' . $ext, $image_path);
            $ig_abs  = $_SERVER['DOCUMENT_ROOT'] . $ig_path;
            $resized = false;
            $src     = match($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($abs_path),
                'png'         => @imagecreatefrompng($abs_path),
                'webp'        => @imagecreatefromwebp($abs_path),
                default       => false,
            };
            if ($src) {
                $new_w = 4800;
                $new_h = (int)round($size[1] * $new_w / $size[0]);
                $dst   = imagecreatetruecolor($new_w, $new_h);
                if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $size[0], $size[1]);
                $resized = match($ext) {
                    'jpg', 'jpeg' => imagejpeg($dst, $ig_abs, 90),
                    'png'         => imagepng($dst, $ig_abs),
                    'webp'        => imagewebp($dst, $ig_abs, 90),
                    default       => false,
                };
                imagedestroy($src);
                imagedestroy($dst);
            }
            if ($resized) {
                $encoded   = implode('/', array_map('rawurlencode', explode('/', ltrim($ig_path, '/'))));
                $image_url = SITE_URL . '/' . $encoded;
                _om_log('INFO', "linkedin-ajax schedule: resized to {$ig_path}");
            }
        }
    }

    $assets_gql   = $image_url !== ''
        ? 'assets: [{ image: { url: ' . json_encode($image_url) . ' } }],'
        : '';
    $query = 'mutation CreatePost {
  createPost(input: {
    ' . $assets_gql . '
    text: ' . json_encode($linkedin_text) . ',
    channelId: ' . json_encode($channel_id) . ',
    schedulingType: automatic,
    mode: customScheduled,
    dueAt: ' . json_encode($due_at) . '
  }) {
    ... on PostActionSuccess {
      post { id text }
    }
    ... on MutationError {
      message
    }
  }
}';

    $ch = curl_init('https://api.buffer.com');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        _om_log('linkedin-ajax schedule', 'cURL: ' . $curl_err);
        echo json_encode(['ok' => false, 'error' => 'Грешка при свързване с Buffer.']);
        exit;
    }

    $resp = json_decode((string)$raw, true);

    // GraphQL always returns HTTP 200 — check the response body for errors
    if (isset($resp['data']['createPost']['post']['id'])) {
        $buffer_post_id = $resp['data']['createPost']['post']['id'];

        $data = load_json($file);
        $data['linkedin_text']          = $linkedin_text;
        $data['linkedin_scheduled_at']  = $scheduled_at;
        $data['linkedin_due_at']        = $due_at;
        $data['buffer_post_id']         = $buffer_post_id;
        // Clear any previous "posted" state from this slot
        $data['linkedin_url']           = $data['linkedin_url']       ?? '';
        $data['linkedin_posted_at']     = $data['linkedin_posted_at'] ?? '';

        if (save_json($file, $data)) {
            echo json_encode([
                'ok'           => true,
                'buffer_post_id' => $buffer_post_id,
                'due_at'       => $due_at,
                'scheduled_at' => $scheduled_at,
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Публикувано в Buffer, но грешка при запис на файла.']);
        }
        exit;
    }

    // MutationError or unexpected shape
    $error_msg = $resp['data']['createPost']['message']
        ?? ($resp['errors'][0]['message'] ?? 'Неочакван отговор от Buffer.');
    _om_log('linkedin-ajax schedule', 'Buffer error: ' . $error_msg . ' | raw: ' . substr((string)$raw, 0, 300));
    echo json_encode(['ok' => false, 'error' => 'Buffer: ' . $error_msg]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
