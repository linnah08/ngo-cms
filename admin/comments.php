<?php
$page_title_admin = 'Коментари & Контакти';
$active_nav       = 'comments';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/spam_filter.php';
admin_require_editorial();

$pdo = get_pdo();

// Ensure tables exist
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
$pdo->exec("
    CREATE TABLE IF NOT EXISTS contact_submissions (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(200) NOT NULL,
        email      VARCHAR(255) NOT NULL,
        topic      VARCHAR(200) NOT NULL DEFAULT '',
        message    TEXT         NOT NULL,
        status     ENUM('new','read','archived') NOT NULL DEFAULT 'new',
        ip         VARCHAR(45)  NOT NULL DEFAULT '',
        lang       VARCHAR(5)   NOT NULL DEFAULT 'bg',
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_date (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $post_action = $_POST['action'] ?? '';

    // Save contact topics
    if ($post_action === 'save_topics') {
        $raw_bg = $_POST['topics_bg'] ?? '';
        $raw_en = $_POST['topics_en'] ?? '';
        $list_bg = array_values(array_filter(array_map('trim', explode("\n", $raw_bg))));
        $list_en = array_values(array_filter(array_map('trim', explode("\n", $raw_en))));
        setting_set('contact_topics',    json_encode($list_bg, JSON_UNESCAPED_UNICODE));
        setting_set('contact_topics_en', json_encode($list_en, JSON_UNESCAPED_UNICODE));
        header('Location: /admin/comments.php?saved=1');
        exit;
    }

    // Comment moderation
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && in_array($post_action, ['approve', 'reject', 'delete', 'spam'], true)) {
        $qs = array_filter($_GET);
        if ($post_action === 'delete') {
            $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
        } elseif ($post_action === 'spam') {
            $domains = spam_mark_comments_as_spam($pdo, [$id]);
            $qs['spam_marked'] = '1';
            if ($domains) {
                start_session();
                $_SESSION['spam_candidate_domains'] = $domains;
                $qs['spam_review'] = '1';
            }
        } else {
            $status = $post_action === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute([$status, $id]);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Contact submission status
    $csub_id = (int)($_POST['csub_id'] ?? 0);
    if ($csub_id > 0 && in_array($post_action, ['csub_read', 'csub_archive', 'csub_delete', 'csub_spam'], true)) {
        $qs = array_filter($_GET);
        if ($post_action === 'csub_delete') {
            $pdo->prepare('DELETE FROM contact_submissions WHERE id = ?')->execute([$csub_id]);
        } elseif ($post_action === 'csub_spam') {
            $domains = spam_mark_contacts_as_spam($pdo, [$csub_id]);
            $qs['spam_marked'] = '1';
            if ($domains) {
                start_session();
                $_SESSION['spam_candidate_domains'] = $domains;
                $qs['spam_review'] = '1';
            }
        } else {
            $status = $post_action === 'csub_read' ? 'read' : 'archived';
            $pdo->prepare('UPDATE contact_submissions SET status = ? WHERE id = ?')->execute([$status, $csub_id]);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk mark as spam — comments
    if ($post_action === 'bulk_spam_comments') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $domains = spam_mark_comments_as_spam($pdo, $ids);
            $qs['spam_marked'] = (string)count($ids);
            if ($domains) {
                start_session();
                $_SESSION['spam_candidate_domains'] = $domains;
                $qs['spam_review'] = '1';
            }
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk approve — comments
    if ($post_action === 'bulk_approve_comments') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE comments SET status = 'approved' WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk reject — comments
    if ($post_action === 'bulk_reject_comments') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE comments SET status = 'rejected' WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk delete — comments
    if ($post_action === 'bulk_delete_comments') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM comments WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk mark as spam — contacts
    if ($post_action === 'bulk_spam_contacts') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $domains = spam_mark_contacts_as_spam($pdo, $ids);
            $qs['spam_marked'] = (string)count($ids);
            if ($domains) {
                start_session();
                $_SESSION['spam_candidate_domains'] = $domains;
                $qs['spam_review'] = '1';
            }
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk mark as read — contacts
    if ($post_action === 'bulk_read_contacts') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE contact_submissions SET status = 'read' WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk archive — contacts
    if ($post_action === 'bulk_archive_contacts') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE contact_submissions SET status = 'archived' WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Bulk delete — contacts
    if ($post_action === 'bulk_delete_contacts') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
        $qs  = array_filter($_GET);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM contact_submissions WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: /admin/comments.php?' . http_build_query($qs));
        exit;
    }

    // Confirm extracted domains → add to blocklist
    if ($post_action === 'confirm_spam_domains') {
        $checked = $_POST['domains'] ?? [];
        if ($checked) spam_blocklist_add($checked);
        start_session();
        unset($_SESSION['spam_candidate_domains']);
        header('Location: /admin/comments.php?' . http_build_query(array_filter($_GET)));
        exit;
    }

    // Dismiss the domain-review banner without adding anything
    if ($post_action === 'dismiss_spam_review') {
        start_session();
        unset($_SESSION['spam_candidate_domains']);
        header('Location: /admin/comments.php?' . http_build_query(array_filter($_GET)));
        exit;
    }

    header('Location: /admin/comments.php');
    exit;
}

// ── Pending domain-review banner (populated by a bulk/single spam mark) ───────
$spam_candidate_domains = [];
if (!empty($_GET['spam_review'])) {
    start_session();
    $spam_candidate_domains = $_SESSION['spam_candidate_domains'] ?? [];
}

// ── Source toggle: comments | contacts | both ──────────────────────────────────
$source = in_array($_GET['source'] ?? '', ['comments', 'contacts', 'both']) ? $_GET['source'] : 'both';

// ── Comment filter ─────────────────────────────────────────────────────────────
$comment_filter = in_array($_GET['filter'] ?? '', ['pending', 'approved', 'rejected', 'spam']) ? $_GET['filter'] : 'pending';

// ── Contact submission filter ──────────────────────────────────────────────────
$contact_filter = in_array($_GET['cfilter'] ?? '', ['new', 'read', 'archived', 'spam']) ? $_GET['cfilter'] : 'new';

// Load comments
$comments = [];
if ($source !== 'contacts') {
    $stmt = $pdo->prepare("
        SELECT id, article_slug, lang, author_name, author_email, content, status, ip, created_at
        FROM comments WHERE status = ? ORDER BY created_at DESC
    ");
    $stmt->execute([$comment_filter]);
    $comments = $stmt->fetchAll();
}

$comment_counts = $pdo->query("
    SELECT status, COUNT(*) as n FROM comments GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Load contact submissions
$contacts = [];
if ($source !== 'comments') {
    $stmt = $pdo->prepare("
        SELECT id, name, email, topic, message, status, ip, lang, created_at
        FROM contact_submissions WHERE status = ? ORDER BY created_at DESC
    ");
    $stmt->execute([$contact_filter]);
    $contacts = $stmt->fetchAll();
}

$contact_counts = $pdo->query("
    SELECT status, COUNT(*) as n FROM contact_submissions GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Load topics for editing
$raw_bg_topics = setting_get('contact_topics');
$raw_en_topics = setting_get('contact_topics_en');
$bg_topics = $raw_bg_topics ? (json_decode($raw_bg_topics, true) ?? []) : [
    'Искам да стана партньор',
    'Искам да стана доброволец',
    'Мога да ви запозная с потенциален партньор',
    'Друго',
];
$en_topics = $raw_en_topics ? (json_decode($raw_en_topics, true) ?? []) : [
    'I want to become a partner',
    'I want to volunteer',
    'I can introduce you to a potential partner',
    'Other',
];

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1>Коментари &amp; Контакти</h1>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div style="background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:.9rem;">
    ✓ Темите са запазени.
  </div>
<?php endif; ?>

<?php if (!empty($_GET['spam_marked'])): ?>
  <div style="background:#e6f4ea;border:1px solid #a8d5b0;color:#2d6a35;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:.9rem;">
    ✓ <?= (int)$_GET['spam_marked'] ?> маркирани като спам.
  </div>
<?php endif; ?>

<?php if ($spam_candidate_domains): ?>
  <div class="admin-card" style="padding:1.25rem;margin-bottom:1.75rem;border-color:#f0c4c0;">
    <p style="margin:0 0 .75rem;font-weight:600;">
      Намерени линкове в маркирания спам — да ги добавя ли към блокирания списък?
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem;">
        <?php foreach ($spam_candidate_domains as $d): ?>
          <label style="font-size:.9rem;">
            <input type="checkbox" name="domains[]" value="<?= h($d) ?>" checked> <?= h($d) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:.75rem;">
        <button type="submit" name="action" value="confirm_spam_domains" class="btn btn--primary" style="font-size:.85rem;">Добави в спам филтъра</button>
        <button type="submit" name="action" value="dismiss_spam_review" class="btn btn--outline" style="font-size:.85rem;">Пропусни</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<!-- ── Source toggle ──────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.5rem;margin-bottom:1.75rem;flex-wrap:wrap;">
  <?php
  $sources = ['both' => 'Всичко', 'comments' => 'Коментари', 'contacts' => 'Контактни форми'];
  foreach ($sources as $key => $label):
    $active = $source === $key;
    $href   = '?' . http_build_query(['source' => $key, 'filter' => $comment_filter, 'cfilter' => $contact_filter]);
  ?>
    <a href="<?= $href ?>"
       style="padding:.45rem 1rem;border-radius:6px;font-size:.88rem;border:1px solid <?= $active ? 'var(--teal)' : 'var(--border)' ?>;background:<?= $active ? 'var(--teal)' : '#fff' ?>;color:<?= $active ? '#fff' : 'var(--text-muted)' ?>;text-decoration:none;font-weight:<?= $active ? '600' : '400' ?>;">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($source !== 'contacts'): ?>
<!-- ── Comments section ───────────────────────────────────────────────────────── -->
<div style="margin-bottom:2.5rem;">
  <h2 style="font-size:1rem;font-weight:600;margin-bottom:1rem;">Коментари към статии</h2>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;border-bottom:1px solid var(--border);">
    <?php
    $ctabs = ['pending' => 'Чакащи', 'approved' => 'Одобрени', 'rejected' => 'Отхвърлени', 'spam' => 'Спам'];
    foreach ($ctabs as $key => $label):
      $count  = (int)($comment_counts[$key] ?? 0);
      $active = $comment_filter === $key;
      $href   = '?' . http_build_query(['source' => $source, 'filter' => $key, 'cfilter' => $contact_filter]);
    ?>
      <a href="<?= $href ?>"
         style="padding:.5rem 1rem;font-size:.88rem;border-bottom:2px solid <?= $active ? 'var(--teal)' : 'transparent' ?>;color:<?= $active ? 'var(--teal)' : 'var(--text-muted)' ?>;text-decoration:none;font-weight:<?= $active ? '600' : '400' ?>;">
        <?= $label ?>
        <?php if ($count > 0): ?>
          <span style="background:<?= $key === 'pending' ? 'var(--teal)' : 'var(--border)' ?>;color:<?= $key === 'pending' ? '#fff' : 'var(--text)' ?>;border-radius:10px;padding:.1rem .45rem;font-size:.75rem;margin-left:.3rem;"><?= $count ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <form id="commentsBulkForm" method="POST" style="margin-bottom:1rem;">
    <?= csrf_field() ?>
    <div id="commentsBulkBar" style="display:none;align-items:center;gap:.75rem;flex-wrap:wrap;background:var(--warm-grey);border:1px solid var(--border);border-radius:8px;padding:.6rem 1rem;">
      <span id="commentsBulkCount" style="font-size:.875rem;font-weight:600;white-space:nowrap;"></span>
      <button type="submit" name="action" value="bulk_approve_comments" class="btn btn--primary" style="font-size:.82rem;padding:.35rem .85rem;">✓ Одобри</button>
      <button type="submit" name="action" value="bulk_reject_comments" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;color:#c0392b;border-color:#c0392b;">✗ Отхвърли</button>
      <button type="submit" name="action" value="bulk_spam_comments" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;color:#c0392b;border-color:#c0392b;">🚫 Маркирай като спам</button>
      <button type="submit" name="action" value="bulk_delete_comments" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;color:#c0392b;border-color:#c0392b;"
              data-confirm="Изтриване на избраните коментари — сигурни ли сте?" data-confirm-ok="Изтрий">Изтрий</button>
      <button type="button" onclick="spamClearAll('comments')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.875rem;">✕ Откажи</button>
    </div>
  </form>
  <?php if (!empty($comments)): ?>
    <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;cursor:pointer;">
      <input type="checkbox" id="commentsCheckAll" onchange="spamToggleAll('comments', this)"> Избери всички
    </label>
  <?php endif; ?>

  <?php if (empty($comments)): ?>
    <p style="color:var(--text-muted);font-size:.9rem;">Няма коментари в тази категория.</p>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:1rem;">
      <?php foreach ($comments as $c): ?>
        <?php $article_url = ($c['lang'] === 'en' ? '/en/news/' : '/novini/') . $c['article_slug'] . '/'; ?>
        <div class="admin-card" style="padding:1.25rem;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:baseline;gap:.75rem;margin-bottom:.4rem;flex-wrap:wrap;">
                <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" form="commentsBulkForm"
                       class="comments-row-check" onchange="spamUpdateBar('comments')">
                <strong><?= h($c['author_name']) ?></strong>
                <span style="font-size:.82rem;color:var(--text-muted);"><?= h($c['author_email']) ?></span>
                <span style="font-size:.78rem;color:var(--text-muted);"><?= h($c['created_at']) ?></span>
                <span style="font-size:.78rem;color:var(--text-muted);">IP: <?= h($c['ip']) ?></span>
              </div>
              <div style="margin-bottom:.65rem;">
                <a href="<?= h($article_url) ?>" target="_blank" style="font-size:.82rem;color:var(--teal);">
                  <?= h(strtoupper($c['lang'])) ?>: /<?= h($c['article_slug']) ?>/ ↗
                </a>
              </div>
              <p style="margin:0;line-height:1.65;white-space:pre-wrap;font-size:.95rem;"><?= h($c['content']) ?></p>
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;flex-shrink:0;">
              <form method="POST" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <?php if ($c['status'] !== 'approved'): ?>
                  <button type="submit" name="action" value="approve"
                          class="btn btn--primary" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;">✓ Одобри</button>
                <?php endif; ?>
                <?php if ($c['status'] !== 'rejected'): ?>
                  <button type="submit" name="action" value="reject"
                          class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;color:#c0392b;border-color:#c0392b;">✗ Отхвърли</button>
                <?php endif; ?>
                <?php if ($c['status'] !== 'spam'): ?>
                  <button type="submit" name="action" value="spam"
                          class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;color:#c0392b;border-color:#c0392b;">🚫 Спам</button>
                <?php endif; ?>
                <button type="submit" name="action" value="delete"
                        class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;"
                        data-confirm="Изтриване на коментар — сигурни ли сте?" data-confirm-ok="Изтрий">Изтрий</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($source !== 'comments'): ?>
<!-- ── Contact submissions section ───────────────────────────────────────────── -->
<div style="margin-bottom:2.5rem;">
  <h2 style="font-size:1rem;font-weight:600;margin-bottom:1rem;">Съобщения от контактната форма</h2>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;border-bottom:1px solid var(--border);">
    <?php
    $ctabs2 = ['new' => 'Нови', 'read' => 'Прочетени', 'archived' => 'Архивирани', 'spam' => 'Спам'];
    foreach ($ctabs2 as $key => $label):
      $count  = (int)($contact_counts[$key] ?? 0);
      $active = $contact_filter === $key;
      $href   = '?' . http_build_query(['source' => $source, 'filter' => $comment_filter, 'cfilter' => $key]);
    ?>
      <a href="<?= $href ?>"
         style="padding:.5rem 1rem;font-size:.88rem;border-bottom:2px solid <?= $active ? 'var(--teal)' : 'transparent' ?>;color:<?= $active ? 'var(--teal)' : 'var(--text-muted)' ?>;text-decoration:none;font-weight:<?= $active ? '600' : '400' ?>;">
        <?= $label ?>
        <?php if ($count > 0): ?>
          <span style="background:<?= $key === 'new' ? '#c0392b' : 'var(--border)' ?>;color:<?= $key === 'new' ? '#fff' : 'var(--text)' ?>;border-radius:10px;padding:.1rem .45rem;font-size:.75rem;margin-left:.3rem;"><?= $count ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <form id="contactsBulkForm" method="POST" style="margin-bottom:1rem;">
    <?= csrf_field() ?>
    <div id="contactsBulkBar" style="display:none;align-items:center;gap:.75rem;flex-wrap:wrap;background:var(--warm-grey);border:1px solid var(--border);border-radius:8px;padding:.6rem 1rem;">
      <span id="contactsBulkCount" style="font-size:.875rem;font-weight:600;white-space:nowrap;"></span>
      <button type="submit" name="action" value="bulk_read_contacts" class="btn btn--primary" style="font-size:.82rem;padding:.35rem .85rem;">✓ Прочетено</button>
      <button type="submit" name="action" value="bulk_archive_contacts" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;">Архивирай</button>
      <button type="submit" name="action" value="bulk_spam_contacts" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;color:#c0392b;border-color:#c0392b;">🚫 Маркирай като спам</button>
      <button type="submit" name="action" value="bulk_delete_contacts" class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;color:#c0392b;border-color:#c0392b;"
              data-confirm="Изтриване на избраните съобщения — сигурни ли сте?" data-confirm-ok="Изтрий">Изтрий</button>
      <button type="button" onclick="spamClearAll('contacts')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.875rem;">✕ Откажи</button>
    </div>
  </form>
  <?php if (!empty($contacts)): ?>
    <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;cursor:pointer;">
      <input type="checkbox" id="contactsCheckAll" onchange="spamToggleAll('contacts', this)"> Избери всички
    </label>
  <?php endif; ?>

  <?php if (empty($contacts)): ?>
    <p style="color:var(--text-muted);font-size:.9rem;">Няма съобщения в тази категория.</p>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:1rem;">
      <?php foreach ($contacts as $cs): ?>
        <div class="admin-card" style="padding:1.25rem;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:baseline;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap;">
                <input type="checkbox" name="ids[]" value="<?= (int)$cs['id'] ?>" form="contactsBulkForm"
                       class="contacts-row-check" onchange="spamUpdateBar('contacts')">
                <strong><?= h($cs['name']) ?></strong>
                <a href="mailto:<?= h($cs['email']) ?>" style="font-size:.82rem;color:var(--teal);"><?= h($cs['email']) ?></a>
                <span style="font-size:.78rem;color:var(--text-muted);"><?= h($cs['created_at']) ?></span>
                <span style="font-size:.72rem;background:var(--off-white);border:1px solid var(--border);border-radius:4px;padding:.1rem .4rem;color:var(--text-muted);"><?= strtoupper(h($cs['lang'])) ?></span>
              </div>
              <div style="margin-bottom:.65rem;">
                <span style="display:inline-block;background:#f0f4ff;border:1px solid #c7d2fe;border-radius:4px;padding:.2rem .6rem;font-size:.82rem;color:#3730a3;font-weight:600;"><?= h($cs['topic']) ?></span>
              </div>
              <p style="margin:0;line-height:1.65;white-space:pre-wrap;font-size:.95rem;"><?= h($cs['message']) ?></p>
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;flex-shrink:0;">
              <form method="POST" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="csub_id" value="<?= (int)$cs['id'] ?>">
                <?php if ($cs['status'] !== 'read'): ?>
                  <button type="submit" name="action" value="csub_read"
                          class="btn btn--primary" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;">✓ Прочетено</button>
                <?php endif; ?>
                <?php if ($cs['status'] !== 'archived'): ?>
                  <button type="submit" name="action" value="csub_archive"
                          class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;">Архивирай</button>
                <?php endif; ?>
                <?php if ($cs['status'] !== 'spam'): ?>
                  <button type="submit" name="action" value="csub_spam"
                          class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;color:#c0392b;border-color:#c0392b;">🚫 Спам</button>
                <?php endif; ?>
                <button type="submit" name="action" value="csub_delete"
                        class="btn btn--outline" style="font-size:.82rem;padding:.35rem .85rem;white-space:nowrap;"
                        data-confirm="Изтриване на съобщение — сигурни ли сте?" data-confirm-ok="Изтрий">Изтрий</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Topic management ───────────────────────────────────────────────────────── -->
<div class="admin-card" style="padding:1.5rem;margin-top:1rem;">
  <h2 style="font-size:1rem;font-weight:600;margin:0 0 1rem;">Теми в контактната форма</h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_topics">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
      <div class="form-group" style="margin:0;">
        <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.4rem;">Теми <span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span> — по една на ред</label>
        <textarea name="topics_bg" rows="6"
                  style="width:100%;font-size:.88rem;line-height:1.6;font-family:inherit;resize:vertical;box-sizing:border-box;"><?= h(implode("\n", $bg_topics)) ?></textarea>
      </div>
      <div class="form-group" style="margin:0;">
        <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:.4rem;">Topics <span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span> — one per line</label>
        <textarea name="topics_en" rows="6"
                  style="width:100%;font-size:.88rem;line-height:1.6;font-family:inherit;resize:vertical;box-sizing:border-box;"><?= h(implode("\n", $en_topics)) ?></textarea>
      </div>
    </div>
    <div style="margin-top:1rem;">
      <button type="submit" class="btn btn--primary" style="font-size:.88rem;">Запази темите</button>
    </div>
  </form>
</div>

<script>
function spamToggleAll(scope, cb) {
    document.querySelectorAll('.' + scope + '-row-check').forEach(c => c.checked = cb.checked);
    spamUpdateBar(scope);
}
function spamUpdateBar(scope) {
    const boxes   = document.querySelectorAll('.' + scope + '-row-check');
    const checked = document.querySelectorAll('.' + scope + '-row-check:checked');
    const bar     = document.getElementById(scope + 'BulkBar');
    const checkAll = document.getElementById(scope + 'CheckAll');
    if (checkAll) checkAll.checked = boxes.length > 0 && checked.length === boxes.length;
    if (checked.length > 0) {
        bar.style.display = 'flex';
        document.getElementById(scope + 'BulkCount').textContent = checked.length + ' избрани';
    } else {
        bar.style.display = 'none';
    }
}
function spamClearAll(scope) {
    document.querySelectorAll('.' + scope + '-row-check').forEach(c => c.checked = false);
    const checkAll = document.getElementById(scope + 'CheckAll');
    if (checkAll) checkAll.checked = false;
    document.getElementById(scope + 'BulkBar').style.display = 'none';
}
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
