<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';

admin_require_shop();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    exit('Invalid request');
}

$order_id = (int) ($_POST['order_id'] ?? 0);
$doc_type = $_POST['doc_type'] ?? '';

if (!$order_id || !in_array($doc_type, ['invoice', 'receipt', 'donation_cert', 'credit_note'], true)) {
    header('Location: /admin/orders.php?doc_error=' . urlencode('Невалидни параметри.'));
    exit;
}

$pdo = get_pdo();

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();
if (!$order) {
    header('Location: /admin/orders.php?doc_error=' . urlencode('Поръчката не е намерена.'));
    exit;
}

// Decode items — malformed JSON is a data error, not just an empty list
$items_raw = json_decode($order['items'] ?? '[]', true);
if (!is_array($items_raw)) {
    _om_log('ERROR', "generate-document: malformed items JSON for order {$order_id}: " . $order['items']);
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка: данните за артикулите на поръчката са повредени.'));
    exit;
}
$items = $items_raw;

// Decode invoice_data — null is valid (no B2B data), malformed JSON is not
$invoice_data_raw = $order['invoice_data'] ?? null;
if ($invoice_data_raw !== null && $invoice_data_raw !== '') {
    $invoice_data = json_decode($invoice_data_raw, true);
    if (!is_array($invoice_data)) {
        _om_log('ERROR', "generate-document: malformed invoice_data JSON for order {$order_id}: " . $invoice_data_raw);
        header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка: фирмените данни на поръчката са повредени.'));
        exit;
    }
} else {
    $invoice_data = null;
}

// Validate doc type is applicable for this order
$has_physical = $order['type'] === 'physical';
$has_donation = $order['type'] === 'donation'
    || count(array_filter($items, fn($i) => ($i['type'] ?? '') === 'donation')) > 0;
$is_b2b       = !empty($invoice_data['needs_invoice']);

if ($doc_type === 'invoice' && (!$has_physical || !$is_b2b)) {
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Фактура може да се издаде само за поръчки с попълнени фирмени данни.'));
    exit;
}
if ($doc_type === 'receipt' && (!$has_physical || $is_b2b)) {
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Бележката не е приложима за тази поръчка.'));
    exit;
}
if ($doc_type === 'donation_cert' && !$has_donation) {
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Сертификатът е приложим само за дарения.'));
    exit;
}

// Check for an existing document record (used during upsert below)
$existing_stmt = $pdo->prepare('SELECT * FROM documents WHERE order_id = ? AND type = ?');
$existing_stmt->execute([$order_id, $doc_type]);
$existing = $existing_stmt->fetch();

// credit_note shares the invoice sequence (Bulgarian accounting rules)
$sequence_type = ($doc_type === 'credit_note') ? 'invoice' : $doc_type;

// Always atomically assign the next number from the counter
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ? FOR UPDATE');
    $lock->execute([$sequence_type]);
    $last = $lock->fetchColumn();
    if ($last === false) {
        throw new \RuntimeException("No sequence row found for type '{$sequence_type}'");
    }
    $doc_number = (int) $last + 1;
    $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')
        ->execute([$doc_number, $sequence_type]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    _om_log('ERROR', 'generate-document: sequence error: ' . $e->getMessage());
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка при генериране на номер на документ.'));
    exit;
}

$pad           = ($doc_type === 'donation_cert') ? 5 : 10;
$doc_formatted = str_pad((string) $doc_number, $pad, '0', STR_PAD_LEFT);

// Determine output directory and file path
$type_dirs = [
    'invoice'       => 'invoices',
    'receipt'       => 'receipts',
    'donation_cert' => 'donation_certs',
    'credit_note'   => 'credit_notes',
];
$year     = date('Y', strtotime($order['created_at']));
$dir      = $_SERVER['DOCUMENT_ROOT'] . '/documents/' . $type_dirs[$doc_type] . '/' . $year;
$filename = $doc_formatted . '_' . preg_replace('/[^a-z0-9_-]/i', '', $order['order_number']) . '.pdf';
$filepath = $dir . '/' . $filename;
$rel_path = 'documents/' . $type_dirs[$doc_type] . '/' . $year . '/' . $filename;

if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    _om_log('ERROR', "generate-document: cannot create directory {$dir}");
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка: не може да се създаде директория за документи. Проверете правата на файловата система.'));
    exit;
}

// Load generator
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DocumentGenerator.php';
$generators = [
    'invoice'       => 'InvoiceGenerator',
    'receipt'       => 'ReceiptGenerator',
    'donation_cert' => 'DonationCertGenerator',
    'credit_note'   => 'CreditNoteGenerator',
];
$gen_file = $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/' . $generators[$doc_type] . '.php';
require_once $gen_file;
$gen_class = $generators[$doc_type];

$document = [
    'number'           => $doc_number,
    'formatted_number' => $doc_formatted,
    'type'             => $doc_type,
];

// For credit notes, look up the original invoice to reference its number and date
if ($doc_type === 'credit_note') {
    $src_stmt = $pdo->prepare('SELECT * FROM documents WHERE order_id = ? AND type = ?');
    $src_stmt->execute([$order_id, 'invoice']);
    $source_doc = $src_stmt->fetch();
    if (!$source_doc) {
        header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Не е намерена фактура за това кредитно известие.'));
        exit;
    }
    $document['source_formatted_number'] = $source_doc['formatted_number'];
    $document['source_date']             = substr($source_doc['generated_at'], 0, 10);
}

try {
    $generator = new $gen_class();
    $pdf_bytes = $generator->generate($order, $items, $document);
} catch (Throwable $e) {
    _om_log('ERROR', 'generate-document: PDF generation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка при генериране на PDF. Вижте server log за детайли.'));
    exit;
}

$written = file_put_contents($filepath, $pdf_bytes);
if ($written === false || $written === 0) {
    _om_log('ERROR', "generate-document: file_put_contents failed for {$filepath}");
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('Грешка: PDF е генериран, но не може да бъде записан на диска. Проверете правата на файловата система.'));
    exit;
}

// Upsert the documents record
try {
    if ($existing) {
        $pdo->prepare('UPDATE documents SET number = ?, formatted_number = ?, file_path = ?, generated_at = NOW(), signed_at = NULL, signed_by = NULL, emailed_at = NULL WHERE id = ?')
            ->execute([$doc_number, $doc_formatted, $rel_path, $existing['id']]);
    } else {
        $pdo->prepare('
            INSERT INTO documents (order_id, type, number, formatted_number, file_path)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$order_id, $doc_type, $doc_number, $doc_formatted, $rel_path]);
    }
} catch (Throwable $e) {
    _om_log('ERROR', 'generate-document: DB upsert failed after PDF written: ' . $e->getMessage());
    header('Location: /admin/order-view.php?id=' . $order_id . '&doc_error=' . urlencode('PDF е записан, но грешка при обновяване на базата данни: ' . $e->getMessage()));
    exit;
}

// Email the customer when a credit note is issued
if ($doc_type === 'credit_note') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/email-templates.php';
    $lang = $order['lang'] ?? 'bg';
    $tpl  = email_tpl_get('credit-note-customer', $lang, [
        'customer_name' => $order['customer_name'],
        'order_number'  => $order['order_number'],
        'doc_number'    => $doc_formatted,
    ]);
    $body = render_email('credit-note-customer', [
        'order' => $order,
        'tpl'   => $tpl,
    ]);
    send_mail(
        $order['customer_email'],
        $tpl['subject'],
        $body,
        '',
        [['path' => $filepath, 'name' => $filename]]
    );
}

// Notify the signing admin when a donation cert is ready
if ($doc_type === 'donation_cert') {
    send_mail(
        SIGNING_ADMIN_EMAIL,
        'Сертификат за дарение #' . $doc_formatted . ' — нужен подпис',
        render_email('cert-needs-signature', [
            'order'    => $order,
            'document' => ['formatted_number' => $doc_formatted],
            'order_id' => $order_id,
        ])
    );
}

header('Location: /admin/order-view.php?id=' . $order_id . '&doc_success=' . urlencode('Документът е генериран успешно.'));
exit;
