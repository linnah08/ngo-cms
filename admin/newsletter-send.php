<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
admin_require_admin();

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();

if (!$campaign || $campaign['status'] === 'sent') {
    header('Location: /admin/newsletter.php');
    exit;
}

$counts = newsletter_active_count();

// ── Phase 1: confirm screen ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $page_title_admin = 'Изпращане на кампания';
    $active_nav       = 'newsletter-send';
    require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
    ?>
    <div style="max-width:600px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
        <h2 style="margin:0;">Изпращане на кампания</h2>
        <a href="/admin/newsletter.php" style="font-size:.9rem;color:var(--text-muted);">← Откажи</a>
      </div>

      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;margin-bottom:1.5rem;">
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding:.4rem 0;font-size:.85rem;color:var(--text-muted);width:120px;">Тема (БГ)</td>
            <td style="padding:.4rem 0;font-weight:600;"><?= h($campaign['subject_bg'] ?: '—') ?></td>
          </tr>
          <tr>
            <td style="padding:.4rem 0;font-size:.85rem;color:var(--text-muted);">Тема (EN)</td>
            <td style="padding:.4rem 0;font-weight:600;"><?= h($campaign['subject_en'] ?: '—') ?></td>
          </tr>
        </table>
      </div>

      <div style="background:var(--off-white);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1.5rem;">
        <div style="font-size:.875rem;margin-bottom:.5rem;">Активни абонати, които ще получат имейл:</div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--teal);"><?= $counts['total'] ?></div>
        <div style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem;">
          БГ: <?= $counts['bg'] ?> &nbsp;·&nbsp; EN: <?= $counts['en'] ?>
        </div>
      </div>

      <?php if (!$campaign['subject_bg'] && !$campaign['subject_en']): ?>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;color:#c0392b;font-size:.9rem;">
        ⚠ Кампанията няма тема. Добавете тема преди изпращане.
      </div>
      <?php elseif ($counts['total'] === 0): ?>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;color:#c0392b;font-size:.9rem;">
        ⚠ Няма активни абонати.
      </div>
      <?php else: ?>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;color:#8b4513;font-size:.9rem;">
        ⚠ Това действие е необратимо. Имейлите ще бъдат изпратени незабавно.
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--primary" style="padding:.9rem 2rem;font-size:1rem;"
                <?= (!$campaign['subject_bg'] && !$campaign['subject_en']) || $counts['total'] === 0 ? 'disabled' : '' ?>>
          Изпрати сега →
        </button>
      </form>
      <?php endif; ?>

      <div style="margin-top:1rem;">
        <a href="/admin/newsletter-preview.php?id=<?= $id ?>&lang=bg" target="_blank" style="font-size:.85rem;color:var(--teal);">Преглед БГ ↗</a>
        &nbsp;&nbsp;
        <a href="/admin/newsletter-preview.php?id=<?= $id ?>&lang=en" target="_blank" style="font-size:.85rem;color:var(--teal);">Преглед EN ↗</a>
      </div>
    </div>
    <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php';
    exit;
}

// ── Phase 2: send ──────────────────────────────────────────────────────────────
if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

if ($campaign['status'] !== 'draft') {
    header('Location: /admin/newsletter.php');
    exit;
}

// Mark as sending before the first email so a crash mid-send is visible
$pdo->prepare("UPDATE newsletter_campaigns SET status='sending' WHERE id=?")->execute([$id]);

// Fetch all active subscribers
$stmt = $pdo->prepare("SELECT id, email, name, lang, token FROM newsletter_subscribers WHERE status='active' ORDER BY id ASC");
$stmt->execute();
$subscribers = $stmt->fetchAll();
$total = count($subscribers);

$pdo->prepare("UPDATE newsletter_campaigns SET recipient_count=? WHERE id=?")->execute([$total, $id]);

// Release session lock so the admin can open other tabs while sending
session_write_close();
set_time_limit(0);

// Stream output to browser
if (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

$page_title_admin = 'Изпращане…';
$active_nav       = 'newsletter-send';
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <title>Изпращане — Odd Minds Admin</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <style>
    body { font-family: monospace; background: #f8f6f2; padding: 2rem; color: #1a1916; }
    .line { margin: .2rem 0; font-size: .9rem; }
    .ok   { color: #2d6a35; }
    .err  { color: #c0392b; }
    .done { font-size: 1.1rem; font-weight: 700; margin-top: 1.5rem; color: #0387A5; }
  </style>
</head>
<body>
<h2>Изпращане на кампания #<?= $id ?></h2>
<p style="color:#6b6560;">Абонати: <strong><?= $total ?></strong> &nbsp;·&nbsp; Не затваряйте прозореца.</p>
<hr style="border:none;border-top:1px solid #e8ddd5;margin:1rem 0;">

<?php
flush();

$batch_size = 100;
$batches    = array_chunk($subscribers, $batch_size);
$sent       = 0;
$failed     = 0;

foreach ($batches as $batch_num => $batch) {
    $from = $batch_num * $batch_size + 1;
    $to   = min(($batch_num + 1) * $batch_size, $total);
    echo '<div class="line">Серия ' . ($batch_num + 1) . '/' . count($batches) . ' &nbsp; (' . $from . '–' . $to . ')…</div>';
    flush();

    foreach ($batch as $sub) {
        $lang    = $sub['lang'];
        $subject = $lang === 'bg' ? $campaign['subject_bg'] : $campaign['subject_en'];
        if (!$subject) $subject = $campaign['subject_bg'] ?: $campaign['subject_en'];

        $name     = $sub['name'] ?: '';
        $greeting = $lang === 'bg'
            ? ('Здравейте' . ($name ? ', ' . $name : '') . ',')
            : ('Dear '     . ($name ?: 'friend')          . ',');

        $body_raw = $lang === 'bg' ? $campaign['body_bg'] : $campaign['body_en'];
        $unsub_url = SITE_URL . '/newsletter/unsubscribe.php?token=' . $sub['token'];

        $html = render_newsletter_email($greeting, $body_raw, $unsub_url, $lang);

        // Insert tracking row and inject pixel + link rewrites
        $track_token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT IGNORE INTO newsletter_sends (campaign_id, subscriber_id, token) VALUES (?,?,?)")
            ->execute([$id, $sub['id'], $track_token]);
        $html = newsletter_inject_tracking($html, $track_token);

        $ok = send_mail($sub['email'], $subject, $html);
        $ok ? $sent++ : $failed++;
    }

    echo '<div class="line ok">✓ Серия ' . ($batch_num + 1) . ' готова</div>';
    flush();

    // Sleep between batches (not after the last one)
    if ($batch_num < count($batches) - 1) sleep(1);
}

// Mark campaign as sent
$pdo->prepare("UPDATE newsletter_campaigns SET status='sent', sent_at=NOW(), recipient_count=? WHERE id=?")
    ->execute([$sent, $id]);
?>

<div class="done">
  ✓ Готово! Изпратени: <?= $sent ?><?= $failed ? " &nbsp;·&nbsp; <span class='err'>Грешки: {$failed}</span>" : '' ?>
</div>
<p style="margin-top:1.5rem;">
  <a href="/admin/newsletter.php" style="color:#0387A5;">← Обратно към кампании</a>
</p>
</body>
</html>
