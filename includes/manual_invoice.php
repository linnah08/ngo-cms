<?php
/**
 * Shop products on a manual invoice (admin/manual-invoice.php).
 *
 * Each invoice line can name a shop product — active or not, and for a variant
 * product one of its variants. The line then fills its name and price from the
 * product (the admin may still change them), records product_id / variant_id,
 * and takes the item out of stock when the invoice is saved. Stock may go below
 * zero: the sale already happened, so the invoice is saved and the admin is
 * warned instead. Cancelling or deleting the order puts the items back through
 * includes/order_stock.php, exactly as for a shop order.
 *
 * Lines without a product are free text, as before, and never touch stock.
 */

require_once __DIR__ . '/shared_stock.php';

/**
 * Everything the line picker offers, active items first.
 *
 * A variant product is offered only through its variants (the stock lives on
 * them); every other product is offered as itself. An item is inactive when its
 * product is, or when the variant itself is switched off.
 *
 * @return array{active: list<array>, inactive: list<array>}
 *   each entry: value ("12" or "12:41"), product_id, variant_id, name_bg, name_en, price_eur, stock
 */
function manual_invoice_product_options(PDO $pdo): array
{
    $products = $pdo->query(
        'SELECT id, name_bg, name_en, price_eur, stock, active, `type` FROM products ORDER BY sort_order, name_bg, id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $variants = [];
    foreach ($pdo->query(
        'SELECT id, product_id, label_bg, label_en, stock, active FROM product_variants ORDER BY sort_order, id'
    )->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $variants[(int)$v['product_id']][] = $v;
    }

    $out = ['active' => [], 'inactive' => []];
    foreach ($products as $p) {
        $pid = (int)$p['id'];
        if (($p['type'] ?? '') === 'variant') {
            foreach ($variants[$pid] ?? [] as $v) {
                $entry = _manual_invoice_entry($p, $v);
                $out[(int)$p['active'] && (int)$v['active'] ? 'active' : 'inactive'][] = $entry;
            }
        } else {
            $out[(int)$p['active'] ? 'active' : 'inactive'][] = _manual_invoice_entry($p, null);
        }
    }
    return $out;
}

/** One picker entry / resolved line source for a product, or one of its variants. */
function _manual_invoice_entry(array $p, ?array $v): array
{
    $name_bg = (string)$p['name_bg'];
    $name_en = trim((string)($p['name_en'] ?? '')) !== '' ? (string)$p['name_en'] : $name_bg;
    if ($v) {
        $label_bg = (string)$v['label_bg'];
        $label_en = trim((string)($v['label_en'] ?? '')) !== '' ? (string)$v['label_en'] : $label_bg;
        $name_bg .= ' (' . $label_bg . ')';
        $name_en .= ' (' . $label_en . ')';
    }
    return [
        'value'      => (int)$p['id'] . ($v ? ':' . (int)$v['id'] : ''),
        'product_id' => (int)$p['id'],
        'variant_id' => $v ? (int)$v['id'] : 0,
        'name_bg'    => $name_bg,
        'name_en'    => $name_en,
        'price_eur'  => round((float)$p['price_eur'], 2),
        'stock'      => (int)($v ? $v['stock'] : $p['stock']),
    ];
}

/**
 * The product a posted picker value ("12" or "12:41") names, or null when it
 * names nothing valid: unknown product, a variant of another product, or a
 * variant product without its variant. Inactive items are valid.
 */
function manual_invoice_resolve(PDO $pdo, string $value): ?array
{
    if (!preg_match('/^(\d+)(?::(\d+))?$/', trim($value), $m)) return null;
    $product_id = (int)$m[1];
    $variant_id = isset($m[2]) ? (int)$m[2] : 0;

    $stmt = $pdo->prepare('SELECT id, name_bg, name_en, price_eur, stock, active, `type` FROM products WHERE id = ?');
    $stmt->execute([$product_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;

    $is_variant_product = ($p['type'] ?? '') === 'variant';
    if ($is_variant_product !== ($variant_id > 0)) return null;
    if (!$variant_id) return _manual_invoice_entry($p, null);

    $stmt = $pdo->prepare('SELECT id, product_id, label_bg, label_en, stock, active FROM product_variants WHERE id = ? AND product_id = ?');
    $stmt->execute([$variant_id, $product_id]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    return $v ? _manual_invoice_entry($p, $v) : null;
}

/**
 * Invoice lines from the posted form columns (item_product[], item_name[],
 * item_qty[], item_price[] — one entry per row).
 *
 * A row with a valid product fills an empty name or price from it and keeps
 * product_id / variant_id; the EN name always comes from the product. A row
 * whose product is not valid is kept as typed, without a product. Rows with
 * neither a name nor a product are skipped.
 *
 * @return list<array> order items, in the shape order_stock_lines() reads
 */
function manual_invoice_items(PDO $pdo, array $post): array
{
    $products = (array)($post['item_product'] ?? []);
    $names    = (array)($post['item_name']    ?? []);
    $qtys     = (array)($post['item_qty']     ?? []);
    $prices   = (array)($post['item_price']   ?? []);

    $items = [];
    foreach (array_keys($names + $products) as $i) {
        $name   = trim((string)($names[$i] ?? ''));
        $chosen = trim((string)($products[$i] ?? ''));
        $source = $chosen !== '' ? manual_invoice_resolve($pdo, $chosen) : null;
        if ($name === '' && !$source) continue;

        $qty       = max(1, (int)($qtys[$i] ?? 1));
        $price_raw = trim((string)($prices[$i] ?? ''));
        $price     = $price_raw === '' && $source ? $source['price_eur'] : round((float)$price_raw, 2);
        if ($name === '') $name = $source['name_bg'];

        $item = [
            'type'         => 'product',
            'name_bg'      => $name,
            'name'         => $name,
            'quantity'     => $qty,
            'price_eur'    => $price,
            'subtotal_eur' => round($price * $qty, 2),
        ];
        if ($source) {
            $item['name_en']    = $source['name_en'];
            $item['product_id'] = $source['product_id'];
            if ($source['variant_id']) $item['variant_id'] = $source['variant_id'];
        }
        $items[] = $item;
    }
    return $items;
}

/** Thrown when a shared item's home site can't be reached: nothing may be saved. */
final class ManualInvoiceStockException extends RuntimeException {}

/**
 * Take an invoice's product lines out of stock, allowing it to go below zero.
 * Run it in the same transaction as the order insert.
 *
 * @return list<string> "Name (−2)" for every item whose stock is now below zero
 * @throws ManualInvoiceStockException when a shared item's stock can't be changed
 */
function manual_invoice_take_stock(PDO $pdo, array $items): array
{
    $touched = [];
    foreach ($items as $item) {
        $product_id = (int)($item['product_id'] ?? 0);
        $qty        = (int)($item['quantity'] ?? 0);
        if ($product_id <= 0 || $qty <= 0) continue;
        $variant_id = (int)($item['variant_id'] ?? 0) ?: null;

        if (!stock_move($pdo, $product_id, $variant_id, -$qty)) {
            throw new ManualInvoiceStockException(
                'Наличността на „' . $item['name_bg'] . '“ се води от друг сайт, който в момента не отговаря. '
                . 'Фактурата не е издадена — опитайте отново след малко.'
            );
        }
        $touched[$product_id . ':' . (int)$variant_id] = [$product_id, $variant_id, $item['name_bg']];
    }

    $short = [];
    foreach ($touched as [$product_id, $variant_id, $name]) {
        $stmt = $variant_id
            ? $pdo->prepare('SELECT stock FROM product_variants WHERE id = ? AND product_id = ?')
            : $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute($variant_id ? [$variant_id, $product_id] : [$product_id]);
        $stock = (int)$stmt->fetchColumn();
        if ($stock < 0) $short[] = $name . ' (' . str_replace('-', '−', (string)$stock) . ')';
    }
    return $short;
}

/** What the picker shows for an entry: "Сапун (Лавандула) — 12.00 € · наличност 5". */
function manual_invoice_option_label(array $entry): string
{
    return $entry['name_bg'] . ' — ' . format_eur((float)$entry['price_eur']) . ' · наличност ' . (int)$entry['stock'];
}
