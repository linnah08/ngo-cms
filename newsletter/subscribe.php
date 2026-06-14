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

$email = trim($_POST['email'] ?? '');
$name  = trim($_POST['name']  ?? '');
$lang  = get_lang();

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', $lang === 'bg' ? 'Невалиден имейл адрес.' : 'Invalid email address.');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
    exit;
}

newsletter_subscribe($email, $name, $lang, 'web_banner');

// Set a 1-year cookie so the banner hides on all subsequent pages
setcookie('om_nl_sub', '1', [
    'expires'  => time() + 365 * 24 * 3600,
    'path'     => '/',
    'samesite' => 'Lax',
    'httponly' => true,
    'secure'   => isset($_SERVER['HTTPS']),
]);

flash_set('success', $lang === 'bg'
    ? 'Записахте се успешно за бюлетина!'
    : 'You have successfully subscribed to our newsletter!'
);

// Redirect back, stripping any previous nl param
$back = strtok($_SERVER['HTTP_REFERER'] ?? '/', '?') ?: '/';
header('Location: ' . $back);
exit;
