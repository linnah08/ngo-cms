<?php
/**
 * AJAX endpoint: save a new team member order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Мария Иванова", "Петър Георгиев", ...] }
 *
 * Response:
 *   { "ok": true }
 *   { "ok": false, "error": "..." }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
admin_require_admin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$_POST['csrf_token'] = $body['csrf_token'] ?? '';
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$order = $body['order'] ?? [];
if (!is_array($order)) {
    echo json_encode(['ok' => false, 'error' => 'order must be an array']);
    exit;
}

$pages = load_json(CONTENT_PATH . '/pages.json');
$team  = $pages['about']['team'] ?? [];

// Duplicate name guard
$names = array_column($team, 'name');
if (count($names) !== count(array_unique($names))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate member names detected']);
    exit;
}

$team = team_assign_order_defaults($team);
$team = team_reorder_by_key($team, $order, 'name');

$pages['about']['team'] = $team;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function team_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function team_reorder_by_key(array $items, array $order, string $key): array
{
    $indexed = [];
    foreach ($items as $p) {
        $indexed[$p[$key]] = $p;
    }

    $sorted = [];
    foreach ($order as $k) {
        if (isset($indexed[$k])) {
            $sorted[] = $indexed[$k];
            unset($indexed[$k]);
        }
    }
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
