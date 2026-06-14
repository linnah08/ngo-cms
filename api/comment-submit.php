<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

if (!csrf_verify()) {
    http_response_code(400);
    exit('Invalid token');
}

$slug = basename(str_replace(['..', "\0", '/'], '', trim($_POST['article_slug'] ?? '')));
$lang = ($_POST['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
$back = ($lang === 'en' ? '/en/news/' : '/novini/') . $slug . '/';

// ── Honeypot ──────────────────────────────────────────────────────────────────
// 'website' is a hidden field — humans leave it blank, bots fill it in.
if (!empty($_POST['website'])) {
    flash_set('comment_success', '1');
    header('Location: ' . $back . '#comments');
    exit;
}

$name    = trim($_POST['author_name']  ?? '');
$email   = trim($_POST['author_email'] ?? '');
$content = trim($_POST['content']      ?? '');

// ── Validate ──────────────────────────────────────────────────────────────────
$errors = [];
if (!$slug)   $errors[] = $lang === 'bg' ? 'Невалидна статия.' : 'Invalid article.';
if (!$name)   $errors[] = $lang === 'bg' ? 'Името е задължително.' : 'Name is required.';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
              $errors[] = $lang === 'bg' ? 'Въведете валиден имейл.' : 'A valid email is required.';
if (!$content || mb_strlen($content) < 5)
              $errors[] = $lang === 'bg' ? 'Коментарът е твърде кратък.' : 'Comment is too short.';
if (mb_strlen($content) > 2000)
              $errors[] = $lang === 'bg' ? 'Коментарът е твърде дълъг (макс. 2000 знака).' : 'Comment is too long (max 2000 characters).';

if ($errors) {
    flash_set('comment_error', implode(' ', $errors));
    header('Location: ' . $back . '#comments');
    exit;
}

$pdo = get_pdo();

// ── Ensure table ──────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS comments (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        article_slug VARCHAR(255) NOT NULL,
        lang         VARCHAR(5)   NOT NULL DEFAULT 'bg',
        author_name  VARCHAR(100) NOT NULL,
        author_email VARCHAR(255) NOT NULL,
        content      TEXT         NOT NULL,
        status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        ip           VARCHAR(45)  NOT NULL DEFAULT '',
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_slug_status (article_slug, status),
        INDEX idx_status_date (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Rate limit: 1 comment per IP per article per 10 minutes ───────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM comments
    WHERE article_slug = ? AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
");
$stmt->execute([$slug, $ip]);
if ((int)$stmt->fetchColumn() > 0) {
    flash_set('comment_error', $lang === 'bg'
        ? 'Изчакайте малко, преди да публикувате нов коментар.'
        : 'Please wait before posting another comment.');
    header('Location: ' . $back . '#comments');
    exit;
}

// ── Insert ────────────────────────────────────────────────────────────────────
$pdo->prepare("
    INSERT INTO comments (article_slug, lang, author_name, author_email, content, ip)
    VALUES (?, ?, ?, ?, ?, ?)
")->execute([$slug, $lang, $name, $email, $content, $ip]);

flash_set('comment_success', '1');
header('Location: ' . $back . '#comments');
exit;
