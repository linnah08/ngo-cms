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
    // Wrong types (e.g. action[]=x) count as missing — never "Array to string conversion".
    $post_str = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $action   = $post_str('action');
    $post_rev = is_string($_POST['rev'] ?? null) && preg_match('/^\d{1,9}$/', $_POST['rev']) ? (int) $_POST['rev'] : -1;
    $post_id  = $post_str('id');

    if ($action === 'reorder') {   // sent by the drag handles, answers in JSON
        header('Content-Type: application/json; charset=UTF-8');
        $order = is_array($_POST['order'] ?? null) ? $_POST['order'] : [];
        $r = home_apply_reorder($doc, $order, $post_id);
        $new_rev = $post_rev;
        if ($r['ok']) {
            $saved = home_save($r['doc'], $post_rev);
            if ($saved['ok']) $new_rev = (int) $saved['doc']['rev'];
            else $r = ['ok' => false, 'message' => home_save_error_message($saved['error'])];
        }
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'rev' => $new_rev], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

    $post = ['id' => $post_id, 'type' => $post_str('type'), 'rev' => $post_rev, 'f' => is_array($_POST['f'] ?? null) ? $_POST['f'] : []];
    $r = home_admin_save($doc, $post, $_FILES);
    switch ($r['status']) {
        case 'bad_type':
            http_response_code(400); exit('Непознат вид секция.');
        case 'not_found':
            flash_set('error', $r['message']);
            header('Location: /admin/home-sections.php'); exit;
        case 'saved':
            flash_set('success', $r['message']);
            header('Location: /admin/home-sections.php?focus=' . rawurlencode('edit:' . $r['sid'])); exit;
        default:   // 'invalid'
            $errors = $r['errors'];
            $form   = $r['form'];
            // The revision the admin started from — or, after a conflict, the current one,
            // so the typed values (kept in the form) can be saved with one more click.
            $rev    = (int) ($r['rev'] ?? $post_rev);
    }
} elseif (isset($_GET['edit'])) {
    $idx = is_string($_GET['edit']) ? home_find($doc, $_GET['edit']) : null;
    if ($idx === null || !isset($types[$doc['sections'][$idx]['type']])) {
        flash_set('error', 'Секцията не е намерена.');
        header('Location: /admin/home-sections.php'); exit;
    }
    $form = $doc['sections'][$idx];
} elseif (isset($_GET['add']) && $_GET['add'] !== '') {
    $type = is_string($_GET['add']) ? $_GET['add'] : '';
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
  <p style="margin:0 0 .5rem;">Секциите се показват на сайта в този ред, отгоре надолу, на български и на английски.</p>
  <?= hs_sort_help('hsSortHelp', 'секциите') ?>
  <div id="hsSortMsg" hidden style="border:2px solid #15803d;background:#f0fdf4;color:#14532d;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;font-weight:600;"></div>
  <?php if ($loaded['corrupt']): ?>
    <div role="alert" style="border:2px solid #b45309;background:#fffbeb;color:#78350f;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.25rem;">
      <strong><span aria-hidden="true">⚠ </span>Файлът с подредбата на началната страница е повреден.</strong>
      Сайтът показва стандартната подредба. Първата промяна, която запазите тук, ще я замени
      (повреденият файл се пази като резервно копие на сървъра).
    </div>
  <?php endif; ?>
  <ol id="list" data-rev="<?= (int) $rev ?>" data-csrf="<?= h(csrf_token()) ?>" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem;">
    <?php $n = count($doc['sections']); foreach ($doc['sections'] as $i => $s):
      $t = $types[$s['type']] ?? null; if ($t === null) continue;
      $name = home_section_name($s); $vis = !empty($s['visible']); ?>
    <li id="row-<?= h($s['id']) ?>" data-id="<?= h($s['id']) ?>" data-name="<?= h($name) ?>" style="border:1px solid var(--border);border-radius:8px;padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;background:<?= $vis ? '#fff' : '#f3f4f6' ?>;">
      <?= hs_sort_handle("Премести „{$name}“ (място " . ($i + 1) . " от {$n})", 'hsSortHelp') ?>
      <div style="flex:1;min-width:220px;">
        <h2 id="h-<?= h($s['id']) ?>" tabindex="-1" style="font-size:1.05rem;margin:0;"><span aria-hidden="true"><?= $t['icon'] ?> </span><?= h($t['label']) ?></h2>
        <p style="margin:.25rem 0 0;color:var(--text-muted);"><?= h(home_section_preview($s)) ?></p>
        <p style="margin:.35rem 0 0;font-weight:600;color:<?= $vis ? '#166534' : '#4b5563' ?>;"><?= $vis ? '● Видима на сайта' : '○ Скрита' ?></p>
        <?php if ($s['type'] === 'campaign' && !feature_enabled('campaign')): ?>
          <p style="margin:.35rem 0 0;color:#4b5563;">Модулът „Кампания“ е изключен — секцията не се показва.</p>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <a id="btn-edit-<?= h($s['id']) ?>" href="/admin/home-sections.php?edit=<?= h(rawurlencode($s['id'])) ?>" class="btn btn--outline" style="min-height:44px;" aria-label="<?= h("Редактирай „{$name}“") ?>">Редактирай</a>
        <?= hs_action_form('toggle', $s, $rev, $vis ? 'Скрий' : 'Покажи', ($vis ? 'Скрий' : 'Покажи') . " „{$name}“") ?>
        <?php if (!$t['builtin']): ?>
          <?= hs_action_form('duplicate', $s, $rev, 'Дублирай', "Дублирай „{$name}“") ?>
          <?= hs_action_form('delete', $s, $rev, 'Изтрий', "Изтрий „{$name}“", true) ?>
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

  // A newly chosen file shows in the preview straight away. The image cropper may swap
  // the file and fire "change" again — each change simply shows the latest file.
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input.matches || !input.matches('[data-hs-image] input[type=file]')) return;
    var img = input.closest('[data-hs-image]').querySelector('[data-hs-preview]');
    if (!img || !input.files || !input.files[0] || !window.URL || !URL.createObjectURL) return;
    if (img.dataset.hsObjectUrl) URL.revokeObjectURL(img.dataset.hsObjectUrl);
    img.dataset.hsObjectUrl = URL.createObjectURL(input.files[0]);
    img.src = img.dataset.hsObjectUrl;
    img.style.display = 'block';
  });

  // Drag-to-reorder. The grip ([data-hs-sort-handle]) works three ways: drag it with a
  // mouse or a finger; or focus it, press Space/Enter, move with ↑ ↓ and press Space/Enter
  // again (Esc puts the row back). o.label(row, pos, total) names each grip,
  // o.done(row, before) runs after a move that changed the order.
  function sortable(list, o) {
    function items() { return Array.prototype.filter.call(list.children, function (el) { return el.matches(o.item); }); }
    function grip(row) { return row.querySelector('[data-hs-sort-handle]'); }
    function relabel() {
      var r = items();
      r.forEach(function (row, i) { grip(row).setAttribute('aria-label', o.label(row, i + 1, r.length)); });
    }
    function lift(row, on) {
      row.style.outline = on ? '3px dashed #0f766e' : '';
      row.style.outlineOffset = on ? '2px' : '';
      row.style.boxShadow = on ? '0 6px 18px rgba(0,0,0,.18)' : '';
      grip(row).style.cursor = on ? 'grabbing' : 'grab';
    }
    function restore(before) { before.forEach(function (row) { list.appendChild(row); }); relabel(); }
    function changed(before) { return items().some(function (row, i) { return row !== before[i]; }); }
    function where(row) { var r = items(); return 'Място ' + (r.indexOf(row) + 1) + ' от ' + r.length + '.'; }

    // Keyboard. Rows around the held one are moved, not the held one itself, so focus stays put.
    var held = null, heldBefore = null;
    function pick(row) {
      held = row; heldBefore = items();
      grip(row).setAttribute('aria-pressed', 'true'); lift(row, true);
      announce(o.name(row) + ' — хваната. ' + where(row) + ' Преместете със стрелките, пуснете с интервал.');
    }
    function release(cancel) {
      if (!held) return;
      var row = held, before = heldBefore;
      held = heldBefore = null;
      grip(row).setAttribute('aria-pressed', 'false'); lift(row, false);
      if (cancel) { restore(before); announce('Преместването е отказано.'); return; }
      relabel();
      if (changed(before)) o.done(row, before); else announce('Редът не е променен.');
    }
    list.addEventListener('keydown', function (e) {
      var g = e.target.closest('[data-hs-sort-handle]');
      if (!g || !list.contains(g)) return;
      var row = g.closest(o.item);
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        if (held === row) release(false); else { release(false); pick(row); }
      } else if (held === row && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        var prev = row.previousElementSibling, next = row.nextElementSibling;
        if (e.key === 'ArrowUp' && prev) list.insertBefore(prev, row.nextElementSibling);
        else if (e.key === 'ArrowDown' && next) list.insertBefore(next, row);
        else { announce(where(row) + (e.key === 'ArrowUp' ? ' Вече е най-горе.' : ' Вече е най-долу.')); return; }
        relabel();
        announce(where(row));
      } else if (held === row && e.key === 'Escape') {
        e.preventDefault();
        release(true);
      }
    });
    list.addEventListener('focusout', function (e) {
      if (held && e.target === grip(held)) release(false);
    });

    // Mouse and touch: the row follows the pointer through the list. Moves and the release
    // are heard on the whole document — moving the row in the page can drop pointer capture.
    var drag = null;
    list.addEventListener('pointerdown', function (e) {
      var g = e.target.closest('[data-hs-sort-handle]');
      if (!g || !list.contains(g) || e.button !== 0) return;
      release(false);
      e.preventDefault();
      g.focus();
      g.setPointerCapture(e.pointerId);
      drag = { row: g.closest(o.item), id: e.pointerId, y: e.clientY, before: items(), moving: false };
    });
    document.addEventListener('pointermove', function (e) {
      if (!drag || e.pointerId !== drag.id) return;
      if (!drag.moving) {
        if (Math.abs(e.clientY - drag.y) < 5) return;
        drag.moving = true; lift(drag.row, true);
      }
      // Drop before the first other row whose middle is below the pointer.
      var ref = null;
      items().some(function (row) {
        if (row === drag.row) return false;
        var b = row.getBoundingClientRect();
        if (e.clientY < b.top + b.height / 2) { ref = row; return true; }
        return false;
      });
      if (ref !== drag.row.nextElementSibling && ref !== drag.row) {
        if (ref) list.insertBefore(drag.row, ref); else list.appendChild(drag.row);
      }
      // Near the top or bottom of the window, scroll so rows out of view can be reached.
      var edge = 60;
      if (e.clientY < edge) window.scrollBy(0, -12);
      else if (e.clientY > window.innerHeight - edge) window.scrollBy(0, 12);
    });
    function end(e, cancel) {
      if (!drag || e.pointerId !== drag.id) return;
      var d = drag; drag = null;
      if (!d.moving) return;
      lift(d.row, false);
      var g = grip(d.row);
      if (g.hasPointerCapture && g.hasPointerCapture(e.pointerId)) g.releasePointerCapture(e.pointerId);
      g.focus();
      if (cancel) { restore(d.before); return; }
      relabel();
      if (changed(d.before)) o.done(d.row, d.before);
    }
    document.addEventListener('pointerup', function (e) { end(e, false); });
    document.addEventListener('pointercancel', function (e) { end(e, true); });

    relabel();
    return { restore: restore, relabel: relabel };
  }

  // The section list: every drop is saved straight away.
  var sections = document.getElementById('list');
  if (sections) {
    var msg = document.getElementById('hsSortMsg'), saving = false;
    function showMsg(text, ok) {
      msg.hidden = false;
      msg.textContent = (ok ? '✓ ' : '⚠ ') + text;
      msg.setAttribute('role', ok ? 'status' : 'alert');
      msg.style.borderColor = ok ? '#15803d' : '#b91c1c';
      msg.style.background  = ok ? '#f0fdf4' : '#fef2f2';
      msg.style.color       = ok ? '#14532d' : '#7f1d1d';
      if (flash) flash.hidden = true;
      // The box sits above the list; the admin may have dropped a row far below it.
      if (!ok) msg.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    var list_ = sortable(sections, {
      item: 'li[data-id]',
      name: function (row) { return '\u201E' + row.dataset.name + '\u201C'; },
      label: function (row, pos, total) { return 'Премести \u201E' + row.dataset.name + '\u201C (място ' + pos + ' от ' + total + ')'; },
      done: function (row, before) {
        if (saving) { list_.restore(before); showMsg('Предишното преместване още се запазва. Опитайте отново след секунда.', false); return; }
        saving = true;
        var body = new FormData();
        body.append('csrf_token', sections.dataset.csrf);
        body.append('action', 'reorder');
        body.append('rev', sections.dataset.rev);
        body.append('id', row.dataset.id);
        Array.prototype.forEach.call(sections.querySelectorAll('li[data-id]'), function (li) { body.append('order[]', li.dataset.id); });
        var fail = 'Новият ред не можа да се запази. Презаредете страницата и опитайте отново.';
        function failed(text) { list_.restore(before); showMsg(text || fail, false); }
        fetch('/admin/home-sections.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return null; }); })   // e.g. a login page after the session ran out
          .then(function (d) {
            if (!d || !d.ok) { failed(d && d.message); return; }
            // Every other button on the page must now send the new revision.
            sections.dataset.rev = String(d.rev);
            Array.prototype.forEach.call(sections.querySelectorAll('input[name="rev"]'), function (i) { i.value = String(d.rev); });
            showMsg(d.message, true);
          }, function () { failed('Няма връзка със сървъра. Проверете интернета и опитайте отново.'); })
          .then(function () { saving = false; });
      }
    });
  }

  // Cards: add, remove, reorder (2 to 4). The order is saved with the form.
  var list = document.querySelector('[data-hs-cards]');
  if (list) {
    var tpl = document.getElementById('hsCardTpl'), add = document.querySelector('[data-hs-card-add]'), next = 100;
    function rows() { return list.querySelectorAll('[data-hs-card]'); }
    function renumber() {
      var r = rows();
      r.forEach(function (row, i) {
        var n = i + 1, rm = row.querySelector('[data-hs-card-remove]');
        row.querySelector('[data-hs-card-legend]').textContent = 'Карта ' + n;
        rm.setAttribute('aria-label', 'Премахни карта ' + n);
        rm.disabled = r.length <= 2;
      });
      add.disabled = r.length >= 4;
    }
    var cards = sortable(list, {
      item: '[data-hs-card]',
      name: function (row) { return row.querySelector('[data-hs-card-legend]').textContent; },
      label: function (row, pos) { return 'Премести карта ' + pos; },
      done: function (row) { renumber(); announce('Картата е на място ' + (Array.prototype.indexOf.call(rows(), row) + 1) + '. Редът се запазва с бутона „Запази“.'); }
    });
    list.addEventListener('click', function (e) {
      var row = e.target.closest('[data-hs-card]');
      if (!row || !e.target.closest('[data-hs-card-remove]') || rows().length <= 2) return;
      var to = row.nextElementSibling || row.previousElementSibling;
      row.remove(); renumber(); cards.relabel();
      to.querySelector('input[type=text]').focus();
      announce('Картата е премахната.');
    });
    add.addEventListener('click', function () {
      if (rows().length >= 4) return;
      list.insertAdjacentHTML('beforeend', tpl.innerHTML.split('__I__').join(String(next++)));
      renumber(); cards.relabel();
      list.lastElementChild.querySelector('input[name$="[title][bg]"]').focus();
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
