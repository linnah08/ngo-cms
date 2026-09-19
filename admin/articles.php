<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/articles.php';
$page_title_admin = 'Статии';
$active_nav       = 'articles';
admin_require_editorial();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $slug = basename(str_replace(['..', "\0"], '', $_POST['slug'] ?? ''));
    if ($slug) {
        $bg = ARTICLES_PATH . '/bg/' . $slug . '.json';
        $en = ARTICLES_PATH . '/en/' . $slug . '.json';
        if (file_exists($bg)) unlink($bg);
        if (file_exists($en)) unlink($en);
    }
    header('Location: /admin/articles.php?deleted=1');
    exit;
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'bulk_delete') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $ids = array_values(array_filter(array_map(
        fn($raw) => basename(str_replace(['..', "\0"], '', (string)$raw)),
        (array)($_POST['ids'] ?? [])
    ), fn($s) => $s !== ''));

    foreach ($ids as $slug) {
        // Same per-slug delete logic as the single-row delete above.
        $bg = ARTICLES_PATH . '/bg/' . $slug . '.json';
        $en = ARTICLES_PATH . '/en/' . $slug . '.json';
        if (file_exists($bg)) unlink($bg);
        if (file_exists($en)) unlink($en);
    }
    flash_set('success', 'Избраните статии са изтрити.');
    header('Location: /admin/articles.php');
    exit;
}

// Handle bulk publish/unpublish (smart toggle, mirrors products.php bulk_toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'bulk_publish') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }
    $ids = array_values(array_filter(array_map(
        fn($raw) => basename(str_replace(['..', "\0"], '', (string)$raw)),
        (array)($_POST['ids'] ?? [])
    ), fn($s) => $s !== ''));

    if (!empty($ids)) {
        // If every selected article is currently published, switch them all
        // to draft; otherwise publish all of them.
        $allPublished = true;
        foreach ($ids as $slug) {
            $bg   = ARTICLES_PATH . '/bg/' . $slug . '.json';
            $data = file_exists($bg) ? load_json($bg) : [];
            if (($data['status'] ?? '') !== 'published') { $allPublished = false; break; }
        }
        $newStatus = $allPublished ? 'draft' : 'published';

        foreach ($ids as $slug) {
            $bg = ARTICLES_PATH . '/bg/' . $slug . '.json';
            if (!file_exists($bg)) continue;
            $data              = load_json($bg);
            $data['status']    = $newStatus;
            $data['scheduled'] = false; // a bulk change is a manual decision
            save_json($bg, $data);

            // Date, author, image and status are shared between BG and EN —
            // keep that in sync here too.
            $en = ARTICLES_PATH . '/en/' . $slug . '.json';
            if (file_exists($en)) {
                $en_data              = load_json($en);
                $en_data['status']    = $newStatus;
                $en_data['scheduled'] = false;
                save_json($en, $en_data);
            }
        }
    }
    flash_set('success', 'Статусите са обновени.');
    header('Location: /admin/articles.php');
    exit;
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';

$articles_dir = ARTICLES_PATH . '/bg';
$en_dir       = ARTICLES_PATH . '/en';
$deepl_ready  = deepl_is_configured();
$articles = [];
if (is_dir($articles_dir)) {
    foreach (glob($articles_dir . '/*.json') as $file) {
        $data = load_json($file);
        if (!empty($data)) {
            $slug         = basename($file, '.json');
            $data['slug'] = $slug;
            $data['has_en'] = file_exists($en_dir . '/' . $slug . '.json');
            $data['is_scheduled'] = article_is_scheduled($data, @filemtime($file) ?: null);
            $articles[] = $data;
        }
    }
    usort($articles, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
}

// Status filter + free-text search — everything is in-memory since articles
// are JSON files, not DB rows.
$status_filter_raw = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
$status_filter     = in_array($status_filter_raw, ['all', 'published', 'draft'], true)
    ? $status_filter_raw : 'all';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');

$status_counts = ['all' => count($articles), 'published' => 0, 'draft' => 0];
foreach ($articles as $a) {
    $status_counts[($a['status'] ?? '') === 'published' ? 'published' : 'draft']++;
}

if ($status_filter !== 'all') {
    $articles = array_values(array_filter(
        $articles,
        fn($a) => (($a['status'] ?? '') === 'published' ? 'published' : 'draft') === $status_filter
    ));
}
if ($search !== '') {
    $needle   = mb_strtolower($search);
    $articles = array_values(array_filter(
        $articles,
        fn($a) => str_contains(mb_strtolower($a['title'] ?? ''), $needle)
    ));
}
?>

<div class="admin-page-header">
  <h1>Статии</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <?php if ($deepl_ready && !empty($articles)): ?>
      <button id="translateAllBtn" class="btn btn--outline" style="font-size:.85rem;">
        ✦ Преведи всички без EN
      </button>
    <?php endif; ?>
    <a href="/admin/article-edit.php" class="btn btn--primary">+ Нова статия</a>
  </div>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Статията е запазена.</div>
<?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;">Статията е изтрита.</div>
<?php endif; ?>
<?php if (!empty($_GET['image_error'])): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;">Снимката не бе запазена: <?= h($_GET['image_error']) ?></div>
<?php endif; ?>
<?php foreach (flash_get() as $_flash): ?>
  <div class="admin-alert admin-alert--<?= h($_flash['type']) ?>" style="margin-bottom:1.5rem;"><?= h($_flash['message']) ?></div>
<?php endforeach; ?>

<!-- Search -->
<form method="GET" style="margin-bottom:1rem;display:flex;gap:.5rem;max-width:520px;">
  <input type="hidden" name="status" value="<?= h($status_filter) ?>">
  <input type="search" name="q" value="<?= h($search) ?>" placeholder="Търси по заглавие…"
         style="flex:1;padding:.5rem .75rem;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit;">
  <button type="submit" class="btn btn--primary" style="padding:.5rem 1rem;font-size:.9rem;">Търси</button>
  <?php if ($search !== ''): ?>
    <a href="?status=<?= h($status_filter) ?>" class="btn btn--outline" style="padding:.5rem 1rem;font-size:.9rem;">Изчисти</a>
  <?php endif; ?>
</form>

<!-- Status filter -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php $status_pill_labels = ['all' => 'Всички', 'published' => 'Публикувани', 'draft' => 'Чернови'];
  foreach ($status_pill_labels as $val => $label): ?>
    <a href="?status=<?= $val ?>&q=<?= urlencode($search) ?>"
       style="padding:.35rem .9rem;border-radius:20px;font-size:.85rem;text-decoration:none;
         <?= $status_filter === $val ? 'background:var(--teal);color:#fff;' : 'background:var(--warm-grey);color:var(--text);' ?>">
      <?= $label ?> (<?= (int)$status_counts[$val] ?>)
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($articles)): ?>
  <p style="color:var(--text-muted);">
    <?php if ($search !== '' || $status_filter !== 'all'): ?>
      Няма статии, отговарящи на филтъра.
    <?php else: ?>
      Няма статии. <a href="/admin/article-edit.php">Създайте първата</a>.
    <?php endif; ?>
  </p>
<?php else: ?>
  <div class="admin-table-wrap" style="overflow-x:hidden;">
    <table class="admin-table" style="table-layout:fixed;width:100%;">
      <colgroup>
        <col style="width:4%;">
        <col style="width:34%;">
        <col style="width:12%;">
        <col style="width:15%;">
        <col style="width:11%;">
        <col style="width:7%;">
        <col style="width:17%;">
      </colgroup>
      <thead>
        <tr>
          <th style="overflow:hidden;"><input type="checkbox" id="select-all" aria-label="Избери всички" style="cursor:pointer;width:16px;height:16px;"></th>
          <th data-sort style="overflow:hidden;">Заглавие</th>
          <th data-sort style="overflow:hidden;">Дата</th>
          <th data-sort style="overflow:hidden;">Автор</th>
          <th data-sort style="overflow:hidden;">Статус</th>
          <th style="text-align:center;overflow:hidden;">EN</th>
          <th style="overflow:hidden;">Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($articles as $article): ?>
          <tr data-slug="<?= h($article['slug']) ?>">
            <td style="text-align:center;vertical-align:middle;">
              <input type="checkbox" class="row-cb"
                     value="<?= h($article['slug']) ?>"
                     data-published="<?= ($article['status'] ?? '') === 'published' ? '1' : '0' ?>"
                     style="cursor:pointer;width:16px;height:16px;">
            </td>
            <td><strong><?= h($article['title'] ?? '—') ?></strong></td>
            <td><?= h($article['date'] ?? '—') ?></td>
            <td><?= h($article['author'] ?? '—') ?></td>
            <td>
              <span class="badge <?= ($article['status'] ?? '') === 'published' ? 'badge--published' : 'badge--draft' ?>">
                <?= ($article['status'] ?? '') === 'published' ? 'Публикувана' : 'Чернова' ?>
              </span>
              <?php if (!empty($article['is_scheduled'])): ?>
                <span style="display:block;margin-top:.3rem;font-size:.75rem;color:#b45309;white-space:nowrap;"
                      title="Ще се публикува автоматично на тази дата">⏱ <?= h(date('d.m.Y', strtotime((string) $article['date']))) ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;" class="en-cell">
              <?php if ($article['has_en']): ?>
                <a href="/admin/article-edit.php?slug=<?= urlencode($article['slug']) ?>&lang=en"
                   class="badge badge--published" style="text-decoration:none;">EN ✓</a>
              <?php else: ?>
                <span class="badge badge--draft">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/admin/article-edit.php?slug=<?= urlencode($article['slug']) ?>" class="btn-link">Редактирай</a>
              <?php if ($deepl_ready): ?>
                <button type="button" class="btn-link translate-one-btn"
                        data-slug="<?= h($article['slug']) ?>"
                        style="color:var(--teal);">
                  <?= $article['has_en'] ? 'Презапиши EN' : 'Преведи →' ?>
                </button>
              <?php endif; ?>
              <form method="POST" action="/admin/articles.php?action=delete"
                    style="display:inline;"
                    data-confirm="Изтриване на статията &quot;<?= addslashes(h($article['title'] ?? $article['slug'])) ?>&quot;? Това не може да се отмени.">
                <?= csrf_field() ?>
                <input type="hidden" name="slug" value="<?= h($article['slug']) ?>">
                <button type="submit" class="btn-link btn-link--danger">Изтрий</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Bulk action bar -->
  <div id="bulk-bar" style="
    position:fixed;bottom:0;left:0;right:0;
    background:#1a1a2e;color:#fff;
    padding:1rem 2rem;
    display:flex;align-items:center;gap:1rem;
    transform:translateY(100%);transition:transform 0.2s ease;
    z-index:1000;box-shadow:0 -2px 8px rgba(0,0,0,0.3);">
    <span id="bulk-count" style="font-weight:500;">0 избрани</span>
    <button id="bulk-toggle" type="button" class="btn btn--primary">Публикувай</button>
    <button id="bulk-delete" type="button" class="btn btn--danger">Изтрий</button>
    <button id="bulk-clear" type="button" class="btn-link" style="color:#aaa;margin-left:auto;">✕ Изчисти</button>
  </div>

  <form id="form-bulk-publish" method="POST" action="/admin/articles.php?action=bulk_publish" style="display:none;">
    <?= csrf_field() ?>
  </form>

  <form id="form-bulk-delete" method="POST" action="/admin/articles.php?action=bulk_delete" style="display:none;">
    <?= csrf_field() ?>
  </form>

  <script>
  (function () {
      const selectAll  = document.getElementById('select-all');
      const bulkBar    = document.getElementById('bulk-bar');
      const countEl    = document.getElementById('bulk-count');
      const toggleBtn  = document.getElementById('bulk-toggle');
      const deleteBtn  = document.getElementById('bulk-delete');
      const clearBtn   = document.getElementById('bulk-clear');
      const formToggle = document.getElementById('form-bulk-publish');
      const formDelete = document.getElementById('form-bulk-delete');

      function getCheckboxes() {
          return Array.from(document.querySelectorAll('.row-cb'));
      }

      function getSelected() {
          return getCheckboxes().filter(cb => cb.checked);
      }

      function updateToolbar() {
          const selected = getSelected();
          const count    = selected.length;

          countEl.textContent = count + ' избрани';
          bulkBar.style.transform = count > 0 ? 'translateY(0)' : 'translateY(100%)';

          const allPublished = selected.length > 0 && selected.every(cb => cb.dataset.published === '1');
          toggleBtn.textContent = allPublished ? 'Направи чернова' : 'Публикувай';

          const all = getCheckboxes();
          selectAll.indeterminate = count > 0 && count < all.length;
          selectAll.checked       = all.length > 0 && count === all.length;
      }

      function injectIds(form, selected) {
          form.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
          selected.forEach(function (cb) {
              const input = document.createElement('input');
              input.type  = 'hidden';
              input.name  = 'ids[]';
              input.value = cb.value;
              form.appendChild(input);
          });
      }

      selectAll.addEventListener('change', function () {
          getCheckboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
          updateToolbar();
      });

      getCheckboxes().forEach(function (cb) {
          cb.addEventListener('change', updateToolbar);
      });

      toggleBtn.addEventListener('click', function () {
          const selected = getSelected();
          if (!selected.length) return;
          injectIds(formToggle, selected);
          formToggle.submit();
      });

      deleteBtn.addEventListener('click', function () {
          const selected = getSelected();
          if (!selected.length) return;
          _adminConfirm(
              'Изтриване на ' + selected.length + ' статии. Това не може да се отмени. Продължавате?',
              'Изтрий'
          ).then(function (confirmed) {
              if (!confirmed) return;
              injectIds(formDelete, selected);
              formDelete.submit();
          });
      });

      clearBtn.addEventListener('click', function () {
          getCheckboxes().forEach(function (cb) { cb.checked = false; });
          selectAll.checked       = false;
          selectAll.indeterminate = false;
          updateToolbar();
      });
  })();
  </script>
<?php endif; ?>

<?php if ($deepl_ready): ?>
<div id="translateLog" style="margin-top:1.5rem;display:none;">
  <strong style="font-size:.875rem;">Напредък:</strong>
  <div id="translateLogLines" style="margin-top:.5rem;font-family:monospace;font-size:.8rem;line-height:1.8;"></div>
</div>
<script>
var _csrfToken = '<?= csrf_token() ?>';

function logLine(msg, ok) {
  var log   = document.getElementById('translateLog');
  var lines = document.getElementById('translateLogLines');
  log.style.display = 'block';
  var div = document.createElement('div');
  div.style.color = ok === false ? '#c0392b' : ok === true ? '#2d6a35' : '#6b7280';
  div.textContent = msg;
  lines.appendChild(div);
}

async function translateSlug(slug, btn) {
  if (btn) { btn.disabled = true; btn.textContent = 'Превежда…'; }
  logLine('Превеждам: ' + slug + '…');
  try {
    var res  = await fetch('/admin/translate-article-ajax.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({slug: slug, csrf_token: _csrfToken})
    });
    var data = await res.json();
    if (data.ok) {
      logLine('✓ ' + slug + (data.en_title ? ' → "' + data.en_title + '"' : ''), true);
      var row = document.querySelector('tr[data-slug="' + slug + '"]');
      if (row) {
        row.querySelector('.en-cell').innerHTML =
          '<a href="/admin/article-edit.php?slug=' + encodeURIComponent(slug) + '&lang=en" class="badge badge--published" style="text-decoration:none;">EN ✓</a>';
        if (btn) btn.textContent = 'Презапиши EN';
      }
    } else {
      logLine('✗ ' + slug + ': ' + data.error, false);
      if (btn) btn.textContent = 'Грешка — опитай пак';
    }
  } catch(e) {
    logLine('✗ ' + slug + ': ' + e.message, false);
    if (btn) btn.textContent = 'Грешка — опитай пак';
  }
  if (btn) btn.disabled = false;
}

document.querySelectorAll('.translate-one-btn').forEach(function(btn) {
  btn.addEventListener('click', function() { translateSlug(this.dataset.slug, this); });
});

var translateAllBtn = document.getElementById('translateAllBtn');
if (translateAllBtn) {
  translateAllBtn.addEventListener('click', async function() {
    this.disabled = true;
    this.textContent = 'Превежда…';
    var rows = document.querySelectorAll('tr[data-slug]');
    for (var row of rows) {
      if (row.querySelector('.badge--draft')) {
        await translateSlug(row.dataset.slug, null);
        await new Promise(r => setTimeout(r, 300));
      }
    }
    this.disabled = false;
    this.textContent = '✦ Преведи всички без EN';
  });
}
</script>
<?php endif; ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
