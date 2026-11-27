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
