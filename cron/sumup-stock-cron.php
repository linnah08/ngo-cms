<?php
/**
 * SumUp sales cron — see includes/sumup.php.
 *   - takes the products sold through the SumUp card reader out of the shop's stock,
 *   - puts refunded ones back,
 *   - emails the organisation when a sale waits for someone to say which product it was.
 *
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
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
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/scheduled_jobs.php';
scheduled_job_track('sumup_stock');
require_once dirname(__DIR__) . '/includes/sumup.php';

if (!sumup_is_connected()) {
    echo "sumup-stock: SumUp is not connected — nothing to do\n";
    exit(0);
}

$pdo = get_pdo();
try {
    $sum = sumup_sync($pdo);
    setting_delete('sumup_last_error');
} catch (SumUpError $e) {
    setting_set('sumup_last_error', json_encode(['at' => time(), 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, 'sumup-stock: ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("sumup-stock: %d new sales, %d items out, %d refunds, %d items back, %d waiting\n",
    $sum['new_sales'], $sum['items_out'], $sum['refunds'], $sum['items_back'], $sum['attention']);

if ($sum['attention'] > 0) {
    send_mail(
        SITE_EMAIL,
        admin_mail_subject('SumUp: ' . ($sum['attention'] === 1 ? '1 продажба чака' : $sum['attention'] . ' продажби чакат') . ' да изберете продукт'),
        render_email('sumup-attention-admin', [
            'count'     => sumup_attention_count($pdo),
            'admin_url' => SITE_URL . '/admin/sumup.php#waiting',
        ])
    );
}
