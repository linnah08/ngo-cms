<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
admin_require_admin();

$pdo  = get_pdo();
$id   = (int)($_GET['id'] ?? 0);
$lang = in_array($_GET['lang'] ?? 'bg', ['bg','en']) ? $_GET['lang'] : 'bg';

$stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();

if (!$campaign) {
    header('Location: /admin/newsletter.php');
    exit;
}

// ── POST: send test email ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_test') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $test_email = trim($_POST['test_email'] ?? '');
    $test_lang  = in_array($_POST['test_lang'] ?? 'bg', ['bg','en']) ? $_POST['test_lang'] : 'bg';

    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $test_error = 'Невалиден имейл адрес.';
    } else {
        $subject  = $test_lang === 'bg' ? $campaign['subject_bg'] : $campaign['subject_en'];
        if (!$subject) $subject = $campaign['subject_bg'] ?: $campaign['subject_en'];
        $subject  = '[ТЕСТ] ' . $subject;

        $body_raw = $test_lang === 'bg' ? $campaign['body_bg'] : $campaign['body_en'];
        $greeting = $test_lang === 'bg' ? 'Здравейте, [предварителен преглед],' : 'Dear [preview],';

        // Look up or create a subscriber record so the test email has a real unsubscribe link
        $sub_stmt = $pdo->prepare("SELECT token FROM newsletter_subscribers WHERE email = ?");
        $sub_stmt->execute([strtolower($test_email)]);
        $sub_row = $sub_stmt->fetch();
        if ($sub_row) {
            $unsub_token = $sub_row['token'];
        } else {
            $unsub_token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email, token, status, lang) VALUES (?, ?, 'active', ?)")
                ->execute([strtolower($test_email), $unsub_token, $test_lang]);
        }
        $unsub_url = SITE_URL . '/newsletter/unsubscribe.php?token=' . $unsub_token;

        $html = render_newsletter_email($greeting, $body_raw, $unsub_url, $test_lang);

        $ok = send_mail($test_email, $subject, $html);
        $test_success = $ok
            ? "Тестовият имейл е изпратен на {$test_email}."
            : 'Грешка при изпращане. Проверете SMTP конфигурацията.';
    }
}

// ── Build preview HTML (always rendered, shown in iframe or directly) ──────────
$subject  = $lang === 'bg' ? $campaign['subject_bg'] : $campaign['subject_en'];
$body_raw = $lang === 'bg' ? $campaign['body_bg']    : $campaign['body_en'];
$greeting = $lang === 'bg' ? 'Здравейте, [Абонат],' : 'Dear [Subscriber],';

// No unsub URL in preview — shows the "preview" notice instead
$preview_html = render_newsletter_email($greeting, $body_raw, '', $lang);

// ── GET ?raw=1: return bare email HTML for iframe src ─────────────────────────
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo $preview_html;
    exit;
}

// ── Render admin preview wrapper ──────────────────────────────────────────────
$page_title_admin = 'Преглед на кампания';
$active_nav       = 'newsletter-compose';
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$current_user = admin_user();
$admin_email  = $current_user['email'] ?? '';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
  <h2 style="margin:0;">Преглед — <?= h($subject ?: '(без тема)') ?></h2>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <a href="?id=<?= $id ?>&lang=bg" class="btn <?= $lang==='bg' ? 'btn--primary' : 'btn--outline' ?>" style="font-size:.85rem;">БГ</a>
    <a href="?id=<?= $id ?>&lang=en" class="btn <?= $lang==='en' ? 'btn--primary' : 'btn--outline' ?>" style="font-size:.85rem;">EN</a>
    <a href="/admin/newsletter-compose.php?id=<?= $id ?>" class="btn btn--outline" style="font-size:.85rem;">← Редактирай</a>
    <a href="/admin/newsletter-send.php?id=<?= $id ?>"    class="btn btn--outline" style="font-size:.85rem;">Изпрати →</a>
  </div>
</div>

<?php if (!empty($test_error)): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;margin-bottom:1.5rem;"><?= h($test_error) ?></div>
<?php elseif (!empty($test_success)): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;margin-bottom:1.5rem;"><?= h($test_success) ?></div>
<?php endif; ?>

<!-- Test send form -->
<div style="background:var(--off-white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.5rem;">
  <strong style="font-size:.875rem;display:block;margin-bottom:.75rem;">Изпрати тестов имейл</strong>
  <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_test">
    <input type="email" name="test_email" value="<?= h($admin_email) ?>" required
           placeholder="имейл за тест"
           style="padding:.45rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;width:260px;">
    <select name="test_lang" style="padding:.45rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;font-family:inherit;">
      <option value="bg" <?= $lang==='bg'?'selected':'' ?>>БГ версия</option>
      <option value="en" <?= $lang==='en'?'selected':'' ?>>EN version</option>
    </select>
    <button type="submit" class="btn btn--primary" style="font-size:.875rem;">Изпрати тест</button>
  </form>
  <p style="margin:.6rem 0 0;font-size:.78rem;color:var(--text-muted);">Тестовите имейли съдържат реален линк за отписване.</p>
</div>

<!-- Email preview iframe -->
<div style="border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;background:#f8f6f2;">
  <div style="background:var(--off-white);padding:.6rem 1rem;font-size:.8rem;color:var(--text-muted);border-bottom:1px solid var(--border);">
    Тема: <strong><?= h($subject ?: '(без тема)') ?></strong>
  </div>
  <iframe src="?id=<?= $id ?>&lang=<?= $lang ?>&raw=1"
          style="width:100%;height:700px;border:none;display:block;"
          title="Email preview"></iframe>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
