<?php
/**
 * Stock held by shop orders.
 *
 * Checkout takes items out of stock when the order is placed. When an order is
 * cancelled (by an admin or automatically after 3 days unpaid) the items go back;
 * if a cancelled order is reopened or paid late they are taken out again.
 *
 * orders.stock_returned_at records that the items are back on the shelf, so
 * stock moves at most once in each direction no matter how often the status
 * flips. Only product lines with a product_id count — donation add-ons and
 * manual-invoice lines never touched stock.
 *
 * Pre-order lines mirror checkout: their stock may go negative (that's the
 * oversold-demand figure), so taking them out again is never blocked or
 * stopped at zero.
 */

/**
 * Product lines of an order that hold stock.
 *
 * @return list<array{product_id:int, variant_id:int, quantity:int, name:string, preorder:bool}>
 */
function order_stock_lines(array $order): array
{
    if (($order['type'] ?? '') !== 'physical') return [];

    $items = is_string($order['items'] ?? null) ? json_decode($order['items'], true) : ($order['items'] ?? []);
    $lines = [];
    foreach ((array)$items as $item) {
        if (($item['type'] ?? '') === 'donation') continue;
        $product_id = (int)($item['product_id'] ?? 0);
        $quantity   = (int)($item['quantity'] ?? 0);
        if ($product_id <= 0 || $quantity <= 0) continue;

        $name = (string)($item['name_bg'] ?? $item['name'] ?? ('#' . $product_id));
        if (!empty($item['variant_label'])) $name .= ' (' . $item['variant_label'] . ')';

        $lines[] = [
            'product_id' => $product_id,
            'variant_id' => (int)($item['variant_id'] ?? 0),
            'quantity'   => $quantity,
            'name'       => $name,
            'preorder'   => !empty($item['preorder']),
        ];
    }
    return $lines;
}

/** Runs $fn inside a transaction unless the caller already opened one. */
function _order_stock_tx(PDO $pdo, callable $fn): mixed
{
    if ($pdo->inTransaction()) return $fn();

    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Put an order's items back in stock. No-op if they already are.
 *
 * @return bool true when stock was returned by this call
 */
function order_return_stock(PDO $pdo, array $order): bool
{
    $lines = order_stock_lines($order);
    if (!$lines) return false;

    return _order_stock_tx($pdo, function () use ($pdo, $order, $lines): bool {
        $claim = $pdo->prepare('UPDATE orders SET stock_returned_at = NOW() WHERE id = ? AND stock_returned_at IS NULL');
        $claim->execute([$order['id']]);
        if ($claim->rowCount() === 0) return false;

        foreach ($lines as $l) {
            if ($l['variant_id'] > 0) {
                $pdo->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ? AND product_id = ?')
                    ->execute([$l['quantity'], $l['variant_id'], $l['product_id']]);
            } else {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')
                    ->execute([$l['quantity'], $l['product_id']]);
            }
        }
        return true;
    });
}

/**
 * Take a previously returned order's items back out of stock (order reopened
 * or paid late). No-op if the items were never returned.
 *
 * By default nothing changes when any product is short, and the short product
 * names are returned so the admin can fix stock first. With $allow_short (a
 * payment already arrived) stock is taken anyway, stopping at zero.
 *
 * @return list<string> names of products without enough stock (empty = done)
 */
function order_take_stock_again(PDO $pdo, array $order, bool $allow_short = false): array
{
    $lines = order_stock_lines($order);
    if (!$lines) return [];

    $run = function () use ($pdo, $order, $lines, $allow_short): array {
        $claim = $pdo->prepare('UPDATE orders SET stock_returned_at = NULL WHERE id = ? AND stock_returned_at IS NOT NULL');
        $claim->execute([$order['id']]);
        if ($claim->rowCount() === 0) return [];

        $short = [];
        foreach ($lines as $l) {
            $q = $l['quantity'];
            if ($l['preorder']) {
                // Same as checkout: pre-order stock is allowed to go negative.
                if ($l['variant_id'] > 0) {
                    $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND product_id = ?')
                        ->execute([$q, $l['variant_id'], $l['product_id']]);
                } else {
                    $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')
                        ->execute([$q, $l['product_id']]);
                }
                continue;
            }
            if ($l['variant_id'] > 0) {
                $stmt = $allow_short
                    ? $pdo->prepare('UPDATE product_variants SET stock = GREATEST(stock - ?, 0) WHERE id = ? AND product_id = ?')
                    : $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND product_id = ? AND stock >= ?');
                $stmt->execute($allow_short ? [$q, $l['variant_id'], $l['product_id']] : [$q, $l['variant_id'], $l['product_id'], $q]);
            } else {
                $stmt = $allow_short
                    ? $pdo->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?')
                    : $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                $stmt->execute($allow_short ? [$q, $l['product_id']] : [$q, $l['product_id'], $q]);
            }
            if (!$allow_short && $stmt->rowCount() === 0) $short[] = $l['name'];
        }
        if ($short) throw new OrderStockShortException($short);
        return [];
    };

    try {
        return _order_stock_tx($pdo, $run);
    } catch (OrderStockShortException $e) {
        return $e->short;
    }
}

/** Internal: aborts the take-again transaction when a product is short. */
final class OrderStockShortException extends RuntimeException
{
    /** @param list<string> $short */
    public function __construct(public readonly array $short)
    {
        parent::__construct('Not enough stock: ' . implode(', ', $short));
    }
}

/**
 * Stock side of an admin status change. Call after validating the new status
 * and BEFORE saving it — when reopening fails for lack of stock the caller must
 * not save.
 *
 * Cancelling a new/confirmed order returns its items. A shipped/delivered order
 * is left alone: its items already left the warehouse.
 *
 * @return array{ok:bool, message:string} message is shown to the admin ('' when nothing happened)
 */
function order_stock_on_status_change(PDO $pdo, array $order, string $new_status): array
{
    $old = $order['status'] ?? '';
    if ($old === $new_status || !order_stock_lines($order)) return ['ok' => true, 'message' => ''];

    if ($new_status === 'cancelled') {
        if (!in_array($old, ['new', 'confirmed'], true)) {
            return ['ok' => true, 'message' => 'Наличността не е променена, защото поръчката вече е изпратена.'];
        }
        return order_return_stock($pdo, $order)
            ? ['ok' => true, 'message' => 'Продуктите са върнати в наличност.']
            : ['ok' => true, 'message' => ''];
    }

    if ($old === 'cancelled' && !empty($order['stock_returned_at'])) {
        $short = order_take_stock_again($pdo, $order);
        if ($short) {
            return ['ok' => false, 'message' => 'Поръчка ' . $order['order_number']
                . ' не може да бъде възстановена — няма достатъчно наличност за: ' . implode(', ', $short)
                . '. Увеличете наличността на продукта и опитайте отново.'];
        }
        return ['ok' => true, 'message' => 'Продуктите са извадени отново от наличността.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * A payment that lands after the order was cancelled and restocked is still
 * real money: take the items back out (even if that empties the shelf) and
 * clear the auto-cancel marker. Call right before marking the order paid.
 */
function order_reinstate_for_late_payment(PDO $pdo, array $order): void
{
    if (empty($order['stock_returned_at']) && empty($order['unpaid_cancelled_at'])) return;

    $pdo->prepare('UPDATE orders SET unpaid_cancelled_at = NULL WHERE id = ?')->execute([$order['id']]);
    if (!empty($order['stock_returned_at'])) {
        order_take_stock_again($pdo, $order, true);
    }
    error_log("order-stock: order {$order['order_number']} was paid after being cancelled — stock taken back out, please check it");
}
