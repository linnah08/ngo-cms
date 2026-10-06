<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

if (!csrf_verify()) {
    http_response_code(400);
    exit('Invalid token');
}

$email = trim((string)($_POST['email'] ?? ''));
$name  = trim((string)($_POST['name']  ?? ''));
$lang  = get_lang();
// Back to the page the form was on (same site only, no query string).
$back  = strtok($_SERVER['HTTP_REFERER'] ?? '/', '?') ?: '/';
if (parse_url($back, PHP_URL_HOST) && parse_url($back, PHP_URL_HOST) !== parse_url(SITE_URL, PHP_URL_HOST)
    && parse_url($back, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
    $back = '/';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', t_or('newsletter.err.email', 'Невалиден имейл адрес.', 'Invalid email address.', $lang));
    header('Location: ' . $back);
    exit;
}

[$wants_news, $wants_edu] = newsletter_topics_from_post($_POST);
if (!$wants_news && !$wants_edu) {
    flash_set('error', t_or('newsletter.err.no_topic', 'Моля, изберете поне една тема.', 'Please choose at least one topic.', $lang));
    header('Location: ' . $back);
    exit;
}

newsletter_subscribe($email, $name, $lang, 'web_banner', $wants_news, $wants_edu);

// A 1-year cookie so the banner hides on all subsequent pages
newsletter_set_subscribed_cookie();

flash_set('success', t_or('newsletter.banner.success', 'Записахте се успешно!', 'You’re subscribed!', $lang));

header('Location: ' . $back);
exit;
