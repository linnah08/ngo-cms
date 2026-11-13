<?php
/**
 * Facebook / Instagram post AJAX handler.
 *
 * POST body (JSON):
 *   action      'check' | 'schedule' | 'save_crop' | 'save_shape' | 'save_fit'
 *   csrf_token  string
 *   slug        string   — BG article slug (not needed for 'check')
 *
 * For 'check' (a dry run: runs every check 'schedule' runs, calls nothing, writes nothing):
 *   scheduled_at, channel, post_type — as for 'schedule'
 *   title, content  string   — the editor's unsaved BG title and text
 *   photos          string[] — the editor's photo paths
 *   The panel calls it before it saves and publishes the article, so a post that would be
 *   refused never publishes a draft. It works from the editor's fields — exactly what the
 *   save is about to write — so a new, never-saved article is checked the same way.
 *
 * For 'schedule':
 *   (no text is accepted: the post is built on the server from the saved article)
 *   scheduled_at  string   — datetime-local (Europe/Sofia)
 *   channel       string   — 'fb' or 'insta'
 *
 * For 'save_crop':
 *   kind  string — 'post' or 'story'
 *   src   string — one of the article's own photos
 *   rect  object — {x, y, w, h} fractions of the photo, ar = the shape it was made for
 *
 * For 'save_shape':
 *   shape string — 'auto', 'portrait', 'square' or 'landscape' (the Instagram post's shape)
 *
 * For 'save_fit':
 *   src   string — one of the article's own photos
 *   fit   string — 'fill' (cut to the post's shape) or 'whole' (all of it, on white)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/social_images.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/buffer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
admin_require_editorial();   // the same people as the article editor that calls it: admin or author
module_ajax_guard('social');

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
$slug   = basename(str_replace(['..', "\0"], '', is_string($body['slug'] ?? null) ? $body['slug'] : ''));

// What 'check' and 'schedule' are asked to do: the same parsing for both.
$scheduled_at = is_string($body['scheduled_at'] ?? null) ? trim($body['scheduled_at']) : '';
$channel      = in_array($body['channel'] ?? '', ['fb', 'insta'], true) ? $body['channel'] : '';
$post_type    = in_array($body['post_type'] ?? 'post', ['post', 'story'], true) ? $body['post_type'] : 'post';
$is_story     = ($channel === 'insta' && $post_type === 'story');

// ── Dry run before the panel saves and publishes ─────────────────────────────
if ($action === 'check') {
    $str    = static fn(string $k): string => is_string($body[$k] ?? null) ? $body[$k] : '';
    $photos = [];
    foreach ((is_array($body['photos'] ?? null) ? $body['photos'] : []) as $src) {
        if (is_string($src)) $photos[] = ['src' => $src, 'caption' => ''];
    }
    [$channel_id, $api_key] = social_buffer_credentials($channel);
    $check = social_schedule_check($channel, $is_story, $scheduled_at, $channel_id, $api_key,
        ['title' => trim($str('title')), 'content' => $str('content'), 'photos' => $photos], []);
    echo json_encode($check['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $check['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$slug) {
    echo json_encode(['ok' => false, 'error' => 'Missing slug']);
    exit;
}

$file = ARTICLES_PATH . '/bg/' . $slug . '.json';
if (!file_exists($file)) {
    echo json_encode(['ok' => false, 'error' => 'Article not found']);
    exit;
}

// ── Save an Instagram crop ────────────────────────────────────────────────────
// Which part of a photo Instagram shows. Stored on the BG article as fractions of the
// photo, with the shape it was made for: insta_crops.{post|story}.{src} = {x,y,w,h,ar}.
if ($action === 'save_crop') {
    $data  = load_json($file);
    $clean = social_crop_clean($data, $body);
    if (!$clean['ok']) {
        echo json_encode(['ok' => false, 'error' => $clean['error']]);
        exit;
    }
    $crops = is_array($data['insta_crops'] ?? null) ? $data['insta_crops'] : [];
    if (!is_array($crops[$clean['kind']] ?? null)) $crops[$clean['kind']] = [];
    $crops[$clean['kind']][$clean['src']] = $clean['rect'];
    $data['insta_crops'] = $crops;
    // Choosing the part to show means the photo fills the frame.
    if ($clean['kind'] === 'post') {
        $fits = is_array($data['insta_fit'] ?? null) ? $data['insta_fit'] : [];
        if (!is_array($fits['post'] ?? null)) $fits['post'] = [];
        $fits['post'][$clean['src']] = 'fill';
        $data['insta_fit'] = $fits;
    }
    if (!save_json($file, $data)) {
        _om_log('ERROR', "social-ajax save_crop: failed to save {$file}");
        echo json_encode(['ok' => false, 'error' => 'Изрязването не можа да се запази. Опитайте отново.']);
        exit;
    }
    echo json_encode(['ok' => true, 'rect' => $clean['rect'],
        'post' => $clean['kind'] === 'post' ? social_insta_preview($data)['post'] : null]);
    exit;
}

// ── Whole photo or fill the frame ─────────────────────────────────────────────
// Per photo of the Instagram post: insta_fit.post.{src} = 'fill' | 'whole' on the BG
// article. Answers with the new preview, so the editor can redraw the photos.
if ($action === 'save_fit') {
    $data  = load_json($file);
    $clean = social_fit_clean($data, $body);
    if (!$clean['ok']) {
        echo json_encode(['ok' => false, 'error' => $clean['error']]);
        exit;
    }
    $fits = is_array($data['insta_fit'] ?? null) ? $data['insta_fit'] : [];
    if (!is_array($fits['post'] ?? null)) $fits['post'] = [];
    $fits['post'][$clean['src']] = $clean['fit'];
    $data['insta_fit'] = $fits;
    if (!save_json($file, $data)) {
        _om_log('ERROR', "social-ajax save_fit: failed to save {$file}");
        echo json_encode(['ok' => false, 'error' => 'Изборът не можа да се запази. Опитайте отново.']);
        exit;
    }
    echo json_encode(['ok' => true, 'post' => social_insta_preview($data)['post']]);
    exit;
}

// ── Pick the Instagram post shape ─────────────────────────────────────────────
// 'auto', 'portrait', 'square' or 'landscape', stored on the BG article as insta_shape.
// Answers with the new preview, so the editor can redraw the photos at that shape.
if ($action === 'save_shape') {
    $shape = $body['shape'] ?? null;
    if (!is_string($shape) || ($shape !== 'auto' && !isset(SOCIAL_IG_SHAPES[$shape]))) {
        echo json_encode(['ok' => false, 'error' => 'Невалидна форма.']);
        exit;
    }
    $data = load_json($file);
    $data['insta_shape'] = $shape;
    if (!save_json($file, $data)) {
        _om_log('ERROR', "social-ajax save_shape: failed to save {$file}");
        echo json_encode(['ok' => false, 'error' => 'Формата не можа да се запази. Опитайте отново.']);
        exit;
    }
    echo json_encode(['ok' => true, 'shape' => $shape, 'post' => social_insta_preview($data)['post']]);
    exit;
}

// ── Schedule via Buffer ───────────────────────────────────────────────────────
if ($action === 'schedule') {
    $data = load_json($file);

    // The same checks the panel's dry run made, now on the saved article.
    [$channel_id, $api_key] = social_buffer_credentials($channel);
    $check = social_schedule_check($channel, $is_story, $scheduled_at, $channel_id, $api_key, $data, []);
    if (!$check['ok']) {
        echo json_encode(['ok' => false, 'error' => $check['error']]);
        exit;
    }
    $ts            = $check['ts'];
    $channel_label = $channel === 'fb' ? 'Facebook' : 'Instagram';

    $due_at       = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $post_id_key  = $channel === 'fb' ? 'fb_buffer_post_id' : ($is_story ? 'insta_story_buffer_post_id' : 'insta_buffer_post_id');
    $sched_key    = $channel === 'fb' ? 'fb_scheduled_at'   : ($is_story ? 'insta_story_scheduled_at'   : 'insta_scheduled_at');
    $due_key      = $channel === 'fb' ? 'fb_due_at'         : ($is_story ? 'insta_story_due_at'         : 'insta_due_at');

    // The post IS the saved article, cut to the platform's limit. Nothing comes from the browser.
    // A Story has no caption, so it sends no text.
    $post_text = $is_story ? '' : social_schedule_text($data, [], $channel);
    $old_buffer_post_id = $data[$post_id_key] ?? '';

    $req = social_fb_insta_request($data, $channel, $is_story);
    _om_log('INFO', "social-ajax schedule/{$channel}: " . count($req['urls']) . ' photo(s)');

    // Instagram requires an image — a photo that couldn't be made ready counts as none.
    if ($channel === 'insta' && !$req['urls']) {
        echo json_encode(['ok' => false, 'error' => 'Instagram изисква снимка. Добавете снимка към статията преди да планирате.']);
        exit;
    }

    // Already in Buffer: edit that post in place (text, time and current photos).
    // Only a post deleted in Buffer gets a fresh one — anything else is reported, so a
    // failed edit never leaves two posts queued.
    if ($old_buffer_post_id !== '') {
        $update_query = buffer_edit_post_query($old_buffer_post_id, $post_text, $due_at,
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
            $data[$sched_key]    = $scheduled_at;
            $data[$due_key]      = $due_at;
            if (!save_json($file, $data)) {
                _om_log('ERROR', "social-ajax schedule/{$channel}: failed to save after edit");
                echo json_encode(['ok' => false, 'error' => 'Планирано в Buffer, но грешка при запис на файла.']);
                exit;
            }
            echo json_encode(['ok' => true, 'buffer_post_id' => $old_buffer_post_id, 'due_at' => $due_at, 'scheduled_at' => $scheduled_at,
                'site_notice' => article_publish_with_social($slug, $ts, $channel_label)]);
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
    text: ' . json_encode($post_text) . ',
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

        $data[$post_id_key]   = $buffer_post_id;
        $data[$sched_key]     = $scheduled_at;
        $data[$due_key]       = $due_at;

        if (!save_json($file, $data)) {
            _om_log('ERROR', "social-ajax schedule/{$channel}: failed to save after create");
            echo json_encode(['ok' => false, 'error' => 'Публикувано в Buffer, но грешка при запис на файла.']);
            exit;
        }

        echo json_encode(['ok' => true, 'buffer_post_id' => $buffer_post_id, 'due_at' => $due_at, 'scheduled_at' => $scheduled_at,
            'site_notice' => article_publish_with_social($slug, $ts, $channel_label)]);
        exit;
    }

    $error_msg = $resp['data']['createPost']['message'] ?? ($resp['errors'][0]['message'] ?? 'Неочакван отговор от Buffer.');
    _om_log('ERROR', "social-ajax schedule/{$channel}: Buffer error: {$error_msg} | raw: " . substr((string)$raw, 0, 300));
    echo json_encode(['ok' => false, 'error' => 'Buffer: ' . $error_msg]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
