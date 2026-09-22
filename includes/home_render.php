<?php
// includes/home_render.php — draws the front page from content/home.json.
// One template per section type lives in templates/home/<type>.php.

require_once __DIR__ . '/home.php';

/** Field value for a language; an empty English value falls back to Bulgarian. */
function hf(array $f, string $key, string $lang): string {
    $v = $f[$key] ?? '';
    if (!is_array($v)) return is_scalar($v) ? (string) $v : '';
    $out = (string) ($v[$lang] ?? '');
    return $out !== '' ? $out : (string) ($v['bg'] ?? '');
}

/**
 * data-cms-* attributes for on-page editing of one text field.
 * data-cms-bg/data-cms-en carry the untranslated value across the language the
 * page is rendering in, so the inline editor can switch tabs client-side
 * without an extra request. inline-cms.js — the only reader of those two
 * attributes — is only enqueued when the admin bar shows (templates/header.php),
 * so they are omitted for everyone else: no reason to leak the other
 * language's copy into a logged-out visitor's DOM.
 */
function home_cms_attrs(string $sid, string $key, array $f, string $type = 'text'): string {
    $out = ' data-cms-section="home:' . h($sid) . '" data-cms-field="' . h($key) . '" data-cms-type="' . h($type) . '"';
    if ($GLOBALS['_show_admin_bar'] ?? false) {
        $v = home_pair($f[$key] ?? null);
        $out .= ' data-cms-bg="' . h($v['bg']) . '" data-cms-en="' . h($v['en']) . '"';
    }
    return $out;
}

function home_img_attrs(string $sid, string $key): string {
    return ' data-cms-section="home:' . h($sid) . '" data-cms-field="' . h($key) . '"';
}

/** The stored background if this block offers it, else $default (a stored teal outside the CTA block → $default). */
function home_bg_key(array $f, string $default = 'white', array $allowed = HOME_BACKGROUNDS): string {
    $bg = is_string($f['background'] ?? null) ? $f['background'] : $default;
    return array_key_exists($bg, $allowed) ? $bg : $default;
}

function home_bg_class(array $f, string $default = 'white', array $allowed = HOME_BACKGROUNDS): string {
    return ['white' => '', 'grey' => ' section--grey', 'teal' => ' section--teal', 'warm' => ' section--warm'][home_bg_key($f, $default, $allowed)];
}

/**
 * Up to two buttons. $styles: [[class, inline style], [class, inline style]].
 * A button renders only with both a label and a link.
 */
function home_buttons(string $sid, array $f, string $lang, array $styles, string $group_style = ''): string {
    $out = '';
    foreach ([1, 2] as $n) {
        if (!isset($f["btn{$n}_label"], $styles[$n - 1])) continue;
        $label = hf($f, "btn{$n}_label", $lang);
        $url   = hf($f, "btn{$n}_url", $lang);
        if ($label === '' || $url === '' || home_clean_link($url) === null) continue;
        [$class, $style] = $styles[$n - 1];
        $ext  = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $out .= '<a href="' . h($url) . '"' . $ext . ' class="' . h($class) . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '')
              . home_cms_attrs($sid, "btn{$n}_label", $f) . '>' . h($label) . '</a>';
    }
    return $out === '' ? '' : '<div class="btn-group"' . ($group_style !== '' ? ' style="' . h($group_style) . '"' : '') . '>' . $out . '</div>';
}

/** Load only the data that visible built-in sections need. */
function home_context(array $doc, string $lang): array {
    $on = [];
    foreach ($doc['sections'] as $s) {
        if (!empty($s['visible'])) $on[$s['type']] = $s;
    }
    $ctx = ['impact' => [], 'centres' => [], 'partners' => [], 'articles' => [],
            'featured_products' => [], 'variant_images' => [], 'variant_stock' => [],
            'campaign' => [], 'campaign_url' => ''];
    if (isset($on['products'])) {
        require_once ROOT_PATH . '/admin/includes/db.php';
        require_once ROOT_PATH . '/includes/products.php';
        $pdo = get_pdo();
        $ctx['featured_products'] = product_featured_list($pdo);
        $support = product_variant_support_data($pdo, $ctx['featured_products']);
        $ctx['variant_images'] = $support['images'];
        $ctx['variant_stock']  = $support['stock'];
    }
    if (isset($on['impact']))   $ctx['impact']   = get_impact();
    if (isset($on['centres']))  $ctx['centres']  = get_centres();
    if (isset($on['partners'])) $ctx['partners'] = get_partners();
    if (isset($on['news'])) {
        $ctx['articles'] = get_articles($lang, ($on['news']['fields']['count'] ?? '3') === '6' ? 6 : 3);
    }
    if (isset($on['campaign']) && feature_enabled('campaign')) {
        $ctx['campaign_url'] = setting_get('campaign_url');
        $ctx['campaign']     = load_json(CONTENT_PATH . '/pages.json')['campaign'] ?? [];
    }
    return $ctx;
}

/** Layout rules shared by the blocks, and the click-to-play script. Printed once. */
function home_shared_head(): string {
    return <<<'HTML'
<style id="home-sections-css">
.home-video__play:focus-visible{outline:4px solid #fff;outline-offset:-8px;box-shadow:inset 0 0 0 8px #000}
.home-rich>*:first-child{margin-top:0}.home-rich>*+*{margin-top:1rem}
@media(max-width:640px){
  .home-ti,.campaign-block-grid{grid-template-columns:1fr!important}
  .home-ti>div{order:0!important}
  .home-cards{grid-template-columns:1fr!important}
}
</style>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('.home-video__play');
  if (!b) return;
  var f = document.createElement('iframe');
  f.src = b.getAttribute('data-embed');
  f.title = b.getAttribute('data-title');
  f.allow = 'autoplay; fullscreen; picture-in-picture';
  f.setAttribute('allowfullscreen', '');
  f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;';
  b.replaceWith(f);
  f.focus();
});
</script>
HTML;
}

function home_render(array $doc, string $lang): void {
    $types      = home_types();
    $ctx        = home_context($doc, $lang);
    $show_admin = (bool) ($GLOBALS['_show_admin_bar'] ?? false);
    echo home_shared_head();
    foreach ($doc['sections'] as $s) {
        if (empty($s['visible']) || !isset($types[$s['type'] ?? ''])) continue;
        home_render_section($s, $lang, $ctx, $show_admin);
    }
}

function home_render_section(array $s, string $lang, array $ctx, bool $show_admin): void {
    $sid = (string) $s['id'];
    $f   = is_array($s['fields'] ?? null) ? $s['fields'] : [];
    require ROOT_PATH . '/templates/home/' . $s['type'] . '.php';
}
