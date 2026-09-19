<?php
/**
 * Unpaid online orders cron (card / IRIS) — see includes/payment/unpaid_orders.php.
 *   - emails the shopper once when the payment is overdue (card 1h, IRIS 2h),
 *   - cancels the order and puts its items back in stock after 24h unpaid.
 *
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
 *
 * Dry run — lists who would be emailed / cancelled (with their payment method)
 * without sending or changing anything:
 *   php cron/unpaid-orders-cron.php --dry-run
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
require_once dirname(__DIR__) . '/includes/payment/DSKBankPayment.php';
require_once dirname(__DIR__) . '/includes/payment/IRISPayment.php';
require_once dirname(__DIR__) . '/includes/scheduled_jobs.php';
scheduled_job_track('unpaid_orders');
require_once dirname(__DIR__) . '/includes/payment/process_payment.php';
require_once dirname(__DIR__) . '/includes/payment/process_iris_result.php';
require_once dirname(__DIR__) . '/includes/payment/unpaid_orders.php';

// Before telling a shopper their payment failed, ask the bank — the callback
// may have been lost while they actually paid.
$refresh = function (PDO $pdo, array $order): void {
    if ($order['payment_method'] === 'card' && !empty($order['dsk_order_id'])) {
        $status = (new DSKBankPayment())->getStatus($order['dsk_order_id']);
        process_dsk_result($pdo, $order, $order['dsk_order_id'], $status);
    } elseif ($order['payment_method'] === 'iris' && !empty($order['iris_payment_hash'])) {
        $status = (new IRISPayment())->getStatus($order['iris_payment_hash']);
        process_iris_result($pdo, $order, $status);
    }
};

$pdo = get_pdo();

if (in_array('--dry-run', $argv ?? [], true)) {
    $due = unpaid_orders_due($pdo);
    $print = function (string $title, array $orders): void {
        echo $title . ' (' . count($orders) . "):\n";
        foreach ($orders as $o) {
            printf("  %s  %s  %-8s  %-22s  %8.2f EUR  %s <%s>\n",
                $o['order_number'], substr($o['created_at'], 0, 16), $o['type'],
                payment_method_label($o['payment_method']), (float)$o['total_eur'],
                $o['customer_name'], $o['customer_email']);
        }
    };
    $print('Would email "payment didn\'t go through"', $due['to_email']);
    $print('Would cancel + put back in stock', $due['to_cancel']);
    echo "Dry run — nothing was sent or changed. (The bank is asked for the latest status before a real run acts.)\n";
    exit(0);
}

$result = run_unpaid_orders_job($pdo, null, $refresh);

echo sprintf("unpaid-orders: emailed %d, cancelled %d\n", $result['emailed'], $result['cancelled']);
