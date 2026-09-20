<?php
/**
 * AJAX endpoint: the current state of a running self-update.
 *
 * Polled once a second by admin/updates.php while
 * admin/update-apply-ajax.php does the work.
 *
 * Response (JSON):
 *   { "ok": true, "phase": "idle", "maintenance": false }
 *   { "ok": true, "phase": "apply", "percent": 72, "message": "…",
 *     "status": "running", "stale": false, "maintenance": true }
 *   { "ok": true, "phase": "done", "percent": 100, "status": "success",
 *     "to_version": "1.2.0", "skipped": [] }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

// Let go of the session lock immediately. This endpoint is polled once a
// second for minutes at a time and must never hold anything else up.
session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';

$state = updater_progress_read();

if ($state === null) {
    echo json_encode([
        'ok'          => true,
        'phase'       => 'idle',
        'maintenance' => updater_is_maintenance_mode(),
    ]);
    exit;
}

echo json_encode([
    'ok'          => true,
    'phase'       => (string) ($state['phase'] ?? ''),
    'percent'     => (int) ($state['percent'] ?? 0),
    'message'     => (string) ($state['message'] ?? ''),
    'status'      => (string) ($state['status'] ?? 'running'),
    'to_version'  => (string) ($state['to_version'] ?? ''),
    'skipped'     => array_values((array) ($state['skipped'] ?? [])),
    'stale'       => updater_progress_is_stale($state),
    'maintenance' => updater_is_maintenance_mode(),
], JSON_UNESCAPED_UNICODE);
