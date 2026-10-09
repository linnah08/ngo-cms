<?php
declare(strict_types=1);

/**
 * Distribution module — track stock of a shop product outside the online shop.
 *
 *   batch       stock produced or delivered (a print run, a workshop delivery)
 *   allocation  stock handed out of that pool, to one of:
 *                 'distributor'  a named distributor (shop, partner…) — gets a
 *                                numbered hand-over protocol PDF
 *                 'online'       the online shop
 *                 'personal'     sold in person, on the spot
 *                 'sample'       given away free (named recipient)
 *   sale        what a distributor reports as sold, and whether they paid
 *
 * Works for any product. A product with variants keeps a separate ledger per
 * variant: every record names product_id + variant_id, and variant_id is NULL
 * only for products without variants. One (product, variant) pair is an "item"
 * below.
 *
 * The 'online' allocations are a record of what was sent to the shop. Live shop
 * stock (products.stock / product_variants.stock) is its own number, changed by
 * checkout. The two only move together where the admin explicitly asks for it
 * on the form (adding to / taking from shop stock) — automatic coupling made the
 * two drift apart every time an old record was corrected.
 *
 * Every function that returns ['ok' => false, 'error' => …] has a plain-language
 * Bulgarian message meant to be shown to the admin as-is.
 */

const DISTRIBUTION_DESTINATIONS = ['distributor', 'online', 'personal', 'sample'];

/**
 * Stop here when the module is switched off.
 *
 * TODO(modules-core): replace the body with module_admin_guard('distribution')
 * once includes/modules.php is merged; every distribution admin page calls this
 * one function.
 */
function distribution_require_enabled(): void
{
    module_admin_guard('distribution');   // also off while the shop is off (needs)
}

// ── Items (product + variant) ──────────────────────────────────────────────────

/** SQL + params that match one item, or every variant of a product when $whole. */
function _distribution_scope(int $product_id, ?int $variant_id, bool $whole = false, string $alias = ''): array
{
    $a = $alias !== '' ? $alias . '.' : '';
    if ($whole) return ["{$a}product_id = ?", [$product_id]];
    // <=> is NULL-safe equality (MySQL and MariaDB): NULL <=> NULL is true.
    return ["{$a}product_id = ? AND {$a}variant_id <=> ?", [$product_id, $variant_id]];
}

/** Products an admin can pick on the distribution page (inactive ones too). */
function distribution_products(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name_bg, name_en, `type`, active, price_eur FROM products ORDER BY active DESC, sort_order, id'
    )->fetchAll();
}

function distribution_product(PDO $pdo, int $product_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$product_id]);
    return $stmt->fetch() ?: null;
}

/**
 * The items of a product: one per variant for a variant product (active
 * variants, plus inactive ones that already have distribution records), or a
 * single item with variant_id null for any other product.
 *
 * @return list<array{variant_id: ?int, label: string, active: bool}>
 */
function distribution_product_items(PDO $pdo, array $product): array
{
    if (($product['type'] ?? '') !== 'variant') {
        return [['variant_id' => null, 'label' => (string) $product['name_bg'], 'active' => true]];
    }
    $stmt = $pdo->prepare(
        'SELECT pv.id, pv.label_bg, pv.active FROM product_variants pv
          WHERE pv.product_id = ?
            AND (pv.active = 1
                 OR EXISTS (SELECT 1 FROM distribution_batches b WHERE b.variant_id = pv.id)
                 OR EXISTS (SELECT 1 FROM distribution_allocations a WHERE a.variant_id = pv.id))
          ORDER BY pv.active DESC, pv.sort_order, pv.id'
    );
    $stmt->execute([(int) $product['id']]);
    $items = [];
    foreach ($stmt->fetchAll() as $v) {
        $items[] = ['variant_id' => (int) $v['id'], 'label' => (string) $v['label_bg'], 'active' => (bool) $v['active']];
    }
    return $items;
}

/**
 * Checks that $variant_id fits the product: required (and its own) for a
 * variant product, absent for any other.
 *
 * @return string|null error message, or null when fine
 */
function distribution_item_error(PDO $pdo, int $product_id, ?int $variant_id): ?string
{
    $product = distribution_product($pdo, $product_id);
    if (!$product) return 'Продуктът не е намерен.';
    if ($product['type'] === 'variant') {
        if (!$variant_id) return 'Моля изберете вариант.';
        $stmt = $pdo->prepare('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?');
        $stmt->execute([$variant_id, $product_id]);
        if (!$stmt->fetchColumn()) return 'Вариантът не е намерен.';
    } elseif ($variant_id) {
        return 'Този продукт няма варианти.';
    }
    return null;
}

/** Display name of an item: product name, plus the variant's in brackets. */
function distribution_item_label(PDO $pdo, int $product_id, ?int $variant_id): string
{
    $product = distribution_product($pdo, $product_id);
    $name    = $product ? (string) $product['name_bg'] : ('#' . $product_id);
    if ($variant_id) {
        $stmt = $pdo->prepare('SELECT label_bg FROM product_variants WHERE id = ?');
        $stmt->execute([$variant_id]);
        $label = $stmt->fetchColumn();
        if ($label !== false && $label !== '') $name .= ' (' . $label . ')';
    }
    return $name;
}

// ── Stock ledger ───────────────────────────────────────────────────────────────

function distribution_total_produced(PDO $pdo, int $product_id, ?int $variant_id, bool $whole = false): int
{
    [$where, $params] = _distribution_scope($product_id, $variant_id, $whole);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM distribution_batches WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Allocated so far — all destinations, or just $destination. */
function distribution_total_allocated(PDO $pdo, int $product_id, ?int $variant_id, ?string $destination = null, bool $whole = false): int
{
    [$where, $params] = _distribution_scope($product_id, $variant_id, $whole);
    if ($destination !== null) {
        $where   .= ' AND destination = ?';
        $params[] = $destination;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM distribution_allocations WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Produced but not yet handed out anywhere — what can still be allocated. */
function distribution_available(PDO $pdo, int $product_id, ?int $variant_id): int
{
    return distribution_total_produced($pdo, $product_id, $variant_id)
        - distribution_total_allocated($pdo, $product_id, $variant_id);
}

/**
 * Live shop stock of an item right now: the variant's own stock for a variant,
 * else the product's. May be negative (pre-ordered items still waiting).
 */
function distribution_online_stock(PDO $pdo, int $product_id, ?int $variant_id): int
{
    if ($variant_id) {
        $stmt = $pdo->prepare('SELECT stock FROM product_variants WHERE id = ? AND product_id = ?');
        $stmt->execute([$variant_id, $product_id]);
    } else {
        $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$product_id]);
    }
    return (int) ($stmt->fetchColumn() ?: 0);
}

/** Move live shop stock of an item by $delta (positive adds, negative removes). */
function distribution_adjust_online_stock(PDO $pdo, int $product_id, ?int $variant_id, int $delta): void
{
    if ($delta === 0) return;
    if ($variant_id) {
        $pdo->prepare('UPDATE product_variants SET stock = stock + ? WHERE id = ? AND product_id = ?')
            ->execute([$delta, $variant_id, $product_id]);
    } else {
        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $product_id]);
    }
}

/**
 * Set live shop stock to a counted number (after a stock-take). Leaves the
 * allocation records alone.
 */
function distribution_set_online_stock(PDO $pdo, int $product_id, ?int $variant_id, int $new_stock): void
{
    distribution_adjust_online_stock(
        $pdo, $product_id, $variant_id, $new_stock - distribution_online_stock($pdo, $product_id, $variant_id)
    );
}

/**
 * Items ordered through the website and not cancelled — for reference only, it
 * is never subtracted from anything (shop sales already come out of live stock).
 */
function distribution_shop_ordered_qty(PDO $pdo, int $product_id, ?int $variant_id, bool $whole = false): int
{
    $stmt = $pdo->prepare(
        "SELECT items FROM orders WHERE status != 'cancelled' AND JSON_CONTAINS(items, ?)"
    );
    $stmt->execute([json_encode([['product_id' => $product_id]])]);

    $qty = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode((string) $json, true) ?: [] as $item) {
            if ((int) ($item['product_id'] ?? 0) !== $product_id) continue;
            if (!$whole && (int) ($item['variant_id'] ?? 0) !== (int) $variant_id) continue;
            $qty += (int) ($item['quantity'] ?? 0);
        }
    }
    return $qty;
}

/**
 * Every figure of one item's ledger.
 *
 * @return array{produced:int, to_distributors:int, to_online:int, to_personal:int,
 *               to_samples:int, available:int, online_stock:int, shop_ordered:int}
 */
function distribution_item_summary(PDO $pdo, int $product_id, ?int $variant_id): array
{
    $produced = distribution_total_produced($pdo, $product_id, $variant_id);
    $by_dest  = array_fill_keys(DISTRIBUTION_DESTINATIONS, 0);
    [$where, $params] = _distribution_scope($product_id, $variant_id);
    $stmt = $pdo->prepare("SELECT destination, COALESCE(SUM(quantity), 0) FROM distribution_allocations WHERE $where GROUP BY destination");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $dest => $sum) {
        $by_dest[$dest] = (int) $sum;
    }
    return [
        'produced'        => $produced,
        'to_distributors' => $by_dest['distributor'],
        'to_online'       => $by_dest['online'],
        'to_personal'     => $by_dest['personal'],
        'to_samples'      => $by_dest['sample'],
        'available'       => $produced - array_sum($by_dest),
        'online_stock'    => distribution_online_stock($pdo, $product_id, $variant_id),
        'shop_ordered'    => distribution_shop_ordered_qty($pdo, $product_id, $variant_id),
    ];
}

/**
 * Ledger of a whole product: one row per item, plus the totals.
 *
 * @return array{items: list<array>, total: array<string,int>}
 */
function distribution_product_summary(PDO $pdo, array $product): array
{
    $rows  = [];
    $total = [];
    foreach (distribution_product_items($pdo, $product) as $item) {
        $s      = distribution_item_summary($pdo, (int) $product['id'], $item['variant_id']);
        $rows[] = $item + $s;
        foreach ($s as $k => $v) $total[$k] = ($total[$k] ?? 0) + $v;
    }
    return ['items' => $rows, 'total' => $total];
}

// ── Distributors ───────────────────────────────────────────────────────────────

/**
 * Validates distributor fields. The company number is required because it is
 * printed on the hand-over protocol.
 *
 * @return string|null error message, or null when fine
 */
function distribution_distributor_error(string $name, string $company_number, ?string $email): ?string
{
    if (trim($name) === '')           return 'Моля въведете име на дистрибутора.';
    if (trim($company_number) === '') return 'Моля въведете ЕИК / Булстат — той се отпечатва в протокола.';
    if (mb_strlen($company_number) > 20) return 'ЕИК / Булстат е твърде дълъг (до 20 знака).';
    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Имейлът не изглежда правилен.';
    return null;
}

function distribution_create_distributor(PDO $pdo, string $name, string $company_number, ?string $contact_name, ?string $address, ?string $email, ?string $notes = null): int
{
    $pdo->prepare(
        'INSERT INTO distribution_distributors (name, company_number, contact_name, address, email, notes) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$name, $company_number, $contact_name, $address, $email, $notes]);
    return (int) $pdo->lastInsertId();
}

function distribution_update_distributor(PDO $pdo, int $id, string $name, string $company_number, ?string $contact_name, ?string $address, ?string $email, ?string $notes = null): void
{
    $pdo->prepare(
        'UPDATE distribution_distributors SET name = ?, company_number = ?, contact_name = ?, address = ?, email = ?, notes = ? WHERE id = ?'
    )->execute([$name, $company_number, $contact_name, $address, $email, $notes, $id]);
}

/**
 * Archive (hide from the pickers) or bring back distributors. Their records
 * stay; a distributor is never deleted because protocols and sales point at it.
 *
 * @param int[] $ids
 * @return int rows changed
 */
function distribution_set_distributors_active(PDO $pdo, array $ids, bool $active): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE distribution_distributors SET active = ? WHERE id IN ($in)");
    $stmt->execute(array_merge([$active ? 1 : 0], $ids));
    return $stmt->rowCount();
}

/**
 * What a distributor holds of one item (or of the whole product when $whole):
 * handed to them, reported sold, still unsold.
 *
 * @return array{allocated:int, sold:int, unsold:int}
 */
function distribution_distributor_stock(PDO $pdo, int $distributor_id, int $product_id, ?int $variant_id, bool $whole = false): array
{
    [$where, $params] = _distribution_scope($product_id, $variant_id, $whole);

    $a = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM distribution_allocations WHERE distributor_id = ? AND destination = 'distributor' AND $where");
    $a->execute(array_merge([$distributor_id], $params));
    $allocated = (int) $a->fetchColumn();

    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM distribution_sales WHERE distributor_id = ? AND $where");
    $s->execute(array_merge([$distributor_id], $params));
    $sold = (int) $s->fetchColumn();

    return ['allocated' => $allocated, 'sold' => $sold, 'unsold' => $allocated - $sold];
}

/**
 * Sold quantity of a product split by whether the distributor has paid.
 *
 * @return array{paid_qty:int, unpaid_qty:int}
 */
function distribution_distributor_payment_summary(PDO $pdo, int $distributor_id, int $product_id): array
{
    $stmt = $pdo->prepare(
        'SELECT payment_received, COALESCE(SUM(quantity), 0) FROM distribution_sales
          WHERE distributor_id = ? AND product_id = ? GROUP BY payment_received'
    );
    $stmt->execute([$distributor_id, $product_id]);
    $out = ['paid_qty' => 0, 'unpaid_qty' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $paid => $qty) {
        $out[(int) $paid ? 'paid_qty' : 'unpaid_qty'] = (int) $qty;
    }
    return $out;
}

// ── Batches ────────────────────────────────────────────────────────────────────

/** Serialises stock changes of one product inside the caller's transaction. */
function _distribution_lock_product(PDO $pdo, int $product_id): void
{
    $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$product_id]);
}

/** Runs $fn in a transaction (or the caller's), returning its result. */
function _distribution_tx(PDO $pdo, callable $fn): array
{
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        if (!empty($result['ok'])) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('distribution: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Записът не можа да бъде запазен. Моля опитайте отново.'];
    }
}

/** A date the admin typed, as Y-m-d, or null when it isn't a real date. */
function distribution_date(?string $value): ?string
{
    $value = trim((string) $value);
    $d     = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** @return array{ok: bool, id?: int, error?: string} */
function distribution_create_batch(PDO $pdo, int $product_id, ?int $variant_id, int $quantity, string $produced_on, ?string $notes = null): array
{
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($produced_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата.'];
    if ($err = distribution_item_error($pdo, $product_id, $variant_id)) return ['ok' => false, 'error' => $err];

    $pdo->prepare('INSERT INTO distribution_batches (product_id, variant_id, quantity, produced_on, notes) VALUES (?, ?, ?, ?, ?)')
        ->execute([$product_id, $variant_id, $quantity, $produced_on, $notes]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * Correct a batch. A smaller quantity is refused when it would leave less
 * produced than is already handed out.
 *
 * @return array{ok: bool, error?: string}
 */
function distribution_update_batch(PDO $pdo, int $id, int $quantity, string $produced_on, ?string $notes = null): array
{
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($produced_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата.'];

    return _distribution_tx($pdo, function () use ($pdo, $id, $quantity, $produced_on, $notes): array {
        $stmt = $pdo->prepare('SELECT product_id, variant_id, quantity FROM distribution_batches WHERE id = ?');
        $stmt->execute([$id]);
        $batch = $stmt->fetch();
        if (!$batch) return ['ok' => false, 'error' => 'Партидата не е намерена.'];

        $pid = (int) $batch['product_id'];
        $vid = $batch['variant_id'] !== null ? (int) $batch['variant_id'] : null;
        _distribution_lock_product($pdo, $pid);

        $produced_others = distribution_total_produced($pdo, $pid, $vid) - (int) $batch['quantity'];
        $allocated       = distribution_total_allocated($pdo, $pid, $vid);
        if ($produced_others + $quantity < $allocated) {
            $min = $allocated - $produced_others;
            return ['ok' => false, 'error' => "Не може да намалите партидата под вече разпределеното количество. Най-малко: {$min} бр."];
        }

        $pdo->prepare('UPDATE distribution_batches SET quantity = ?, produced_on = ?, notes = ? WHERE id = ?')
            ->execute([$quantity, $produced_on, $notes, $id]);
        return ['ok' => true];
    });
}

// ── Allocations ────────────────────────────────────────────────────────────────

/**
 * Items in the 'online' records that can be taken back out: the lower of the
 * records and live shop stock (the two are kept separately and can differ),
 * never below 0.
 */
function distribution_online_reclaimable(PDO $pdo, int $product_id, ?int $variant_id): int
{
    return max(0, min(
        distribution_total_allocated($pdo, $product_id, $variant_id, 'online'),
        distribution_online_stock($pdo, $product_id, $variant_id)
    ));
}

/**
 * Take $quantity back out of the online shop: shrink the newest 'online'
 * records first (removing any that reach 0) and lower live shop stock by the
 * same amount. The caller checks availability and holds the transaction.
 */
function _distribution_drain_online(PDO $pdo, int $product_id, ?int $variant_id, int $quantity): void
{
    if ($quantity <= 0) return;
    [$where, $params] = _distribution_scope($product_id, $variant_id);
    $stmt = $pdo->prepare("SELECT id, quantity FROM distribution_allocations WHERE destination = 'online' AND $where ORDER BY id DESC");
    $stmt->execute($params);

    $remaining = $quantity;
    foreach ($stmt->fetchAll() as $row) {
        if ($remaining <= 0) break;
        $take = min($remaining, (int) $row['quantity']);
        $left = (int) $row['quantity'] - $take;
        if ($left > 0) {
            $pdo->prepare('UPDATE distribution_allocations SET quantity = ? WHERE id = ?')->execute([$left, $row['id']]);
        } else {
            $pdo->prepare('DELETE FROM distribution_allocations WHERE id = ?')->execute([$row['id']]);
        }
        $remaining -= $take;
    }
    distribution_adjust_online_stock($pdo, $product_id, $variant_id, -$quantity);
}

/**
 * Hand out stock of an item.
 *
 * $use_shop_stock is the per-entry checkbox on the form and means:
 *   - 'online':   also add the items to live shop stock (they are now for sale);
 *   - 'personal': the items sold in person came off the shop shelf — move them
 *                 out of the 'online' records and live stock instead of taking
 *                 them from the unallocated pool;
 *   - ignored for 'distributor' and 'sample'.
 * A 'sample' takes what it can from the unallocated pool and the rest, without
 * asking, from the shop (a given-away item has physically left either way).
 *
 * Does NOT issue the protocol for a distributor — see distribution_issue_protocol().
 *
 * @return array{ok: bool, id?: int, error?: string}
 */
function distribution_create_allocation(
    PDO $pdo,
    int $product_id,
    ?int $variant_id,
    string $destination,
    ?int $distributor_id,
    int $quantity,
    float $unit_price_eur,
    string $allocated_on,
    ?string $notes = null,
    bool $use_shop_stock = false,
    ?string $sample_recipient = null
): array {
    if (!in_array($destination, DISTRIBUTION_DESTINATIONS, true)) return ['ok' => false, 'error' => 'Моля изберете на кого се дават.'];
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($allocated_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата.'];
    if ($err = distribution_item_error($pdo, $product_id, $variant_id)) return ['ok' => false, 'error' => $err];

    if ($destination === 'distributor') {
        if (!$distributor_id) return ['ok' => false, 'error' => 'Моля изберете дистрибутор.'];
        $d = $pdo->prepare('SELECT active FROM distribution_distributors WHERE id = ?');
        $d->execute([$distributor_id]);
        $active = $d->fetchColumn();
        if ($active === false) return ['ok' => false, 'error' => 'Дистрибуторът не е намерен.'];
        if (!(int) $active)    return ['ok' => false, 'error' => 'Този дистрибутор е архивиран. Върнете го от раздел „Дистрибутори“, за да му дадете стока.'];
    } else {
        $distributor_id = null;
    }

    $sample_recipient = $destination === 'sample' ? mb_substr(trim((string) $sample_recipient), 0, 150) : null;
    if ($destination === 'sample' && $sample_recipient === '') return ['ok' => false, 'error' => 'Моля напишете на кого са дадени безплатните бройки.'];
    if ($destination === 'sample') $unit_price_eur = 0.0;
    if ($destination !== 'sample' && $unit_price_eur <= 0) return ['ok' => false, 'error' => 'Цената за брой трябва да е по-голяма от 0.'];

    $use_shop_stock = $use_shop_stock && in_array($destination, ['online', 'personal'], true);

    return _distribution_tx($pdo, function () use (
        $pdo, $product_id, $variant_id, $destination, $distributor_id, $quantity,
        $unit_price_eur, $allocated_on, $notes, $use_shop_stock, $sample_recipient
    ): array {
        _distribution_lock_product($pdo, $product_id);
        $available = distribution_available($pdo, $product_id, $variant_id);

        $from_shop = 0;
        if ($destination === 'personal' && $use_shop_stock) {
            // Reclassifying shop stock as sold in person — the pool is untouched.
            $in_records = distribution_total_allocated($pdo, $product_id, $variant_id, 'online');
            if ($quantity > $in_records) {
                return ['ok' => false, 'error' => "Към онлайн магазина са записани само {$in_records} бр. — не може да прехвърлите {$quantity} бр. от там. Махнете отметката, ако бройките не са от магазина."];
            }
            $live = distribution_online_stock($pdo, $product_id, $variant_id);
            if ($quantity > $live) {
                return ['ok' => false, 'error' => "В магазина в момента има само {$live} бр. — не може да извадите {$quantity} бр. Първо поправете наличността в магазина (горе, „Наличност в магазина сега“)."];
            }
            $from_shop = $quantity;
        } elseif ($destination === 'sample') {
            $from_shop = max(0, $quantity - max(0, $available));
            if ($from_shop > 0) {
                $reclaimable = distribution_online_reclaimable($pdo, $product_id, $variant_id);
                if ($from_shop > $reclaimable) {
                    $free = max(0, $available);
                    return ['ok' => false, 'error' => "Няма достатъчно бройки за {$quantity} бр. мостри. Неразпределени: {$free} бр., в магазина: {$reclaimable} бр."];
                }
            }
        } elseif ($quantity > $available) {
            return ['ok' => false, 'error' => 'Няма толкова неразпределени бройки. Налични: ' . max(0, $available) . ' бр. Добавете партида, ако има нова стока.'];
        }

        _distribution_drain_online($pdo, $product_id, $variant_id, $from_shop);

        $pdo->prepare(
            'INSERT INTO distribution_allocations
                (product_id, variant_id, distributor_id, destination, sample_recipient, quantity, unit_price_eur, allocated_on, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$product_id, $variant_id, $distributor_id, $destination, $sample_recipient, $quantity, $unit_price_eur, $allocated_on, $notes]);
        $id = (int) $pdo->lastInsertId();

        if ($destination === 'online' && $use_shop_stock) {
            distribution_adjust_online_stock($pdo, $product_id, $variant_id, $quantity);
        }
        return ['ok' => true, 'id' => $id];
    });
}

/**
 * Correct an allocation's numbers. Who it went to doesn't change. Refused when
 * it would hand out more than was produced, or leave a distributor with less
 * than they already reported sold. Never touches live shop stock.
 *
 * @return array{ok: bool, error?: string}
 */
function distribution_update_allocation(PDO $pdo, int $id, int $quantity, float $unit_price_eur, string $allocated_on, ?string $notes = null): array
{
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($allocated_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата.'];

    return _distribution_tx($pdo, function () use ($pdo, $id, $quantity, $unit_price_eur, $allocated_on, $notes): array {
        $stmt = $pdo->prepare('SELECT * FROM distribution_allocations WHERE id = ?');
        $stmt->execute([$id]);
        $a = $stmt->fetch();
        if (!$a) return ['ok' => false, 'error' => 'Записът не е намерен.'];

        $price = $a['destination'] === 'sample' ? 0.0 : $unit_price_eur;
        if ($a['destination'] !== 'sample' && $price <= 0) return ['ok' => false, 'error' => 'Цената за брой трябва да е по-голяма от 0.'];

        $pid = (int) $a['product_id'];
        $vid = $a['variant_id'] !== null ? (int) $a['variant_id'] : null;
        _distribution_lock_product($pdo, $pid);

        $max = distribution_available($pdo, $pid, $vid) + (int) $a['quantity'];
        if ($quantity > $max) {
            return ['ok' => false, 'error' => "Няма толкова неразпределени бройки. Най-много: {$max} бр."];
        }
        if ($a['destination'] === 'distributor') {
            $held = distribution_distributor_stock($pdo, (int) $a['distributor_id'], $pid, $vid);
            if ($quantity < $held['sold']) {
                return ['ok' => false, 'error' => "Дистрибуторът вече е продал {$held['sold']} бр. — не може да намалите под това количество."];
            }
        }

        $pdo->prepare('UPDATE distribution_allocations SET quantity = ?, unit_price_eur = ?, allocated_on = ?, notes = ? WHERE id = ?')
            ->execute([$quantity, $price, $allocated_on, $notes, $id]);
        return ['ok' => true];
    });
}

// ── Hand-over protocol ─────────────────────────────────────────────────────────

/**
 * Next protocol number, taken atomically from document_sequences. Creates the
 * sequence row if a site somehow lacks it.
 */
function distribution_next_protocol_number(PDO $pdo): int
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT IGNORE INTO document_sequences (type, last_number) VALUES ('distribution_protocol', 0)");
        $lock = $pdo->prepare("SELECT last_number FROM document_sequences WHERE type = 'distribution_protocol' FOR UPDATE");
        $lock->execute();
        $next = (int) $lock->fetchColumn() + 1;
        $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'distribution_protocol'")->execute([$next]);
        if ($own) $pdo->commit();
        return $next;
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

/** The fields DistributionProtocolGenerator prints, for one allocation row. */
function distribution_protocol_fields(PDO $pdo, array $allocation): array
{
    $d = $pdo->prepare('SELECT * FROM distribution_distributors WHERE id = ?');
    $d->execute([(int) $allocation['distributor_id']]);
    $distributor = $d->fetch() ?: [];

    return [
        'distributor_name'    => (string) ($distributor['name'] ?? ''),
        'distributor_eik'     => (string) ($distributor['company_number'] ?? ''),
        'distributor_contact' => (string) ($distributor['contact_name'] ?? ''),
        'distributor_address' => (string) ($distributor['address'] ?? ''),
        'item_name'           => distribution_item_label(
            $pdo, (int) $allocation['product_id'],
            $allocation['variant_id'] !== null ? (int) $allocation['variant_id'] : null
        ),
        'allocated_on'        => (string) $allocation['allocated_on'],
        'quantity'            => (int) $allocation['quantity'],
        'unit_price_eur'      => (float) $allocation['unit_price_eur'],
    ];
}

/**
 * Create — or, after an edit, re-create under the same number — the hand-over
 * protocol PDF of a distributor allocation, and record it on the row.
 *
 * $root is the site root (the documents/ folder lives under it).
 *
 * @return array{ok: bool, number?: string, error?: string}
 */
function distribution_issue_protocol(PDO $pdo, int $allocation_id, string $root): array
{
    require_once __DIR__ . '/documents/DistributionProtocolGenerator.php';

    $stmt = $pdo->prepare('SELECT * FROM distribution_allocations WHERE id = ?');
    $stmt->execute([$allocation_id]);
    $a = $stmt->fetch();
    if (!$a || $a['destination'] !== 'distributor') {
        return ['ok' => false, 'error' => 'Протокол се издава само при даване на дистрибутор.'];
    }

    try {
        if ($a['protocol_file_path']) {
            $number    = (int) $a['protocol_number'];
            $formatted = (string) $a['protocol_formatted_number'];
            $rel_path  = (string) $a['protocol_file_path'];
        } else {
            $number    = distribution_next_protocol_number($pdo);
            $formatted = str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            $year      = substr((string) $a['allocated_on'], 0, 4);
            $rel_path  = 'documents/distribution_protocols/' . $year . '/' . $formatted . '.pdf';
        }

        $abs = rtrim($root, '/') . '/' . $rel_path;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0755, true)) {
            throw new RuntimeException('cannot create ' . dirname($abs));
        }
        $pdf = (new DistributionProtocolGenerator())->generate(
            distribution_protocol_fields($pdo, $a), [], ['formatted_number' => $formatted]
        );
        if (file_put_contents($abs, $pdf) === false) {
            throw new RuntimeException('cannot write ' . $abs);
        }

        $pdo->prepare(
            'UPDATE distribution_allocations
                SET protocol_number = ?, protocol_formatted_number = ?, protocol_file_path = ?, protocol_generated_at = NOW()
              WHERE id = ?'
        )->execute([$number, $formatted, $rel_path, $allocation_id]);
    } catch (Throwable $e) {
        error_log('distribution: protocol for allocation ' . $allocation_id . ' failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Протоколът не можа да бъде създаден. Опитайте пак с бутона „Създай протокола отново“.'];
    }
    return ['ok' => true, 'number' => $formatted];
}

// ── Distributor sales ──────────────────────────────────────────────────────────

/**
 * Record items a distributor reports as sold. Refused when it is more than they
 * still hold of that item.
 *
 * @return array{ok: bool, id?: int, error?: string}
 */
function distribution_record_sale(
    PDO $pdo,
    int $distributor_id,
    int $product_id,
    ?int $variant_id,
    int $quantity,
    string $sale_date,
    bool $payment_received,
    ?string $payment_received_on = null,
    ?string $notes = null
): array {
    if (!$distributor_id) return ['ok' => false, 'error' => 'Моля изберете дистрибутор.'];
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($sale_date)) return ['ok' => false, 'error' => 'Моля въведете валидна дата на продажба.'];
    if ($payment_received && !distribution_date((string) $payment_received_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата на плащане.'];
    if ($err = distribution_item_error($pdo, $product_id, $variant_id)) return ['ok' => false, 'error' => $err];

    $held = distribution_distributor_stock($pdo, $distributor_id, $product_id, $variant_id);
    if ($quantity > $held['unsold']) {
        return ['ok' => false, 'error' => "Дистрибуторът има само {$held['unsold']} бр. непродадени от този продукт."];
    }

    $pdo->prepare(
        'INSERT INTO distribution_sales (product_id, variant_id, distributor_id, quantity, sale_date, payment_received, payment_received_on, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $product_id, $variant_id, $distributor_id, $quantity, $sale_date,
        $payment_received ? 1 : 0, $payment_received ? $payment_received_on : null, $notes,
    ]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/** @return array{ok: bool, error?: string} */
function distribution_update_sale(PDO $pdo, int $id, int $quantity, string $sale_date, bool $payment_received, ?string $payment_received_on = null, ?string $notes = null): array
{
    if ($quantity <= 0) return ['ok' => false, 'error' => 'Количеството трябва да е поне 1.'];
    if (!distribution_date($sale_date)) return ['ok' => false, 'error' => 'Моля въведете валидна дата на продажба.'];
    if ($payment_received && !distribution_date((string) $payment_received_on)) return ['ok' => false, 'error' => 'Моля въведете валидна дата на плащане.'];

    $stmt = $pdo->prepare('SELECT * FROM distribution_sales WHERE id = ?');
    $stmt->execute([$id]);
    $sale = $stmt->fetch();
    if (!$sale) return ['ok' => false, 'error' => 'Продажбата не е намерена.'];

    $held = distribution_distributor_stock(
        $pdo, (int) $sale['distributor_id'], (int) $sale['product_id'],
        $sale['variant_id'] !== null ? (int) $sale['variant_id'] : null
    );
    $max = $held['unsold'] + (int) $sale['quantity'];
    if ($quantity > $max) return ['ok' => false, 'error' => "Дистрибуторът има само {$max} бр. непродадени от този продукт."];

    $pdo->prepare(
        'UPDATE distribution_sales SET quantity = ?, sale_date = ?, payment_received = ?, payment_received_on = ?, notes = ? WHERE id = ?'
    )->execute([$quantity, $sale_date, $payment_received ? 1 : 0, $payment_received ? $payment_received_on : null, $notes, $id]);
    return ['ok' => true];
}

/**
 * Mark several sales paid (on $paid_on) or unpaid at once. Sales already in
 * that state keep their own payment date.
 *
 * @param int[] $ids
 * @return int rows changed
 */
function distribution_set_sales_paid(PDO $pdo, array $ids, bool $paid, string $paid_on): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($paid) {
        $stmt = $pdo->prepare("UPDATE distribution_sales SET payment_received = 1, payment_received_on = ? WHERE payment_received = 0 AND id IN ($in)");
        $stmt->execute(array_merge([$paid_on], $ids));
    } else {
        $stmt = $pdo->prepare("UPDATE distribution_sales SET payment_received = 0, payment_received_on = NULL WHERE payment_received = 1 AND id IN ($in)");
        $stmt->execute($ids);
    }
    return $stmt->rowCount();
}

/**
 * Whether any distribution record points at these products — such products
 * must not be deleted for good (their history would go with them).
 *
 * @param int[] $product_ids
 * @return int[] the ids that have records
 */
function distribution_products_with_records(PDO $pdo, array $product_ids): array
{
    $ids = array_values(array_filter(array_map('intval', $product_ids), fn($v) => $v > 0));
    if (!$ids) return [];
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT product_id FROM distribution_batches WHERE product_id IN ($in)
             UNION SELECT product_id FROM distribution_allocations WHERE product_id IN ($in)
             UNION SELECT product_id FROM distribution_sales WHERE product_id IN ($in)"
        );
        $stmt->execute(array_merge($ids, $ids, $ids));
    } catch (PDOException $e) {
        return []; // tables not created yet (migration 041 not run)
    }
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Whether any distribution record points at this variant. */
function distribution_variant_has_records(PDO $pdo, int $variant_id): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM distribution_batches WHERE variant_id = ?
             UNION SELECT 1 FROM distribution_allocations WHERE variant_id = ?
             UNION SELECT 1 FROM distribution_sales WHERE variant_id = ? LIMIT 1'
        );
        $stmt->execute([$variant_id, $variant_id, $variant_id]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}
