<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

admin_require_shop();

$doc_id = (int) ($_GET['id'] ?? 0);
if (!$doc_id) {
    http_response_code(400);
    exit('Invalid document ID');
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Документът не е намерен.');
}

$filepath = $_SERVER['DOCUMENT_ROOT'] . '/' . $doc['file_path'];
if (!file_exists($filepath)) {
    http_response_code(404);
    exit('Файлът не е намерен на сървъра. Генерирайте документа отново.');
}

$type_names = [
    'invoice'       => 'Фактура',
    'receipt'       => 'Бележка',
    'donation_cert' => 'Сертификат',
];
$label    = $type_names[$doc['type']] ?? 'Документ';
$filename = $label . '_' . $doc['formatted_number'] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0');
readfile($filepath);
exit;
