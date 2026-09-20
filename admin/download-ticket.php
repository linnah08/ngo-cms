<?php
/**
 * Serves a ticket PDF from the protected documents/tickets/ directory.
 * ?pledge=CP-YYYYMMDD-XXXX&n=1  (n = 1-based ticket index, default 1)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_shop();

$pledge_number = trim($_GET['pledge'] ?? '');
$n             = max(1, (int)($_GET['n'] ?? 1));

if (!preg_match('/^CP-\d{8}-[A-F0-9]{4}$/i', $pledge_number)) {
    http_response_code(400);
    exit('Invalid pledge number.');
}

$pdo  = get_pdo();
$stmt = $pdo->prepare('SELECT ticket_path FROM campaign_pledges WHERE pledge_number = ?');
$stmt->execute([$pledge_number]);
$row  = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Pledge not found.');
}

$paths = json_decode($row['ticket_path'] ?? '', true);
if (!is_array($paths)) {
    $paths = $row['ticket_path'] ? [$row['ticket_path']] : [];
}

$rel_path = $paths[$n - 1] ?? null;
if (!$rel_path) {
    http_response_code(404);
    exit('Ticket not found.');
}

$filepath = $_SERVER['DOCUMENT_ROOT'] . $rel_path;
if (!file_exists($filepath)) {
    http_response_code(404);
    exit('Файлът не е намерен на сървъра.');
}

$total    = count($paths);
$suffix   = $total > 1 ? "-{$n}" : '';
$filename = 'ticket-' . $pledge_number . $suffix . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0');
readfile($filepath);
exit;
