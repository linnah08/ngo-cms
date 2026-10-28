<?php
/**
 * SumUp: in-person card sales take their products out of the shop's stock.
 *
 * The admin pastes a SumUp API key (Админ → SumUp). Every night
 * cron/sumup-stock-cron.php asks SumUp for the card-reader sales since the last
 * check, works out which product (and variant) each line of the sale was, and
 * lowers its stock. A refund in SumUp puts the items back.
 *
 * SumUp names the products in a sale but gives no product code, so a line is
 * matched by name: the shop's own product name, plus the variant's label for a
 * product with variants ("Тениска - M", "Тениска (M)" and "тениска m" are all
 * the same). A variant is also known by its own label alone ("Ледоразбивачи"
 * for "Лафетки — Ледоразбивачи"), as long as no other product has that name.
 * A line that doesn't match — or a sale typed in as an amount, which
 * has no products at all — waits on the admin page until the admin picks the
 * product; the choice is remembered for that name (sumup_name_map).
 *
 * Every sale in the SumUp account counts, also those from before the key was
 * connected: the first check goes through the whole history.
 */

require_once __DIR__ . '/settings.php';

const SUMUP_API_BASE = 'https://api.sumup.com';
/** The nightly check looks back this much before the last check, in case SumUp records a sale late. */
const SUMUP_OVERLAP_SECONDS = 3600;
const SUMUP_MAX_PAGES = 50;
/** Where the first check starts: before any SumUp sale (SumUp opened in 2012). */
const SUMUP_HISTORY_START = 1325376000; // 2012-01-01 UTC
/** What SumUp calls the line of a sale typed in as an amount: not a product, so never matched or remembered. */
const SUMUP_NO_PRODUCT_NAMES = ['custom amount'];

/** A problem talking to SumUp, worded for the admin. */
final class SumUpError extends RuntimeException {}

// ── Talking to SumUp ──────────────────────────────────────────────────────────

/**
 * A GET request to SumUp's API with the given key.
 * @return array{status:int, json:?array, error:string}
 */
function sumup_http_get(string $key, string $path): array
{
    $ch = curl_init(SUMUP_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = $body === false ? curl_error($ch) : '';
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'error' => $error];
}

/**
 * fn(string $path): array — GETs from SumUp and returns the decoded JSON, or
 * throws SumUpError in plain Bulgarian. Tests pass their own instead.
 */
function sumup_getter(string $key): callable
{
    return static function (string $path) use ($key): array {
        $r = sumup_http_get($key, $path);
        if ($r['status'] === 0) {
            throw new SumUpError('Не успяхме да се свържем със SumUp. Проверете интернет връзката на сървъра и опитайте отново по-късно.');
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new SumUpError('SumUp не прие ключа. Проверете дали е копиран целият ключ (започва със „sup_sk_“ или „sk_“) и дали не е изтрит в SumUp.');
        }
        if ($r['status'] >= 400 || $r['json'] === null) {
            error_log('sumup: GET ' . strtok($path, '?') . ' → HTTP ' . $r['status']);
            throw new SumUpError('SumUp върна грешка (' . $r['status'] . '). Опитайте отново по-късно.');
        }
        return $r['json'];
    };
}

/**
 * The SumUp account the key belongs to.
 * @return array{code:string, name:string}
 */
function sumup_fetch_merchant(callable $get): array
{
    $data = $get('/v0.1/memberships?kind=merchant&status=accepted&limit=25');
    foreach ((array) ($data['items'] ?? []) as $m) {
        $code = (string) ($m['resource_id'] ?? $m['resource']['id'] ?? '');
        if ($code !== '') return ['code' => $code, 'name' => (string) ($m['resource']['name'] ?? $code)];
    }
    throw new SumUpError('Ключът работи, но към него няма търговски профил в SumUp. Създайте ключа, докато сте влезли с профила, с който продавате.');
}

function sumup_is_connected(): bool
{
    return setting_get('sumup_api_key') !== '' && setting_get('sumup_merchant_code') !== '';
}

/** Checks the key with SumUp and saves it. */
function sumup_connect(string $key, ?callable $get = null): array
{
    $key = trim($key);
    if ($key === '') throw new SumUpError('Поставете ключа от SumUp в полето.');
    $merchant = sumup_fetch_merchant($get ?? sumup_getter($key));
    setting_set('sumup_api_key', $key);
    setting_set('sumup_merchant_code', $merchant['code']);
    setting_set('sumup_merchant_name', $merchant['name']);
    return $merchant;
}

function sumup_disconnect(): void
{
    foreach (['sumup_api_key', 'sumup_merchant_code', 'sumup_merchant_name'] as $k) setting_delete($k);
}

// ── Matching a SumUp product name to a shop product ───────────────────────────

/** "Тениска - M", "Тениска (M)" and "  тениска  m " all become "тениска m". */
function sumup_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[\s\-–—_(),\/:|.+]+/u', ' ', $s) ?? $s;
    return mb_substr(trim($s), 0, 191, 'UTF-8');
}

/**
 * Everything a SumUp sale can take stock from: standard products, and each
 * active variant of a product with variants. Made-to-order prints hold no stock.
 *
 * @return list<array{product_id:int, variant_id:?int, label:string, names:list<string>, stock:int}>
 */
function sumup_catalog(PDO $pdo): array
{
    $products = $pdo->query("SELECT id, name_bg, name_en, type, stock, active FROM products
                              WHERE type IN ('standard','variant') ORDER BY active DESC, name_bg")->fetchAll(PDO::FETCH_ASSOC);
    $variants = [];
    foreach ($pdo->query('SELECT id, product_id, label_bg, label_en, stock FROM product_variants
                           WHERE active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $variants[(int) $v['product_id']][] = $v;
    }

    $out = [];
    foreach ($products as $p) {
        $pid   = (int) $p['id'];
        $names = array_values(array_unique(array_filter([trim((string) $p['name_bg']), trim((string) $p['name_en'])])));
        if ($p['type'] !== 'variant') {
            $out[] = ['product_id' => $pid, 'variant_id' => null, 'label' => (string) $p['name_bg'], 'names' => $names, 'stock' => (int) $p['stock']];
            continue;
        }
        $vs = $variants[$pid] ?? [];
        foreach ($vs as $v) {
            $labels = array_values(array_unique(array_filter([trim((string) $v['label_bg']), trim((string) ($v['label_en'] ?? ''))])));
            $vnames = [];
            foreach ($names as $n) foreach ($labels as $l) $vnames[] = $n . ' ' . $l;
            // A product with a single variant is also known by its name alone.
            if (count($vs) === 1) $vnames = array_merge($vnames, $names);
            // A SumUp item is often named after the variant only ("Ледоразбивачи").
            $vnames = array_merge($vnames, $labels);
            $out[] = ['product_id' => $pid, 'variant_id' => (int) $v['id'], 'label' => $p['name_bg'] . ' — ' . $v['label_bg'],
                      'names' => $vnames, 'stock' => (int) $v['stock']];
        }
    }
    return $out;
}

/** Normalised name => list of "pid:vid" targets with that name. */
function sumup_catalog_index(array $catalog): array
{
    $index = [];
    foreach ($catalog as $e) {
        $target = sumup_target_key($e['product_id'], $e['variant_id']);
        foreach ($e['names'] as $n) {
            $k = sumup_norm($n);
            if ($k !== '' && !in_array($target, $index[$k] ?? [], true)) $index[$k][] = $target;
        }
    }
    return $index;
}

function sumup_target_key(int $product_id, ?int $variant_id): string
{
    return $product_id . ':' . ($variant_id ?? 0);
}

/** "12:0" / "12:5" → [12, null] / [12, 5]; null when it isn't one. */
function sumup_parse_target(string $s): ?array
{
    if (!preg_match('/^(\d+):(\d+)$/', $s, $m) || (int) $m[1] < 1) return null;
    return [(int) $m[1], (int) $m[2] ?: null];
}

/**
 * The product a SumUp line is for: the admin's remembered choice for that name
 * first, else the one catalogue entry with that name. Null when there is none,
 * or more than one (two products with the same name — ask rather than guess).
 *
 * @param array $map normalised name => "pid:vid" (sumup_name_map)
 * @return array{0:int, 1:?int}|null
 */
function sumup_match(string $name, array $index, array $map): ?array
{
    $k = sumup_norm($name);
    if ($k === '') return null;
    $valid = array_merge(...array_values($index ?: [[]]));
    if (isset($map[$k]) && in_array($map[$k], $valid, true)) return sumup_parse_target($map[$k]);
    $hits = $index[$k] ?? [];
    return count($hits) === 1 ? sumup_parse_target($hits[0]) : null;
}

/** @return array<string,string> normalised name => "pid:vid" */
function sumup_name_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT name_key, product_id, variant_id FROM sumup_name_map')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[$r['name_key']] = sumup_target_key((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null);
    }
    return $map;
}

// ── Stock ─────────────────────────────────────────────────────────────────────

/**
 * Move stock by $delta. A sale at the stall really happened, so the shop's
 * count never goes below zero for it (if it would, the count was already off).
 */
function sumup_stock_move(PDO $pdo, int $product_id, ?int $variant_id, int $delta): void
{
    if ($variant_id) {
        $pdo->prepare('UPDATE product_variants SET stock = GREATEST(stock + ?, 0) WHERE id = ? AND product_id = ?')
            ->execute([$delta, $variant_id, $product_id]);
    } else {
        $pdo->prepare('UPDATE products SET stock = GREATEST(stock + ?, 0) WHERE id = ?')
            ->execute([$delta, $product_id]);
    }
}

/** Does any line still wait for the admin? A sale with no lines at all does. */
function sumup_lines_need_attention(array $lines): bool
{
    if (!$lines) return true;
    foreach ($lines as $l) {
        if (empty($l['done']) && empty($l['skipped'])) return true;
    }
    return false;
}

// ── The nightly check ─────────────────────────────────────────────────────────

/** SumUp's ISO time → the site's local 'Y-m-d H:i:s' (and the Unix time). */
function sumup_parse_time(string $iso): ?array
{
    try {
        $d = new DateTimeImmutable($iso);
    } catch (Throwable) {
        return null;
    }
    $local = $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return ['ts' => $d->getTimestamp(), 'local' => $local->format('Y-m-d H:i:s')];
}

/**
 * Asks SumUp for the card-reader sales changed since the last check and brings
 * the shop's stock in line. Safe to run any number of times — and at the same
 * time as another run: each sale is claimed by its unique row first.
 *
 * @return array{new_sales:int, items_out:int, refunds:int, items_back:int, attention:int}
 */
function sumup_sync(PDO $pdo, ?callable $get = null, ?int $now = null): array
{
    $now      = $now ?? time();
    $merchant = setting_get('sumup_merchant_code');
    // Not sumup_synced_until: sites that ran the version which skipped older
    // sales go through the whole history once (sales already seen stay as they are).
    $since    = setting_get('sumup_history_until');
    if ($merchant === '') {
        throw new SumUpError('SumUp още не е свързан. Поставете ключа в Админ → SumUp.');
    }
    $get   = $get ?? sumup_getter(setting_get('sumup_api_key'));
    $from  = ctype_digit($since) ? max(SUMUP_HISTORY_START, (int) $since - SUMUP_OVERLAP_SECONDS) : SUMUP_HISTORY_START;
    $until = $now;

    $base  = '/v2.1/merchants/' . rawurlencode($merchant) . '/transactions';
    $query = http_build_query(['changes_since' => gmdate('Y-m-d\TH:i:s\Z', $from), 'order' => 'ascending', 'limit' => 100])
           . '&payment_types[]=POS&types[]=PAYMENT&statuses[]=SUCCESSFUL&statuses[]=REFUNDED';

    $catalog = sumup_catalog($pdo);
    $index   = sumup_catalog_index($catalog);
    $map     = sumup_name_map($pdo);
    $sum     = ['new_sales' => 0, 'items_out' => 0, 'refunds' => 0, 'items_back' => 0, 'attention' => 0];

    $last = null;
    for ($page = 0; $page < SUMUP_MAX_PAGES && $query !== null; $page++) {
        $data  = $get($base . '/history?' . $query);
        $query = null;
        foreach ((array) ($data['items'] ?? []) as $tx) {
            sumup_sync_one($pdo, $get, $base, (array) $tx, $index, $map, $sum);
            $last = sumup_parse_time((string) ($tx['timestamp'] ?? ''))['ts'] ?? $last;
        }
        foreach ((array) ($data['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'next' && !empty($link['href']) && !empty($data['items'])) {
                $query = ltrim((string) $link['href'], '?');
            }
        }
    }

    // More pages than one check reads (a long history): the next check carries on from here.
    if ($query !== null && $last !== null) $until = $last;
    setting_set('sumup_history_until', (string) $until);
    setting_set('sumup_last_sync', json_encode($sum + ['at' => $now]));
    return $sum;
}

/** One sale from SumUp's history: take its items out of stock, or put them back if it was refunded. */
function sumup_sync_one(PDO $pdo, callable $get, string $base, array $tx, array $index, array $map, array &$sum): void
{
    $id     = (string) ($tx['id'] ?? $tx['transaction_id'] ?? '');
    $status = (string) ($tx['status'] ?? '');
    $time   = sumup_parse_time((string) ($tx['timestamp'] ?? ''));
    if ($id === '' || $time === null || !in_array($status, ['SUCCESSFUL', 'REFUNDED'], true)) return;

    $row = $pdo->prepare('SELECT id, `lines`, refunded_at FROM sumup_sales WHERE transaction_id = ?');
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($status === 'REFUNDED' && $existing['refunded_at'] === null) {
            $sum['items_back'] += sumup_refund_sale($pdo, (int) $existing['id']);
            $sum['refunds']++;
        }
        return;
    }

    $detail = $get($base . '?id=' . rawurlencode($id));
    $lines  = [];
    foreach ((array) ($detail['products'] ?? []) as $p) {
        $name = trim((string) ($p['name'] ?? ''));
        // A typed-in amount: the admin says what was sold, as for a sale with no products.
        if (in_array(sumup_norm($name), SUMUP_NO_PRODUCT_NAMES, true)) continue;
        $qty  = max(1, (int) ($p['quantity'] ?? 1));
        $hit  = sumup_match($name, $index, $map);
        $lines[] = ['name' => $name, 'quantity' => $qty, 'product_id' => $hit[0] ?? null, 'variant_id' => $hit[1] ?? null,
                    'done' => false, 'skipped' => false];
    }
    $refunded = $status === 'REFUNDED';

    $pdo->beginTransaction();
    try {
        // Claim the sale first: a second run that gets here too inserts nothing and stops.
        $ins = $pdo->prepare('INSERT IGNORE INTO sumup_sales
            (transaction_id, transaction_code, sold_at, amount, currency, `lines`, needs_attention, refunded_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)');
        $ins->execute([$id, (string) ($tx['transaction_code'] ?? ''), $time['local'], (float) ($tx['amount'] ?? 0),
                       substr((string) ($tx['currency'] ?? 'EUR'), 0, 3), '[]', $refunded ? date('Y-m-d H:i:s') : null]);
        if ($ins->rowCount() === 0) { $pdo->rollBack(); return; }
        $sale_id = (int) $pdo->lastInsertId();

        // Refunded before we ever saw it: nothing left the shelf for good.
        if (!$refunded) {
            foreach ($lines as &$l) {
                if ($l['product_id'] === null) continue;
                sumup_stock_move($pdo, $l['product_id'], $l['variant_id'], -$l['quantity']);
                $l['done'] = true;
                $sum['items_out'] += $l['quantity'];
            }
            unset($l);
        }
        $attention = !$refunded && sumup_lines_need_attention($lines);
        $pdo->prepare('UPDATE sumup_sales SET `lines` = ?, needs_attention = ? WHERE id = ?')
            ->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), (int) $attention, $sale_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $sum['new_sales']++;
    if ($attention) $sum['attention']++;
}

/** A sale refunded in SumUp: put back what it took. Returns how many items went back. */
function sumup_refund_sale(PDO $pdo, int $sale_id): int
{
    $back = 0;
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE sumup_sales SET refunded_at = NOW(), needs_attention = 0 WHERE id = ? AND refunded_at IS NULL');
        $claim->execute([$sale_id]);
        if ($claim->rowCount() === 0) { $pdo->rollBack(); return 0; }
        $stmt = $pdo->prepare('SELECT `lines` FROM sumup_sales WHERE id = ?');
        $stmt->execute([$sale_id]);
        foreach ((array) json_decode((string) $stmt->fetchColumn(), true) as $l) {
            if (empty($l['done']) || empty($l['product_id'])) continue;
            sumup_stock_move($pdo, (int) $l['product_id'], $l['variant_id'] ? (int) $l['variant_id'] : null, (int) $l['quantity']);
            $back += (int) $l['quantity'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $back;
}

// ── The admin's answers ───────────────────────────────────────────────────────

/**
 * Is this just the name of a product with several variants ("Тениска" with no
 * size)? Then one sale's answer says nothing about the next, so it isn't remembered.
 */
function sumup_name_lacks_variant(PDO $pdo, string $key): bool
{
    $stmt = $pdo->query("SELECT p.name_bg, p.name_en FROM products p
                          WHERE p.type = 'variant'
                            AND (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) > 1");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (sumup_norm((string) $p['name_bg']) === $key || sumup_norm((string) $p['name_en']) === $key) return true;
    }
    return false;
}

/** Is "pid:vid" something a sale can take stock from right now? */
function sumup_target_exists(PDO $pdo, array $hit): bool
{
    $want = sumup_target_key($hit[0], $hit[1]);
    foreach (sumup_catalog($pdo) as $e) {
        if (sumup_target_key($e['product_id'], $e['variant_id']) === $want) return true;
    }
    return false;
}

/**
 * Runs $fn(array $lines): ?array on a sale that still waits, inside a
 * transaction, and saves the lines it returns. Returns '' or a message.
 */
function _sumup_edit_sale(PDO $pdo, int $sale_id, callable $fn): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT `lines`, needs_attention, refunded_at FROM sumup_sales WHERE id = ? FOR UPDATE');
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale || $sale['refunded_at'] !== null || !(int) $sale['needs_attention']) {
            $pdo->rollBack();
            return 'Тази продажба вече не чака избор. Презаредете страницата.';
        }
        $lines = $fn((array) json_decode((string) $sale['lines'], true));
        if (is_string($lines)) { $pdo->rollBack(); return $lines; }
        $pdo->prepare('UPDATE sumup_sales SET `lines` = ?, needs_attention = ? WHERE id = ?')
            ->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), (int) sumup_lines_need_attention($lines), $sale_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return '';
}

/**
 * The admin says which product a waiting line of a sale was ($target "pid:vid"),
 * or that it wasn't a shop product ($target 'skip'). The choice is remembered
 * for that name, so the next sale with it matches by itself.
 *
 * @return string '' when done, else a message for the admin
 */
function sumup_resolve_line(PDO $pdo, int $sale_id, int $line, string $target): string
{
    $skip = $target === 'skip';
    $hit  = $skip ? null : sumup_parse_target($target);
    if (!$skip && ($hit === null || !sumup_target_exists($pdo, $hit))) return 'Изберете продукт от списъка.';

    return _sumup_edit_sale($pdo, $sale_id, function (array $lines) use ($pdo, $line, $skip, $hit) {
        if (!isset($lines[$line]) || !empty($lines[$line]['done']) || !empty($lines[$line]['skipped'])) {
            return 'Този ред вече е отбелязан. Презаредете страницата.';
        }
        if ($skip) {
            $lines[$line]['skipped'] = true;
            return $lines;
        }
        sumup_stock_move($pdo, $hit[0], $hit[1], -(int) $lines[$line]['quantity']);
        $lines[$line] = array_merge($lines[$line], ['product_id' => $hit[0], 'variant_id' => $hit[1], 'done' => true]);
        $key = sumup_norm((string) $lines[$line]['name']);
        if ($key !== '' && !in_array($key, SUMUP_NO_PRODUCT_NAMES, true) && !sumup_name_lacks_variant($pdo, $key)) {
            $pdo->prepare('REPLACE INTO sumup_name_map (name_key, product_id, variant_id) VALUES (?, ?, ?)')
                ->execute([$key, $hit[0], $hit[1]]);
        }
        return $lines;
    });
}

/**
 * A sale typed into SumUp as an amount has no products: the admin says what was
 * sold — a list of [target "pid:vid", quantity] — and its stock is taken out.
 *
 * @return string '' when done, else a message for the admin
 */
function sumup_resolve_sale_products(PDO $pdo, int $sale_id, array $picks): string
{
    $clean = [];
    foreach ($picks as [$target, $qty]) {
        $hit = sumup_parse_target((string) $target);
        if ($hit === null) continue;
        if (!sumup_target_exists($pdo, $hit)) return 'Един от избраните продукти вече не съществува. Презаредете страницата.';
        $clean[] = [$hit, max(1, min(999, (int) $qty))];
    }
    if (!$clean) return 'Изберете поне един продукт или натиснете „Не е продукт от магазина“.';

    return _sumup_edit_sale($pdo, $sale_id, function (array $lines) use ($pdo, $clean) {
        if ($lines) return 'Тази продажба има продукти от SumUp — изберете ги ред по ред.';
        foreach ($clean as [$hit, $qty]) {
            sumup_stock_move($pdo, $hit[0], $hit[1], -$qty);
            $lines[] = ['name' => '', 'quantity' => $qty, 'product_id' => $hit[0], 'variant_id' => $hit[1], 'done' => true, 'skipped' => false];
        }
        return $lines;
    });
}

/** The admin closes a whole sale as "not a shop sale": nothing more is taken out of stock. */
function sumup_dismiss_sale(PDO $pdo, int $sale_id): void
{
    $pdo->prepare('UPDATE sumup_sales SET needs_attention = 0 WHERE id = ?')->execute([$sale_id]);
}

function sumup_attention_count(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM sumup_sales WHERE needs_attention = 1 AND refunded_at IS NULL')->fetchColumn();
    } catch (Throwable) {
        return 0; // table not migrated yet
    }
}
