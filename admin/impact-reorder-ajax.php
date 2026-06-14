<?php
/**
 * AJAX endpoint: save a new impact metrics order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["деца", "центъра", ...] }
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

// CSRF — token passed in JSON body
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

$items = load_json(IMPACT_FILE);

// Duplicate label_bg guard
$keys = array_column($items, 'label_bg');
if (count($keys) !== count(array_unique($keys))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate identifiers detected']);
    exit;
}

$items = impact_assign_order_defaults($items);
$items = impact_reorder_by_key($items, $order, 'label_bg');

save_json(IMPACT_FILE, $items);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function impact_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function impact_reorder_by_key(array $items, array $order, string $key): array
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
