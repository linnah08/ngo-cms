<?php
/**
 * Monthly orders report cron script.
 * Run via cPanel cron: 0 6 1 * *
 * Command: /usr/local/bin/php /path/to/site/cron/monthly-report-cron.php
 */

// Ensure CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/includes/db.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/reports/monthly-orders.php';

// Previous calendar month
$ts    = strtotime('first day of last month');
$year  = (int) date('Y', $ts);
$month = (int) date('n', $ts);

$month_names_bg = [
    1 => 'Януари', 2 => 'Февруари', 3 => 'Март',     4 => 'Април',
    5 => 'Май',    6 => 'Юни',      7 => 'Юли',      8 => 'Август',
    9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
];

$month_name = $month_names_bg[$month];
$label      = "{$month_name} {$year}";
$filename   = sprintf('report-%04d-%02d.csv', $year, $month);

$site_root = dirname(__DIR__);

$csv = generate_monthly_orders_csv($year, $month);
$tmp = sys_get_temp_dir() . '/' . $filename;

if (file_put_contents($tmp, $csv) === false) {
    error_log("[monthly-report-cron] Failed to write temp file: {$tmp}");
    exit(1);
}

// Count data rows (subtract BOM header line)
$lines     = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
$row_count = max(0, count($lines) - 1); // exclude header

$doc_attachments = get_monthly_document_attachments($year, $month, $site_root);
$cert_count      = count(array_filter($doc_attachments, fn($a) => str_contains($a['name'], 'DC-') || str_contains($a['path'], 'donation_cert')));
$invoice_count   = count($doc_attachments) - $cert_count;

$subject = "Месечен отчет — {$label}";
$body    = "<p>Здравей,</p>
<p>Прилагаме месечния отчет за <strong>{$label}</strong>.</p>
<ul>
<li>Поръчки (платени / върнати): <strong>{$row_count}</strong></li>
<li>Дарителски удостоверения: <strong>{$cert_count}</strong></li>
<li>Фактури: <strong>{$invoice_count}</strong></li>
</ul>
<p>Поздрави,<br>" . SITE_NAME_BG . "</p>";

$attachments = array_merge(
    [['path' => $tmp, 'name' => $filename]],
    $doc_attachments
);

$ok = send_mail(
    defined('REPORT_EMAIL') ? REPORT_EMAIL : SITE_EMAIL,
    $subject,
    $body,
    '',
    $attachments
);

unlink($tmp);

if ($ok) {
    echo "[monthly-report-cron] Report for {$label} sent ({$row_count} orders, {$cert_count} certs, {$invoice_count} invoices).\n";
} else {
    error_log("[monthly-report-cron] Failed to send report email for {$label}.");
    exit(1);
}
