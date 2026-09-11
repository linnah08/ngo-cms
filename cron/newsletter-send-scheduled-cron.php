<?php
/**
 * Scheduled newsletter sender.
 *
 * Finds draft campaigns whose send_date <= today and sends them, using the
 * same logic as the manual "Изпрати сега" flow. Run via cron — CLI only.
 *
 * CRON SETUP (run once on the server):
 *   crontab -e
 *   Add this line (adjust path to match server):
 *   0 9 * * * /usr/local/bin/php /path/to/site/cron/newsletter-send-scheduled-cron.php >> /path/to/logs/newsletter-send-scheduled.log 2>&1
 *
 *   If the server is in UTC, use 0 6 * * * instead (06:00 UTC = 09:00 EEST / 07:00 EET).
 *   Check server timezone with: php -r "echo date_default_timezone_get();"
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
require_once dirname(__DIR__) . '/includes/newsletter.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$pdo          = get_pdo();
$sent_total   = 0;
$failed_total = 0;
$errors       = 0;

foreach (newsletter_due_campaigns($pdo) as $campaign) {
    $id = (int)$campaign['id'];

    // Atomic claim — guards against a manual send happening at the same moment.
    if (!newsletter_claim_for_sending($pdo, $id)) {
        continue;
    }

    log_line("Sending campaign #$id (send_date={$campaign['send_date']})");

    $stmt = $pdo->prepare("SELECT id, email, name, lang, token FROM newsletter_subscribers WHERE status='active' ORDER BY id ASC");
    $stmt->execute();
    $subscribers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $pdo->prepare("UPDATE newsletter_campaigns SET recipient_count=? WHERE id=?")->execute([count($subscribers), $id]);

    $sent   = 0;
    $failed = 0;
    foreach ($subscribers as $sub) {
        $ok = newsletter_send_to_subscriber($pdo, $id, $campaign, $sub);
        $ok ? $sent++ : $failed++;
    }

    $pdo->prepare("UPDATE newsletter_campaigns SET status='sent', sent_at=NOW(), recipient_count=? WHERE id=?")
        ->execute([$sent, $id]);

    log_line("Campaign #$id done. sent=$sent failed=$failed");
    $sent_total   += $sent;
    $failed_total += $failed;
    if ($failed > 0) $errors++;
}

log_line("Done. sent=$sent_total failed=$failed_total campaigns_with_errors=$errors");
exit($errors > 0 ? 1 : 0);

function log_line(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}
