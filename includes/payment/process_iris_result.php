<?php
/**
 * IRIS Pay result processor — marks an order paid after a *verified* callback.
 *
 * IRIS provides no status-query API, so authenticity is established by the
 * per-order capability token, which api/iris-payment-callback.php checks BEFORE
 * calling this. Amount integrity is guaranteed by registration: the customer can
 * only have paid the exact `sum` we registered for this order, so there is no
 * separate amount to re-verify here.
 *
 * Reuses notify_order_paid() from process_payment.php for the paid emails,
 * so shop/donation orders are notified identically to the DSK Bank flow.
 */
require_once __DIR__ . '/process_payment.php';

function process_iris_result(PDO $pdo, array $order): void
{
    if ($order['payment_status'] === 'paid') {
        return; // idempotent — duplicate callback for an already-paid order
    }

    // Conditional update: only transitions a still-pending order. Combined with
    // the rowCount() check below, this makes the paid emails fire exactly once
    // even if IRIS sends concurrent/duplicate callbacks.
    $stmt = $pdo->prepare(
        "UPDATE orders SET payment_status = 'paid', status = 'confirmed', updated_at = NOW()
         WHERE id = ? AND payment_status = 'pending'"
    );
    $stmt->execute([$order['id']]);

    if ($stmt->rowCount() > 0) {
        $order['payment_status'] = 'paid';
        $order['status']         = 'confirmed';
        notify_order_paid($order);
    }
}
