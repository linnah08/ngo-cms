<?php
/**
 * Pure, testable read-side helpers for admin/order-view.php.
 *
 * order-view.php is mostly side-effect orchestration (refunds, courier labels,
 * emails) that is neither safe nor useful to pull into pure functions. What IS
 * pure is the presentation/derivation logic that was inlined into the view: the
 * print placement → cm measurement maths, ticket-path decoding and donation
 * detection. Those live here so they can be unit-tested; the page keeps the I/O.
 */

/**
 * Decode a pledge's stored ticket_path into a list of relative PDF paths.
 * The column holds either a JSON array of paths or a single bare path.
 */
function order_ticket_paths(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    return [$raw];
}

/** True if the order is a donation, or any line item is a donation. */
function order_has_donation(string $type, array $items): bool {
    if ($type === 'donation') return true;
    foreach ($items as $i) {
        if (($i['type'] ?? '') === 'donation') return true;
    }
    return false;
}

/**
 * Sum of donation line items within an order, falling back to the order total
 * for dedicated donation orders (which have no items marked type=donation).
 * Same rule DonationCertGenerator uses for the amount on the certificate.
 */
function order_donation_amount(array $items, float $order_total): float {
    $sum = 0.0;
    foreach ($items as $i) {
        if (($i['type'] ?? '') === 'donation') $sum += (float)($i['amount_eur'] ?? 0);
    }
    return $sum > 0 ? $sum : $order_total;
}

/**
 * Which "thank you" email template to use when (re)sending a signed donation
 * certificate. The campaign-confirmation template thanks the donor for supporting
 * a campaign, so it must only go to donors who actually pledged to one (i.e. have
 * a campaign_pledges row). Every other donation gets the general donation thanks.
 */
function donation_cert_email_key(bool $is_campaign_pledge): string {
    return $is_campaign_pledge ? 'campaign-confirmation' : 'donation-confirmation-customer';
}

/**
 * SQL condition: the order carries a donation — either a dedicated donation
 * order, or a product order with a donation add-on line (stored as
 * {"type":"donation"} inside the items JSON). The SQL twin of
 * order_has_donation(). Portable to MySQL and MariaDB.
 *
 * @param string $alias Table alias of `orders` in the query ('' for none).
 */
function order_has_donation_sql(string $alias = ''): string {
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias.');
    }
    $p = $alias === '' ? '' : $alias . '.';
    return "({$p}type = 'donation' OR JSON_SEARCH({$p}items, 'one', 'donation', NULL, '\$[*].type') IS NOT NULL)";
}

/**
 * Paid orders that carry a donation but have no donation certificate yet,
 * oldest first. Each row gets `donation_eur`: the donation portion only (for a
 * mixed order, not the whole order total) — the amount the certificate uses.
 */
function orders_paid_donations_without_cert(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT o.id, o.order_number, o.customer_name, o.total_eur, o.items
         FROM orders o
         LEFT JOIN documents d ON d.order_id = o.id AND d.type = 'donation_cert'
         WHERE " . order_has_donation_sql('o') . "
           AND o.payment_status = 'paid' AND d.id IS NULL
         ORDER BY o.created_at ASC, o.id ASC"
    );
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items = json_decode($row['items'] ?? '[]', true);
        $row['donation_eur'] = order_donation_amount(is_array($items) ? $items : [], (float)$row['total_eur']);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Human-readable placement spec for a printed design.
 *
 * Given the design placement ($pos: scale, x, y in 0..1 of the print area), the
 * design's aspect ratio ($ar = height/width) and the ordered size's real-world
 * dimensions ($sdims: w, h in cm), return the printed size and offsets in cm —
 * e.g. "18 × 24 cm · 6 cm от ляво · 9 cm от горе · Размер: L". When the size has
 * no stored dimensions, return the prompt to add them. Mirrors the calc that was
 * inlined in the item-row view.
 *
 * @param array      $pos          ['scale'=>float,'x'=>float,'y'=>float]
 * @param float      $ar           design aspect ratio (height / width)
 * @param array|null $sdims        ['w'=>float,'h'=>float] in cm, or null
 * @param string     $ordered_size e.g. 'L' (for the trailing label)
 */
function order_print_spec(array $pos, float $ar, ?array $sdims, string $ordered_size): string {
    $size_suffix = $ordered_size !== '' ? " · Размер: {$ordered_size}" : '';

    if ($sdims && ($sdims['w'] ?? 0) > 0) {
        $dw_cm   = round($pos['scale'] * $sdims['w'], 1);
        $dh_cm   = round($pos['scale'] * $ar * $sdims['h'], 1);
        $left_cm = round(max(0, $pos['x'] - $pos['scale'] / 2) * $sdims['w'], 1);
        $top_cm  = round(max(0, $pos['y'] - ($pos['scale'] * $ar) / 2) * $sdims['h'], 1);
        return "{$dw_cm} × {$dh_cm} cm · {$left_cm} cm от ляво · {$top_cm} cm от горе" . $size_suffix;
    }

    return 'Добавете размери на тениската в продукта за да изчислим cm' . $size_suffix;
}

/** Order list filters (admin/orders.php) — the values the type/status links offer. */
const ORDERS_LIST_TYPES    = ['all', 'physical', 'donation', 'ticket', 'pledge'];
const ORDERS_LIST_STATUSES = ['all', 'new', 'confirmed', 'shipped', 'delivered', 'cancelled', 'unpaid'];

/**
 * Read the order list's filters from the query string and build the WHERE for them.
 *
 *   type   — one of ORDERS_LIST_TYPES ('donation' also finds product orders with a donation line)
 *   status — one of ORDERS_LIST_STATUSES ('unpaid' = the "Неплатени" filter)
 *   q      — search text: part of the order number, name, email or phone. A phone
 *            matches however it is written ("0888 12 34 56", "+359888123456").
 *
 * Unknown type/status values fall back to 'all'. The search text only ever goes
 * into $params, never into the SQL itself.
 *
 * @param array $query $_GET (or $_POST with the same keys)
 * @return array{type:string,status:string,q:string,where:string,params:list<string>}
 */
function orders_list_filter(array $query): array {
    require_once __DIR__ . '/payment/unpaid_orders.php';

    $type   = is_string($query['type'] ?? null) && in_array($query['type'], ORDERS_LIST_TYPES, true) ? $query['type'] : 'all';
    $status = is_string($query['status'] ?? null) && in_array($query['status'], ORDERS_LIST_STATUSES, true) ? $query['status'] : 'all';
    $q      = is_string($query['q'] ?? null) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $query['q']) ?? ''), 0, 100) : '';

    $where  = ['1=1'];
    $params = [];
    if ($type === 'donation') {
        $where[] = order_has_donation_sql();
    } elseif ($type !== 'all') {
        $where[]  = 'type = ?';
        $params[] = $type;
    }
    if ($status === 'unpaid') {
        $where[] = unpaid_orders_sql_condition();
    } elseif ($status !== 'all') {
        $where[]  = 'status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        // '!' escapes LIKE's own wildcards, so "100%" or "a_b" are searched for literally.
        $like  = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $match = ["order_number LIKE ? ESCAPE '!'", "customer_name LIKE ? ESCAPE '!'", "customer_email LIKE ? ESCAPE '!'"];
        array_push($params, $like, $like, $like);

        // Phone: compare digits only, without the leading 0 / country code, so the
        // way it was typed at checkout and in the search box doesn't matter.
        if (preg_match('/^[\d\s+\-().\/]+$/', $q)) {
            $digits = ltrim(preg_replace('/\D/', '', $q) ?? '', '0');
            if (strlen($digits) >= 3) {
                $match[]  = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(customer_phone, ''),"
                          . " ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', ''), '/', '') LIKE ?";
                $params[] = '%' . $digits . '%';
            }
        }
        $where[] = '(' . implode(' OR ', $match) . ')';
    }

    return ['type' => $type, 'status' => $status, 'q' => $q, 'where' => implode(' AND ', $where), 'params' => $params];
}

/**
 * Query string for an order list link: the current filters with some changed
 * ($change), leaving out the defaults. E.g. orders_list_url($f, ['status' => 'new']).
 */
function orders_list_url(array $filter, array $change = []): string {
    $f  = array_merge(['type' => $filter['type'], 'status' => $filter['status'], 'q' => $filter['q']], $change);
    $qs = http_build_query(array_filter($f, fn($v) => $v !== '' && $v !== 'all'));
    return '/admin/orders.php' . ($qs !== '' ? '?' . $qs : '');
}
