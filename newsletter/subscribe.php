<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';

// Module switched off in Admin → Модули — the sign-up does not exist (site's 404).
module_public_guard('newsletter');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

$lang = newsletter_form_lang($_POST);

// The footer band signs up in the background (fetch) and shows the answer in
// place, so it asks for JSON: an Accept header, X-Requested-With, or ajax=1 in
// the form. A plain form post (no JavaScript) still gets the full-page flow below.
$wants_json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    || ($_POST['ajax'] ?? '') === '1';

if (!csrf_verify()) {
    http_response_code(400);
    if ($wants_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => t_or('newsletter.err.session',
            'Страницата беше отворена твърде дълго. Моля, презаредете я и опитайте отново.',
            'This page was open for too long. Please reload it and try again.', $lang)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('Invalid token');
}

$email = trim((string)($_POST['email'] ?? ''));
$name  = trim((string)($_POST['name']  ?? ''));
// Back to the page the form was on (same site only, no query string).
$back      = strtok($_SERVER['HTTP_REFERER'] ?? '/', '?') ?: '/';
$back_host = parse_url($back, PHP_URL_HOST);
$own_hosts = [parse_url(SITE_URL, PHP_URL_HOST), parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)];
if ($back_host && !in_array($back_host, $own_hosts, true)) {
    $back = $lang === 'en' ? '/en/' : '/';
}

// One answer, two shapes: JSON for the background sign-up (field names which
// input to send focus back to), a flash message + redirect for a plain post.
$respond = static function (bool $ok, string $message, string $field = '') use ($wants_json, $back): never {
    if ($wants_json) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$ok) http_response_code(422);
        echo json_encode(['ok' => $ok, 'message' => $message, 'field' => $field], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set($ok ? 'success' : 'error', $message);
    header('Location: ' . $back);
    exit;
};

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $respond(false, t_or('newsletter.err.email', 'Невалиден имейл адрес.', 'Invalid email address.', $lang), 'email');
}

$topic_ids = newsletter_signup_topics($_POST);
if ($topic_ids === null) {
    $respond(false, t_or('newsletter.err.no_topic', 'Моля, изберете поне една тема.', 'Please choose at least one topic.', $lang), 'topics');
}

newsletter_subscribe($email, $name, $lang, 'web_banner', $topic_ids);

// A 1-year cookie so the banner hides on all subsequent pages
newsletter_set_subscribed_cookie();

$respond(true, t_or('newsletter.banner.success', 'Записахте се успешно!', 'You’re subscribed!', $lang));
