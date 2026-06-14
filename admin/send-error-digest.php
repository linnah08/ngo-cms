<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$root = dirname(__DIR__);
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $root;
}

require_once $root . '/config.php';
require_once $root . '/admin/includes/db.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/email-templates.php';
require_once $root . '/includes/error-alerts.php';

if (setting_get('error_alert_enabled') !== '1') {
    echo "Alerts disabled — nothing to do.\n";
    exit(0);
}

$email = setting_get('error_alert_email');
if ($email === '') {
    echo "No alert email configured — nothing to do.\n";
    exit(0);
}

$freq = setting_get('error_alert_frequency', 'immediate');
if ($freq === 'immediate') {
    echo "Frequency is 'immediate' — cron not needed.\n";
    exit(0);
}

// Weekly: only send on Monday (date('N') === '1')
if ($freq === 'weekly' && date('N') !== '1') {
    echo "Weekly digest: today is not Monday — skipping.\n";
    exit(0);
}

$interval = $freq === 'daily' ? 'INTERVAL 1 DAY' : 'INTERVAL 7 DAY';
error_alert_ensure_table();

$stmt = get_pdo()->query("
    SELECT * FROM error_alerts
    WHERE sent_at IS NULL
      AND created_at >= NOW() - {$interval}
    ORDER BY created_at ASC
");
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No unsent errors in period — skipping.\n";
    exit(0);
}

$count     = count($rows);
$from_date = date('d.m.Y', strtotime($rows[0]['created_at']));
$to_date   = date('d.m.Y');
$period    = $freq === 'daily' ? 'последните 24 часа' : 'последната седмица';

$subject = email_tpl_subject('error-digest', 'bg', [
    'count'     => $count,
    'period'    => $period,
    'from_date' => $from_date,
    'to_date'   => $to_date,
]);
$body = render_email('error-digest', [
    'rows'      => $rows,
    'period'    => $period,
    'from_date' => $from_date,
    'to_date'   => $to_date,
]);

if (send_mail($email, $subject, $body)) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    get_pdo()->exec("UPDATE error_alerts SET sent_at = NOW() WHERE id IN ({$ids})");
    echo date('Y-m-d H:i:s') . " — Digest sent: {$count} error(s) to {$email}\n";
} else {
    echo date('Y-m-d H:i:s') . " — ERROR: Failed to send digest email\n";
    exit(1);
}
