<?php
declare(strict_types=1);

/**
 * Saved carts — remembers a browser's shop cart (and campaign pledges) in the
 * database so a forgotten order can be followed up. Both features are off
 * unless an admin switches them on (settings cart_reminder_email / _notice).
 *
 * One shop row per browser (pledge_id IS NULL), one row per pledge. `token` is
 * for links in emails; `browser_token` is the value of the om_cart cookie.
 */

if (!defined('SAVED_CART_REMIND_AFTER_HOURS'))   define('SAVED_CART_REMIND_AFTER_HOURS', 24);
if (!defined('SAVED_CART_REMIND_MAX_AGE_DAYS'))  define('SAVED_CART_REMIND_MAX_AGE_DAYS', 7);
if (!defined('SAVED_CART_KEEP_DAYS'))            define('SAVED_CART_KEEP_DAYS', 30);

function saved_cart_email_enabled(): bool  { return setting_get('cart_reminder_email', '0') === '1'; }
function saved_cart_notice_enabled(): bool { return setting_get('cart_reminder_notice', '0') === '1'; }
function saved_carts_enabled(): bool       { return saved_cart_email_enabled() || saved_cart_notice_enabled(); }

function saved_cart_token_valid(string $t): bool
{
    return (bool)preg_match('/^[0-9a-f]{64}$/', $t);
}

function saved_cart_set_cookie(string $token): void
{
    if (!headers_sent()) {
        setcookie('om_cart', $token, [
            'expires'  => time() + 30 * 86400,
            'path'     => '/',
            'secure'   => str_starts_with(SITE_URL, 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE['om_cart'] = $token;
}

function saved_cart_browser_token(bool $create): ?string
{
    $t = $_COOKIE['om_cart'] ?? '';
    if (is_string($t) && saved_cart_token_valid($t)) return $t;
    if (!$create) return null;
    $t = bin2hex(random_bytes(32));
    saved_cart_set_cookie($t);
    return $t;
}

function saved_cart_sync(PDO $pdo, array $items, string $lang): void
{
    if (!saved_carts_enabled()) return;
    if (!empty($_SESSION['checkout_data']['_pledge_number'])) return;
    $browser = saved_cart_browser_token(true);
    if ($browser === null) return;
    $lang = $lang === 'en' ? 'en' : 'bg';
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);

    $sel = $pdo->prepare('SELECT id FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL AND ordered_at IS NULL ORDER BY id DESC LIMIT 1');
    $sel->execute([$browser]);
    $id = $sel->fetchColumn();
    if ($id !== false) {
        $pdo->prepare('UPDATE saved_carts SET items = ?, lang = ?, updated_at = NOW(), dismissed_at = NULL WHERE id = ?')
            ->execute([$json, $lang, (int)$id]);
        return;
    }
    $pdo->prepare('INSERT INTO saved_carts (token, browser_token, items, lang, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
        ->execute([bin2hex(random_bytes(32)), $browser, $json, $lang]);
}

function saved_cart_find(PDO $pdo, string $token): ?array
{
    if (!saved_cart_token_valid($token)) return null;
    $st = $pdo->prepare('SELECT * FROM saved_carts WHERE token = ?');
    $st->execute([$token]);
    return saved_cart_decode($st->fetch(PDO::FETCH_ASSOC) ?: null);
}

function saved_cart_decode(?array $row): ?array
{
    if ($row === null) return null;
    $items = json_decode((string)$row['items'], true);
    $row['items'] = is_array($items) ? $items : [];
    return $row;
}

/** @return array{cart: ?array, pledge: ?array} */
function saved_cart_for_browser(PDO $pdo): array
{
    $out = ['cart' => null, 'pledge' => null];
    $browser = saved_cart_browser_token(false);
    if ($browser === null) return $out;

    $base = 'SELECT * FROM saved_carts WHERE browser_token = ? AND ordered_at IS NULL AND dismissed_at IS NULL'
          . ' AND updated_at >= DATE_SUB(NOW(), INTERVAL ' . (int)SAVED_CART_KEEP_DAYS . ' DAY)';

    $st = $pdo->prepare($base . ' AND pledge_id IS NULL ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$browser]);
    $cart = saved_cart_decode($st->fetch(PDO::FETCH_ASSOC) ?: null);
    if ($cart !== null && $cart['items'] !== []) $out['cart'] = $cart;

    $st = $pdo->prepare($base . ' AND pledge_id IS NOT NULL ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$browser]);
    $out['pledge'] = saved_cart_decode($st->fetch(PDO::FETCH_ASSOC) ?: null);
    return $out;
}

/** Stops a row driving the on-site notice (used once its email link has been followed). */
function saved_cart_dismiss_row(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE saved_carts SET dismissed_at = NOW() WHERE id = ?')->execute([$id]);
}

function saved_cart_mark_ordered(PDO $pdo, ?int $pledge_id = null): void
{
    if ($pledge_id !== null) {
        $pdo->prepare('UPDATE saved_carts SET ordered_at = NOW() WHERE pledge_id = ?')->execute([$pledge_id]);
        return;
    }
    $browser = saved_cart_browser_token(false);
    if ($browser === null) return;
    $pdo->prepare('UPDATE saved_carts SET ordered_at = NOW() WHERE browser_token = ? AND pledge_id IS NULL AND ordered_at IS NULL')
        ->execute([$browser]);
}

// ── Restoring ────────────────────────────────────────────────────────────────

/**
 * Checks a saved cart's lines against the shop as it is now, with the same rules
 * as cart/add.php: the product must be active, a variant must still exist and be
 * active, quantity is capped to stock unless the line is a pre-order, and a print
 * design file must still be on disk. Prices are never stored or read here.
 * Each dropped or capped line adds one plain-language note that names the product.
 *
 * @return array{items: list<array>, notes: list<string>}
 */
function saved_cart_validate_items(PDO $pdo, array $items, string $lang): array
{
    require_once __DIR__ . '/products.php';
    $lang  = $lang === 'en' ? 'en' : 'bg';
    $out   = [];
    $notes = [];
    $prod_st = $pdo->prepare('SELECT id, name_bg, name_en, stock, active, `type`, preorder_enabled FROM products WHERE id = ?');
    $var_st  = $pdo->prepare('SELECT id, label_bg, label_en, stock, active FROM product_variants WHERE id = ? AND product_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $pid = (int)($item['product_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        if ($pid <= 0) continue;

        $prod_st->execute([$pid]);
        $p = $prod_st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $notes[] = t_or('cart.restore.gone_unknown', 'Един артикул от количката ви вече не се продава и не е добавен.', 'An item from your cart is no longer sold and was not added.', $lang);
            continue;
        }
        $name = $lang === 'en' ? ((string)($p['name_en'] ?? '') ?: (string)$p['name_bg']) : (string)$p['name_bg'];
        if (!(int)$p['active']) {
            $notes[] = t_or('cart.restore.gone', '„{name}“ вече не се продава и не е добавен.', '"{name}" is no longer sold and was not added.', $lang, ['name' => $name]);
            continue;
        }

        $available = (int)$p['stock'];
        $entry     = $item;
        if ($p['type'] === 'variant') {
            $vid = (int)($item['variant_id'] ?? 0);
            $var_st->execute([$vid, $pid]);
            $v = $vid > 0 ? $var_st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$v || !(int)$v['active']) {
                $notes[] = t_or('cart.restore.variant_gone', 'Избраният вариант на „{name}“ вече не се предлага и не е добавен.', 'The option you chose for "{name}" is no longer offered and was not added.', $lang, ['name' => $name]);
                continue;
            }
            $available = (int)$v['stock'];
            $entry['variant_id']    = $vid;
            $entry['variant_label'] = $v['label_bg'];
        } elseif ($p['type'] === 'print' && !empty($item['design_file'])) {
            $file = (string)$item['design_file'];
            if (!preg_match('#^uploads/print-designs/[A-Za-z0-9._-]+$#', $file) || str_contains($file, '..')
                || !is_file($_SERVER['DOCUMENT_ROOT'] . '/' . $file)) {
                $notes[] = t_or('cart.restore.design_gone', 'Дизайнът за „{name}“ вече не е наличен и артикулът не е добавен.', 'The design for "{name}" is no longer available, so it was not added.', $lang, ['name' => $name]);
                continue;
            }
        }

        $limit = product_line_limit($p, $available);
        if ($limit <= 0) {
            $notes[] = t_or('cart.restore.sold_out', '„{name}“ е изчерпан и не е добавен.', '"{name}" is sold out and was not added.', $lang, ['name' => $name]);
            continue;
        }
        if ($qty > $limit) {
            $notes[] = t_or('cart.restore.capped', 'От „{name}“ са налични само {n} бр. — количеството е коригирано.', 'Only {n} of "{name}" are available — the quantity was adjusted.', $lang, ['name' => $name, 'n' => $limit]);
            $qty = $limit;
        }
        $entry['product_id'] = $pid;
        $entry['quantity']   = $qty;
        $out[] = $entry;
    }
    return ['items' => $out, 'notes' => $notes];
}

function saved_cart_line_key(array $i): string
{
    return implode('|', [
        (int)($i['product_id'] ?? 0), (int)($i['variant_id'] ?? 0),
        (string)($i['colour'] ?? ''), (string)($i['size'] ?? ''), (string)($i['design_file'] ?? ''),
    ]);
}

/**
 * Adds saved lines to the cart a device already has. A line in both keeps the
 * higher quantity; new lines are appended. `merged` is true when there was a
 * cart to merge into.
 *
 * @return array{items: list<array>, merged: bool}
 */
function saved_cart_merge(array $current, array $saved): array
{
    $items = array_values($current);
    $index = [];
    foreach ($items as $n => $i) $index[saved_cart_line_key($i)] = $n;
    foreach ($saved as $s) {
        $k = saved_cart_line_key($s);
        if (isset($index[$k])) {
            $n = $index[$k];
            $items[$n]['quantity'] = max((int)$items[$n]['quantity'], (int)$s['quantity']);
        } else {
            $items[] = $s;
            $index[$k] = count($items) - 1;
        }
    }
    return ['items' => $items, 'merged' => $current !== []];
}

/**
 * Puts a saved pledge back at checkout step 1. Returns a plain-language error, or
 * null after setting the checkout data. Same checks campaign/checkout.php makes:
 * campaign switched on, rewards need the shop, reward still active.
 */
function saved_cart_restore_pledge(PDO $pdo, array $row, string $lang): ?string
{
    $lang = $lang === 'en' ? 'en' : 'bg';
    $st = $pdo->prepare('SELECT id, pledge_number, name, email, phone, amount_eur, reward_id, payment_status FROM campaign_pledges WHERE id = ?');
    $st->execute([(int)($row['pledge_id'] ?? 0)]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    $gone = t_or('cart.restore.pledge_gone', 'Тази подкрепа вече не може да бъде довършена.', 'This pledge can no longer be completed.', $lang);
    if (!$p || $p['payment_status'] !== 'pending') {
        return t_or('cart.restore.pledge_done', 'Тази подкрепа вече е обработена.', 'This pledge has already been dealt with.', $lang);
    }
    if (setting_get('campaign_active', '0') !== '1') {
        return t_or('cart.restore.campaign_closed', 'Кампанията вече не приема подкрепа.', 'The campaign is no longer taking pledges.', $lang);
    }
    if ((int)($p['reward_id'] ?? 0) <= 0 || !module_enabled_with_needs('shop')) return $gone;
    $rw = $pdo->prepare('SELECT id FROM campaign_rewards WHERE id = ? AND active = 1');
    $rw->execute([(int)$p['reward_id']]);
    if (!$rw->fetchColumn()) {
        return t_or('cart.restore.reward_gone', 'Наградата, която сте избрали, вече не се предлага.', 'The reward you chose is no longer offered.', $lang);
    }

    require_once __DIR__ . '/order_session.php';
    $_SESSION['checkout_data'] = [
        'customer_name'  => (string)$p['name'],
        'customer_email' => (string)$p['email'],
        'customer_phone' => (string)($p['phone'] ?? ''),
        '_pledge_number' => (string)$p['pledge_number'],
        '_pledge_id'     => (int)$p['id'],
        '_pledge_amount' => (float)$p['amount_eur'],
    ];
    order_session_remember((string)$p['pledge_number']);
    return null;
}
