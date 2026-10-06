<?php
/**
 * "This browser placed this order."
 *
 * Order and pledge numbers are short (OM-20261006-1A2B: four random characters a
 * day) and travel in URLs — confirmation pages, payment returns, emails — so
 * knowing one must never be enough to see the buyer's details or act for them.
 * The checkout, the donation form and the campaign form remember each number they
 * create in the session; pages that show personal data ask order_session_owns().
 */

const ORDER_SESSION_KEY = '_owned_orders';

/** Remember that this session placed $number (an order or pledge number). Keeps the last 10. */
function order_session_remember(string $number): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || $number === '') return;
    $list   = is_array($_SESSION[ORDER_SESSION_KEY] ?? null) ? $_SESSION[ORDER_SESSION_KEY] : [];
    $list[] = strtoupper($number);
    $_SESSION[ORDER_SESSION_KEY] = array_slice(array_values(array_unique($list)), -10);
}

/** Did this session place $number? Case-insensitive, like the numbers in URLs. */
function order_session_owns(string $number): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || $number === '') return false;
    $list = $_SESSION[ORDER_SESSION_KEY] ?? [];
    return is_array($list) && in_array(strtoupper($number), $list, true);
}
