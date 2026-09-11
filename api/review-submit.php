<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/product_reviews.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/spam_filter.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

$slug = basename(str_replace(['..', "\0", '/'], '', trim($_POST['product_slug'] ?? '')));
$lang = ($_POST['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$back = ($lang === 'en' ? '/en/shop/' : '/magazin/') . rawurlencode($slug) . '/';

// Honeypot — humans leave 'website' blank.
if (!empty($_POST['website'])) {
    product_review_flash_set('success');
    header('Location: ' . $back . '#reviews'); exit;
}

// Turnstile — silently pretend success on failure, same as the honeypot above.
if (turnstile_is_configured() && !turnstile_verify($_POST['cf-turnstile-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '')) {
    product_review_flash_set('success');
    header('Location: ' . $back . '#reviews'); exit;
}

$name    = trim($_POST['author_name']  ?? '');
$email   = trim($_POST['author_email'] ?? '');
$rating  = (int)($_POST['rating'] ?? 0);
$content = trim($_POST['content'] ?? '');

$errors = [];
if (!$name)                       $errors[] = $lang === 'bg' ? 'Името е задължително.' : 'Name is required.';
if (mb_strlen($name) > 100)       $errors[] = $lang === 'bg' ? 'Името е твърде дълго (макс. 100 знака).' : 'Name is too long (max 100 characters).';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
                                   $errors[] = $lang === 'bg' ? 'Въведи валиден имейл.' : 'A valid email is required.';
if ($rating < 1 || $rating > 5)   $errors[] = $lang === 'bg' ? 'Изберете оценка от 1 до 5.' : 'Choose a rating from 1 to 5.';
if (!$content || mb_strlen($content) < 5) $errors[] = $lang === 'bg' ? 'Отзивът е твърде кратък.' : 'Review is too short.';
if (mb_strlen($content) > 2000)   $errors[] = $lang === 'bg' ? 'Отзивът е твърде дълъг (макс. 2000 знака).' : 'Review is too long (max 2000 characters).';

$pdo = get_pdo();

// Resolve product id from slug.
$pid = 0;
if ($slug) {
    $st = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND active = 1');
    $st->execute([$slug]);
    $pid = (int)$st->fetchColumn();
}
if (!$pid) $errors[] = $lang === 'bg' ? 'Невалиден продукт.' : 'Invalid product.';

if ($errors) {
    product_review_flash_set('error', implode(' ', $errors));
    header('Location: ' . $back . '#reviews'); exit;
}

// Content blocklist — silently pretend success, same as the honeypot/Turnstile above.
if (spam_content_is_blocked($content)) {
    product_review_flash_set('success');
    header('Location: ' . $back . '#reviews'); exit;
}

// Rate limit: 1 review per IP per product per 10 minutes.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM product_reviews
     WHERE product_id = ? AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
);
$st->execute([$pid, $ip]);
if ((int)$st->fetchColumn() > 0) {
    product_review_flash_set('error', $lang === 'bg'
        ? 'Изчакайте малко, преди да публикувате нов отзив.'
        : 'Please wait before posting another review.');
    header('Location: ' . $back . '#reviews'); exit;
}

$verified = product_review_is_verified_purchase($pdo, $email, $pid);

$pdo->prepare(
    "INSERT INTO product_reviews (product_id, lang, author_name, author_email, rating, content, ip, verified_purchase)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([$pid, $lang, $name, $email, $rating, $content, $ip, $verified ? 1 : 0]);

product_review_flash_set('success');
header('Location: ' . $back . '#reviews');
exit;
