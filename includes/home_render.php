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
 * A front-page link saved before a page moved, pointed at where it lives now.
 * The donation form used to sit on the shop page (#donation); sites set up
 * before /donation/ existed still have that link in content/home.json.
 */
function home_current_link(string $url): string {
    return [
        '/magazin/#donation' => '/donation/',
        '/en/shop/#donation' => '/en/donation/',
    ][$url] ?? $url;
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
        $url   = home_current_link(hf($f, "btn{$n}_url", $lang));
        if ($label === '' || $url === '' || home_clean_link($url) === null) continue;
        // A button to a page of a switched-off module would lead to a 404 — leave it out.
        if (!module_link_visible($url)) continue;
        [$class, $style] = $styles[$n - 1];
        $ext  = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $out .= '<a href="' . h($url) . '"' . $ext . ' class="' . h($class) . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '')
              . home_cms_attrs($sid, "btn{$n}_label", $f) . '>' . h($label) . '</a>';
    }
    return $out === '' ? '' : '<div class="btn-group"' . ($group_style !== '' ? ' style="' . h($group_style) . '"' : '') . '>' . $out . '</div>';
}

/**
 * Front-page blocks that belong to an optional module: such a block is not
 * drawn while its module is off (Admin → Модули). Its settings are kept.
 */
const HOME_SECTION_MODULES = ['products' => 'shop', 'campaign' => 'campaign'];

function home_section_module_on(string $type): bool {
    $module = HOME_SECTION_MODULES[$type] ?? null;
    return $module === null || module_enabled_with_needs($module);
}

/** Load only the data that visible built-in sections need. */
function home_context(array $doc, string $lang): array {
    $on = [];
    foreach ($doc['sections'] as $s) {
        if (!empty($s['visible']) && home_section_module_on((string) ($s['type'] ?? ''))) $on[$s['type']] = $s;
    }
    $ctx = ['impact' => [], 'centres' => [], 'partners' => [], 'articles' => [],
            'featured_products' => [], 'variant_images' => [], 'variant_stock' => [], 'variant_single' => [],
            'campaign' => [], 'campaign_url' => ''];
    if (isset($on['products'])) {
        require_once ROOT_PATH . '/admin/includes/db.php';
        require_once ROOT_PATH . '/includes/products.php';
        $pdo = get_pdo();
        $ctx['featured_products'] = product_featured_list($pdo);
        $support = product_variant_support_data($pdo, $ctx['featured_products']);
        $ctx['variant_images'] = $support['images'];
        $ctx['variant_stock']  = $support['stock'];
        $ctx['variant_single'] = $support['single'];
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
/* Single-product spotlight (templates/home/product-spotlight.php). A site theme
   stylesheet restyles it without touching this shared file: set the --spot-* variables
   on .home-spot, or override a part with one extra class (.home-spot .home-spot__title). */
.home-spot{display:grid;grid-template-columns:var(--spot-columns,minmax(0,1fr) minmax(0,1fr));gap:var(--spot-gap,clamp(1.5rem,4vw,4rem));align-items:center;background:var(--spot-bg,var(--off-white));border:var(--spot-border,1px solid var(--border));border-radius:var(--spot-radius,calc(var(--radius-lg) * 2));padding:var(--spot-padding,clamp(1.25rem,4vw,3.5rem))}
.home-spot__media{display:block;aspect-ratio:var(--spot-img-ratio,1);overflow:hidden;border-radius:var(--spot-img-radius,var(--radius-lg));background:var(--white);box-shadow:var(--spot-img-shadow,0 12px 32px rgba(0,0,0,.10))}
.home-spot__media img{width:100%;height:100%;object-fit:cover;display:block}
.home-spot__body{display:flex;flex-direction:column;align-items:flex-start}
.home-spot__label{margin:0 0 1rem}
.home-spot__title{font-size:var(--spot-title-size,clamp(1.75rem,3.5vw,2.75rem));line-height:1.15;margin:0 0 1rem}
.home-spot__title a{color:inherit;text-decoration:none}
.home-spot__desc{font-size:1rem;color:var(--text-muted);line-height:1.7;margin:0 0 1.5rem;white-space:pre-line;display:-webkit-box;-webkit-line-clamp:var(--spot-desc-lines,8);-webkit-box-orient:vertical;overflow:hidden}
.home-spot__price{margin:0 0 .5rem}
.home-spot__price .price{font-size:1.5rem;font-weight:700}
.home-spot__low{font-size:.85rem;color:#b45309;font-weight:600;margin:0 0 .5rem}
.home-spot__actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1rem}
.home-spot__actions form{margin:0}
.home-spot__actions .btn{justify-content:center;font-size:1rem;padding:.8rem 1.6rem;min-height:44px;box-sizing:border-box}
.home-spot__soldout{margin:0;padding:.8rem 1.6rem;border-radius:var(--radius);background:#fdf0ef;border:1px solid #f0c4c0;color:#c0392b;font-weight:600}
@media(max-width:640px){
  .home-ti,.campaign-block-grid,.home-spot{grid-template-columns:1fr!important}
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
    // The hero carries the page's only <h1>. Hidden, the page still needs one for screen readers.
    $hero_shown = false;
    foreach ($doc['sections'] as $s) {
        if (!empty($s['visible']) && ($s['type'] ?? '') === 'hero') $hero_shown = true;
    }
    if (!$hero_shown) {
        echo '<h1 style="position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;">'
           . h($lang === 'bg' ? SITE_NAME_BG : SITE_NAME_EN) . '</h1>';
    }
    foreach ($doc['sections'] as $s) {
        if (empty($s['visible']) || !isset($types[$s['type'] ?? ''])) continue;
        if (!home_section_module_on((string) $s['type'])) continue;
        home_render_section($s, $lang, $ctx, $show_admin);
    }
}

function home_render_section(array $s, string $lang, array $ctx, bool $show_admin): void {
    $sid = (string) $s['id'];
    $f   = is_array($s['fields'] ?? null) ? $s['fields'] : [];
    require ROOT_PATH . '/templates/home/' . $s['type'] . '.php';
}
