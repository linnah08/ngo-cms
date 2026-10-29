<?php
declare(strict_types=1);

/** Bulgarian labels for the CSV payment-status column. */
const MONTHLY_REPORT_STATUS_LABELS = [
    'paid'     => 'Платена',
    'refunded' => 'Върната',
];

/** What the non-donation part of an order is called in the report, by order type. */
const MONTHLY_REPORT_TYPE_LABELS = [
    'physical' => 'Покупка',
    'ticket'   => 'Билет',
    'pledge'   => 'Кампания (награда)',
];

/**
 * Bulgarian label for an order's real payment state in the report.
 *
 * `payment_status='paid'` is overloaded: for card/iris it is written only by a
 * verified gateway callback (money settled), but for a bank_transfer/cod invoice
 * it is set the moment the proforma is issued — before the money arrives. Such an
 * invoice must still be accounted for in the month it was issued, but shown as
 * NOT paid. The tell is an order still sitting at status='new' (a genuinely paid
 * order has advanced to 'confirmed'+ when the payment was confirmed).
 *
 * Public helper: a site's own report (site_reports()) may call it too.
 *
 * @param array $order Row with keys: payment_status, status, payment_method.
 */
function monthly_report_payment_label(array $order): string
{
    $payment_status = (string) ($order['payment_status'] ?? '');

    if ($payment_status === 'paid'
        && ($order['status'] ?? '') === 'new'
        && in_array($order['payment_method'] ?? '', ['bank_transfer', 'cod'], true)) {
        return 'Неплатена (издадена фактура)';
    }

    return MONTHLY_REPORT_STATUS_LABELS[$payment_status] ?? $payment_status;
}

/**
 * The report's name for a donation to $recipient: plain "Дарение" when the site
 * has a single recipient (the organisation itself), "Дарение (<recipient>)" when
 * donors choose between several (site-extensions 'recipients', named by their
 * order_label).
 */
function monthly_report_donation_label(string $recipient): string
{
    if (!function_exists('donation_recipients')) {
        require_once dirname(__DIR__) . '/site_extensions.php';
    }
    if (!donation_recipients_choosable()) return 'Дарение';
    return 'Дарение (' . donation_recipient_order_label($recipient, 'bg') . ')';
}

/**
 * Break one order down into the accounting line rows it should contribute to
 * the report. A plain purchase or ticket yields one row; a standalone donation
 * yields one donation row per recipient; a mixed order (products + a donation
 * add-on in the same checkout) is split so the donation is shown explicitly as
 * its own line, with amounts that reconcile back to total_eur.
 *
 * Donations are detected from the `items` JSON (the recipient lives only
 * there), the same way the rest of the CMS reads it (donation_line_recipient()).
 *
 * @param array $order Row with keys: type, total_eur, items (JSON string).
 * @return array<int, array{label: string, amount: float}>
 */
function monthly_report_order_lines(array $order): array
{
    if (!function_exists('donation_line_recipient')) {
        require_once dirname(__DIR__) . '/site_extensions.php';
    }
    $type  = (string) ($order['type'] ?? 'physical');
    $total = (float) ($order['total_eur'] ?? 0);

    $items = [];
    if (!empty($order['items'])) {
        $decoded = json_decode((string) $order['items'], true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }

    // Sum donation line amounts per recipient. Amount lives in amount_eur
    // (standalone donations) or subtotal_eur (shop add-on); a line without a
    // known recipient belongs to the default one.
    $donation_by_recipient = [];
    foreach ($items as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'donation') {
            continue;
        }
        $recipient = donation_line_recipient($item);
        $amount    = (float) ($item['amount_eur'] ?? $item['subtotal_eur'] ?? 0);
        $donation_by_recipient[$recipient] = ($donation_by_recipient[$recipient] ?? 0.0) + $amount;
    }
    $donation_total = array_sum($donation_by_recipient);

    // A donation-type order whose items carry no itemised amount (older records):
    // the whole total is the donation, to the named recipient if any.
    if ($type === 'donation' && $donation_total <= 0.0) {
        $recipient = array_key_first($donation_by_recipient) ?? donation_recipient_default();
        $donation_by_recipient = [$recipient => $total];
        $donation_total = $total;
    }

    $lines = [];

    // Non-donation portion (products, shipping, ticket). Zero for a pure donation order.
    $base_label = MONTHLY_REPORT_TYPE_LABELS[$type] ?? 'Покупка';
    $base = round($total - $donation_total, 2);
    if ($type !== 'donation' && $base > 0.0) {
        $lines[] = ['label' => $base_label, 'amount' => $base];
    }

    // One explicit row per donation recipient.
    foreach ($donation_by_recipient as $recipient => $amount) {
        if ($amount <= 0.0) {
            continue;
        }
        $lines[] = ['label' => monthly_report_donation_label((string) $recipient), 'amount' => round($amount, 2)];
    }

    // Safety net: never drop an order (e.g. odd/empty data) — one row on its type.
    if (!$lines) {
        $label = $type === 'donation' ? monthly_report_donation_label(donation_recipient_default()) : $base_label;
        $lines[] = ['label' => $label, 'amount' => $total];
    }

    return $lines;
}

/**
 * Generate a UTF-8 BOM CSV of paid/refunded orders for a given month, in
 * Bulgarian, for the accountant. Each order is broken into accounting line
 * rows: donations are their own line (per recipient when a site has several),
 * including a donation added on top of a purchase in the same order, and an
 * issued-but-unpaid invoice is marked as such.
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
        "SELECT created_at, order_number, type, status, payment_status, payment_method, total_eur, customer_name, items
           FROM orders
          WHERE payment_status IN ('paid', 'refunded')
            AND created_at >= ?
            AND created_at <= ?
          ORDER BY created_at ASC"
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $buf = fopen('php://temp', 'r+');
    if ($buf === false) {
        throw new \RuntimeException('Failed to open temporary stream for CSV generation.');
    }
    fputcsv($buf, ['Дата', 'Номер на поръчка', 'Вид', 'Статус на плащане', 'Сума (EUR)', 'Име на клиент'], ',', '"', '');

    foreach ($rows as $row) {
        $date   = substr((string) $row['created_at'], 0, 10);
        $status = monthly_report_payment_label($row);

        foreach (monthly_report_order_lines($row) as $line) {
            fputcsv($buf, [
                $date,
                $row['order_number'],
                $line['label'],
                $status,
                number_format($line['amount'], 2, '.', ''),
                $row['customer_name'],
            ], ',', '"', '');
        }
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
