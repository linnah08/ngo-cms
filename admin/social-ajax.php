<?php
/**
 * Facebook / Instagram post AJAX handler.
 *
 * POST body (JSON):
 *   action      'generate' | 'schedule'
 *   csrf_token  string
 *   slug        string   — BG article slug
 *
 * For 'generate':
 *   (no extra fields)
 *
 * For 'schedule':
 *   social_text   string   — text to post
 *   scheduled_at  string   — datetime-local (Europe/Sofia)
 *   channel       string   — 'fb' or 'insta'
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
    $title   = $article['title']   ?? '';
    $content = mb_substr(strip_tags($article['content'] ?? ''), 0, 3000);

    if ($generate_type === 'post') {
        $article_url = rtrim(SITE_URL, '/') . '/novini/' . rawurlencode($slug) . '/';
        $prompt = "You write Facebook posts for Фондация Различни умове (Odd Minds Foundation) — a Bulgarian NGO supporting children with developmental differences and ones growing up without parental care.

VOICE: Warm, honest, occasionally dry/funny. Team voice (\"ние\"), never corporate. Story-first. Emotion through specific detail, not adjectives.

STRUCTURE: Hook → context/story → what the article covers → link. CTA at the end only.

FORMATTING RULES:
- Bulgarian only
- 100–200 words
- Short paragraphs, generous white space
- 1–2 emojis max, never mid-sentence
- No dashes or em-dashes. Use full stops instead
- No hashtags. Never.
- No exclamation marks more than once per post
- End with: 👉 {$article_url}

DO NOT:
- Start with \"С радост съобщаваме\", \"Какво става когато\" or \"С удоволствие споделяме\"
- Use NGO jargon: устойчивост, въздействие, екосистема, уязвими групи
- Use dashes to connect clauses (reads as AI-generated)
- Over-explain the foundation's mission
- Invent overly specific illustrative examples

Write a Facebook post for this article.
Title: {$title}
Content: {$content}

Return only the post text. No explanations, no alternatives.";
    } else {
        $prompt = "Напиши публикация за Facebook и Instagram за Фондация Различни Умове - българска НПО, която подкрепя деца с различия в развитието и деца, лишени от родителска грижа. Фондацията също управлява Лафетки - социално предприятие, произвеждащо салфетки-разговорници, пакетирани от младежи от резиденции.

Тон: топъл, честен, близък. Не корпоративен. Истински истории, реален ефект. Подходящ за широката аудитория.

На базата на тази статия:
Заглавие: {$title}
Съдържание: {$content}

Напиши публикацията на БЪЛГАРСКИ. Насоки:
- 2–4 кратки абзаца
- Започни с нещо, което привлича внимание — въпрос, факт или момент
- Топло и автентично, подходящо за родители, доброволци, дарители
- Завърши с призив за действие или размисъл
- 3–5 хаштага на Bulgarian (напр. #РазличниУмове #НПО #Дарение)
- Обикновен текст, без markdown форматиране

Върни само текста на публикацията, нищо друго.";
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 500,
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
        _om_log('ERROR', 'social-ajax generate: cURL: ' . $curl_err);
        echo json_encode(['ok' => false, 'error' => 'API connection failed.']);
        exit;
    }
    if ($http_code !== 200) {
        $d = json_decode((string)$response, true);
        _om_log('ERROR', "social-ajax generate: HTTP {$http_code}: " . ($d['error']['message'] ?? ''));
        echo json_encode(['ok' => false, 'error' => 'Claude API error (HTTP ' . $http_code . ').']);
        exit;
    }

    $d    = json_decode((string)$response, true);
    $text = trim($d['content'][0]['text'] ?? '');

    if ($text === '') {
        _om_log('ERROR', 'social-ajax generate: empty response from Claude');
        echo json_encode(['ok' => false, 'error' => 'Empty response from Claude.']);
        exit;
    }

    $data = load_json($file);
    $data['fb_text']     = $text;
    $data['social_text'] = $text; // backward compat
    if (!save_json($file, $data)) {
        _om_log('ERROR', "social-ajax generate: failed to save {$file}");
        echo json_encode(['ok' => false, 'error' => 'Текстът е генериран, но грешка при запис на файла.']);
        exit;
    }

    echo json_encode(['ok' => true, 'text' => $text]);
    exit;
}

// ── Schedule via Buffer ───────────────────────────────────────────────────────
if ($action === 'schedule') {
    $social_text = trim($body['social_text']  ?? '');
    $scheduled_at = trim($body['scheduled_at'] ?? '');
    $channel      = $body['channel'] ?? '';

    if ($social_text === '') {
        echo json_encode(['ok' => false, 'error' => 'Няма текст за публикуване.']);
        exit;
    }
    if ($scheduled_at === '') {
        echo json_encode(['ok' => false, 'error' => 'Изберете дата и час.']);
        exit;
    }
    if (!in_array($channel, ['fb', 'insta'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Невалиден канал.']);
        exit;
    }

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

    $setting_key = $channel === 'fb' ? 'buffer_facebook_channel_id' : 'buffer_instagram_channel_id';
    $const_key   = $channel === 'fb' ? 'BUFFER_FACEBOOK_CHANNEL_ID' : 'BUFFER_INSTAGRAM_CHANNEL_ID';
    $channel_id  = setting_resolve($setting_key, $const_key);
    $channel_label = $channel === 'fb' ? 'Facebook' : 'Instagram';

    if ($channel_id === '') {
        echo json_encode(['ok' => false, 'error' => "Buffer {$channel_label} Channel ID не е конфигуриран (Плащания → Buffer)."]);
        exit;
    }

    $api_key = setting_resolve('buffer_api_key', 'BUFFER_API_KEY');
    if ($api_key === '') {
        echo json_encode(['ok' => false, 'error' => 'Buffer API ключ не е конфигуриран (Плащания → Buffer).']);
        exit;
    }

    $due_at       = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $post_id_key  = $channel === 'fb' ? 'fb_buffer_post_id' : 'insta_buffer_post_id';
    $sched_key    = $channel === 'fb' ? 'fb_scheduled_at'   : 'insta_scheduled_at';
    $due_key      = $channel === 'fb' ? 'fb_due_at'         : 'insta_due_at';

    $data               = load_json($file);
    $old_buffer_post_id = $data[$post_id_key] ?? '';

    // Build absolute image URL — only if the file actually exists on disk
    $image_path = $data['image'] ?? '';
    $image_url  = '';
    if ($image_path !== '' && is_file($_SERVER['DOCUMENT_ROOT'] . $image_path)) {
        $encoded   = implode('/', array_map('rawurlencode', explode('/', ltrim($image_path, '/'))));
        $image_url = SITE_URL . '/' . $encoded;
    }
    _om_log('INFO', "social-ajax schedule/{$channel}: image_path={$image_path} image_url={$image_url}");

    // Instagram requires an image
    if ($channel === 'insta' && $image_url === '') {
        echo json_encode(['ok' => false, 'error' => 'Instagram изисква снимка. Добавете снимка към статията преди да планирате.']);
        exit;
    }

    // Resize/crop image for Buffer/Instagram constraints
    if ($image_url !== '') {
        $abs_path  = $_SERVER['DOCUMENT_ROOT'] . $image_path;
        $ext       = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
        $ig_path   = preg_replace('/\.' . preg_quote($ext, '/') . '$/', '-ig.' . $ext, $image_path);
        $ig_abs    = $_SERVER['DOCUMENT_ROOT'] . $ig_path;
        $work_abs  = $abs_path;
        $size      = @getimagesize($abs_path);

        // Step 1: resize if wider than 4800px (Buffer max is 5000px)
        if ($size && $size[0] > 4800) {
            $src = match($ext) {
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
                if ($resized) {
                    $encoded   = implode('/', array_map('rawurlencode', explode('/', ltrim($ig_path, '/'))));
                    $image_url = SITE_URL . '/' . $encoded;
                    $work_abs  = $ig_abs;
                    _om_log('INFO', "social-ajax schedule/{$channel}: resized to {$ig_path}");
                }
            }
        }

        // Step 2: for Instagram, enforce aspect ratio between 4:5 (0.8) and 1.91:1
        if ($channel === 'insta') {
            $check_size = @getimagesize($work_abs);
            if ($check_size) {
                $w     = $check_size[0];
                $h     = $check_size[1];
                $ratio = $w / $h;
                $crop_w = $w; $crop_h = $h; $crop_x = 0; $crop_y = 0;

                if ($ratio < 0.8) {
                    // Too tall — center-crop height to 4:5
                    $crop_h = (int)round($w * 5 / 4);
                    $crop_y = (int)(($h - $crop_h) / 2);
                } elseif ($ratio > 1.91) {
                    // Too wide — center-crop width to 1.91:1
                    $crop_w = (int)round($h * 1.91);
                    $crop_x = (int)(($w - $crop_w) / 2);
                }

                if ($crop_w !== $w || $crop_h !== $h) {
                    $src = match($ext) {
                        'jpg', 'jpeg' => @imagecreatefromjpeg($work_abs),
                        'png'         => @imagecreatefrompng($work_abs),
                        'webp'        => @imagecreatefromwebp($work_abs),
                        default       => false,
                    };
                    if ($src) {
                        $dst = imagecreatetruecolor($crop_w, $crop_h);
                        if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
                        imagecopy($dst, $src, 0, 0, $crop_x, $crop_y, $crop_w, $crop_h);
                        $cropped = match($ext) {
                            'jpg', 'jpeg' => imagejpeg($dst, $ig_abs, 90),
                            'png'         => imagepng($dst, $ig_abs),
                            'webp'        => imagewebp($dst, $ig_abs, 90),
                            default       => false,
                        };
                        imagedestroy($src);
                        imagedestroy($dst);
                        if ($cropped) {
                            $encoded   = implode('/', array_map('rawurlencode', explode('/', ltrim($ig_path, '/'))));
                            $image_url = SITE_URL . '/' . $encoded;
                            _om_log('INFO', "social-ajax schedule/{$channel}: cropped aspect {$ratio} → {$crop_w}x{$crop_h}");
                        }
                    }
                }
            }
        }
    }

    // If there's an existing scheduled post, update it
    if ($old_buffer_post_id !== '') {
        $update_query = 'mutation { updatePost(input: {
            id: ' . json_encode($old_buffer_post_id) . ',
            text: ' . json_encode($social_text) . ',
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
            _om_log('ERROR', "social-ajax schedule/{$channel}: cURL update: " . $uerr);
            echo json_encode(['ok' => false, 'error' => 'Грешка при свързване с Buffer: ' . $uerr]);
            exit;
        }

        $uresp        = json_decode((string)$uraw, true);
        $update_error = $uresp['data']['updatePost']['message'] ?? ($uresp['errors'][0]['message'] ?? '');

        if ($update_error === '') {
            // Updated successfully
            $data['social_text'] = $social_text;
            if ($channel === 'fb') {
                $data['fb_text'] = $social_text;
            } else {
                $data['insta_text'] = $social_text;
            }
            $data[$sched_key]    = $scheduled_at;
            $data[$due_key]      = $due_at;
            if (!save_json($file, $data)) {
                _om_log('ERROR', "social-ajax schedule/{$channel}: failed to save after update");
                echo json_encode(['ok' => false, 'error' => 'Планирано в Buffer, но грешка при запис на файла.']);
                exit;
            }
            echo json_encode(['ok' => true, 'buffer_post_id' => $old_buffer_post_id, 'due_at' => $due_at, 'scheduled_at' => $scheduled_at]);
            exit;
        }

        // Stale ID — clear it and fall through to create
        _om_log('ERROR', "social-ajax schedule/{$channel}: stale post ID, falling through to create: " . $update_error);
        $data[$post_id_key] = '';
        save_json($file, $data);
        $old_buffer_post_id = '';
    }

    // Create fresh post
    $metadata = $channel === 'fb'
        ? 'facebook: { type: post }'
        : 'instagram: { type: post, shouldShareToFeed: true }';
    $assets_gql = $image_url !== ''
        ? 'assets: [{ image: { url: ' . json_encode($image_url) . ' } }],'
        : '';
    $query = 'mutation {
  createPost(input: {
    ' . $assets_gql . '
    text: ' . json_encode($social_text) . ',
    channelId: ' . json_encode($channel_id) . ',
    schedulingType: automatic,
    mode: customScheduled,
    dueAt: ' . json_encode($due_at) . ',
    metadata: { ' . $metadata . ' }
  }) {
    ... on PostActionSuccess { post { id } }
    ... on MutationError { message }
  }
}';

    $ch = curl_init('https://api.buffer.com');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        _om_log('ERROR', "social-ajax schedule/{$channel}: cURL create: " . $curl_err);
        echo json_encode(['ok' => false, 'error' => 'Грешка при свързване с Buffer.']);
        exit;
    }

    $resp = json_decode((string)$raw, true);

    if (isset($resp['data']['createPost']['post']['id'])) {
        $buffer_post_id = $resp['data']['createPost']['post']['id'];

        $data['social_text']  = $social_text;
        if ($channel === 'fb') {
            $data['fb_text'] = $social_text;
        } else {
            $data['insta_text'] = $social_text;
        }
        $data[$post_id_key]   = $buffer_post_id;
        $data[$sched_key]     = $scheduled_at;
        $data[$due_key]       = $due_at;

        if (!save_json($file, $data)) {
            _om_log('ERROR', "social-ajax schedule/{$channel}: failed to save after create");
            echo json_encode(['ok' => false, 'error' => 'Публикувано в Buffer, но грешка при запис на файла.']);
            exit;
        }

        echo json_encode(['ok' => true, 'buffer_post_id' => $buffer_post_id, 'due_at' => $due_at, 'scheduled_at' => $scheduled_at]);
        exit;
    }

    $error_msg = $resp['data']['createPost']['message'] ?? ($resp['errors'][0]['message'] ?? 'Неочакван отговор от Buffer.');
    _om_log('ERROR', "social-ajax schedule/{$channel}: Buffer error: {$error_msg} | raw: " . substr((string)$raw, 0, 300));
    echo json_encode(['ok' => false, 'error' => 'Buffer: ' . $error_msg]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
