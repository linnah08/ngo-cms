<?php
/**
 * Monthly orders report cron script.
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
 */

// Ensure CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/includes/db.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/reports/monthly-orders.php';
require_once dirname(__DIR__) . '/includes/scheduled_jobs.php';
scheduled_job_track('monthly_report');

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

// The site's own report files (includes/site-extensions.php) go along; one that
// fails is left out, and the main report is still sent.
require_once $site_root . '/includes/site_extensions.php';
$site_files = [];
$site_lines = '';
foreach (array_keys(site_reports()) as $rk) {
    $built = site_report_build($rk, $year, $month);
    if ($built !== null) {
        $path = sys_get_temp_dir() . '/' . $built['name'];
        if (file_put_contents($path, $built['csv']) !== false) $site_files[] = ['path' => $path, 'name' => $built['name']];
    }
    $line = site_report_summary($rk, $year, $month);
    if ($line !== '') $site_lines .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
}

$subject = "Месечен отчет — {$label}";
$body    = "<p>Здравей,</p>
<p>Прилагаме месечния отчет за <strong>{$label}</strong>.</p>
<ul>
<li>Поръчки (платени / върнати): <strong>{$row_count}</strong></li>
<li>Дарителски удостоверения: <strong>{$cert_count}</strong></li>
<li>Фактури: <strong>{$invoice_count}</strong></li>
{$site_lines}</ul>
<p>Поздрави,<br>" . SITE_NAME_BG . "</p>";

$attachments = array_merge(
    [['path' => $tmp, 'name' => $filename]],
    $site_files,
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
foreach ($site_files as $f) @unlink($f['path']);

if ($ok) {
    echo "[monthly-report-cron] Report for {$label} sent ({$row_count} orders, {$cert_count} certs, {$invoice_count} invoices).\n";
} else {
    error_log("[monthly-report-cron] Failed to send report email for {$label}.");
    exit(1);
}
