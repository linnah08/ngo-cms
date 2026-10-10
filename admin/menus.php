<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/menus.php';
$page_title_admin = 'Менюта';
$active_nav       = 'menus';

admin_require_login();
admin_require_admin();

$menus_file = cpage_menus_file();
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
        $picks     = $col('pick_bg');
        $urls_bg   = $col('url_bg');
        $labels_en = $col('label_en');
        $urls_en   = $col('url_en');
        $n = max(count($labels_bg), count($urls_bg), count($labels_en), count($urls_en));
        $posted = [];
        for ($i = 0; $i < min($n, 200); $i++) {
            $posted[] = [
                'label_bg' => $labels_bg[$i] ?? '',
                // The page picked in the row, or the typed address ("Външна връзка", or no JavaScript).
                'url_bg'   => menu_resolve_pick($picks[$i] ?? '', $urls_bg[$i] ?? ''),
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

/** main.css takes every select's arrow away; the picker must look like a list you can open. */
const MENU_SELECT_ARROW = 'padding-right:2.2rem;cursor:pointer;background-repeat:no-repeat;background-position:right .7rem center;background-size:.8rem;'
    . "background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 12 8%27%3E%3Cpath d=%27M1 1l5 5 5-5%27 fill=%27none%27 stroke=%27%23334155%27 stroke-width=%272%27/%3E%3C/svg%3E');";

/** The page picker's <option>s; $value is selected. Each option carries its EN address and names. */
function menu_pick_options_html(array $opts, string $value): string
{
    $o = function (string $v, string $text, array $data = []) use ($value): string {
        $attrs = '';
        foreach ($data as $k => $d) $attrs .= ' data-' . $k . '="' . h($d) . '"';
        return '<option value="' . h($v) . '"' . $attrs . ($v === $value ? ' selected' : '') . '>' . h($text) . '</option>';
    };
    $html = $o('', '— Изберете страница —');
    $html .= '<optgroup label="Страници на сайта">';
    foreach ($opts['builtin'] as $url => $name) {
        $html .= $o($url, $name, ['en' => menu_en_url($url), 'name' => $name, 'name-en' => menu_known_label_en($name) ?? '']);
    }
    $html .= '</optgroup><optgroup label="Ваши страници" class="menu-pick-created">';
    foreach ($opts['created'] as $url => $p) {
        $draft = $p['status'] !== 'published';
        $html .= $o($url, $p['title_bg'] . ($draft ? ' (чернова)' : ''),
            ['en' => cpage_url($p, 'en'), 'name' => $p['title_bg'], 'name-en' => $p['title_en'], 'draft' => $draft ? '1' : '',
             'edit' => '/admin/page-edit.php?id=' . $p['id']]);
    }
    $html .= '</optgroup>';
    return $html . $o('__custom__', 'Външна връзка…');
}

/** One editable pair: BG item and its EN twin. */
function menu_editor_row(string $uid, array $row, array $err = [], ?array $opts = null): void
{
    $opts ??= menu_pick_options();
    $in  = 'width:100%;box-sizing:border-box;font-size:.9rem;padding:.5rem .65rem;min-height:2.75rem;';
    $lab = 'display:block;font-size:.8rem;font-weight:600;margin-bottom:.2rem;';
    $bad = 'border:2px solid #b91c1c;';
    $fe  = 'display:block;color:#b91c1c;font-size:.8rem;margin-top:.2rem;';
    $only_en  = $row['label_bg'] === '' && ($row['label_en'] !== '' || $row['url_en'] !== '');
    $untrans  = menu_label_untranslated($row['label_bg'], $row['label_en']);
    $pick     = menu_pick_value($row['url_bg'], $opts);
    if (isset($err['url_bg']) && $row['url_bg'] !== '') $pick = '__custom__';   // show what was typed, with its error
    $state    = $pick === '__custom__' ? menu_link_state($row['url_bg']) : ['state' => 'ok', 'page' => null];
    $picked   = $opts['created'][$pick] ?? null;
    $is_draft = $picked !== null && $picked['status'] !== 'published';
    $gone     = in_array($state['state'], ['missing', 'deleted'], true);
    $describe = function (string $f, string $more = '') use ($uid, $err): string {
        $ids = trim((isset($err[$f]) ? 'err-' . $f . '-' . $uid : '') . ' ' . $more);
        return (isset($err[$f]) ? ' aria-invalid="true"' : '') . ($ids !== '' ? ' aria-describedby="' . $ids . '"' : '');
    };
    $btn = 'min-height:2.75rem;font-size:.85rem;padding:.35rem .75rem;';
    ?>
    <li class="menu-pair" style="list-style:none;border:1px solid #cbd5e1;border-radius:8px;padding:.75rem;margin:0 0 .75rem;background:#fff;">
      <?php if ($only_en): ?>
      <p class="menu-pair-orphan" style="margin:0 0 .6rem;padding:.5rem .65rem;background:#fef3c7;border:1px solid #b45309;border-radius:6px;font-size:.85rem;color:#78350f;">
        ⚠ Тази връзка е само в английското меню. Попълнете текста и адреса на български или премахнете реда.
      </p>
      <?php endif; ?>
      <?php $off_module = module_for_path($row['url_bg']) ?? module_for_path($row['url_en']);
            if ($off_module !== null && module_enabled_with_needs($off_module)) $off_module = null; ?>
      <?php if ($off_module !== null): ?>
      <p class="menu-pair-module-off" style="margin:0 0 .6rem;padding:.5rem .65rem;background:#f1f5f9;border:1px solid #64748b;border-radius:6px;font-size:.85rem;color:#334155;">
        Скрита от сайта: води към модул „<?= h(module_label($off_module)) ?>“, който е изключен. Ще се покаже отново, когато го включите в „Модули“.
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
            <label for="mp-bg-<?= $uid ?>" style="<?= $lab ?>">Води към (BG)</label>
            <select id="mp-bg-<?= $uid ?>" name="pick_bg[]" class="menu-pick" aria-describedby="ms-<?= $uid ?>" style="<?= $in ?><?= MENU_SELECT_ARROW ?>">
              <?= menu_pick_options_html($opts, $pick) ?>
            </select>
          </div>
          <div class="menu-url-wrap" style="grid-column:1/-1;"<?= $pick === '__custom__' || isset($err['url_bg']) ? '' : ' data-js-hide="1"' ?>>
            <label for="mu-bg-<?= $uid ?>" style="<?= $lab ?>">Адрес (BG)</label>
            <input type="text" id="mu-bg-<?= $uid ?>" name="url_bg[]" value="<?= h($row['url_bg']) ?>" class="menu-url-bg" maxlength="300" aria-required="true"<?= $describe('url_bg', 'mh-' . $uid) ?>
                   style="<?= $in ?><?= isset($err['url_bg']) ? $bad : '' ?>">
            <span id="mh-<?= $uid ?>" style="display:block;font-size:.78rem;color:#475569;margin-top:.2rem;">Например https://example.org, mailto:info@example.org, tel:+359888123456, #дарение или адрес от сайта като /novini/</span>
            <?php if (isset($err['url_bg'])): ?><span id="err-url_bg-<?= $uid ?>" style="<?= $fe ?>">⚠ <?= h($err['url_bg']) ?></span><?php endif; ?>
          </div>
          <div id="ms-<?= $uid ?>" class="menu-pick-status" style="grid-column:1/-1;font-size:.85rem;">
            <p class="menu-st-draft" style="margin:0;padding:.45rem .65rem;background:#fef3c7;border:1px solid #b45309;border-radius:6px;color:#78350f;"<?= $is_draft ? '' : ' hidden' ?>>
              <span aria-hidden="true">✎ </span>Тази страница е чернова — посетителите не виждат връзката, докато не я публикувате.
              <a class="menu-st-edit" href="<?= h($picked ? '/admin/page-edit.php?id=' . $picked['id'] : '#') ?>" target="_blank" rel="noopener" style="color:#78350f;font-weight:600;">Отвори страницата<span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"> (в нов раздел)</span></a>
            </p>
            <div class="menu-st-gone" style="margin:0;padding:.45rem .65rem;background:#fef2f2;border:1px solid #b91c1c;border-radius:6px;color:#7f1d1d;"<?= $gone ? '' : ' hidden' ?>>
              <p style="margin:0 0 .4rem;"><span aria-hidden="true">⚠ </span><strong>Тази страница не съществува.</strong>
                <?= $state['state'] === 'deleted' ? 'Тя е изтрита и връзката не се показва на сайта.' : 'Ако адресът не се обслужва по друг начин, посетителите ще видят „Страницата не е намерена“.' ?></p>
              <button type="button" class="btn btn--outline menu-create-missing" style="<?= $btn ?>">Създай страницата</button>
            </div>
          </div>
          <div style="grid-column:1/-1;">
            <button type="button" class="btn btn--outline menu-new-toggle" aria-expanded="false" aria-controls="mn-<?= $uid ?>" style="<?= $btn ?>">+ Нова страница</button>
            <div id="mn-<?= $uid ?>" class="menu-new" hidden style="margin-top:.5rem;padding:.65rem;border:1px dashed #64748b;border-radius:6px;background:#f8fafc;">
              <label for="mnt-<?= $uid ?>" style="<?= $lab ?>">Заглавие на новата страница (на български)</label>
              <input type="text" id="mnt-<?= $uid ?>" class="menu-new-title" maxlength="<?= CPAGE_TITLE_MAX ?>" aria-describedby="mnh-<?= $uid ?>" style="<?= $in ?>">
              <span id="mnh-<?= $uid ?>" style="display:block;font-size:.78rem;color:#475569;margin:.2rem 0 .5rem;">Страницата се създава като чернова и се избира в този ред. Съдържанието ѝ добавяте после от „Страници“.</span>
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <button type="button" class="btn btn--primary menu-new-create" style="<?= $btn ?>">Създай</button>
                <button type="button" class="btn btn--outline menu-new-cancel" style="<?= $btn ?>">Отказ</button>
              </div>
            </div>
            <p class="menu-row-error" role="alert" style="margin:.4rem 0 0;color:#b91c1c;font-weight:600;font-size:.85rem;" hidden></p>
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
  Английското меню винаги повтаря българското — същите връзки, в същия ред. Попълнете текста на български и изберете
  страницата, към която води връзката; английският текст и адрес се попълват сами (можете да ги промените).
  За връзка към друг сайт, имейл или телефон изберете „Външна връзка…“.
</p>

<div id="menu-live" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;"></div>

<?php
$uid_n     = 0;
$pick_opts = menu_pick_options();
foreach ($sections as $key => $title):
    $rows   = $form_rows[$key] ?? menu_rows_from_section($menus[$key] ?? []);
    $errors = $form_errors[$key] ?? [];
?>

<h2 id="h-<?= h($key) ?>" style="margin-bottom:1rem;"><?= h($title) ?></h2>
<form method="POST" action="/admin/menus.php" class="admin-form menu-form" style="margin-bottom:3rem;">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="<?= h($key) ?>">

  <ol class="menu-pairs" aria-labelledby="h-<?= h($key) ?>" style="padding:0;margin:0;">
    <?php foreach ($rows as $i => $row) menu_editor_row('r' . (++$uid_n), $row, $errors[$i] ?? [], $pick_opts); ?>
  </ol>
  <button type="button" class="btn btn--outline menu-add" style="font-size:.85rem;margin-top:.25rem;min-height:2.75rem;">+ Добавяне на връзка</button>

  <div style="margin-top:1.25rem;">
    <button type="submit" class="btn btn--primary" style="white-space:normal;text-align:left;">Запази — <?= h($title) ?></button>
  </div>
</form>

<?php endforeach; ?>

<template id="menu-row-tpl"><?php menu_editor_row('__UID__', ['label_bg' => '', 'url_bg' => '', 'label_en' => '', 'url_en' => ''], [], $pick_opts); ?></template>

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
    return '/en' + path + tail;
  }
  function untranslated(bg, en) { return en !== '' && en.trim() === bg.trim() && /[Ѐ-ӿ]/.test(en); }

  function parts(row) {
    return {
      lbg: row.querySelector('.menu-label-bg'), ubg: row.querySelector('.menu-url-bg'),
      len: row.querySelector('.menu-label-en'), uen: row.querySelector('.menu-url-en'),
      note: row.querySelector('.menu-untranslated'),
      pick: row.querySelector('.menu-pick'), urlWrap: row.querySelector('.menu-url-wrap'),
      stDraft: row.querySelector('.menu-st-draft'), stEdit: row.querySelector('.menu-st-edit'),
      stGone: row.querySelector('.menu-st-gone'), createMissing: row.querySelector('.menu-create-missing'),
      newToggle: row.querySelector('.menu-new-toggle'), newBox: row.querySelector('.menu-new'),
      newTitle: row.querySelector('.menu-new-title'), newCreate: row.querySelector('.menu-new-create'),
      newCancel: row.querySelector('.menu-new-cancel'), err: row.querySelector('.menu-row-error')
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
    if (!en && window._aiHelpersOn !== false) {
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
    // Not translated: the English title of the picked page is better than Bulgarian text in the English menu.
    var o = p.pick ? p.pick.options[p.pick.selectedIndex] : null;
    var pageEn = !en && o && o.dataset.nameEn && !/[\u0400-\u04FF]/.test(o.dataset.nameEn) ? o.dataset.nameEn : '';
    p.len.value = en || pageEn || bg;
    p.len.dataset.auto = '1';
    showNote(p);
    say(en ? 'Текстът на английски е попълнен: ' + en
           : pageEn ? 'Текстът не можа да се преведе — на английски засега стои заглавието на страницата „' + pageEn + '“. Проверете го.'
           : (window._aiHelpersOn === false
              ? 'На английски засега стои „' + bg + '“. Попълнете превода.'
              : 'Текстът не можа да се преведе — на английски засега стои „' + bg + '“. Проверете го.'));
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

    wirePick(row, p);

    row.querySelector('.menu-up').addEventListener('click', function () { move(row, -1, this); });
    row.querySelector('.menu-down').addEventListener('click', function () { move(row, 1, this); });
    row.querySelector('.menu-remove').addEventListener('click', function () { remove(row, this); });
  }

  // ── The page picker ("Води към"), "+ Нова страница" and "Създай страницата" ──

  function opt(p) { return p.pick.options[p.pick.selectedIndex] || null; }

  function showDraft(p) {
    var o = opt(p), draft = !!(o && o.dataset.draft === '1');
    p.stDraft.hidden = !draft;
    if (draft) p.stEdit.href = o.dataset.edit;
  }
  function showError(p, text) { p.err.textContent = text ? '⚠ ' + text : ''; p.err.hidden = !text; }

  /* The row follows what is picked. $quiet: no announcement (the caller says it). */
  function applyPick(row, p, quiet) {
    var v = p.pick.value, o = opt(p);
    showError(p, '');
    p.stGone.hidden = true;
    if (v === '__custom__') {
      p.urlWrap.hidden = false;
      // Coming from a page: its address is not something the admin typed.
      if (p.ubg.dataset.picked === '1') { p.ubg.value = ''; if (p.uen.dataset.auto === '1') p.uen.value = ''; }
      p.ubg.dataset.picked = '0';
      showDraft(p);
      if (!quiet) say('Напишете адреса в полето „Адрес (BG)“ под списъка.');
      return;
    }
    p.urlWrap.hidden = true;
    p.ubg.value = v;
    p.ubg.dataset.picked = v ? '1' : '0';
    if (p.uen.dataset.auto === '1') p.uen.value = v ? enUrl(v) : '';
    // The text follows the page while the admin has not written their own.
    if (v && o && (p.lbg.value.trim() === '' || p.lbg.value === p.lbg.dataset.autoName)) {
      p.lbg.value = o.dataset.name; p.lbg.dataset.autoName = o.dataset.name;
      if (p.len.dataset.auto === '1') {
        if (o.dataset.nameEn) { p.len.value = o.dataset.nameEn; showNote(p); } else { fillEnLabel(row); }
      }
      var warn = row.querySelector('.menu-pair-orphan'); if (warn) warn.style.display = 'none';
    } else if (v && o && o.dataset.nameEn && !/[\u0400-\u04FF]/.test(o.dataset.nameEn)
               && p.len.dataset.auto === '1' && untranslated(p.lbg.value, p.len.value)) {
      // The admin's own text could not be translated: the page's English title is better than Bulgarian.
      p.len.value = o.dataset.nameEn; showNote(p);
    }
    showDraft(p);
    if (!quiet && v && o) {
      say('Избрано: ' + o.dataset.name + (o.dataset.draft === '1' ? ' — чернова, посетителите още не я виждат' : '')
        + '. Адрес на английски: ' + p.uen.value + '.');
    }
  }

  /* A page made from this screen: offered in every row's list (and in rows added later). */
  function addCreatedOption(page) {
    MAP[page.url_bg.replace(/\/+$/, '')] = page.url_en.replace(/\/+$/, '');
    var lists = Array.prototype.slice.call(document.querySelectorAll('.menu-pick'))
      .concat(Array.prototype.slice.call(tpl.content.querySelectorAll('.menu-pick')));
    lists.forEach(function (sel) {
      if (Array.prototype.some.call(sel.options, function (o) { return o.value === page.url_bg; })) return;
      var o = document.createElement('option');
      o.value = page.url_bg;
      o.textContent = page.title_bg + (page.status === 'published' ? '' : ' (чернова)');
      o.dataset.en = page.url_en; o.dataset.name = page.title_bg; o.dataset.nameEn = page.title_en;
      o.dataset.draft = page.status === 'published' ? '' : '1'; o.dataset.edit = page.edit_url;
      sel.querySelector('optgroup.menu-pick-created').appendChild(o);
    });
  }

  var busy = false;
  async function createPage(row, p, fields, btn) {
    if (busy) return;
    busy = true; btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    showError(p, '');
    say('Създавам страницата…');
    function form(o) { var fd = new FormData(); for (var k in o) fd.append(k, o[k]); return fd; }
    var d = null;
    try {
      var res = await fetch('/admin/created-pages.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' },
        body: form(Object.assign({ csrf_token: window._csrfToken, action: 'create', format: 'json' }, fields)) });
      d = await res.json().catch(function () { return null; });   // e.g. a login page after the session ran out
    } catch (e) {}
    busy = false; btn.disabled = false; btn.removeAttribute('aria-busy');
    if (!d || !d.ok) {
      var msg = (d && d.error) || 'Страницата не можа да се създаде. Презаредете страницата и опитайте отново.';
      showError(p, msg); say(msg); btn.focus();
      return false;
    }
    addCreatedOption(d.page);
    p.pick.value = d.page.url_bg;
    applyPick(row, p, true);
    p.pick.focus();
    say('Страницата „' + d.page.title_bg + '“ е създадена като чернова и е избрана в този ред.'
      + (d.translated ? '' : ' Английското ѝ заглавие не е преведено — проверете го в „Страници“.')
      + ' Натиснете „Запази“, за да остане в менюто.');
    return true;
  }

  function wirePick(row, p) {
    if (p.urlWrap.dataset.jsHide === '1') p.urlWrap.hidden = true;
    p.ubg.dataset.picked = p.pick.value && p.pick.value !== '__custom__' ? '1' : '0';
    var o = opt(p); if (o && o.dataset.name && p.lbg.value === o.dataset.name) p.lbg.dataset.autoName = o.dataset.name;
    p.pick.addEventListener('change', function () { applyPick(row, p, false); });
    p.ubg.addEventListener('input', function () { p.stGone.hidden = true; });

    // "+ Нова страница": type a title → a draft page, picked in this row.
    function closeNew(focusToggle) {
      p.newBox.hidden = true; p.newToggle.setAttribute('aria-expanded', 'false');
      if (focusToggle) p.newToggle.focus();
    }
    p.newToggle.addEventListener('click', function () {
      var open = p.newBox.hidden;
      p.newBox.hidden = !open; p.newToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { p.newTitle.value = p.newTitle.value || p.lbg.value.trim(); p.newTitle.focus(); }
    });
    async function submitNew() {
      var title = p.newTitle.value.trim();
      if (title === '') { showError(p, 'Напишете заглавие на новата страница.'); p.newTitle.focus(); return; }
      if (await createPage(row, p, { title_bg: title }, p.newCreate)) { p.newTitle.value = ''; closeNew(false); }
    }
    p.newCreate.addEventListener('click', submitNew);
    p.newCancel.addEventListener('click', function () { closeNew(true); });
    p.newTitle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submitNew(); }   // not the menu's own "Запази"
      if (e.key === 'Escape') { e.preventDefault(); closeNew(true); }
    });

    // "Създай страницата": the row points at an address no page has — make that page.
    p.createMissing.addEventListener('click', function () {
      var title = p.lbg.value.trim();
      if (title === '') { showError(p, 'Попълнете „Текст (BG)“ — той става заглавие на новата страница.'); p.lbg.focus(); return; }
      var m = p.ubg.value.trim().match(/^\/([a-z0-9-]+)\/?$/);
      var fields = { title_bg: title, slug_bg: m ? m[1] : '' };
      var en = p.len.value.trim();
      if (en !== '' && !untranslated(title, en)) fields.title_en = en;
      createPage(row, p, fields, p.createMissing);
    });
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
      say('Добавен е нов ред. Попълнете текста на български и изберете страницата — английските се попълват сами.');
    });
  });
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/admin-footer.php'; ?>
