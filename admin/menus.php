<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/menus.php';
$page_title_admin = 'Менюта';
$active_nav       = 'menus';

admin_require_login();
admin_require_admin();

$menus_file = CONTENT_PATH . '/menus.json';
$sections = [
    'header'      => 'Хедър навигация',
    'footer_nav'  => 'Футър — Навигация',
    'footer_help' => 'Футър — Как да помогна',
];
$success     = '';
$error       = '';
$form_rows   = []; // section → rows to show again after a refused save
$form_errors = []; // section → row index → field → message

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = (string) ($_POST['section'] ?? '');
    if (!csrf_verify()) {
        $error = 'Менюто не беше запазено — страницата е стояла отворена твърде дълго. Презаредете я и опитайте отново.';
    } elseif (!isset($sections[$section])) {
        $error = 'Невалиден раздел.';
    } else {
        $col = function (string $name): array {
            $v = $_POST[$name] ?? [];
            return is_array($v) ? array_values($v) : [];
        };
        $labels_bg = $col('label_bg');
        $urls_bg   = $col('url_bg');
        $labels_en = $col('label_en');
        $urls_en   = $col('url_en');
        $n = max(count($labels_bg), count($urls_bg), count($labels_en), count($urls_en));
        $posted = [];
        for ($i = 0; $i < min($n, 200); $i++) {
            $posted[] = [
                'label_bg' => $labels_bg[$i] ?? '',
                'url_bg'   => $urls_bg[$i]   ?? '',
                'label_en' => $labels_en[$i] ?? '',
                'url_en'   => $urls_en[$i]   ?? '',
            ];
        }

        $result = menu_section_from_rows($posted);
        if ($result['errors']) {
            $form_rows[$section]   = $result['rows'];
            $form_errors[$section] = $result['errors'];
            $error = '„' . $sections[$section] . '“ не е запазено — поправете отбелязаните редове по-долу.';
        } else {
            $menus = load_json($menus_file);
            $menus[$section] = $result['section'];
            if (save_json($menus_file, $menus)) {
                $success = '„' . $sections[$section] . '“ е запазено. Английското меню показва същите връзки в същия ред.';
            } else {
                $error = 'Менюто не беше запазено — файлът не може да се запише. Опитайте отново или се обърнете към поддръжката.';
            }
        }
    }
}

$menus = load_json($menus_file);

/** One editable pair: BG item and its EN twin. */
function menu_editor_row(string $uid, array $row, array $err = []): void
{
    $in  = 'width:100%;box-sizing:border-box;font-size:.9rem;padding:.5rem .65rem;min-height:2.6rem;';
    $lab = 'display:block;font-size:.8rem;font-weight:600;margin-bottom:.2rem;';
    $bad = 'border:2px solid #b91c1c;';
    $fe  = 'display:block;color:#b91c1c;font-size:.8rem;margin-top:.2rem;';
    $only_en  = $row['label_bg'] === '' && ($row['label_en'] !== '' || $row['url_en'] !== '');
    $untrans  = menu_label_untranslated($row['label_bg'], $row['label_en']);
    $describe = function (string $f) use ($uid, $err): string {
        return isset($err[$f]) ? ' aria-invalid="true" aria-describedby="err-' . $f . '-' . $uid . '"' : '';
    };
    ?>
    <li class="menu-pair" style="list-style:none;border:1px solid #cbd5e1;border-radius:8px;padding:.75rem;margin:0 0 .75rem;background:#fff;">
      <?php if ($only_en): ?>
      <p class="menu-pair-orphan" style="margin:0 0 .6rem;padding:.5rem .65rem;background:#fef3c7;border:1px solid #b45309;border-radius:6px;font-size:.85rem;color:#78350f;">
        ⚠ Тази връзка е само в английското меню. Попълнете текста и адреса на български или премахнете реда.
      </p>
      <?php endif; ?>
      <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-start;">
        <div style="flex:1 1 18rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.5rem;">
          <div>
            <label for="ml-bg-<?= $uid ?>" style="<?= $lab ?>">Текст (BG)</label>
            <input type="text" id="ml-bg-<?= $uid ?>" name="label_bg[]" value="<?= h($row['label_bg']) ?>" class="menu-label-bg" maxlength="300" aria-required="true"<?= $describe('label_bg') ?>
                   style="<?= $in ?><?= isset($err['label_bg']) ? $bad : '' ?>">
            <?php if (isset($err['label_bg'])): ?><span id="err-label_bg-<?= $uid ?>" style="<?= $fe ?>">⚠ <?= h($err['label_bg']) ?></span><?php endif; ?>
          </div>
          <div>
            <label for="mu-bg-<?= $uid ?>" style="<?= $lab ?>">Адрес (BG)</label>
            <input type="text" id="mu-bg-<?= $uid ?>" name="url_bg[]" value="<?= h($row['url_bg']) ?>" class="menu-url-bg" maxlength="300" placeholder="/kontakti/" aria-required="true"<?= $describe('url_bg') ?>
                   style="<?= $in ?><?= isset($err['url_bg']) ? $bad : '' ?>">
            <?php if (isset($err['url_bg'])): ?><span id="err-url_bg-<?= $uid ?>" style="<?= $fe ?>">⚠ <?= h($err['url_bg']) ?></span><?php endif; ?>
          </div>
        </div>
        <div style="flex:1 1 18rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.5rem;">
          <div>
            <label for="ml-en-<?= $uid ?>" style="<?= $lab ?>">Текст (EN)</label>
            <input type="text" id="ml-en-<?= $uid ?>" name="label_en[]" value="<?= h($row['label_en']) ?>" class="menu-label-en" maxlength="300" data-translate-from="ml-bg-<?= $uid ?>" aria-describedby="note-<?= $uid ?>"
                   style="<?= $in ?>">
            <span id="note-<?= $uid ?>" class="menu-untranslated" style="<?= $untrans ? 'display:block;' : 'display:none;' ?>color:#92400e;font-size:.8rem;margin-top:.2rem;"><?= $untrans ? '⚠ Не е преведено — проверете английския текст' : '' ?></span>
          </div>
          <div>
            <label for="mu-en-<?= $uid ?>" style="<?= $lab ?>">Адрес (EN)</label>
            <input type="text" id="mu-en-<?= $uid ?>" name="url_en[]" value="<?= h($row['url_en']) ?>" class="menu-url-en" maxlength="300"<?= $describe('url_en') ?>
                   style="<?= $in ?><?= isset($err['url_en']) ? $bad : '' ?>">
            <?php if (isset($err['url_en'])): ?><span id="err-url_en-<?= $uid ?>" style="<?= $fe ?>">⚠ <?= h($err['url_en']) ?></span><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:.35rem;align-self:center;">
          <button type="button" class="btn btn--outline menu-up"     aria-label="Премести нагоре"     style="min-width:2.75rem;min-height:2.75rem;padding:.3rem;">↑</button>
          <button type="button" class="btn btn--outline menu-down"   aria-label="Премести надолу"     style="min-width:2.75rem;min-height:2.75rem;padding:.3rem;">↓</button>
          <button type="button" class="btn btn--outline menu-remove" aria-label="Премахни реда (БГ и EN)" style="min-width:2.75rem;min-height:2.75rem;padding:.3rem;">✕</button>
        </div>
      </div>
    </li>
    <?php
}

require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-header.php';
?>

<?php if ($success): ?>
  <div class="admin-alert admin-alert--success" role="status" style="margin-bottom:1.5rem;">✓ <?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="admin-alert admin-alert--error" role="alert" style="margin-bottom:1.5rem;">⚠ <?= h($error) ?></div>
<?php endif; ?>

<h1 style="margin-bottom:.5rem;">Менюта</h1>
<p style="color:var(--text-muted);margin-bottom:.5rem;font-size:.9rem;">
  Редактирайте навигационните връзки в хедъра и футъра. Магазин, количка и превключвателят на езика се добавят автоматично.
</p>
<p style="color:var(--text-muted);margin-bottom:2rem;font-size:.9rem;">
  Английското меню винаги повтаря българското — същите връзки, в същия ред. Попълнете текста и адреса на български;
  английският текст и адрес се попълват сами (можете да ги промените).
</p>

<div id="menu-live" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"></div>

<?php
$uid_n = 0;
foreach ($sections as $key => $title):
    $rows   = $form_rows[$key] ?? menu_rows_from_section($menus[$key] ?? []);
    $errors = $form_errors[$key] ?? [];
?>

<h2 id="h-<?= h($key) ?>" style="margin-bottom:1rem;"><?= h($title) ?></h2>
<form method="POST" action="/admin/menus.php" class="admin-form menu-form" style="margin-bottom:3rem;">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="<?= h($key) ?>">

  <ol class="menu-pairs" aria-labelledby="h-<?= h($key) ?>" style="padding:0;margin:0;">
    <?php foreach ($rows as $i => $row) menu_editor_row('r' . (++$uid_n), $row, $errors[$i] ?? []); ?>
  </ol>
  <button type="button" class="btn btn--outline menu-add" style="font-size:.85rem;margin-top:.25rem;min-height:2.75rem;">+ Добавяне на връзка</button>

  <div style="margin-top:1.25rem;">
    <button type="submit" class="btn btn--primary" style="white-space:normal;text-align:left;">Запази — <?= h($title) ?></button>
  </div>
</form>

<?php endforeach; ?>

<template id="menu-row-tpl"><?php menu_editor_row('__UID__', ['label_bg' => '', 'url_bg' => '', 'label_en' => '', 'url_en' => '']); ?></template>

<script>
(function () {
  var MAP   = <?= json_encode(path_map_bg_to_en(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var KNOWN = <?= json_encode((object) menu_known_labels(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var live  = document.getElementById('menu-live');
  var seq   = 0;

  function say(text) { live.textContent = ''; setTimeout(function () { live.textContent = text; }, 50); }

  /* Same rules as menu_en_url() in includes/menus.php. */
  function enUrl(url) {
    url = (url || '').trim();
    if (url === '' || url[0] !== '/' || url.indexOf('//') === 0) return url;
    var cut = url.search(/[?#]/); if (cut < 0) cut = url.length;
    var path = url.slice(0, cut), tail = url.slice(cut);
    if (path.replace(/\/+$/, '') === '') return '/en/' + tail;
    if (path === '/en' || path.indexOf('/en/') === 0) return url;
    var p = path.replace(/\/+$/, ''), trailing = /\/$/.test(path) ? '/' : '';
    for (var bg in MAP) {
      var b = bg.replace(/\/+$/, '');
      if (b === '') continue;
      if (p === b || p.indexOf(b + '/') === 0) return MAP[bg] + p.slice(b.length) + trailing + tail;
    }
    return url;
  }
  function untranslated(bg, en) { return en !== '' && en.trim() === bg.trim() && /[Ѐ-ӿ]/.test(en); }

  function parts(row) {
    return {
      lbg: row.querySelector('.menu-label-bg'), ubg: row.querySelector('.menu-url-bg'),
      len: row.querySelector('.menu-label-en'), uen: row.querySelector('.menu-url-en'),
      note: row.querySelector('.menu-untranslated')
    };
  }
  function showNote(p) {
    var on = untranslated(p.lbg.value, p.len.value);
    p.note.textContent = on ? '⚠ Не е преведено — проверете английския текст' : '';
    p.note.style.display = on ? 'block' : 'none';
  }

  async function fillEnLabel(row) {
    var p = parts(row), bg = p.lbg.value.trim();
    if (p.len.dataset.auto !== '1' || bg === '') return;
    var known = KNOWN[bg.toLowerCase()];
    var en = known || '';
    if (!en) {
      try {
        var res = await fetch('/admin/translate-ajax.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({text: bg, is_html: false, csrf_token: window._csrfToken})
        });
        var data = await res.json();
        if (data.ok && data.translated) en = String(data.translated).trim();
      } catch (e) {}
    }
    // The admin may have typed in the EN field (or changed the BG one) meanwhile.
    if (p.len.dataset.auto !== '1' || p.lbg.value.trim() !== bg) return;
    p.len.value = en || bg;
    showNote(p);
    say(en ? 'Текстът на английски е попълнен: ' + en
           : 'Текстът не можа да се преведе — на английски засега стои „' + bg + '“. Проверете го.');
  }

  function wire(row) {
    if (row._wired) return; row._wired = true;
    var p = parts(row);
    // EN fields the admin has not set themselves follow the BG side.
    p.len.dataset.auto = (p.len.value === '' || p.len.value === p.lbg.value) ? '1' : '0';
    p.uen.dataset.auto = (p.uen.value === '' || p.uen.value === enUrl(p.ubg.value)) ? '1' : '0';

    p.lbg.addEventListener('change', function () { fillEnLabel(row); });
    p.lbg.addEventListener('input', function () {
      showNote(p);
      var warn = row.querySelector('.menu-pair-orphan');
      if (warn && p.lbg.value.trim() !== '') warn.style.display = 'none';
    });
    p.ubg.addEventListener('input', function () {
      if (p.uen.dataset.auto === '1') p.uen.value = enUrl(p.ubg.value);
    });
    p.ubg.addEventListener('change', function () {
      if (p.uen.dataset.auto === '1' && p.uen.value) say('Адресът на английски е попълнен: ' + p.uen.value);
    });
    p.len.addEventListener('input', function () { p.len.dataset.auto = p.len.value === '' ? '1' : '0'; showNote(p); });
    p.uen.addEventListener('input', function () { p.uen.dataset.auto = p.uen.value === '' ? '1' : '0'; });

    row.querySelector('.menu-up').addEventListener('click', function () { move(row, -1, this); });
    row.querySelector('.menu-down').addEventListener('click', function () { move(row, 1, this); });
    row.querySelector('.menu-remove').addEventListener('click', function () { remove(row, this); });
  }

  function position(row) { return Array.prototype.indexOf.call(row.parentNode.children, row) + 1; }

  function move(row, dir, btn) {
    var list = row.parentNode;
    var other = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!other) { say(dir < 0 ? 'Връзката вече е първа.' : 'Връзката вече е последна.'); return; }
    if (dir < 0) list.insertBefore(row, other); else list.insertBefore(other, row);
    btn.focus();
    say('Връзката е преместена на място ' + position(row) + ' от ' + list.children.length + ' (и в двете менюта).');
  }

  async function remove(row, btn) {
    var name = parts(row).lbg.value.trim() || parts(row).len.value.trim() || 'празен ред';
    var ok = await window._adminConfirm('Да премахна ли „' + name + '“ от българското и от английското меню? Промяната се записва с бутона „Запази“.', 'Премахни');
    if (!ok) { btn.focus(); return; }
    var next = row.nextElementSibling || row.previousElementSibling;
    var form = row.closest('form');
    row.remove();
    (next ? next.querySelector('input[type=text]') : form.querySelector('.menu-add')).focus();
    say('„' + name + '“ е премахнато. Натиснете „Запази“, за да остане така.');
  }

  var tpl = document.getElementById('menu-row-tpl');
  document.querySelectorAll('.menu-form').forEach(function (form) {
    form.querySelectorAll('.menu-pair').forEach(wire);
    form.querySelector('.menu-add').addEventListener('click', function () {
      var html = tpl.innerHTML.replace(/__UID__/g, 'n' + (++seq));
      var holder = document.createElement('ol');
      holder.innerHTML = html;
      var row = holder.querySelector('.menu-pair');
      form.querySelector('.menu-pairs').appendChild(row);
      wire(row);
      row.querySelector('.menu-label-bg').focus();
      say('Добавен е нов ред. Попълнете текста и адреса на български — английските се попълват сами.');
    });
  });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
