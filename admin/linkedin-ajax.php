<?php
/**
 * LinkedIn panel AJAX handler.
 *
 * POST body (JSON):
 *   action      'check' | 'schedule'
 *   csrf_token  string
 *   slug        string  — article slug (BG; not needed for 'check')
 *
 * For 'check' (a dry run: runs every check 'schedule' runs, calls nothing, writes nothing):
 *   scheduled_at                       — as for 'schedule'
 *   title, title_en, content_en string — the editor's unsaved titles and English text
 *   The panel calls it before it saves and publishes the article. It works from the
 *   editor's fields — what the save is about to write — so a new article is checked too.
 *
 * For 'schedule':
 *   scheduled_at  string — datetime-local (Europe/Sofia) or 'now'
 *   (no text is accepted: the post is built on the server from the saved English article)
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

// csrf_verify() reads $_POST, so backfill it from the JSON body
if (!isset($_POST['csrf_token']) && isset($body['csrf_token'])) {
    $_POST['csrf_token'] = $body['csrf_token'];
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
$action = $body['action'] ?? '';
$slug   = basename(str_replace(['..', "\0"], '', is_string($body['slug'] ?? null) ? $body['slug'] : ''));
$scheduled_at = is_string($body['scheduled_at'] ?? null) ? trim($body['scheduled_at']) : '';

// ── Dry run before the panel saves and publishes ─────────────────────────────
if ($action === 'check') {
    $str = static fn(string $k): string => is_string($body[$k] ?? null) ? trim($body[$k]) : '';
    [$channel_id, $api_key] = social_buffer_credentials('linkedin');
    // An empty EN title is saved as the BG one (article-edit.php), so it is checked that way too.
    $check = social_schedule_check('linkedin', false, $scheduled_at, $channel_id, $api_key, [],
        ['title' => $str('title_en') !== '' ? $str('title_en') : $str('title'), 'content' => $str('content_en')]);
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

// ── Schedule via Buffer ───────────────────────────────────────────────────────
if ($action === 'schedule') {
    $existing_data      = load_json($file);
    $en_data            = load_json(ARTICLES_PATH . '/en/' . basename((string) ($existing_data['slug_en'] ?? $slug)) . '.json') ?: [];

    // The same checks the panel's dry run made, now on the saved article.
    [$channel_id, $api_key] = social_buffer_credentials('linkedin');
    $check = social_schedule_check('linkedin', false, $scheduled_at, $channel_id, $api_key, $existing_data, $en_data);
    if (!$check['ok']) {
        echo json_encode(['ok' => false, 'error' => $check['error']]);
        exit;
    }
    $ts     = $check['ts'];
    $due_at = gmdate('Y-m-d\TH:i:s\Z', $ts);

    // The post IS the saved English article, cut to LinkedIn's limit. Nothing comes from the browser.
    $linkedin_text = social_schedule_text($existing_data, $en_data, 'linkedin');
    $assets_gql         = social_linkedin_assets_gql($existing_data, $en_data);
    _om_log('INFO', 'linkedin-ajax schedule: ' . (str_contains($assets_gql, 'document:') ? 'PDF carousel' : ($assets_gql ? 'one image' : 'no image')));

    // Already in Buffer: edit that post in place. Delete-and-recreate is not an option —
    // Buffer's duplicate check rejects the same text for a while even after deletion.
    // Only a post deleted in Buffer gets a fresh one; anything else is reported.
    $old_buffer_post_id = $existing_data['buffer_post_id'] ?? '';
    if ($old_buffer_post_id !== '') {
        $update_query = buffer_edit_post_query($old_buffer_post_id, $linkedin_text, $due_at, $assets_gql, '');

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

        $edit = buffer_edit_outcome(json_decode((string)$uraw, true));

        if ($edit['status'] === 'ok') {
            $existing_data['linkedin_scheduled_at'] = $scheduled_at;
            $existing_data['linkedin_due_at']        = $due_at;
            save_json($file, $existing_data);

            echo json_encode([
                'ok'             => true,
                'buffer_post_id' => $old_buffer_post_id,
                'due_at'         => $due_at,
                'scheduled_at'   => $scheduled_at,
                'site_notice'    => article_publish_with_social($slug, $ts, 'LinkedIn'),
            ]);
            exit;
        }

        if ($edit['status'] === 'error') {
            _om_log('ERROR', 'linkedin-ajax schedule: edit failed: ' . $edit['message']);
            echo json_encode(['ok' => false, 'error' => 'Публикацията в Buffer не можа да се промени (' . $edit['message'] . '). Нищо не е публикувано два пъти — опитайте отново след малко.']);
            exit;
        }

        // Deleted in Buffer — forget the old id and create a fresh post below.
        _om_log('INFO', 'linkedin-ajax schedule: post gone in Buffer, creating a new one');
        $existing_data['buffer_post_id'] = '';
        save_json($file, $existing_data);
    }

    // No existing post — create fresh
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
                'site_notice'  => article_publish_with_social($slug, $ts, 'LinkedIn'),
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
