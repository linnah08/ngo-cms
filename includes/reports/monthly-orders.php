<?php
declare(strict_types=1);

/**
 * Generate a UTF-8 BOM CSV of paid/refunded orders for a given month.
 *
 * @param int $year  Four-digit year (e.g. 2026)
 * @param int $month Month number 1–12
 * @return string    CSV string with UTF-8 BOM prepended (ready to output or write to file)
 */
function generate_monthly_orders_csv(int $year, int $month): string
{
    $pdo   = get_pdo();
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

    $stmt = $pdo->prepare(
        "SELECT created_at, order_number, type, payment_status, total_eur, customer_name
           FROM orders
          WHERE payment_status IN ('paid', 'refunded')
            AND created_at >= ?
            AND created_at <= ?
          ORDER BY created_at ASC"
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $type_labels = [
        'physical' => 'purchase',
        'donation' => 'donation',
        'ticket'   => 'ticket',
    ];

    $buf = fopen('php://temp', 'r+');
    if ($buf === false) {
        throw new \RuntimeException('Failed to open temporary stream for CSV generation.');
    }
    fputcsv($buf, ['Date', 'Order Number', 'Type', 'Payment Status', 'Amount (EUR)', 'Customer Name']);

    foreach ($rows as $row) {
        fputcsv($buf, [
            substr($row['created_at'], 0, 10),
            $row['order_number'],
            $type_labels[$row['type']] ?? $row['type'],
            $row['payment_status'],
            number_format((float) $row['total_eur'], 2, '.', ''),
            $row['customer_name'],
        ]);
    }

    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);

    return "\xEF\xBB\xBF" . $csv;
}

/**
 * Return email attachment descriptors for signed donation certs and issued
 * invoices whose generated_at falls in the given month.
 *
 * Each entry: ['path' => absolute_path, 'name' => filename_for_email]
 * Files missing from disk are skipped with an error_log warning.
 *
 * @param int    $year
 * @param int    $month
 * @param string $site_root  Absolute path to the site root (no trailing slash)
 * @return array<int, array{path: string, name: string}>
 */
function get_monthly_document_attachments(int $year, int $month, string $site_root): array
{
    $pdo   = get_pdo();
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

    $stmt = $pdo->prepare(
        "SELECT type, file_path
           FROM documents
          WHERE type IN ('donation_cert', 'invoice')
            AND (type != 'donation_cert' OR signed_at IS NOT NULL)
            AND generated_at >= ?
            AND generated_at <= ?
          ORDER BY type, generated_at ASC"
    );
    $stmt->execute([$start, $end]);

    $attachments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $full = $site_root . '/' . ltrim($doc['file_path'], '/');
        if (!file_exists($full)) {
            error_log("[monthly-report] Missing document file: {$full}");
            continue;
        }
        $attachments[] = ['path' => $full, 'name' => basename($full)];
    }
    return $attachments;
}
