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
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/social_images.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/buffer.php';
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
        $prompt = "You write Facebook posts for a Bulgarian non-profit organisation.

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
        $prompt = "Напиши публикация за Facebook и Instagram за българска НПО.

Тон: топъл, честен, близък. Не корпоративен. Истински истории, реален ефект. Подходящ за широката аудитория.

На базата на тази статия:
Заглавие: {$title}
Съдържание: {$content}

Напиши публикацията на БЪЛГАРСКИ. Насоки:
- 2–4 кратки абзаца
- Започни с нещо, което привлича внимание — въпрос, факт или момент
- Топло и автентично, подходящо за родители, доброволци, дарители
- Завърши с призив за действие или размисъл
- 3–5 хаштага на Bulgarian (напр. #НПО #Дарение)
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
    $post_type    = in_array($body['post_type'] ?? 'post', ['post', 'story'], true) ? $body['post_type'] : 'post';
    $is_story     = ($channel === 'insta' && $post_type === 'story');

    // Instagram Stories have no caption — the API doesn't support one — so
    // there's nothing for the admin to type and nothing to require here.
    if (!$is_story && $social_text === '') {
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
    $post_id_key  = $channel === 'fb' ? 'fb_buffer_post_id' : ($is_story ? 'insta_story_buffer_post_id' : 'insta_buffer_post_id');
    $sched_key    = $channel === 'fb' ? 'fb_scheduled_at'   : ($is_story ? 'insta_story_scheduled_at'   : 'insta_scheduled_at');
    $due_key      = $channel === 'fb' ? 'fb_due_at'         : ($is_story ? 'insta_story_due_at'         : 'insta_due_at');
    $text_key     = $channel === 'fb' ? 'fb_text'           : 'insta_text';

    $data               = load_json($file);
    $old_buffer_post_id = $data[$post_id_key] ?? '';

    $req = social_fb_insta_request($data, $channel, $is_story);
    _om_log('INFO', "social-ajax schedule/{$channel}: " . count($req['urls']) . ' photo(s)');

    // Instagram requires an image
    if ($channel === 'insta' && !$req['urls']) {
        echo json_encode(['ok' => false, 'error' => 'Instagram изисква снимка. Добавете снимка към статията преди да планирате.']);
        exit;
    }

    // Already in Buffer: edit that post in place (text, time and current photos).
    // Only a post deleted in Buffer gets a fresh one — anything else is reported, so a
    // failed edit never leaves two posts queued.
    if ($old_buffer_post_id !== '') {
        $update_query = buffer_edit_post_query($old_buffer_post_id, $social_text, $due_at,
            social_buffer_assets_gql($req['urls']), $req['metadata']);

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
            _om_log('ERROR', "social-ajax schedule/{$channel}: cURL edit: " . $uerr);
            echo json_encode(['ok' => false, 'error' => 'Грешка при свързване с Buffer: ' . $uerr]);
            exit;
        }

        $edit = buffer_edit_outcome(json_decode((string)$uraw, true));

        if ($edit['status'] === 'ok') {
            // A story has no text — keep the feed post's caption as it is.
            if (!$is_story) {
                $data['social_text'] = $social_text;
                $data[$text_key]     = $social_text;
            }
            $data[$sched_key]    = $scheduled_at;
            $data[$due_key]      = $due_at;
            if (!save_json($file, $data)) {
                _om_log('ERROR', "social-ajax schedule/{$channel}: failed to save after edit");
                echo json_encode(['ok' => false, 'error' => 'Планирано в Buffer, но грешка при запис на файла.']);
                exit;
            }
            echo json_encode(['ok' => true, 'buffer_post_id' => $old_buffer_post_id, 'due_at' => $due_at, 'scheduled_at' => $scheduled_at]);
            exit;
        }

        if ($edit['status'] === 'error') {
            _om_log('ERROR', "social-ajax schedule/{$channel}: edit failed: " . $edit['message']);
            echo json_encode(['ok' => false, 'error' => 'Публикацията в Buffer не можа да се промени (' . $edit['message'] . '). Нищо не е публикувано два пъти — опитайте отново след малко.']);
            exit;
        }

        // Deleted in Buffer — forget the old id and create a fresh post below.
        _om_log('INFO', "social-ajax schedule/{$channel}: post gone in Buffer, creating a new one");
        $data[$post_id_key] = '';
        save_json($file, $data);
        $old_buffer_post_id = '';
    }

    // Create fresh post
    $metadata   = $req['metadata'];
    $assets_gql = social_buffer_assets_gql($req['urls']);
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

        if (!$is_story) {
            $data['social_text'] = $social_text;
            $data[$text_key]     = $social_text;
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
