<?php
/**
 * Comments partial — include in single article views.
 *
 * Expected variables:
 *   $slug  string  article slug
 *   $lang  string  'bg' | 'en'
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

$is_en = $lang === 'en';
// Labels are HTML: each is editable on the page in Edit mode (te()).

// ── Load approved comments ─────────────────────────────────────────────────────
$comments = [];
try {
    $pdo = get_pdo();
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
    $stmt = $pdo->prepare("
        SELECT author_name, content, created_at
        FROM comments
        WHERE article_slug = ? AND status = 'approved'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$slug]);
    $comments = $stmt->fetchAll();
} catch (Throwable $e) {
    // Silently skip — comments are non-critical
}

$flash_success = false;
$flash_error   = '';
foreach (flash_get() as $f) {
    if ($f['type'] === 'comment_success') {
        $flash_success = true;
    } elseif ($f['type'] === 'comment_error') {
        $flash_error = $f['message'];
    }
}

$label_title = te('comments.title', 'Коментари', 'Comments', $lang);
$label_name = te('comments.name', 'Вашето име', 'Your name', $lang);
$label_email = te('comments.email', 'Имейл (не се показва публично)', 'Email (not shown publicly)', $lang);
$label_comment = te('comments.comment', 'Коментар', 'Comment', $lang);
$label_submit = te('comments.submit', 'Публикувай коментар', 'Post comment', $lang);
$label_pending = te('comments.pending', 'Коментарът ви е получен и очаква одобрение. Благодарим!', 'Your comment has been received and is awaiting moderation. Thank you!', $lang);
$label_none = te('comments.none', 'Все още няма коментари. Бъди първият!', 'No comments yet. Be the first!', $lang);
?>

<section class="section" id="comments" style="border-top:1px solid var(--border);padding-top:3rem;">
  <div class="container container--narrow">

    <h2 style="font-size:1.3rem;margin-bottom:2rem;">
      <?= $label_title ?>
      <?php if ($comments): ?>
        <span style="font-size:.85rem;font-weight:400;color:var(--text-muted);margin-left:.5rem;">(<?= count($comments) ?>)</span>
      <?php endif; ?>
    </h2>

    <?php if ($flash_success): ?>
      <div style="background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;padding:.85rem 1.1rem;border-radius:6px;margin-bottom:2rem;">
        ✓ <?= $label_pending ?>
      </div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
      <div style="background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;padding:.85rem 1.1rem;border-radius:6px;margin-bottom:2rem;">
        <?= h($flash_error) ?>
      </div>
    <?php endif; ?>

    <!-- ── Approved comments ─────────────────────────────────────────────────── -->
    <?php if ($comments): ?>
      <div style="margin-bottom:3rem;display:flex;flex-direction:column;gap:1.5rem;">
        <?php foreach ($comments as $c): ?>
          <div style="border-left:3px solid var(--teal);padding:.75rem 1rem .75rem 1.25rem;background:var(--off-white);border-radius:0 6px 6px 0;">
            <div style="display:flex;align-items:baseline;gap:.75rem;margin-bottom:.5rem;">
              <strong style="font-size:.95rem;"<?= cms_admin(['Коментари', '/admin/comments.php']) ?>><?= h($c['author_name']) ?></strong>
              <time style="font-size:.78rem;color:var(--text-muted);"<?= cms_admin(['Коментари', '/admin/comments.php']) ?>>
                <?= h(format_date(substr($c['created_at'], 0, 10), $lang)) ?>
              </time>
            </div>
            <p style="margin:0;line-height:1.7;white-space:pre-wrap;"<?= cms_admin(['Коментари', '/admin/comments.php']) ?>><?= h($c['content']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);margin-bottom:2.5rem;"><?= $label_none ?></p>
    <?php endif; ?>

    <!-- ── Comment form ───────────────────────────────────────────────────────── -->
    <form method="POST" action="/api/comment-submit.php" style="display:flex;flex-direction:column;gap:1rem;">
      <?= csrf_field() ?>
      <input type="hidden" name="article_slug" value="<?= h($slug) ?>">
      <input type="hidden" name="lang"         value="<?= h($lang) ?>">

      <!-- Honeypot: hidden from humans, filled by bots -->
      <div style="display:none;" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div>
          <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_name ?> *</label>
          <input type="text" name="author_name" required maxlength="100"
                 style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;">
        </div>
        <div>
          <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_email ?> *</label>
          <input type="email" name="author_email" required maxlength="255"
                 style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;">
        </div>
      </div>

      <div>
        <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;"><?= $label_comment ?> *</label>
        <textarea name="content" required minlength="5" maxlength="2000" rows="5"
                  style="width:100%;box-sizing:border-box;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.95rem;resize:vertical;font-family:inherit;"></textarea>
      </div>

      <?php require $_SERVER['DOCUMENT_ROOT'] . '/templates/turnstile-widget.php'; ?>

      <div>
        <button type="submit" class="btn btn--primary"><?= $label_submit ?></button>
      </div>
    </form>

  </div>
</section>
