<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
start_session();

// The form says which language it came from; go back to that cart.
$back = shop_path('cart', post_lang());

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . $back);
    exit;
}

$idx  = (int)($_POST['cart_index'] ?? -1);
$cart = $_SESSION['cart'] ?? [];
if ($idx >= 0 && isset($cart[$idx])) {
    array_splice($cart, $idx, 1);
}
$_SESSION['cart'] = array_values($cart);

header('Location: ' . $back);
exit;
