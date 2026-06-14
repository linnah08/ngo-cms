<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: /cart/');
    exit;
}

$idx  = (int)($_POST['cart_index'] ?? -1);
$cart = $_SESSION['cart'] ?? [];
if ($idx >= 0 && isset($cart[$idx])) {
    array_splice($cart, $idx, 1);
}
$_SESSION['cart'] = array_values($cart);

header('Location: /cart/');
exit;
