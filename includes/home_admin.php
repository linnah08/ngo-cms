<?php
// includes/home_admin.php — form pieces for admin/home-sections.php.
// Field ids follow the error keys: error "cards.1.title.bg" ↔ input id "f_cards_1_title_bg".

require_once __DIR__ . '/home.php';

const HS_SR = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';

/**
 * Address of the section editor for the document being edited, with $q as its query:
 * /admin/home-sections.php for the front page, /admin/page-edit.php?id=… for a created page.
 */
function hs_url(array $q = []): string {
    $t = home_target();
    if ($t['kind'] === 'page') {
        $q = ['id' => $t['id']] + $q;
        $base = '/admin/page-edit.php';
    } else {
        $base = '/admin/home-sections.php';
    }
    return $base . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

function hs_badge(string $lang): string {
    return $lang === 'bg'
        ? '<span style="font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">BG</span>'
        : '<span style="font-size:.68rem;font-weight:700;background:#dbeafe;color:#1d4ed8;border-radius:3px;padding:.05rem .35rem;margin-left:.4rem;vertical-align:middle;">EN</span>';
}

function hs_error_html(string $id, ?string $msg): string {
    return $msg === null ? '' : '<p id="' . h($id) . '" style="color:#b91c1c;font-weight:600;margin:.35rem 0 0;"><span aria-hidden="true">⚠ </span>' . h($msg) . '</p>';
}

/**
 * One field of a section form.
 * $nb: name base ("f" or "f[cards][2]"), $ib: id base ("f" or "f_cards_2"),
 * $eb: error-key prefix ("" or "cards.2."), $upload: file input name for images.
 */
function hs_field(string $key, array $def, mixed $val, array $errors, string $nb = 'f', string $ib = 'f', string $eb = '', string $upload = ''): string {
    $name    = "{$nb}[{$key}]";
    $id      = "{$ib}_{$key}";
    $ek      = $eb . $key;
    $label   = h($def['label']) . (!empty($def['required']) ? ' <span style="font-weight:400;">(задължително)</span>' : '');
    $hint_id = $id . '_hint';
    $hint    = isset($def['hint']) ? '<p id="' . h($hint_id) . '" style="font-size:.82rem;color:var(--text-muted);margin:.3rem 0 0;">' . h($def['hint']) . '</p>' : '';

    switch ($def['kind']) {
        case 'text': case 'textarea': case 'alt': case 'html': case 'link':
            $pair = home_pair($val);
            $cols = '';
            foreach (['bg', 'en'] as $l) {
                $fid  = "{$id}_{$l}";
                $err  = $errors["{$ek}.{$l}"] ?? null;
                $desc = trim((isset($def['hint']) ? $hint_id : '') . ($err ? " {$fid}_err" : ''));
                $attrs = ' id="' . h($fid) . '" name="' . h("{$name}[{$l}]") . '"'
                       . ($desc !== '' ? ' aria-describedby="' . h($desc) . '"' : '')
                       . ($err ? ' aria-invalid="true"' : '')
                       . (!empty($def['required']) && $l === 'bg' ? ' aria-required="true"' : '')
                       . ($l === 'en' && $def['kind'] !== 'link' ? ' data-translate-from="' . h("{$name}[bg]") . '"' : '');
                $v = h($pair[$l]);
                $control = match ($def['kind']) {
                    'textarea' => "<textarea{$attrs} rows=\"3\">{$v}</textarea>",
                    'html'     => "<textarea{$attrs} rows=\"8\" class=\"hs-rich\">{$v}</textarea>",
                    'link'     => "<input type=\"text\" inputmode=\"url\" autocomplete=\"off\"{$attrs} value=\"{$v}\">",
                    default    => "<input type=\"text\"{$attrs} value=\"{$v}\">",
                };
                $cols .= '<div class="form-group" style="margin:0;"><label for="' . h($fid) . '">' . $label . hs_badge($l) . '</label>'
                       . $control . hs_error_html("{$fid}_err", $err) . '</div>';
            }
            return '<div style="margin-bottom:1.5rem;"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem 1.5rem;">'
                 . $cols . '</div>' . $hint . '</div>';

        case 'image':
            $v   = is_string($val) ? $val : '';
            $err = $errors[$ek] ?? null;
            $up  = $upload !== '' ? $upload : "up[{$key}]";
            $describe = trim((isset($def['hint']) ? $hint_id : '') . ($err ? " {$id}_err" : ''));
            return '<div class="form-group" data-hs-image style="margin-bottom:1.5rem;">'
                 . '<label for="' . h($id) . '">' . $label . '</label>'
                 . '<img src="' . h($v) . '" alt="" data-hs-preview style="max-width:240px;max-height:160px;border-radius:4px;margin-bottom:.5rem;display:' . ($v !== '' ? 'block' : 'none') . ';">'
                 . '<input type="hidden" name="' . h($name) . '" value="' . h($v) . '" data-hs-path>'
                 . '<input type="file" id="' . h($id) . '" name="' . h($up) . '" accept="image/jpeg,image/png,image/webp" data-om-crop'
                 . ($describe !== '' ? ' aria-describedby="' . h($describe) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>'
                 . '<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem;">'
                 . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-pick>Избери от библиотека</button>'
                 . '<button type="button" class="btn btn--outline" style="min-height:44px;" data-hs-clear>Премахни снимката</button>'
                 . '</div>' . $hint . hs_error_html($id . '_err', $err) . '</div>';

        case 'choice':
            $opts = '';
            foreach ($def['options'] as $k => $lbl) {
                $opts .= '<option value="' . h((string) $k) . '"' . ((string) $val === (string) $k ? ' selected' : '') . '>' . h($lbl) . '</option>';
            }
            $err = $errors[$ek] ?? null;
            return '<div class="form-group" style="margin-bottom:1.5rem;"><label for="' . h($id) . '">' . $label . '</label>'
                 . '<select id="' . h($id) . '" name="' . h($name) . '" style="min-height:44px;"' . ($err ? ' aria-invalid="true" aria-describedby="' . h($id) . '_err"' : '') . '>' . $opts . '</select>'
                 . hs_error_html($id . '_err', $err) . '</div>';

        case 'video':
            $url = is_array($val) && home_video_valid($val) ? home_video_watch_url($val) : (is_string($val) ? $val : '');
            $err = $errors[$ek] ?? null;
            return '<div class="form-group" style="margin-bottom:1.5rem;"><label for="' . h($id) . '">' . $label . '</label>'
                 . '<input type="text" inputmode="url" autocomplete="off" id="' . h($id) . '" name="' . h($name) . '" value="' . h($url) . '"'
                 . ' aria-describedby="' . h($id) . '_hint' . ($err ? ' ' . h($id) . '_err' : '') . '"' . ($err ? ' aria-invalid="true"' : '') . ' aria-required="true">'
                 . '<p id="' . h($id) . '_hint" style="font-size:.82rem;color:var(--text-muted);margin:.3rem 0 0;">Отворете видеото в YouTube или Vimeo, копирайте адреса от браузъра и го поставете тук.</p>'
                 . hs_error_html($id . '_err', $err) . '</div>';

        case 'list':
            return hs_list($key, $def, is_array($val) ? $val : [], $errors);

        case 'frame':  // set on the live page; the form says where, and can undo it
            $reset = home_frame($val) === null ? ''
                : '<label style="display:flex;gap:.5rem;align-items:center;min-height:44px;font-weight:400;">'
                . '<input type="checkbox" id="' . h($id) . '" name="' . h($name) . '" value="reset" aria-describedby="' . h($hint_id) . '" style="width:20px;height:20px;">'
                . 'Върни снимката в обичайния ѝ размер и изрязване</label>';
            return '<div class="form-group" style="margin-bottom:1.5rem;"><p style="font-weight:600;margin:0;">' . $label . '</p>' . $reset
                 . '<p id="' . h($hint_id) . '" style="font-size:.82rem;color:var(--text-muted);margin:.3rem 0 0;">'
                 . 'Колко голяма да е снимката и коя част от нея да се вижда, се нагласява на самата страница: '
                 . 'включете режима за редактиране, щракнете снимката и изберете „Нагласи снимката“.</p></div>';

        case 'site':   // a site's own field (see home_site_types()) draws itself
            return is_callable($def['render'] ?? null) ? ($def['render'])($key, $def, $val, $errors) : '';
    }
    return '';   // 'internal' fields are not shown
}

/**
 * The grip that moves a row: drag it with the mouse or a finger, or focus it and press
 * Space, move with ↑ ↓ and press Space again. The script in admin/home-sections.php drives it.
 */
function hs_sort_handle(string $aria, string $help_id): string {
    return '<button type="button" class="btn btn--outline" data-hs-sort-handle aria-pressed="false"'
         . ' aria-label="' . h($aria) . '" aria-describedby="' . h($help_id) . '"'
         . ' style="min-height:44px;min-width:44px;cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;">'
         . '<span aria-hidden="true" style="font-size:1.2rem;line-height:1;margin-right:.35rem;">⠿</span>Премести</button>';
}

/** How to use the grips, for the list of $what ("секциите", "картите"). */
function hs_sort_help(string $id, string $what): string {
    return '<p id="' . h($id) . '" style="margin:0 0 1rem;color:var(--text-muted);">'
         . 'За да смените реда на ' . h($what) . ', хванете бутона „⠿ Премести“ и плъзнете. '
         . 'С клавиатура: застанете на бутона, натиснете интервал, преместете със стрелките ↑ ↓ и натиснете интервал отново. '
         . 'Esc отказва преместването.</p>';
}

/**
 * One row of a repeating list ('kind' => 'list'). The hidden id input keeps the item's
 * stable id, so a row that is reordered or replaced elsewhere is still the same item.
 */
function hs_list_row(string $key, array $def, string $i, array $item, array $errors, int $number): string {
    $iid = is_string($item['id'] ?? null) ? $item['id'] : home_list_item_id();
    $out = '<li data-hs-item style="border:1px solid var(--border);border-radius:8px;padding:1rem;background:#fff;">'
         . '<fieldset style="border:0;padding:0;margin:0;min-width:0;"><legend data-hs-item-legend style="font-weight:600;margin-bottom:.75rem;">' . h($def['item']) . ' ' . $number . '</legend>'
         . '<input type="hidden" name="' . h("f[$key][$i][id]") . '" value="' . h($iid) . '" data-hs-item-id>'
         . '<div style="margin:0 0 .75rem;">' . hs_sort_handle('Премести ' . mb_strtolower($def['item']) . ' ' . $number, 'hsListSortHelp_' . $key) . '</div>';
    foreach ($def['fields'] as $ik => $idef) {
        $out .= hs_field($ik, $idef, $item[$ik] ?? null, $errors, "f[$key][$i]", "f_{$key}_$i", "$key.$i.", "up_list[$key][$i][$ik]");
    }
    return $out . '<div style="display:flex;gap:.5rem;flex-wrap:wrap;">'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;color:#b91c1c;border-color:#b91c1c;" data-hs-item-remove>' . h($def['remove']) . '</button>'
         . '</div></fieldset></li>';
}

function hs_list(string $key, array $def, array $items, array $errors): string {
    $items = array_values(array_filter($items, 'is_array'));
    while (count($items) < (int) ($def['min'] ?? 0)) $items[] = [];
    $err  = $errors[$key] ?? null;
    $say  = ($def['say'] ?? []) + ['added' => 'Добавен е нов ред.', 'removed' => 'Редът е премахнат.', 'moved' => 'Редът е на място %d. Редът се запазва с бутона „Запази“.'];
    $html = '<fieldset id="f_' . h($key) . '" tabindex="-1" style="border:0;padding:0;margin:0 0 1.5rem;min-width:0;"' . ($err ? ' aria-describedby="f_' . h($key) . '_err"' : '') . '>'
          . '<legend style="font-weight:600;font-size:1.05rem;margin-bottom:.5rem;">' . h($def['label']) . ' (от ' . (int) $def['min'] . ' до ' . (int) $def['max'] . ')</legend>'
          . hs_error_html('f_' . $key . '_err', $err)
          . hs_sort_help('hsListSortHelp_' . $key, $def['plural_the'] ?? 'редовете')
          . '<ol data-hs-list="' . h($key) . '" data-hs-min="' . (int) $def['min'] . '" data-hs-max="' . (int) $def['max'] . '"'
          . ' data-hs-item-name="' . h(mb_strtolower($def['item'])) . '" data-hs-item-label="' . h($def['item']) . '" data-hs-plural="' . h($def['plural'] ?? 'елемента') . '"'
          . ' data-hs-say-added="' . h($say['added']) . '" data-hs-say-removed="' . h($say['removed']) . '" data-hs-say-moved="' . h($say['moved']) . '"'
          . ' style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;">';
    foreach ($items as $i => $item) $html .= hs_list_row($key, $def, (string) $i, $item, $errors, $i + 1);
    return $html . '</ol>'
         . '<button type="button" class="btn btn--outline" style="min-height:44px;margin-top:1rem;" data-hs-item-add aria-describedby="hsListNote_' . h($key) . '">' . h($def['add']) . '</button>'
         . '<span id="hsListNote_' . h($key) . '" data-hs-list-note style="margin-left:.75rem;color:var(--text-muted);"></span>'
         . '<template id="hsListTpl_' . h($key) . '">' . hs_list_row($key, $def, '__I__', ['id' => ''], [], 0) . '</template></fieldset>';
}

/** Human label for an error key, e.g. "cards.1.title.bg" → "Карта 2 — Заглавие (BG)". */
function hs_error_label(string $type, string $key): string {
    $parts = explode('.', $key);
    $lang  = in_array(end($parts), ['bg', 'en'], true) && count($parts) > 1 ? strtoupper(array_pop($parts)) : '';
    $def   = home_types()[$type]['fields'][$parts[0]] ?? [];
    if (($def['kind'] ?? '') === 'list' && isset($parts[2])) {
        $label = $def['item'] . ' ' . ((int) $parts[1] + 1) . ' — ' . ($def['fields'][$parts[2]]['label'] ?? '');
    } else {
        $label = $def['label'] ?? '';
    }
    return $label . ($lang !== '' ? " ($lang)" : '');
}

function hs_error_summary(string $type, array $errors): string {
    if (!$errors) return '';
    $items = '';
    foreach ($errors as $k => $msg) {
        $items .= $k === '_form' || $k === '_type'
            ? '<li>' . h($msg) . '</li>'
            : '<li><a href="#f_' . h(str_replace('.', '_', $k)) . '">' . h(hs_error_label($type, $k) . ': ' . $msg) . '</a></li>';
    }
    return '<div id="hsErrSummary" role="alert" tabindex="-1" style="border:2px solid #b91c1c;background:#fef2f2;color:#7f1d1d;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.5rem;">'
         . '<h2 style="font-size:1rem;margin:0 0 .5rem;"><span aria-hidden="true">⚠ </span>Секцията не е запазена. Поправете следното:</h2>'
         . '<ul style="margin:0;padding-left:1.25rem;">' . $items . '</ul></div>';
}

function hs_action_form(string $action, array $s, int $rev, string $label, string $aria, bool $danger = false): string {
    $id      = (string) $s['id'];
    $confirm = $action === 'delete'
        ? ' data-confirm="' . h('Да изтрия ли „' . home_section_name($s) . '“? Това не може да се върне.') . '" data-confirm-ok="Да, изтрий"'
        : '';
    return '<form method="POST" action="' . h(hs_url()) . '" style="display:inline;margin:0;"' . $confirm . '>'
         . csrf_field()
         . '<input type="hidden" name="action" value="' . h($action) . '">'
         . '<input type="hidden" name="id" value="' . h($id) . '">'
         . '<input type="hidden" name="rev" value="' . $rev . '">'
         . '<button type="submit" id="' . h("btn-$action-$id") . '" class="btn btn--outline"'
         . ' aria-label="' . h($aria) . '"'
         . ' style="min-height:44px;min-width:44px;' . ($danger ? 'color:#b91c1c;border-color:#b91c1c;' : '') . '">' . h($label) . '</button>'
         . '</form>';
}
