<?php
/**
 * Shared stock: some products' stock lives in another site's database.
 *
 * Two sites on the same MySQL server can sell the same physical items. One site
 * (the "home") keeps the real count in its own products / product_variants rows.
 * The other lists the items in its own shop too and, in its site.config.php,
 * points them at the home rows:
 *
 *   define('SHARED_STOCK', [
 *       'db'       => 'home_site_db',  // the home site's database on this MySQL server
 *       'name'     => 'example.org',   // shown in the admin next to the shared items
 *       'products' => [12 => 3],       // this site's product id => the home site's product id
 *       'variants' => [41 => 8],       // this site's variant id => the home site's variant id
 *   ]);
 *
 * When the two sites set an item up differently — a product of its own on one,
 * a variant on the other — name the other kind: 'products' => [2 => ['variant' => 10]]
 * or 'variants' => [41 => ['product' => 3]].
 *
 * This site's database user needs SELECT and UPDATE on the home database. The
 * home site needs nothing: to it, the other site's sales are stock changes.
 *
 * Every stock change goes through stock_move(). For a shared item it changes the
 * home row — in the caller's transaction, so a sale is checked against the one
 * real count and two shops can't sell the same last item — and copies the result
 * into the local row. The local row stays a copy every page reads as before;
 * shared_stock_sync() refreshes it once per request from get_pdo(). When the
 * home database can't be reached, shared items show as sold out, never oversold.
 */

require_once __DIR__ . '/error-alerts.php';

/**
 * The SHARED_STOCK setting, checked. Tests set $GLOBALS['_shared_stock_config'].
 *
 * 'products' / 'variants' come back keyed by this site's id, each pointing at
 * ['table' => 'products'|'product_variants', 'id' => the home site's id].
 *
 * @return array{db:string, name:string, products:array<int,array{table:string,id:int}>, variants:array<int,array{table:string,id:int}>}|null
 */
function shared_stock_config(): ?array
{
    $c = $GLOBALS['_shared_stock_config'] ?? (defined('SHARED_STOCK') ? SHARED_STOCK : null);
    if (!is_array($c) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)($c['db'] ?? ''))) return null;

    $ids = function ($map, string $same_table): array {
        $out = [];
        foreach ((array)$map as $local => $home) {
            $table = $same_table;
            if (is_array($home)) {
                $table = isset($home['variant']) ? 'product_variants' : 'products';
                $home  = $home['variant'] ?? $home['product'] ?? 0;
            }
            if ((int)$local > 0 && (int)$home > 0) $out[(int)$local] = ['table' => $table, 'id' => (int)$home];
        }
        return $out;
    };
    return [
        'db'       => $c['db'],
        'name'     => trim((string)($c['name'] ?? '')),
        'products' => $ids($c['products'] ?? [], 'products'),
        'variants' => $ids($c['variants'] ?? [], 'product_variants'),
    ];
}

/** Where a shared item's real count lives, or null for an item of this site's own. */
function _shared_stock_target(int $product_id, ?int $variant_id): ?array
{
    $c = shared_stock_config();
    if (!$c) return null;
    $home = $variant_id ? ($c['variants'][$variant_id] ?? null) : ($c['products'][$product_id] ?? null);
    if (!$home) return null;
    return ['home' => "`{$c['db']}`.`{$home['table']}`", 'id' => $home['id']];
}

/** Is this item's stock kept by the other site? */
function shared_stock_is_shared(int $product_id, ?int $variant_id = null): bool
{
    return _shared_stock_target($product_id, $variant_id) !== null;
}

/**
 * Move an item's stock by $delta (positive adds, negative takes).
 *
 *   'free'  — no limit: returns, corrections, pre-orders (whose stock may go below zero)
 *   'guard' — only when there's enough; otherwise nothing changes and false comes back
 *   'floor' — stops at zero: a sale that already happened elsewhere, e.g. at a stall
 *
 * For a shared item, false also means the other site's database couldn't be
 * reached — nothing changed, and the admin gets an error alert.
 */
function stock_move(PDO $pdo, int $product_id, ?int $variant_id, int $delta, string $mode = 'free'): bool
{
    if ($delta === 0) return true;
    $variant_id = $variant_id ?: null;
    $set   = $mode === 'floor' ? 'stock = GREATEST(stock + ?, 0)' : 'stock = stock + ?';
    $guard = $mode === 'guard' && $delta < 0;

    $target = _shared_stock_target($product_id, $variant_id);
    if (!$target) {
        $sql  = $variant_id ? "UPDATE product_variants SET $set WHERE id = ? AND product_id = ?" : "UPDATE products SET $set WHERE id = ?";
        $args = $variant_id ? [$delta, $variant_id, $product_id] : [$delta, $product_id];
        if ($guard) { $sql .= ' AND stock >= ?'; $args[] = -$delta; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return !$guard || $stmt->rowCount() > 0;
    }

    try {
        $stmt = $pdo->prepare("UPDATE {$target['home']} SET $set WHERE id = ?" . ($guard ? ' AND stock >= ?' : ''));
        $stmt->execute($guard ? [$delta, $target['id'], -$delta] : [$delta, $target['id']]);
        $moved = !$guard || $stmt->rowCount() > 0;
        _shared_stock_copy($pdo, $product_id, $variant_id, $target);
        return $moved;
    } catch (PDOException $e) {
        _shared_stock_unreachable($e);
        return false;
    }
}

/** Copy one shared item's real count into its local row. */
function _shared_stock_copy(PDO $pdo, int $product_id, ?int $variant_id, array $target): void
{
    if ($variant_id) {
        $pdo->prepare("UPDATE product_variants l JOIN {$target['home']} h ON h.id = ? SET l.stock = h.stock WHERE l.id = ? AND l.product_id = ?")
            ->execute([$target['id'], $variant_id, $product_id]);
    } else {
        $pdo->prepare("UPDATE products l JOIN {$target['home']} h ON h.id = ? SET l.stock = h.stock WHERE l.id = ?")
            ->execute([$target['id'], $product_id]);
    }
}

/**
 * Bring every shared item's local count up to date with the other site. Runs
 * once per request from get_pdo(). When the other site's database can't be
 * reached, the shared items are set to 0 — sold out — and the admin is alerted.
 */
function shared_stock_sync(PDO $pdo): void
{
    $c = shared_stock_config();
    if (!$c || (!$c['variants'] && !$c['products'])) return;

    try {
        foreach (['product_variants' => $c['variants'], 'products' => $c['products']] as $table => $map) {
            foreach ($map as $local => $home) {
                $pdo->prepare("UPDATE `$table` l JOIN `{$c['db']}`.`{$home['table']}` h ON h.id = ? SET l.stock = h.stock WHERE l.id = ? AND l.stock <> h.stock")
                    ->execute([$home['id'], $local]);
            }
        }
    } catch (PDOException $e) {
        _shared_stock_unreachable($e);
        try {
            foreach (['product_variants' => $c['variants'], 'products' => $c['products']] as $table => $map) {
                if (!$map) continue;
                $in = implode(',', array_fill(0, count($map), '?'));
                $pdo->prepare("UPDATE `$table` SET stock = 0 WHERE id IN ($in)")->execute(array_keys($map));
            }
        } catch (PDOException $e2) {
            error_log('shared_stock_sync: ' . $e2->getMessage());
        }
    }
}

/** Log and alert, without the database's own wording reaching any page. */
function _shared_stock_unreachable(PDOException $e): void
{
    $c = shared_stock_config();
    $msg = 'Shared stock: the database of ' . ($c['name'] ?: $c['db'] ?? 'the other site')
         . ' could not be reached, so its products show as sold out here. (' . $e->getMessage() . ')';
    error_log($msg);
    try {
        error_alert_capture('SharedStock', $msg, __FILE__, __LINE__);
    } catch (Throwable) {
    }
}

/**
 * A product's shared items as this site counts them now, keyed by variant id
 * (0 = the product itself). The product editor takes this before saving.
 *
 * @return array<int,int>
 */
function shared_stock_snapshot(PDO $pdo, int $product_id): array
{
    $c = shared_stock_config();
    if (!$c || $product_id <= 0) return [];

    $out = [];
    if (isset($c['products'][$product_id])) {
        $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$product_id]);
        $out[0] = (int)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT id, stock FROM product_variants WHERE product_id = ?');
    $stmt->execute([$product_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        if (isset($c['variants'][(int)$v['id']])) $out[(int)$v['id']] = (int)$v['stock'];
    }
    return $out;
}

/**
 * After the product editor saved: pass the change it made to each shared item
 * on to the other site, and copy the real count back. Passing the change, not
 * the number, keeps a sale made there in the meantime.
 *
 * @param array<int,int> $before shared_stock_snapshot() taken before the save
 * @return bool false when the other site's database couldn't be reached
 */
function shared_stock_after_edit(PDO $pdo, int $product_id, array $before): bool
{
    $ok = true;
    foreach ($before as $vid => $old) {
        $stmt = $vid
            ? $pdo->prepare('SELECT stock FROM product_variants WHERE id = ? AND product_id = ?')
            : $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute($vid ? [$vid, $product_id] : [$product_id]);
        $now = $stmt->fetchColumn();
        if ($now === false) continue;
        $delta = (int)$now - $old;
        if ($delta === 0) continue;
        $ok = stock_move($pdo, $product_id, $vid ?: null, $delta) && $ok;
    }
    return $ok;
}
