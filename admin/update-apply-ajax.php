<?php
/**
 * AJAX endpoint: run a self-update, reporting progress as it goes.
 *
 * The work itself is exactly what admin/updates.php's POST handler has always
 * done. It lives here instead so the browser can poll
 * admin/update-progress-ajax.php while it runs — see the session_write_close()
 * note below, without which those polls queue behind this request and the
 * progress bar only starts moving once there is nothing left to report.
 *
 * POST body (JSON):
 *   { "csrf_token": "..." }
 *
 * Response (JSON):
 *   { "ok": true,  "status": "success"|"partial"|"failed", "to_version": "...", "skipped": [...] }
 *   { "ok": false, "error": "..." }
 *
 * Failure details are deliberately not returned: they can name server paths.
 * They go to the platform_updates audit table, and the UI shows the same plain
 * "contact support" message it always has.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
admin_require_admin(); // this endpoint can rewrite the whole app's code — admin-only

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// CSRF is checked before including includes/updater.php on purpose — a bad
// token should be rejected outright, without depending on (or triggering) any
// of the update logic.
$_POST['csrf_token'] = $body['csrf_token'] ?? '';
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';

if (!updater_self_update_allowed()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'self_update_disabled']);
    exit;
}

// Guard against a double submit, a stale tab, or a second admin clicking at
// the same moment: never start an update on top of one already running.
if (updater_is_maintenance_mode()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'already_running']);
    exit;
}

// An update rewrites the application's own code. Stopping halfway because the
// admin closed the tab would be far worse than running to completion.
ignore_user_abort(true);
@set_time_limit(0);

// This is the whole reason the apply moved out of admin/updates.php. PHP holds
// the session lock for the life of a request, so every poll of
// update-progress-ajax.php would block behind this one until the update
// finished. Nothing below this line writes to the session.
session_write_close();

updater_progress_write([
    'phase'   => 'check',
    'percent' => 0,
    'message' => 'Подготовка…',
    'status'  => 'running',
]);

$last_percent = 0;
$result = updater_apply(static function (array $state) use (&$last_percent): void {
    $last_percent = (int) $state['percent'];
    updater_progress_write($state + ['status' => 'running']);
});

// The terminal state is persisted too: the apply is no longer a form POST that
// renders its own outcome, so admin/updates.php picks the result up from here
// on the next plain GET. A failed run keeps the percent it reached rather than
// snapping to 100.
updater_progress_write([
    'phase'      => $result['status'] === 'failed' ? 'failed' : 'done',
    'percent'    => $result['status'] === 'failed' ? $last_percent : 100,
    'message'    => '',
    'status'     => $result['status'],
    'to_version' => $result['to_version'],
    'skipped'    => $result['skipped'],
]);

echo json_encode([
    'ok'         => true,
    'status'     => $result['status'],
    'to_version' => $result['to_version'],
    'skipped'    => $result['skipped'],
], JSON_UNESCAPED_UNICODE);
