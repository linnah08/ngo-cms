<?php
/**
 * AJAX endpoint: save a new ways-to-help order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Дарение", "Доброволци", ...] }
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
$ways  = $pages['how_to_help']['ways'] ?? [];

// Duplicate title guard
$titles = array_column($ways, 'title');
if (count($titles) !== count(array_unique($titles))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate way titles detected']);
    exit;
}

$ways = ways_assign_order_defaults($ways);
$ways = ways_reorder_by_key($ways, $order, 'title');

$pages['how_to_help']['ways'] = $ways;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

function ways_assign_order_defaults(array $items): array
{
    foreach ($items as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $items;
}

function ways_reorder_by_key(array $items, array $order, string $key): array
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
