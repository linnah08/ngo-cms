<?php
/**
 * LinkedIn panel AJAX handler.
 *
 * POST body (JSON):
 *   action      'schedule'
 *   csrf_token  string
 *   slug        string  — article slug (BG)
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

// ── Schedule via Buffer ───────────────────────────────────────────────────────
if ($action === 'schedule') {
    $scheduled_at  = trim($body['scheduled_at']  ?? '');

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

    // The photos this post sends now (one image, or the PDF carousel for several).
    $existing_data      = load_json($file);
    $en_data            = load_json(ARTICLES_PATH . '/en/' . basename((string) ($existing_data['slug_en'] ?? $slug)) . '.json') ?: [];

    // The post IS the saved English article, cut to LinkedIn's limit. Nothing comes from the browser.
    $linkedin_text = social_schedule_text($existing_data, $en_data, 'linkedin');
    if ($linkedin_text === '') {
        echo json_encode(['ok' => false, 'error' => 'Няма английски текст — преведете статията.']);
        exit;
    }
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
