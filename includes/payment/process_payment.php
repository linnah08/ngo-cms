<?php
/**
 * Shared DSK Bank result processor.
 * Handles both 'physical' (shop) and 'donation' order types.
 *
 * Called from api/payment-return.php (browser redirect) and
 * api/payment-callback.php (server-to-server).
 */
require_once __DIR__ . '/unpaid_orders.php';
require_once __DIR__ . '/payment_errors.php';

/**
 * Send the "payment received" emails for a now-paid order.
 * Shared by the DSK Bank and IRIS Pay flows. Caller is responsible for the
 * actual paid DB transition (and for only calling this once per order).
 */
function notify_order_paid(array $order): void
{
    if ($order['type'] === 'donation') {
        $items   = json_decode($order['items'] ?? '[]', true) ?? [];
        $don     = $items[0] ?? [];
        // The donor gets the email in the language they donated in.
        $lang      = ($order['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
        $reference = $order['customer_name'] . ' — ' . ($lang === 'en' ? 'donation' : 'дарение');
        send_order_mail(
            (int)$order['id'],
            $order['customer_email'],
            render_email_subject('donation-confirmation-customer', $lang, ['donor_name' => $order['customer_name']]),
            render_email('donation-confirmation-customer', [
                'donor_name'       => $order['customer_name'],
                'amount_eur'       => (float)$order['total_eur'],
                'donation_message' => $order['donation_message'] ?? '',
                'reference'        => $reference,
                'lang'             => $lang,
            ]),
            ['template_key' => 'donation-confirmation-customer']
        );
        send_mail(
            SITE_EMAIL,
            'Ново дарение — ' . $order['customer_name'] . ' — ' . number_format((float)$order['total_eur'], 2) . '€',
            render_email('donation-notification-admin', [
                'donor_name'       => $order['customer_name'],
                'donor_email'      => $order['customer_email'],
                'amount_eur'       => (float)$order['total_eur'],
                'donation_message' => $order['donation_message'] ?? '',
                'order_number'     => $order['order_number'],
            ])
        );
    } else {
        send_order_mail(
            (int)$order['id'],
            $order['customer_email'],
            render_email_subject('order-confirmation-customer', $order['lang'] ?? 'bg', ['order_number' => $order['order_number'], 'customer_name' => $order['customer_name']]),
            render_email('order-confirmation-customer', ['order' => $order]),
            ['template_key' => 'order-confirmation-customer']
        );
        send_mail(
            SITE_EMAIL,
            'Нова поръчка #' . $order['order_number'],
            render_email('order-notification-admin', [
                'order'     => $order,
                'admin_url' => SITE_URL . '/admin/order-view.php?id=' . $order['id'],
            ])
        );
    }
}

/**
 * Is this DSK status reply about THIS order or pledge? We start every card payment
 * as register("<number>_<timestamp>", amount), and the bank echoes both back in
 * getOrderStatusExtended (orderNumber, amount in cents — checked against a real
 * reply). A payment id copied from another payment fails here, so it can neither
 * mark this order paid nor cancel it.
 */
function dsk_status_belongs_to(array $status, string $number, float $amountEur): bool
{
    $ref = (string) ($status['orderNumber'] ?? '');
    return $number !== ''
        && str_starts_with($ref, $number . '_')
        && isset($status['amount'])
        && (int) $status['amount'] === (int) round($amountEur * 100);
}

function process_dsk_result(PDO $pdo, array $order, string $dskOrderId, array $status): void
{
    // Never act on a reply about some other payment — not to mark paid, not to cancel.
    if (!dsk_status_belongs_to($status, (string) $order['order_number'], (float) $order['total_eur'])) {
        payment_error_report(
            'Отговорът на банката не е за тази поръчка — плащането не е отбелязано',
            (string) $order['order_number'],
            new RuntimeException('DSK reply for ' . ($status['orderNumber'] ?? '?') . ' / ' . ($status['amount'] ?? '?') . ' cents')
        );
        return;
    }

    $orderStatus = (int)($status['orderStatus'] ?? -1);

    // Always persist the DSK order UUID if we don't have it yet
    if (!$order['dsk_order_id']) {
        $pdo->prepare('UPDATE orders SET dsk_order_id = ? WHERE id = ?')
            ->execute([$dskOrderId, $order['id']]);
        $order['dsk_order_id'] = $dskOrderId;
    }

    if ($orderStatus === 2 || $orderStatus === 1) {
        // Paid (deposited or pre-auth approved)
        if ($order['payment_status'] !== 'paid') {
            order_reinstate_for_late_payment($pdo, $order);
            $pdo->prepare("UPDATE orders SET payment_status = 'paid', status = 'confirmed', updated_at = NOW() WHERE id = ?")
                ->execute([$order['id']]);
            notify_order_paid($order);
        }
    } elseif ($orderStatus === 3) {
        // Declined
        $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND payment_status = 'pending'");
        $stmt->execute([$order['id']]);
        if ($stmt->rowCount() > 0) order_cancel_shipment_or_alert($pdo, $order);
        send_payment_failed_email($pdo, $order);
    }
}
