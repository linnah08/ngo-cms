<?php
/**
 * Unpaid online orders (card via DSK Bank, bank transfer via IRIS).
 *
 * Checkout saves the order *before* sending the shopper to the bank, so an
 * order stays payment_status='pending' when the shopper abandons the bank page
 * or the payment is declined. This module:
 *   - decides when such an order counts as "unpaid" (admin badge/filter),
 *   - emails the shopper once that the payment didn't go through, with a
 *     "try again" link (on decline right away, on abandon via cron),
 *   - auto-cancels the order after 3 days unpaid and puts its items back in stock.
 *
 * Covers shop orders ('physical') and donations. Cron entry point:
 * cron/unpaid-orders-cron.php.
 */
require_once __DIR__ . '/payment_errors.php';
require_once dirname(__DIR__) . '/order_stock.php';

/**
 * Minutes after checkout before a still-pending order counts as unpaid, per
 * payment method. Only methods listed here get the badge, the emails, the
 * 3-day auto-cancel and the dashboard/retry handling. IRIS gets longer because
 * bank transfers can take a while to confirm.
 */
const UNPAID_AFTER_MINUTES = ['card' => 60, 'iris' => 120];

/**
 * Minutes after checkout before an unpaid order is cancelled and restocked.
 * The shopper's email states this deadline (see unpaid_cancel_deadline()).
 */
const UNPAID_CANCEL_AFTER_MINUTES = 3 * 24 * 60;

/**
 * Orders older than this are never touched by the cron. Keeps the first run
 * from cancelling/restocking historic pending orders an admin may already
 * have dealt with by hand. The cron runs far more often than this window, so
 * every new order passes through it.
 */
const UNPAID_CRON_LOOKBACK_MINUTES = 5 * 24 * 60;   // must stay longer than UNPAID_CANCEL_AFTER_MINUTES

const UNPAID_ORDER_TYPES = ['physical', 'donation'];

/**
 * True when the order is an online payment that should have been paid by now:
 * still pending and either declined (status cancelled), already emailed, or
 * past its method's wait time.
 */
function order_is_unpaid(array $order, int $age_minutes): bool
{
    if (($order['payment_status'] ?? '') !== 'pending') return false;
    if (!in_array($order['type'] ?? '', UNPAID_ORDER_TYPES, true)) return false;

    $after = UNPAID_AFTER_MINUTES[$order['payment_method'] ?? ''] ?? null;
    if ($after === null) return false;

    return ($order['status'] ?? '') === 'cancelled'
        || !empty($order['payment_failed_email_at'])
        || $age_minutes >= $after;
}

/**
 * SQL condition equivalent to order_is_unpaid(), for the admin "Неплатени"
 * filter. Only interpolates the constants above — no user data.
 */
function unpaid_orders_sql_condition(): string
{
    $types = "'" . implode("','", UNPAID_ORDER_TYPES) . "'";
    $by_age = [];
    foreach (UNPAID_AFTER_MINUTES as $method => $minutes) {
        $by_age[] = sprintf("(payment_method = '%s' AND created_at <= NOW() - INTERVAL %d MINUTE)", $method, $minutes);
    }
    $methods = "'" . implode("','", array_keys(UNPAID_AFTER_MINUTES)) . "'";

    return "(payment_status = 'pending' AND type IN ($types) AND payment_method IN ($methods)"
         . " AND (status = 'cancelled' OR payment_failed_email_at IS NOT NULL OR " . implode(' OR ', $by_age) . '))';
}

/** Plain-language payment method name for the admin. */
function payment_method_label(?string $method): string
{
    return [
        'card'          => 'Карта (DSK Банк)',
        'iris'          => 'Банков превод (IRIS)',
        'bank_transfer' => 'Банков превод',
        'cod'           => 'Наложен платеж',
    ][$method ?? ''] ?? ($method ? ucfirst($method) : '—');
}

/** Whole days the shopper has to finish paying before the order is cancelled. */
function unpaid_cancel_days(): int
{
    return intdiv(UNPAID_CANCEL_AFTER_MINUTES, 24 * 60);
}

/** When this order will be auto-cancelled if still unpaid, e.g. "22.09.2026 08:09". */
function unpaid_cancel_deadline(array $order): string
{
    $created = strtotime((string)($order['created_at'] ?? '')) ?: time();
    return date('d.m.Y H:i', $created + UNPAID_CANCEL_AFTER_MINUTES * 60);
}

/** Public link that sends the shopper back to the bank for the same order. */
function payment_retry_url(array $order): string
{
    $endpoint = ($order['payment_method'] ?? '') === 'iris'
        ? '/api/iris-payment-return.php'
        : '/api/payment-return.php';
    return SITE_URL . $endpoint . '?retry=1&order=' . urlencode((string)$order['order_number']);
}

/** True while the shopper can still pay this order via payment_retry_url(). */
function order_can_retry_payment(array $order): bool
{
    return ($order['payment_status'] ?? '') === 'pending'
        && in_array($order['type'] ?? '', UNPAID_ORDER_TYPES, true)
        && isset(UNPAID_AFTER_MINUTES[$order['payment_method'] ?? ''])
        && empty($order['unpaid_cancelled_at'])
        && empty($order['stock_returned_at']);
}

/** The "Опитай отново" / "Try again" email button (inline styles — email clients). */
function payment_retry_button_html(array $order): string
{
    $label = ($order['lang'] ?? 'bg') === 'en' ? 'Try again' : 'Опитай отново';
    return '<p style="text-align:center;margin:28px 0;">'
         . '<a href="' . htmlspecialchars(payment_retry_url($order), ENT_QUOTES, 'UTF-8') . '"'
         . ' style="display:inline-block;background:#0387A5;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 32px;border-radius:6px;font-size:16px;">'
         . $label . '</a></p>';
}

/** Email template key for the payment-failed email of this order (shop order or donation). */
function payment_failed_template_key(array $order): string
{
    return ($order['type'] ?? '') === 'donation' ? 'donation-payment-failed-customer' : 'order-payment-failed-customer';
}

/**
 * Email the shopper that the payment didn't go through — at most once per order.
 *
 * The payment_failed_email_at column is claimed atomically before sending, so
 * duplicate bank callbacks or overlapping cron runs can't send it twice. If the
 * send fails the claim is released so the next cron run retries.
 *
 * @param callable|null $mailer fn(string $to, string $subject, string $html): bool — defaults to send_mail()
 * @return bool true when an email was sent
 */
function send_payment_failed_email(PDO $pdo, array $order, ?callable $mailer = null): bool
{
    if (!in_array($order['type'] ?? '', UNPAID_ORDER_TYPES, true)) return false;
    if (!isset(UNPAID_AFTER_MINUTES[$order['payment_method'] ?? ''])) return false;

    $claim = $pdo->prepare(
        "UPDATE orders SET payment_failed_email_at = NOW()
         WHERE id = ? AND payment_failed_email_at IS NULL
           AND payment_status = 'pending' AND unpaid_cancelled_at IS NULL AND stock_returned_at IS NULL"
    );
    $claim->execute([$order['id']]);
    if ($claim->rowCount() === 0) return false;

    $lang = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
    $key  = payment_failed_template_key($order);
    $tpl  = email_tpl_get($key, $lang, [
        'customer_name' => $order['customer_name'],
        'order_number'  => $order['order_number'],
        'amount_eur'    => number_format((float)$order['total_eur'], 2, '.', ''),
        'cancel_date'   => unpaid_cancel_deadline($order),
        'cancel_days'   => unpaid_cancel_days(),
    ]);
    $html = render_email('payment-failed-customer', ['order' => $order, 'tpl' => $tpl]);

    $mailer ??= 'send_mail';
    $sent = false;
    try {
        $sent = (bool)$mailer($order['customer_email'], $tpl['subject'], $html);
    } catch (Throwable $e) {
        error_log("payment-failed email for order {$order['order_number']}: " . $e->getMessage());
    }

    if (!$sent) {
        error_log("payment-failed email for order {$order['order_number']} was not sent — will retry");
        $pdo->prepare('UPDATE orders SET payment_failed_email_at = NULL WHERE id = ?')
            ->execute([$order['id']]);
    }
    return $sent;
}

/**
 * Cancel an order that stayed unpaid for 3 days and put its items back in stock.
 * Safe to call repeatedly — restocks at most once.
 *
 * @return bool true when the order was cancelled by this call
 */
function cancel_unpaid_order(PDO $pdo, array $order): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', unpaid_cancelled_at = NOW(), updated_at = NOW()
             WHERE id = ? AND payment_status = 'pending' AND unpaid_cancelled_at IS NULL
               AND stock_returned_at IS NULL AND status IN ('new', 'cancelled')"
        );
        $stmt->execute([$order['id']]);
        if ($stmt->rowCount() === 0) {
            $pdo->commit();
            return false;
        }
        order_return_stock($pdo, $order);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Orders the cron would act on right now.
 *
 * @return array{to_email: list<array>, to_cancel: list<array>}
 */
function unpaid_orders_due(PDO $pdo): array
{
    $types   = "'" . implode("','", UNPAID_ORDER_TYPES) . "'";
    $methods = "'" . implode("','", array_keys(UNPAID_AFTER_MINUTES)) . "'";
    $base    = "payment_status = 'pending' AND type IN ($types) AND payment_method IN ($methods)"
             . " AND unpaid_cancelled_at IS NULL AND stock_returned_at IS NULL AND status IN ('new', 'cancelled')"
             . ' AND created_at > NOW() - INTERVAL ' . UNPAID_CRON_LOOKBACK_MINUTES . ' MINUTE';

    $by_age = [];
    foreach (UNPAID_AFTER_MINUTES as $method => $minutes) {
        $by_age[] = sprintf("(payment_method = '%s' AND created_at <= NOW() - INTERVAL %d MINUTE)", $method, $minutes);
    }

    // Overdue and not yet emailed (and not yet due for cancelling).
    $to_email = $pdo->query(
        "SELECT * FROM orders WHERE $base AND payment_failed_email_at IS NULL"
        . ' AND created_at > NOW() - INTERVAL ' . UNPAID_CANCEL_AFTER_MINUTES . ' MINUTE'
        . ' AND (' . implode(' OR ', $by_age) . ') ORDER BY created_at'
    )->fetchAll();

    // Unpaid for 3 days → cancel and put the items back in stock.
    $to_cancel = $pdo->query(
        "SELECT * FROM orders WHERE $base"
        . ' AND created_at <= NOW() - INTERVAL ' . UNPAID_CANCEL_AFTER_MINUTES . ' MINUTE ORDER BY created_at'
    )->fetchAll();

    return ['to_email' => $to_email, 'to_cancel' => $to_cancel];
}

/**
 * Cron job: email shoppers whose online payment is overdue, then cancel and
 * restock orders unpaid for 3 days.
 *
 * @param callable|null $mailer  see send_payment_failed_email()
 * @param callable|null $refresh fn(PDO, array $order): void — asks the bank for the
 *                               latest status before we act (a lost callback may
 *                               mean the shopper actually paid). Errors are logged.
 * @return array{emailed:int, cancelled:int}
 */
function run_unpaid_orders_job(PDO $pdo, ?callable $mailer = null, ?callable $refresh = null): array
{
    $reload = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $still_pending = function (array $order) use ($pdo, $refresh, $reload): ?array {
        if (!$refresh) return $order;
        try {
            $refresh($pdo, $order);
        } catch (Throwable $e) {
            payment_error_report('Автоматичната проверка не можа да попита банката за статуса', $order['order_number'], $e);
        }
        $reload->execute([$order['id']]);
        $fresh = $reload->fetch();
        return ($fresh && $fresh['payment_status'] === 'pending') ? $fresh : null;
    };

    $due    = unpaid_orders_due($pdo);
    $result = ['emailed' => 0, 'cancelled' => 0];

    foreach ($due['to_email'] as $order) {
        if (!$order = $still_pending($order)) continue;
        // Asking the bank may already have sent it (a decline triggers the email) — count that too.
        if (!empty($order['payment_failed_email_at']) || send_payment_failed_email($pdo, $order, $mailer)) {
            $result['emailed']++;
        }
    }
    foreach ($due['to_cancel'] as $order) {
        if (($order = $still_pending($order)) && cancel_unpaid_order($pdo, $order)) {
            $result['cancelled']++;
        }
    }
    return $result;
}
