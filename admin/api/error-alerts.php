<?php
// Machine-to-machine endpoint: groundwork for a future hourly AI auto-fix
// cloud routine that would poll captured server errors. The routine itself
// does not exist in this repo (it would be a cloud-side cron) — this file
// only exposes bearer-token-authenticated read access to error_alerts, plus
// the auth mechanism a real routine would use.
//
// Bearer-token authenticated (not a browser session), so CSRF doesn't apply —
// a hostile page can't attach an Authorization header to a forged request.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/error-alerts.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

header('Content-Type: application/json');

function error_alert_api_deny(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$configuredToken = error_alert_api_token();
if ($configuredToken === '') {
    error_alert_api_deny(403, 'AI auto-fix API is not configured');
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('apache_request_headers')) {
    $headers    = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? '';
}
$providedToken = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    $providedToken = trim($m[1]);
}

if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    error_log('error-alerts API: rejected request with invalid/missing bearer token');
    error_alert_api_deny(401, 'Invalid or missing token');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    echo json_encode(['ok' => true, 'errors' => error_alert_list_recent($limit)]);
    exit;
}

// No POST/update support yet — there is no auto-fix routine populating any
// outcome state, so there is nothing to write back. That lands together
// with the routine itself.
error_alert_api_deny(405, 'Method not allowed');
