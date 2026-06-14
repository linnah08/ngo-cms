<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
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
            $articles[] = $data;
        }
    }
    usort($articles, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
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

<?php if (empty($articles)): ?>
  <p style="color:var(--text-muted);">Няма статии. <a href="/admin/article-edit.php">Създайте първата</a>.</p>
<?php else: ?>
  <div class="admin-table-wrap" style="overflow-x:hidden;">
    <table class="admin-table" style="table-layout:fixed;width:100%;">
      <colgroup>
        <col style="width:38%;">
        <col style="width:13%;">
        <col style="width:16%;">
        <col style="width:12%;">
        <col style="width:8%;">
        <col style="width:13%;">
      </colgroup>
      <thead>
        <tr>
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
            <td><strong><?= h($article['title'] ?? '—') ?></strong></td>
            <td><?= h($article['date'] ?? '—') ?></td>
            <td><?= h($article['author'] ?? '—') ?></td>
            <td>
              <span class="badge <?= ($article['status'] ?? '') === 'published' ? 'badge--published' : 'badge--draft' ?>">
                <?= ($article['status'] ?? '') === 'published' ? 'Публикувана' : 'Чернова' ?>
              </span>
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
