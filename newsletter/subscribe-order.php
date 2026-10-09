<?php
/**
 * One-click newsletter sign-up from the order / donation confirmation page.
 *
 * The buyer only ticks topics: the email address is read from the order on
 * the server, and only for an order this browser session placed (see
 * newsletter_session_owns_order()), so an order number alone — it is in the
 * page URL — can never be used to sign someone else up.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/donation.php';
start_session();

// Module switched off in Admin → Модули — the sign-up does not exist (site's 404).
module_public_guard('newsletter');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}
if (!csrf_verify()) {
    http_response_code(400);
    exit('Invalid token');
}

$lang         = newsletter_form_lang($_POST);
$order_number = trim((string) ($_POST['order'] ?? ''));
if (!preg_match('/^OM-\d{8}-[A-F0-9]{4}$/i', $order_number)) {
    header('Location: /');
    exit;
}

// Back to the confirmation page the form was on, in the page's language.
$is_donation = ($_POST['flow'] ?? '') === 'donation';
$back = ($is_donation ? donation_path('confirmation', $lang) : shop_path('confirmation', $lang))
      . '?order=' . urlencode($order_number) . '#newsletter';

[$wants_news, $wants_edu] = newsletter_topics_from_post($_POST);
$status = newsletter_subscribe_order(get_pdo(), $order_number, $wants_news, $wants_edu);

if ($status === 'no_topics') {
    flash_set('error', t_or('newsletter.err.no_topic', 'Моля, изберете поне една тема.', 'Please choose at least one topic.', $lang));
} elseif ($status === 'not_found') {
    // Session expired or a different browser: point to the sign-up band instead.
    flash_set('error', t_or('newsletter.err.order', 'Не успяхме да ви запишем оттук. Моля, използвайте формата за абонамент най-долу на страницата.', 'We could not sign you up from here. Please use the sign-up form at the bottom of the page.', $lang));
} else {
    newsletter_set_subscribed_cookie();
    flash_set('success', t_or('newsletter.banner.success', 'Записахте се успешно!', 'You’re subscribed!', $lang));
}

header('Location: ' . $back);
exit;
