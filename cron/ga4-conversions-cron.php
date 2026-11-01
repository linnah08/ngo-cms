<?php
/**
 * Resend paid orders and donations to Google Analytics 4 that did not reach it
 * when they were paid (Google down, a timeout) — see includes/ga4.php.
 * Only those paid within the last 72 hours: Google takes nothing older.
 *
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
 *
 *   php cron/ga4-conversions-cron.php --dry-run           lists what would be sent
 *   php cron/ga4-conversions-cron.php --validate OM-…     asks Google's checker about one order, sends nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/includes/db.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/scheduled_jobs.php';
scheduled_job_track('ga4_conversions');
require_once dirname(__DIR__) . '/includes/ga4.php';

$pdo  = get_pdo();
$args = $argv ?? [];

if (ga4_measurement_id() === '' || ga4_api_secret() === '') {
    echo "ga4-conversions: GA4_ID or GA4_API_SECRET is not set — nothing to do.\n";
    exit(0);
}

if (($i = array_search('--validate', $args, true)) !== false) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
    $stmt->execute([(string) ($args[$i + 1] ?? '')]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { echo "No such order.\n"; exit(1); }
    $payload = ga4_conversion_payload($order);
    if ($payload === null) { echo "No GA client id on this order (no cookie consent) — it is never sent.\n"; exit(0); }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $ch = curl_init(ga4_collect_url(true));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    echo "Google's checker says:\n" . curl_exec($ch) . "\n";
    exit(0);
}

if (in_array('--dry-run', $args, true)) {
    $due = ga4_conversions_due($pdo);
    echo 'Would send (' . count($due) . "):\n";
    foreach ($due as $o) {
        printf("  %s  %-8s  paid %s  %8.2f EUR\n", $o['order_number'], $o['type'], substr((string) $o['paid_at'], 0, 16), (float) $o['total_eur']);
    }
    echo "Dry run — nothing was sent.\n";
    exit(0);
}

[$sent, $failed] = run_ga4_conversions_job($pdo);
echo sprintf("ga4-conversions: sent %d, failed %d\n", $sent, $failed);
