<?php
/**
 * IRIS Pay browser return target (redirectUrl).
 *
 * Payment is confirmed via the server-to-server callback (iris-payment-callback.php),
 * NOT here — the browser return is untrusted and carries no token. We simply send
 * the customer to the order confirmation page, which reflects the current payment
 * status (it may still read 'pending' for a moment if the callback is in flight).
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

$order_number = $_GET['order'] ?? '';
header('Location: /checkout/confirmation/?order=' . urlencode($order_number));
exit;
