<?php
/**
 * Brings a saved cart or pledge back: from the link in a reminder email (?t=token)
 * or from the on-site notice (no token: the row this browser's om_cart cookie
 * points at). GET only; changes nothing but this visitor's session and cookie.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
start_session();

module_public_guard('shop');

$req_lang = $_GET['lang'] ?? '';
$lang     = in_array($req_lang, ['bg', 'en'], true) ? $req_lang : get_lang();

function restore_go(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function restore_expired(string $lang): never
{
    flash_set('error', t_or('cart.restore.expired', 'Тази количка вече не е налична.', 'This cart is no longer available.', $lang));
    restore_go(shop_path('cart', $lang));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') restore_go(shop_path('shop', $lang));

$token = $_GET['t'] ?? null;
$from_email = $token !== null;
if ($from_email && (!is_string($token) || !saved_cart_token_valid($token))) restore_expired($lang);

$pdo = get_pdo();
$row = null;
$pledge_row = null;
if ($from_email) {
    $row = saved_cart_find($pdo, $token);
    if ($row === null || $row['ordered_at'] !== null) restore_expired($lang);
    $lang = $row['lang'] === 'en' ? 'en' : 'bg';
    if ($row['pledge_id'] !== null) { $pledge_row = $row; $row = null; }
} else {
    $found = saved_cart_for_browser($pdo);
    if ($found['cart'] === null && $found['pledge'] === null) restore_expired($lang);
    $row        = $found['cart'];
    $pledge_row = $row === null ? $found['pledge'] : null;
}

// A pledge goes back to checkout step 1; a failure goes to the campaign page.
if ($pledge_row !== null) {
    $err = saved_cart_restore_pledge($pdo, $pledge_row, $lang);
    if ($err !== null) {
        flash_set('error', $err);
        restore_go($lang === 'en' ? '/en/campaign/' : '/campaign/');
    }
    restore_go(shop_path('checkout', $lang) . '?step=1');
}

$checked = saved_cart_validate_items($pdo, $row['items'], $lang);
foreach ($checked['notes'] as $note) flash_set('error', $note);

$current = $_SESSION['cart'] ?? [];
if (!is_array($current)) $current = [];

if ($current === []) {
    $_SESSION['cart'] = $checked['items'];
} else {
    $merge = saved_cart_merge($current, $checked['items']);
    $_SESSION['cart'] = $merge['items'];
    if ($checked['items'] !== []) {
        flash_set('info', t_or('cart.restore.merged', 'Добавихме артикулите от напомнянето ви в количката.', 'We added the items from your reminder to your cart.', $lang));
    }
}

try { saved_cart_sync($pdo, $_SESSION['cart'], $lang); }
catch (Throwable $e) { error_log('Saved cart sync failed: ' . $e->getMessage()); }

// The device saves under its own cookie, never the link's. The emailed row has done its job.
if ($from_email && $row['browser_token'] !== saved_cart_browser_token(false)) {
    try { saved_cart_dismiss_row($pdo, (int)$row['id']); }
    catch (Throwable $e) { error_log('Saved cart dismiss failed: ' . $e->getMessage()); }
}

restore_go(shop_path('cart', $lang));
