<?php
/**
 * Shared helpers for campaign pledge → orders/documents integration.
 * Used by api/campaign-payment-return.php and admin/pledge-view.php.
 */

/**
 * Find or create the synthetic orders row for a campaign pledge.
 * Returns the orders.id for the row (existing or newly created).
 *
 * @param array $pledge Row from campaign_pledges
 */
function pledge_ensure_order_row(PDO $pdo, array $pledge): int
{
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? AND type = 'pledge'");
    $stmt->execute([$pledge['pledge_number']]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int)$existing;
    }

    $items = json_encode([[
        'type'       => 'donation',
        'amount_eur' => (float)$pledge['amount_eur'],
        'recipient'  => 'foundation',
    ]]);

    // INSERT IGNORE relies on the UNIQUE KEY on order_number — orders table has this from migration 001
    $pdo->prepare("
        INSERT IGNORE INTO orders
            (order_number, type, status, customer_name, customer_email,
             items, subtotal_eur, shipping_eur, total_eur,
             payment_method, payment_status, invoice_data, created_at)
        VALUES (?, 'pledge', 'confirmed', ?, ?, ?, ?, 0, ?, 'card', 'paid', ?, ?)
    ")->execute([
        $pledge['pledge_number'],
        $pledge['name'],
        $pledge['email'],
        $items,
        (float)$pledge['amount_eur'],
        (float)$pledge['amount_eur'],
        json_encode(['donor_type' => 'individual']),
        $pledge['created_at'],
    ]);

    $new_id = (int)$pdo->lastInsertId();
    if ($new_id > 0) {
        return $new_id;
    }

    // INSERT IGNORE silently skipped a duplicate — fetch the existing row
    $stmt->execute([$pledge['pledge_number']]);
    return (int)$stmt->fetchColumn();
}

/**
 * Insert a documents row for a pledge cert if one doesn't already exist.
 * Idempotent — safe to call multiple times.
 */
function pledge_insert_cert_document(
    PDO    $pdo,
    int    $order_id,
    int    $cert_number,
    string $formatted_number,
    string $rel_path
): void {
    // INSERT IGNORE is atomic — safe under concurrent calls
    $pdo->prepare("
        INSERT IGNORE INTO documents (order_id, type, number, formatted_number, file_path)
        VALUES (?, 'donation_cert', ?, ?, ?)
    ")->execute([$order_id, $cert_number, $formatted_number, $rel_path]);
}

/**
 * Mark the pledge as reversed and its linked orders row as refunded.
 * Called after a successful DSK Bank refund.
 */
function pledge_update_refunded_statuses(PDO $pdo, int $pledge_id, string $pledge_number): void
{
    // campaign_pledges has no updated_at column — orders does
    $pdo->prepare("UPDATE campaign_pledges SET payment_status='reversed' WHERE id=?")
        ->execute([$pledge_id]);
    $pdo->prepare("UPDATE orders SET payment_status='refunded', updated_at=NOW() WHERE order_number=? AND type='pledge'")
        ->execute([$pledge_number]);
}
