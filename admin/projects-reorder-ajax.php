<?php
/**
 * AJAX endpoint: save a new project order.
 *
 * POST body (JSON):
 *   { "csrf_token": "...", "order": ["Title A", "Title B", ...] }
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

$pages    = load_json(CONTENT_PATH . '/pages.json');
$projects = $pages['projects'] ?? [];

$titles = array_column($projects, 'title');
if (count($titles) !== count(array_unique($titles))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate project titles detected']);
    exit;
}

$projects = projects_assign_order_defaults($projects);
$projects = projects_reorder_by_titles($projects, $order);

$pages['projects'] = $projects;
save_json(CONTENT_PATH . '/pages.json', $pages);

echo json_encode(['ok' => true]);

// ── helpers ────────────────────────────────────────────────────────────────────

/**
 * Ensure every project has an 'order' field.
 * Projects that already have one keep it; others get their array index.
 */
function projects_assign_order_defaults(array $projects): array
{
    foreach ($projects as $i => &$p) {
        if (!isset($p['order'])) {
            $p['order'] = $i;
        }
    }
    return $projects;
}

/**
 * Re-sort $projects according to the $order array of titles.
 * Projects not mentioned in $order are appended at the end.
 * Reassigns sequential 'order' values (0, 1, 2, …).
 */
function projects_reorder_by_titles(array $projects, array $order): array
{
    $indexed = [];
    foreach ($projects as $p) {
        $indexed[$p['title']] = $p;
    }

    $sorted = [];
    foreach ($order as $title) {
        if (isset($indexed[$title])) {
            $sorted[] = $indexed[$title];
            unset($indexed[$title]);
        }
    }
    // Append any projects not mentioned in the order array
    foreach ($indexed as $p) {
        $sorted[] = $p;
    }

    // Reassign sequential order values
    foreach ($sorted as $i => &$p) {
        $p['order'] = $i;
    }

    return $sorted;
}
