<?php
/**
 * Cart & pledge reminders cron — see includes/saved_carts.php.
 *   - emails shoppers (once, only if they asked) who left a cart or pledge unfinished for 24h,
 *   - forgets saved carts untouched for 30 days.
 *
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
 *
 * Dry run — lists who would be emailed, without sending or changing anything:
 *   php cron/cart-reminders-cron.php --dry-run
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
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/scheduled_jobs.php';
scheduled_job_track('cart_reminders');
require_once dirname(__DIR__) . '/includes/saved_carts.php';

$pdo = get_pdo();

if (in_array('--dry-run', $argv ?? [], true)) {
    if (!saved_cart_email_enabled()) {
        echo "Email reminders are switched off — a real run would send nothing.\n";
    }
    $due = saved_carts_due($pdo);
    echo 'Would email "you left something behind" (' . count($due) . "):\n";
    foreach ($due as $r) {
        printf("  #%d  %s  %-6s  %s  <%s>\n", $r['id'], substr($r['updated_at'], 0, 16),
            $r['pledge_id'] !== null ? 'pledge' : 'cart', $r['lang'], $r['email']);
    }
    echo 'Would forget saved carts untouched for ' . SAVED_CART_KEEP_DAYS . " days.\n";
    echo "Dry run — nothing was sent or changed.\n";
    exit(0);
}

$result = run_cart_reminders_job($pdo);

echo sprintf("cart-reminders: emailed %d, skipped %d, deleted %d\n", $result['emailed'], $result['skipped'], $result['deleted']);
