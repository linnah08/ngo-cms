<?php
/**
 * Fetches Buffer Org ID + LinkedIn Channel ID using the provided API key,
 * then saves all three values to the settings table.
 *
 * POST body (JSON):
 *   csrf_token    string
 *   buffer_api_key  string
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
admin_require_admin();

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

$api_key = trim($body['buffer_api_key'] ?? '');
if ($api_key === '') {
    echo json_encode(['ok' => false, 'error' => 'Въведете API ключ.']);
    exit;
}

function buffer_gql(string $api_key, string $query): array
{
    $ch = curl_init('https://api.buffer.com');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) throw new RuntimeException('cURL error: ' . $curl_err);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON from Buffer');
    if (!empty($data['errors'])) throw new RuntimeException($data['errors'][0]['message'] ?? 'GraphQL error');
    return $data;
}

try {
    // Step 1: get org ID
    $org_data = buffer_gql($api_key, '{ account { currentOrganization { id name } } }');
    $org_id   = $org_data['data']['account']['currentOrganization']['id'] ?? '';
    $org_name = $org_data['data']['account']['currentOrganization']['name'] ?? '';
    if ($org_id === '') {
        echo json_encode(['ok' => false, 'error' => 'Не беше намерена организация. Проверете API ключа.']);
        exit;
    }

    // Step 2: get channels, find LinkedIn / Facebook / Instagram
    $ch_data  = buffer_gql($api_key, 'query { channels(input: { organizationId: ' . json_encode($org_id) . ' }) { id name service } }');
    $channels = $ch_data['data']['channels'] ?? [];

    $li_channel    = null;
    $fb_channel    = null;
    $insta_channel = null;
    $all_channels  = [];
    foreach ($channels as $ch) {
        $svc = strtolower($ch['service'] ?? '');
        $all_channels[] = $svc . ': ' . ($ch['name'] ?? $ch['id']);
        if ($svc === 'linkedin')  $li_channel    = $ch;
        if ($svc === 'facebook')  $fb_channel    = $ch;
        if ($svc === 'instagram') $insta_channel = $ch;
    }

    if ($li_channel === null && $fb_channel === null && $insta_channel === null) {
        $list = implode(', ', $all_channels) ?: 'няма канали';
        echo json_encode(['ok' => false, 'error' => 'Не са намерени LinkedIn, Facebook или Instagram канали. Налични: ' . $list]);
        exit;
    }

    // Save API key + org
    setting_set('buffer_api_key', $api_key);
    setting_set('buffer_org_id',  $org_id);

    // Save each found channel
    if ($li_channel)    setting_set('buffer_linkedin_channel_id',  $li_channel['id']);
    if ($fb_channel)    setting_set('buffer_facebook_channel_id',  $fb_channel['id']);
    if ($insta_channel) setting_set('buffer_instagram_channel_id', $insta_channel['id']);

    echo json_encode([
        'ok'               => true,
        'org_name'         => $org_name,
        'org_id'           => $org_id,
        'linkedin_name'    => $li_channel['name']    ?? '',
        'linkedin_id'      => $li_channel['id']      ?? '',
        'facebook_name'    => $fb_channel['name']    ?? '',
        'facebook_id'      => $fb_channel['id']      ?? '',
        'instagram_name'   => $insta_channel['name'] ?? '',
        'instagram_id'     => $insta_channel['id']   ?? '',
        // kept for backward compat with old JS
        'channel_name'     => $li_channel['name']    ?? '',
        'channel_id'       => $li_channel['id']      ?? '',
    ]);

} catch (RuntimeException $e) {
    _om_log('buffer-setup-ajax', $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
