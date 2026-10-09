<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

// Module switched off in Admin → Модули — the shop does not exist (site's 404).
module_public_guard('shop');

$lang = post_lang();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . shop_path('cart', $lang));
    exit;
}

$quantities = $_POST['quantity'] ?? [];
$cart = $_SESSION['cart'] ?? [];

// Fetch stock limits for all products and variants in the cart
$pdo = get_pdo();
$product_ids = array_unique(array_column($cart, 'product_id'));
$stock_by_product = [];
if ($product_ids) {
    $in = implode(',', array_fill(0, count($product_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, stock FROM products WHERE id IN ($in)");
    $stmt->execute($product_ids);
    foreach ($stmt->fetchAll() as $p) $stock_by_product[$p['id']] = (int)$p['stock'];
}
$variant_ids = array_filter(array_column($cart, 'variant_id'));
$stock_by_variant = [];
if ($variant_ids) {
    $in2 = implode(',', array_fill(0, count($variant_ids), '?'));
    $vstmt = $pdo->prepare("SELECT id, stock FROM product_variants WHERE id IN ($in2)");
    $vstmt->execute(array_values($variant_ids));
    foreach ($vstmt->fetchAll() as $pv) $stock_by_variant[$pv['id']] = (int)$pv['stock'];
}

$new_cart = [];
$capped   = false;
foreach ($cart as $i => $item) {
    $qty = (int)($quantities[$i] ?? 0);
    if ($qty <= 0) continue;

    $vid = $item['variant_id'] ?? null;
    if ($vid) {
        $limit = $stock_by_variant[$vid] ?? 0;
    } else {
        $limit = $stock_by_product[$item['product_id']] ?? 0;
    }

    if ($qty > $limit) {
        $qty    = $limit;
        $capped = true;
    }
    if ($qty <= 0) continue; // out of stock entirely — drop from cart silently? keep at 0? keep item with 1.

    $item['quantity'] = $qty;
    $new_cart[] = $item;
}
$_SESSION['cart'] = $new_cart;

if ($capped) {
    $msg = t_or('cart.err.quantities_adjusted', 'Количествата са коригирани според наличността.', 'Some quantities were adjusted to match available stock.', $lang);
    flash_set('error', $msg);
}

header('Location: ' . shop_path('cart', $lang));
exit;
