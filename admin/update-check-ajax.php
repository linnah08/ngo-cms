<?php
/**
 * AJAX endpoint: check GitHub for a new version in the background.
 *
 * Called by the admin top bar (admin/includes/admin-header.php) when the saved
 * result of the last check is older than UPDATER_CACHE_TTL, so drawing a page
 * never waits on the network. The result is saved; the „Нова версия“ notice
 * in the top bar shows from the next page on.
 *
 * POST, csrf_token in the body. Response (JSON): { "ok": true, "update": "0.18.0" | null }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

// Let go of the session lock: the check can take a few seconds and the admin
// may already be opening the next page.
session_write_close();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';

if (!updater_self_update_allowed() || !module_admin_page_visible('updates.php')) {
    echo json_encode(['ok' => true, 'update' => null]);
    exit;
}

if (updater_cache_is_stale()) {
    updater_check_latest();   // never throws; a failed check just leaves no notice
}

echo json_encode(['ok' => true, 'update' => updater_cached_update()]);
