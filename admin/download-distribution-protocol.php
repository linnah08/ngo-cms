<?php
/** Serves a distribution hand-over protocol PDF (admin/distribution.php). */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/distribution.php';

admin_require_shop();
distribution_require_enabled();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Невалиден протокол.');
}

$stmt = get_pdo()->prepare('SELECT protocol_file_path, protocol_formatted_number FROM distribution_allocations WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

// Only ever serve a file from our own protocols folder, whatever the row says.
$rel = (string) ($row['protocol_file_path'] ?? '');
if (!$row || !preg_match('#^documents/distribution_protocols/\d{4}/[0-9]+\.pdf$#', $rel)) {
    http_response_code(404);
    exit('Протоколът не е намерен.');
}

$path = $_SERVER['DOCUMENT_ROOT'] . '/' . $rel;
if (!is_file($path)) {
    http_response_code(404);
    exit('Файлът на протокола липсва. Отворете „Разпространение“ и го създайте отново.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Protokol_' . preg_replace('/\D/', '', (string) $row['protocol_formatted_number']) . '.pdf"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
