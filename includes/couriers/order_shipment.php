<?php
/**
 * Courier shipments vs. the order's payment and status.
 *
 * - A label may only be created for an order that is paid (or cash on
 *   delivery, which is paid when the parcel arrives) and not cancelled —
 *   otherwise the courier bills us for a parcel nobody paid for.
 * - When an order is cancelled — by the admin, a declined card, a failed
 *   IRIS payment or the unpaid-order cron — its Speedy / BoxNow shipment is
 *   cancelled with the courier too, so it doesn't land on the monthly invoice.
 */

/**
 * Why no label may be created for this order, in plain language — or null when it may.
 */
function order_label_block_reason(array $order): ?string
{
    if (($order['status'] ?? '') === 'cancelled') {
        return 'Поръчката е отменена — не може да се създаде товарителница.';
    }
    if (($order['payment_method'] ?? '') !== 'cod' && ($order['payment_status'] ?? '') !== 'paid') {
        return 'Поръчката още не е платена. Създайте товарителница, след като плащането пристигне.';
    }
    return null;
}

/**
 * Cancel the order's Speedy / BoxNow shipment with the courier and clear it
 * from the order. Does nothing when the order has no shipment.
 *
 * @param callable|null $cancel fn(string $courier, string $shipment_id): void —
 *        defaults to the real courier API; throws on failure
 * @return string|null null when there was nothing to cancel or it was cancelled;
 *         otherwise a plain-language error naming the shipment
 */
function order_cancel_shipment(PDO $pdo, array $order, ?callable $cancel = null): ?string
{
    $courier = $order['courier'] ?? '';
    $column  = ['speedy' => 'speedy_shipment_id', 'boxnow' => 'boxnow_parcel_id'][$courier] ?? null;
    if ($column === null || empty($order[$column])) return null;
    $shipment_id = (string)$order[$column];

    $cancel ??= function (string $courier, string $shipment_id): void {
        if ($courier === 'speedy') {
            require_once __DIR__ . '/SpeedyCourier.php';
            (new SpeedyCourier())->cancelShipment($shipment_id);
        } else {
            require_once __DIR__ . '/BoxNowCourier.php';
            (new BoxNowCourier())->cancelShipment($shipment_id);
        }
    };

    $name = $courier === 'speedy' ? 'Speedy' : 'BoxNow';
    try {
        $cancel($courier, $shipment_id);
    } catch (Throwable $e) {
        error_log("$name cancel for order {$order['order_number']}: " . $e->getMessage());
        return "Товарителницата $name $shipment_id не беше анулирана автоматично. "
             . "Анулирайте я ръчно в сайта на $name, иначе ще бъде таксувана.";
    }

    $pdo->prepare("UPDATE orders SET $column = NULL, tracking_number = NULL, updated_at = NOW() WHERE id = ?")
        ->execute([(int)$order['id']]);
    return null;
}

/**
 * For automatic cancellations (cron, bank callbacks), where nobody is looking
 * at a page: cancel the shipment and email the admin if that fails.
 */
function order_cancel_shipment_or_alert(PDO $pdo, array $order, ?callable $cancel = null): void
{
    $error = order_cancel_shipment($pdo, $order, $cancel);
    if ($error === null) return;

    require_once dirname(__DIR__) . '/payment/payment_errors.php';
    payment_error_report('Отменена поръчка има товарителница, която не беше анулирана', $order['order_number'] ?? '', $error);
}
