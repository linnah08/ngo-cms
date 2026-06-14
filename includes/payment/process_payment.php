<?php
/**
 * Shared DSK Bank result processor.
 * Handles both 'physical' (shop) and 'donation' order types.
 *
 * Called from api/payment-return.php (browser redirect) and
 * api/payment-callback.php (server-to-server).
 */

function process_dsk_result(PDO $pdo, array $order, string $dskOrderId, array $status): void
{
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
            $pdo->prepare("UPDATE orders SET payment_status = 'paid', status = 'confirmed', updated_at = NOW() WHERE id = ?")
                ->execute([$order['id']]);

            if ($order['type'] === 'donation') {
                $items   = json_decode($order['items'] ?? '[]', true) ?? [];
                $don     = $items[0] ?? [];
                $reference = $order['customer_name'] . ' — дарение';
                send_mail(
                    $order['customer_email'],
                    'Благодарим за вашето дарение!',
                    render_email('donation-confirmation-customer', [
                        'donor_name'       => $order['customer_name'],
                        'amount_eur'       => (float)$order['total_eur'],
                        'donation_message' => $order['donation_message'] ?? '',
                        'reference'        => $reference,
                    ])
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
                send_mail(
                    $order['customer_email'],
                    render_email_subject('order-confirmation-customer', $order['lang'] ?? 'bg', ['order_number' => $order['order_number'], 'customer_name' => $order['customer_name']]),
                    render_email('order-confirmation-customer', ['order' => $order])
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
    } elseif ($orderStatus === 3) {
        // Declined
        $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND payment_status = 'pending'")
            ->execute([$order['id']]);
    }
}
