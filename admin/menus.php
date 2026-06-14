<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
$page_title_admin = 'Менюта';
$active_nav       = 'menus';

admin_require_login();
admin_require_admin();

$menus_file = CONTENT_PATH . '/menus.json';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Invalid token'); }

    $section = $_POST['section'] ?? '';
    $menus   = load_json($menus_file);

    $allowed = ['header', 'footer_nav', 'footer_help'];
    if (!in_array($section, $allowed)) {
        $error = 'Невалиден раздел.';
    } else {
        $urls_bg    = $_POST['url_bg']    ?? [];
        $labels_bg  = $_POST['label_bg']  ?? [];
        $urls_en    = $_POST['url_en']    ?? [];
        $labels_en  = $_POST['label_en']  ?? [];

        $items_bg = [];
        foreach ($urls_bg as $i => $url) {
            $url   = trim($url);
            $label = trim($labels_bg[$i] ?? '');
            if ($url !== '' && $label !== '') {
                $items_bg[] = ['url' => $url, 'label' => $label];
            }
        }

        $items_en = [];
        foreach ($urls_en as $i => $url) {
            $url   = trim($url);
            $label = trim($labels_en[$i] ?? '');
            if ($url !== '' && $label !== '') {
                $items_en[] = ['url' => $url, 'label' => $label];
            }
        }

        $menus[$section] = ['bg' => $items_bg, 'en' => $items_en];

        if (save_json($menus_file, $menus)) {
            $success = 'Менюто е запазено.';
        } else {
            $error = 'Грешка при запис на файла.';
        }
    }
}

$menus = load_json($menus_file);

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if ($success): ?>
  <div class="admin-alert admin-alert--success" style="margin-bottom:1.5rem;"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="admin-alert admin-alert--error" style="margin-bottom:1.5rem;"><?= h($error) ?></div>
<?php endif; ?>

<h1 style="margin-bottom:.5rem;">Менюта</h1>
<p style="color:var(--text-muted);margin-bottom:2rem;font-size:.9rem;">
  Редактирайте навигационните връзки в хедъра и футъра. Магазин, количка и превключвателят на езика се добавят автоматично.
</p>

<?php
$sections = [
    'header'      => 'Хедър навигация',
    'footer_nav'  => 'Футър — Навигация',
    'footer_help' => 'Футър — Как да помогна',
];

foreach ($sections as $key => $title):
    $items_bg = $menus[$key]['bg'] ?? [];
    $items_en = $menus[$key]['en'] ?? [];
?>

<h2 style="margin-bottom:1rem;"><?= h($title) ?></h2>
<form method="POST" action="/admin/menus.php" class="admin-form" style="margin-bottom:3rem;">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="<?= h($key) ?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;">

    <!-- BG -->
    <div>
      <div style="font-weight:600;margin-bottom:.75rem;font-size:.875rem;text-transform:uppercase;letter-spacing:.05em;">БГ</div>
      <div id="rows-bg-<?= $key ?>">
        <?php foreach ($items_bg as $item): ?>
        <div class="menu-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;">
          <input type="text" name="label_bg[]" value="<?= h($item['label']) ?>" placeholder="Етикет"
                 style="font-size:.875rem;padding:.45rem .65rem;">
          <input type="text" name="url_bg[]"   value="<?= h($item['url']) ?>"   placeholder="/страница/"
                 style="font-size:.875rem;padding:.45rem .65rem;">
          <button type="button" onclick="this.closest('.menu-row').remove()"
                  class="btn btn--outline" style="padding:.4rem .65rem;font-size:.8rem;">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn--outline" style="font-size:.8rem;margin-top:.5rem;"
              onclick="addRow('rows-bg-<?= $key ?>', 'label_bg', 'url_bg')">+ Добавяне</button>
    </div>

    <!-- EN -->
    <div>
      <div style="font-weight:600;margin-bottom:.75rem;font-size:.875rem;text-transform:uppercase;letter-spacing:.05em;">EN</div>
      <div id="rows-en-<?= $key ?>">
        <?php foreach ($items_en as $item): ?>
        <div class="menu-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;">
          <input type="text" name="label_en[]" value="<?= h($item['label']) ?>" placeholder="Label"
                 style="font-size:.875rem;padding:.45rem .65rem;">
          <input type="text" name="url_en[]"   value="<?= h($item['url']) ?>"   placeholder="/en/page/"
                 style="font-size:.875rem;padding:.45rem .65rem;">
          <button type="button" onclick="this.closest('.menu-row').remove()"
                  class="btn btn--outline" style="padding:.4rem .65rem;font-size:.8rem;">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn--outline" style="font-size:.8rem;margin-top:.5rem;"
              onclick="addRow('rows-en-<?= $key ?>', 'label_en', 'url_en')">+ Add</button>
    </div>

  </div>

  <div style="margin-top:1.25rem;">
    <button type="submit" class="btn btn--primary">Запази — <?= h($title) ?></button>
  </div>
</form>

<?php endforeach; ?>

<script>
function addRow(containerId, labelName, urlName) {
  var c = document.getElementById(containerId);
  var row = document.createElement('div');
  row.className = 'menu-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center;';
  row.innerHTML =
    '<input type="text" name="' + labelName + '[]" placeholder="Етикет / Label" style="font-size:.875rem;padding:.45rem .65rem;">' +
    '<input type="text" name="' + urlName   + '[]" placeholder="/url/" style="font-size:.875rem;padding:.45rem .65rem;">' +
    '<button type="button" onclick="this.closest(\'.menu-row\').remove()" class="btn btn--outline" style="padding:.4rem .65rem;font-size:.8rem;">✕</button>';
  c.appendChild(row);
  row.querySelector('input').focus();
}
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
