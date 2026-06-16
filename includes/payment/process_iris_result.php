<?php
/**
 * IRIS Pay result processor.
 *
 * Called from api/iris-payment-callback.php with the AUTHORITATIVE status fetched
 * via IRISPayment::getStatus() — never with the (untrusted) callback status param.
 * Marks the order paid only if IRIS reports CONFIRMED for the exact amount and
 * recipient IBAN we registered; cancels on FAILED; leaves WAITING pending.
 *
 * Reuses notify_order_paid() from process_payment.php so shop/donation orders are
 * notified identically to the DSK Bank flow.
 */
require_once __DIR__ . '/process_payment.php';

function process_iris_result(PDO $pdo, array $order, array $status): void
{
    $state = strtoupper((string)($status['status'] ?? ''));

    if ($state === 'CONFIRMED') {
        if ($order['payment_status'] === 'paid') {
            return; // idempotent — already processed
        }

        // Integrity checks against what IRIS actually settled.
        $paidSum  = (float)($status['sum'] ?? 0);
        $expected = (float)$order['total_eur'];
        if (abs($paidSum - $expected) > 0.01) {
            error_log("iris: amount mismatch for order {$order['order_number']} — paid {$paidSum}, expected {$expected}");
            return;
        }

        $ourIban  = preg_replace('/\s+/', '', setting_get('iris_iban', ''));
        $recvIban = preg_replace('/\s+/', '', (string)($status['receiverIban'] ?? ''));
        if ($ourIban !== '' && $recvIban !== '' && strcasecmp($recvIban, $ourIban) !== 0) {
            error_log("iris: receiver IBAN mismatch for order {$order['order_number']} — got {$recvIban}");
            return;
        }

        // Conditional update + rowCount guard => paid emails fire exactly once,
        // even under concurrent/duplicate callbacks.
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
    } elseif ($state === 'FAILED') {
        $pdo->prepare(
            "UPDATE orders SET status = 'cancelled', updated_at = NOW()
             WHERE id = ? AND payment_status = 'pending'"
        )->execute([$order['id']]);
    }
    // WAITING (or anything else) → leave the order pending.
}
