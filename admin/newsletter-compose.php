<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
$page_title_admin = 'Кампания';
$active_nav       = 'newsletter-compose';
admin_require_admin();
$deepl_ready = deepl_is_configured();
$lbl_bg_badge = '<span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>';
$lbl_en_badge = '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';
$_tinymce_key = setting_get('tinymce_api_key', 'no-api-key');
$page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);

// Load existing campaign for edit
$campaign = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if (!$campaign) { header('Location: /admin/newsletter.php'); exit; }
    if ($campaign['status'] === 'sent') {
        flash_set('error', 'Изпратените кампании не могат да бъдат редактирани.');
        header('Location: /admin/newsletter.php'); exit;
    }
}

$errors = [];

// ── POST: format articles ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'format_articles') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $slugs_bg = $_POST['article_slugs_bg'] ?? [];
    $slugs_en = $_POST['article_slugs_en'] ?? [];

    $articles_bg = array_filter(array_map(fn($s) => get_article($s, 'bg'), array_slice($slugs_bg, 0, 3)));
    $articles_en = array_filter(array_map(fn($s) => get_article($s, 'en'), array_slice($slugs_en, 0, 3)));

    // Carry current form state + inject generated bodies
    $campaign = array_merge($campaign ?? [], [
        'subject_bg' => $_POST['subject_bg'] ?? ($campaign['subject_bg'] ?? ''),
        'subject_en' => $_POST['subject_en'] ?? ($campaign['subject_en'] ?? ''),
        'body_bg'    => $articles_bg ? newsletter_format_articles(array_values($articles_bg), 'bg') : ($campaign['body_bg'] ?? ''),
        'body_en'    => $articles_en ? newsletter_format_articles(array_values($articles_en), 'en') : ($campaign['body_en'] ?? ''),
    ]);
}

// ── POST: save ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $subject_bg = trim($_POST['subject_bg'] ?? '');
    $subject_en = trim($_POST['subject_en'] ?? '');
    $body_bg    = $_POST['body_bg'] ?? '';
    $body_en    = $_POST['body_en'] ?? '';

    if (!$subject_bg && !$subject_en) $errors[] = 'Въведете поне една тема.';

    if (!$errors) {
        if ($id && $campaign) {
            $pdo->prepare("UPDATE newsletter_campaigns SET subject_bg=?,subject_en=?,body_bg=?,body_en=?,status='draft' WHERE id=?")
                ->execute([$subject_bg, $subject_en, $body_bg, $body_en, $id]);
        } else {
            $pdo->prepare("INSERT INTO newsletter_campaigns (subject_bg,subject_en,body_bg,body_en) VALUES (?,?,?,?)")
                ->execute([$subject_bg, $subject_en, $body_bg, $body_en]);
            $id = (int)$pdo->lastInsertId();
        }
        flash_set('success', 'Кампанията е записана.');
        header('Location: /admin/newsletter.php');
        exit;
    }

    // Re-populate for error display
    $campaign = array_merge($campaign ?? [], compact('subject_bg','subject_en','body_bg','body_en'));
}

// Load published articles for picker (both langs)
$articles_bg = get_articles('bg');
$articles_en = get_articles('en');

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<div class="admin-page-header">
  <h1><?= $id ? 'Редактирай кампания' : 'Нова кампания' ?></h1>
  <div style="display:flex;gap:.75rem;align-items:center;">
    <a href="/admin/newsletter.php" class="btn btn--outline">← Назад</a>
    <button type="submit" form="saveForm" class="btn btn--primary" onclick="syncSubjectsAndSave()">Запази чернова</button>
  </div>
</div>

<?php foreach ($errors as $e): ?>
<div style="padding:.9rem 1.25rem;border-radius:6px;background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;margin-bottom:1.5rem;"><?= h($e) ?></div>
<?php endforeach; ?>

<div style="display:grid;gap:1.5rem;max-width:900px;">

  <!-- Subject fields -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
    <h3 style="margin:0 0 1rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Теми</h3>
    <div style="display:grid;gap:.75rem;">
      <label style="font-size:.875rem;font-weight:600;">Тема <?= $lbl_bg_badge ?>
        <input type="text" id="subject_bg" value="<?= h($campaign['subject_bg'] ?? '') ?>"
               style="margin-top:.35rem;width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;box-sizing:border-box;">
      </label>
      <div style="font-size:.875rem;font-weight:600;">
        <?php if ($deepl_ready): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem;">
            <span>Тема <?= $lbl_en_badge ?></span>
            <button type="button" class="btn btn--outline" id="txSubjectBtn" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
          </div>
        <?php else: ?>
          <div style="margin-bottom:.35rem;">Тема <?= $lbl_en_badge ?></div>
        <?php endif; ?>
        <input type="text" id="subject_en" value="<?= h($campaign['subject_en'] ?? '') ?>"
               style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;box-sizing:border-box;">
      </div>
    </div>
  </div>

  <!-- Article picker -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
    <h3 style="margin:0 0 .5rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Генерирай от статии</h3>
    <p style="font-size:.85rem;color:var(--text-muted);margin:0 0 1rem;">Избери до 3 статии на всеки език. Генерирането ще замени съдържанието по-долу.</p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="format_articles">
      <input type="hidden" name="subject_bg" id="hidden_subject_bg">
      <input type="hidden" name="subject_en" id="hidden_subject_en">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1rem;">
        <div>
          <strong style="font-size:.85rem;display:block;margin-bottom:.5rem;">БГ статии</strong>
          <?php if (empty($articles_bg)): ?>
            <p style="color:var(--text-muted);font-size:.85rem;">Няма публикувани статии.</p>
          <?php else: foreach (array_slice($articles_bg, 0, 10) as $a): ?>
          <label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.4rem;font-size:.85rem;cursor:pointer;">
            <input type="checkbox" name="article_slugs_bg[]" value="<?= h($a['slug']) ?>" style="margin-top:.15rem;accent-color:var(--teal);">
            <span><?= h($a['title']) ?> <span style="color:var(--text-muted);">(<?= h($a['date']) ?>)</span></span>
          </label>
          <?php endforeach; endif; ?>
        </div>
        <div>
          <strong style="font-size:.85rem;display:block;margin-bottom:.5rem;">EN articles</strong>
          <?php if (empty($articles_en)): ?>
            <p style="color:var(--text-muted);font-size:.85rem;">No published articles.</p>
          <?php else: foreach (array_slice($articles_en, 0, 10) as $a): ?>
          <label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.4rem;font-size:.85rem;cursor:pointer;">
            <input type="checkbox" name="article_slugs_en[]" value="<?= h($a['slug']) ?>" style="margin-top:.15rem;accent-color:var(--teal);">
            <span><?= h($a['title']) ?> <span style="color:var(--text-muted);">(<?= h($a['date']) ?>)</span></span>
          </label>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <button type="submit" class="btn btn--outline" onclick="syncSubjects()">Генерирай HTML →</button>
    </form>
  </div>

  <!-- Body editors -->
  <form method="POST" id="saveForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="subject_bg" id="save_subject_bg">
    <input type="hidden" name="subject_en" id="save_subject_en">

    <div style="display:grid;gap:1.5rem;">

      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
        <h3 style="margin:0 0 .75rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Тяло <?= $lbl_bg_badge ?> — HTML</h3>
        <textarea id="bodyBg" name="body_bg" rows="14"
                  style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;font-family:monospace;box-sizing:border-box;resize:vertical;"
                  ><?= htmlspecialchars($campaign['body_bg'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;">
        <?php if ($deepl_ready): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">
          <h3 style="margin:0;font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Тяло <?= $lbl_en_badge ?> — HTML</h3>
          <button type="button" class="btn btn--outline" id="txBodyBtn" style="font-size:.75rem;padding:.2rem .5rem;">✦ Translate</button>
        </div>
        <?php else: ?>
        <h3 style="margin:0 0 .75rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);">Тяло <?= $lbl_en_badge ?> — HTML</h3>
        <?php endif; ?>
        <textarea id="bodyEn" name="body_en" rows="14"
                  style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;font-family:monospace;box-sizing:border-box;resize:vertical;"
                  ><?= htmlspecialchars($campaign['body_en'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <?php if ($id): ?>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <a href="/admin/newsletter-preview.php?id=<?= $id ?>&lang=bg" target="_blank" class="btn btn--outline">Преглед БГ ↗</a>
        <a href="/admin/newsletter-preview.php?id=<?= $id ?>&lang=en" target="_blank" class="btn btn--outline">Преглед EN ↗</a>
        <a href="/admin/newsletter-send.php?id=<?= $id ?>" class="btn btn--outline">Изпрати →</a>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" form="saveForm" class="btn btn--primary" onclick="syncSubjectsAndSave()">Запази чернова</button>
    </div>
  </form>

</div>

<script>
tinymce.init(Object.assign({}, window._tinyBase, { selector: '#bodyBg, #bodyEn', min_height: 280 }));
initAutosave({
  key:     <?= json_encode('newsletter:' . $id) ?>,
  formId:  'saveForm',
  tinyIds: ['bodyBg', 'bodyEn']
});

function syncSubjects() {
    document.getElementById('save_subject_bg').value   = document.getElementById('subject_bg').value;
    document.getElementById('save_subject_en').value   = document.getElementById('subject_en').value;
    document.getElementById('hidden_subject_bg').value = document.getElementById('subject_bg').value;
    document.getElementById('hidden_subject_en').value = document.getElementById('subject_en').value;
}

function syncSubjectsAndSave() {
    tinymce.triggerSave();
    syncSubjects();
}

// Sync on page load so hidden inputs are populated before any submit
syncSubjects();
document.getElementById('subject_bg').addEventListener('input', syncSubjects);
document.getElementById('subject_en').addEventListener('input', syncSubjects);

// Sync TinyMCE on generate-from-articles submit too
document.querySelector('button[onclick="syncSubjects()"]').addEventListener('click', function() {
    tinymce.triggerSave();
});

<?php if ($deepl_ready): ?>
async function txSubject(btn) {
    var src = document.getElementById('subject_bg').value.trim();
    if (!src) return;
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Превежда…';
    try {
        var r = await fetch('/admin/translate-ajax.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                csrf_token: document.querySelector('[name=csrf_token]').value,
                text: src,
                is_html: '0',
            })
        });
        var d = await r.json();
        if (d.translated) {
            document.getElementById('subject_en').value = d.translated;
            syncSubjects();
            btn.textContent = '✓ Преведено';
        } else {
            btn.textContent = '✗ Грешка';
        }
    } catch(e) { btn.textContent = '✗ Грешка'; }
    btn.disabled = false;
    setTimeout(function(){ btn.textContent = orig; }, 3000);
}
document.getElementById('txSubjectBtn').addEventListener('click', function(){ txSubject(this); });

async function txBody(btn) {
    var src = tinymce.get('bodyBg').getContent();
    if (!src) return;
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Превежда…';
    try {
        var r = await fetch('/admin/translate-ajax.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                csrf_token: document.querySelector('[name=csrf_token]').value,
                text: src,
                is_html: '1',
            })
        });
        var d = await r.json();
        if (d.translated) {
            tinymce.get('bodyEn').setContent(d.translated);
            btn.textContent = '✓ Преведено';
        } else {
            btn.textContent = '✗ Грешка';
        }
    } catch(e) { btn.textContent = '✗ Грешка'; }
    btn.disabled = false;
    setTimeout(function(){ btn.textContent = orig; }, 3000);
}
document.getElementById('txBodyBtn').addEventListener('click', function(){ txBody(this); });
<?php endif; ?>
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
