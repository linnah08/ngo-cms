<?php
// admin/home-sections.php — build and edit the front page (content/home.json).
// Spec: docs/superpowers/specs/2026-09-21-home-sections-design.md
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/translator.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/home_admin.php';

admin_require_login();
admin_require_admin();

$types  = home_types();
$loaded = home_load();
$doc    = $loaded['doc'];
$rev    = (int) $doc['rev'];
$errors = [];
$form   = null;   // section shown in the edit form: ['id' => '' for new, 'type', 'fields']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { http_response_code(400); exit('Невалидна заявка — презаредете страницата и опитайте отново.'); }
    $action   = (string) ($_POST['action'] ?? '');
    $post_rev = (int) ($_POST['rev'] ?? -1);
    $post_id  = (string) ($_POST['id'] ?? '');

    if (in_array($action, HOME_ACTIONS, true)) {
        $r = home_apply_action($doc, $action, $post_id);
        if ($r['ok']) {
            $saved = home_save($r['doc'], $post_rev);
            if (!$saved['ok']) $r = ['ok' => false, 'message' => home_save_error_message($saved['error']), 'focus' => $post_id];
        }
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
        header('Location: /admin/home-sections.php?focus=' . rawurlencode($action . ':' . ($r['focus'] ?? ''))); exit;
    }
    if ($action !== 'save') { http_response_code(400); exit('Непознато действие.'); }

    $idx = $post_id !== '' ? home_find($doc, $post_id) : null;
    if ($post_id !== '' && $idx === null) {
        flash_set('error', 'Секцията не е намерена — може би е изтрита междувременно.');
        header('Location: /admin/home-sections.php'); exit;
    }
    $type = $idx !== null ? (string) $doc['sections'][$idx]['type'] : (string) ($_POST['type'] ?? '');
    if (!isset($types[$type]) || ($idx === null && home_is_builtin($type))) { http_response_code(400); exit('Непознат вид секция.'); }

    $sid = $idx !== null ? $post_id : home_new_id();
    $old = $idx !== null ? ($doc['sections'][$idx]['fields'] ?? []) : [];
    $in  = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $upload_errors     = home_apply_uploads($in, $type, $_FILES, $sid);
    [$fields, $errors] = home_validate_section($type, $in);
    $errors += $upload_errors;

    if (!$errors && $type === 'video') {
        $fields['thumb'] = (($old['video'] ?? null) === $fields['video'] && !empty($old['thumb']))
            ? $old['thumb'] : home_fetch_video_thumb($fields['video'], $sid);
    }
    if (!$errors) {
        $section = ['id' => $sid, 'type' => $type, 'visible' => $idx !== null ? !empty($doc['sections'][$idx]['visible']) : true, 'fields' => $fields];
        $saved   = home_save(home_upsert($doc, $section), $post_rev);
        if ($saved['ok']) {
            $name = home_section_name($section);
            flash_set('success', $idx !== null ? "„{$name}“ е запазена." : "„{$name}“ е добавена най-долу на страницата и вече се вижда.");
            header('Location: /admin/home-sections.php?focus=' . rawurlencode('edit:' . $sid)); exit;
        }
        $errors['_form'] = home_save_error_message($saved['error']);
    }
    $form = ['id' => $idx !== null ? $post_id : '', 'type' => $type, 'fields' => $fields];
    $rev  = $post_rev;   // keep the revision the admin started from
} elseif (isset($_GET['edit'])) {
    $idx = home_find($doc, (string) $_GET['edit']);
    if ($idx === null || !isset($types[$doc['sections'][$idx]['type']])) {
        flash_set('error', 'Секцията не е намерена.');
        header('Location: /admin/home-sections.php'); exit;
    }
    $form = $doc['sections'][$idx];
} elseif (isset($_GET['add']) && $_GET['add'] !== '') {
    $type = (string) $_GET['add'];
    if (!isset($types[$type]) || home_is_builtin($type)) { header('Location: /admin/home-sections.php?add='); exit; }
    $form = ['id' => '', 'type' => $type, 'fields' => home_validate_section($type, [])[0]];
}
$picker = $form === null && isset($_GET['add']);
$flash  = flash_get();

$active_nav       = 'pages';
$page_title_admin = 'Начална страница';
if ($form !== null) {
    $_tinymce_key    = setting_get('tinymce_api_key', 'no-api-key');
    $page_head_extra = '<script src="https://cdn.tiny.cloud/1/' . h($_tinymce_key) . '/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>';
}
require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>
<div id="hsLive" role="status" aria-live="polite" style="<?= HS_SR ?>"></div>
<?php foreach ($flash as $msg): $ok = $msg['type'] === 'success'; ?>
  <div <?= $ok ? 'id="hsFlash"' : 'role="alert"' ?> style="border:2px solid <?= $ok ? '#15803d' : '#b91c1c' ?>;background:<?= $ok ? '#f0fdf4' : '#fef2f2' ?>;color:<?= $ok ? '#14532d' : '#7f1d1d' ?>;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;font-weight:600;">
    <span aria-hidden="true"><?= $ok ? '✓' : '⚠' ?> </span><?= h($msg['message']) ?>
  </div>
<?php endforeach; ?>

<?php if ($form !== null): $t = $types[$form['type']]; $is_new = $form['id'] === ''; ?>
  <!-- ══ EDIT / NEW ══ -->
  <a href="/admin/home-sections.php" style="display:inline-block;margin-bottom:.5rem;">← Назад към всички секции</a>
  <h1 style="margin:0 0 1rem;"><span aria-hidden="true"><?= $t['icon'] ?></span> <?= h(($is_new ? 'Нова секция: ' : 'Редактиране: ') . $t['label']) ?></h1>
  <?= hs_error_summary($form['type'], $errors) ?>
  <?php if (!empty($t['note'])): ?><p style="background:#f8fafc;border-left:4px solid var(--border);padding:.75rem 1rem;"><?= h($t['note']) ?></p><?php endif; ?>

  <?php if ($form['type'] === 'campaign'): ?>
    <p>Текстът, снимката и линкът на кампанията се редактират на отделна страница.</p>
    <?php if (feature_enabled('campaign')): ?>
      <p><a class="btn btn--primary" href="/admin/pages.php?page=home_campaign" style="min-height:44px;">Редактирай кампанията</a></p>
    <?php else: ?>
      <p><strong>Модулът „Кампания“ е изключен</strong>, затова тази секция не се показва на сайта, дори да е видима тук.</p>
    <?php endif; ?>
  <?php else: ?>
  <form id="hsForm" method="POST" action="/admin/home-sections.php" enctype="multipart/form-data" class="admin-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="rev" value="<?= (int) $rev ?>">
    <?php if ($is_new): ?><input type="hidden" name="type" value="<?= h($form['type']) ?>">
    <?php else: ?><input type="hidden" name="id" value="<?= h($form['id']) ?>"><?php endif; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
      <button type="submit" class="btn btn--primary" style="min-height:44px;">Запази</button>
      <a href="/admin/home-sections.php" class="btn btn--outline" style="min-height:44px;">Отказ</a>
    </div>
    <?php if ($form['type'] === 'video' && !empty($form['fields']['thumb'])): ?>
      <p style="margin:0 0 .5rem;font-weight:600;">Картинка на видеото сега:</p>
      <img src="<?= h($form['fields']['thumb']) ?>" alt="" style="max-width:240px;border-radius:4px;margin-bottom:1.5rem;display:block;">
    <?php endif; ?>
    <?php foreach ($t['fields'] as $key => $def): ?>
      <?= hs_field($key, $def, $form['fields'][$key] ?? null, $errors) ?>
    <?php endforeach; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);">
      <button type="submit" class="btn btn--primary" style="min-height:44px;">Запази</button>
      <a href="/admin/home-sections.php" class="btn btn--outline" style="min-height:44px;">Отказ</a>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($picker): ?>
  <!-- ══ TYPE PICKER ══ -->
  <a href="/admin/home-sections.php" style="display:inline-block;margin-bottom:.5rem;">← Назад към всички секции</a>
  <h1 style="margin:0 0 .5rem;">Добави секция</h1>
  <p style="margin:0 0 1.5rem;">Изберете вид. Новата секция ще се появи най-долу на страницата — после можете да я преместите.</p>
  <ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
    <?php foreach ($types as $key => $t): if ($t['builtin']) continue; ?>
      <li><a href="/admin/home-sections.php?add=<?= h($key) ?>" style="display:block;min-height:44px;padding:1rem 1.25rem;border:2px solid var(--border);border-radius:8px;text-decoration:none;color:inherit;">
        <span aria-hidden="true" style="font-size:1.5rem;"><?= $t['icon'] ?></span>
        <strong style="display:block;font-size:1.05rem;margin:.25rem 0;"><?= h($t['label']) ?></strong>
        <span style="color:var(--text-muted);"><?= h($t['desc']) ?></span>
      </a></li>
    <?php endforeach; ?>
  </ul>

<?php else: ?>
  <!-- ══ LIST ══ -->
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
    <h1 id="hsListTitle" tabindex="-1" style="margin:0;">Начална страница</h1>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <a href="/" target="_blank" rel="noopener" class="btn btn--outline" style="min-height:44px;">Виж началната страница<span style="<?= HS_SR ?>"> (отваря се в нов раздел)</span></a>
      <a href="/admin/home-sections.php?add=" class="btn btn--primary" style="min-height:44px;">+ Добави секция</a>
    </div>
  </div>
  <p style="margin:0 0 1.5rem;">Секциите се показват на сайта в този ред, отгоре надолу, на български и на английски.</p>
  <?php if ($loaded['corrupt']): ?>
    <div role="alert" style="border:2px solid #b45309;background:#fffbeb;color:#78350f;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;">
      <strong><span aria-hidden="true">⚠ </span>Файлът с подредбата на началната страница е повреден.</strong>
      Сайтът показва стандартната подредба. Първата промяна, която запазите тук, ще я замени.
    </div>
  <?php endif; ?>
  <ol id="list" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem;">
    <?php $n = count($doc['sections']); foreach ($doc['sections'] as $i => $s):
      $t = $types[$s['type']] ?? null; if ($t === null) continue;
      $name = home_section_name($s); $vis = !empty($s['visible']); ?>
    <li id="row-<?= h($s['id']) ?>" style="border:1px solid var(--border);border-radius:8px;padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;background:<?= $vis ? '#fff' : '#f3f4f6' ?>;">
      <div style="flex:1;min-width:220px;">
        <h2 id="h-<?= h($s['id']) ?>" tabindex="-1" style="font-size:1.05rem;margin:0;"><span aria-hidden="true"><?= $t['icon'] ?> </span><?= h($t['label']) ?></h2>
        <p style="margin:.25rem 0 0;color:var(--text-muted);"><?= h(home_section_preview($s)) ?></p>
        <p style="margin:.35rem 0 0;font-weight:600;color:<?= $vis ? '#166534' : '#4b5563' ?>;"><?= $vis ? '● Видима на сайта' : '○ Скрита' ?></p>
        <?php if ($s['type'] === 'campaign' && !feature_enabled('campaign')): ?>
          <p style="margin:.35rem 0 0;color:#4b5563;">Модулът „Кампания“ е изключен — секцията не се показва.</p>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <?= hs_action_form('move_up', $s, $rev, '↑ Нагоре', "Премести „{$name}“ нагоре", $i === 0, 'вече е най-горе') ?>
        <?= hs_action_form('move_down', $s, $rev, '↓ Надолу', "Премести „{$name}“ надолу", $i === $n - 1, 'вече е най-долу') ?>
        <a id="btn-edit-<?= h($s['id']) ?>" href="/admin/home-sections.php?edit=<?= h(rawurlencode($s['id'])) ?>" class="btn btn--outline" style="min-height:44px;" aria-label="<?= h("Редактирай „{$name}“") ?>">Редактирай</a>
        <?= hs_action_form('toggle', $s, $rev, $vis ? 'Скрий' : 'Покажи', ($vis ? 'Скрий' : 'Покажи') . " „{$name}“") ?>
        <?php if (!$t['builtin']): ?>
          <?= hs_action_form('duplicate', $s, $rev, 'Дублирай', "Дублирай „{$name}“") ?>
          <?= hs_action_form('delete', $s, $rev, 'Изтрий', "Изтрий „{$name}“", false, '', true) ?>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>

<script>
(function () {
  var live = document.getElementById('hsLive');
  function announce(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 100); }

  // After a list action: say what happened, and put focus back where the admin was.
  var flash = document.getElementById('hsFlash');
  if (flash) announce(flash.textContent.trim());
  var q = new URLSearchParams(location.search).get('focus');
  if (q) {
    var p = q.split(':'), el = document.getElementById('btn-' + p[0] + '-' + p[1]);
    if (!el || el.disabled) el = document.getElementById('h-' + p[1]) || document.getElementById('hsListTitle');
    if (el) el.focus();
  }
  var summary = document.getElementById('hsErrSummary');
  if (summary) summary.focus();

  // Images: library picker and "remove".
  document.addEventListener('click', function (e) {
    var box = e.target.closest('[data-hs-image]');
    if (!box) return;
    var path = box.querySelector('[data-hs-path]'), img = box.querySelector('[data-hs-preview]'), file = box.querySelector('input[type=file]');
    if (e.target.closest('[data-hs-pick]')) {
      openMediaPicker(function (p) { path.value = p; img.src = p; img.style.display = 'block'; file.value = ''; announce('Снимката е избрана.'); });
    }
    if (e.target.closest('[data-hs-clear]')) {
      path.value = ''; img.removeAttribute('src'); img.style.display = 'none'; file.value = ''; announce('Снимката е премахната.');
    }
  });

  // Cards: add, remove, reorder (2 to 4).
  var list = document.querySelector('[data-hs-cards]');
  if (list) {
    var tpl = document.getElementById('hsCardTpl'), add = document.querySelector('[data-hs-card-add]'), next = 100;
    function rows() { return list.querySelectorAll('[data-hs-card]'); }
    function renumber() {
      var r = rows();
      r.forEach(function (row, i) {
        var n = i + 1;
        row.querySelector('[data-hs-card-legend]').textContent = 'Карта ' + n;
        var up = row.querySelector('[data-hs-card-up]'), down = row.querySelector('[data-hs-card-down]'), rm = row.querySelector('[data-hs-card-remove]');
        up.setAttribute('aria-label', 'Премести карта ' + n + ' нагоре');
        down.setAttribute('aria-label', 'Премести карта ' + n + ' надолу');
        rm.setAttribute('aria-label', 'Премахни карта ' + n);
        up.disabled = i === 0;
        down.disabled = i === r.length - 1;
        rm.disabled = r.length <= 2;
      });
      add.disabled = r.length >= 4;
    }
    list.addEventListener('click', function (e) {
      var row = e.target.closest('[data-hs-card]');
      if (!row) return;
      if (e.target.closest('[data-hs-card-up]') && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling); renumber();
        (row.querySelector('[data-hs-card-up]:not(:disabled)') || row.querySelector('[data-hs-card-down]')).focus();
        announce('Картата е преместена нагоре.');
      } else if (e.target.closest('[data-hs-card-down]') && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row); renumber();
        (row.querySelector('[data-hs-card-down]:not(:disabled)') || row.querySelector('[data-hs-card-up]')).focus();
        announce('Картата е преместена надолу.');
      } else if (e.target.closest('[data-hs-card-remove]') && rows().length > 2) {
        var to = row.nextElementSibling || row.previousElementSibling;
        row.remove(); renumber();
        to.querySelector('input[type=text]').focus();
        announce('Картата е премахната.');
      }
    });
    add.addEventListener('click', function () {
      if (rows().length >= 4) return;
      list.insertAdjacentHTML('beforeend', tpl.innerHTML.split('__I__').join(String(next++)));
      renumber();
      list.lastElementChild.querySelector('input[type=text]').focus();
      announce('Добавена е нова карта.');
    });
    renumber();
  }

  if (window.tinymce && document.querySelector('textarea.hs-rich')) {
    tinymce.init(Object.assign({}, window._tinyBase, { selector: 'textarea.hs-rich', min_height: 220 }));
  }
})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
