<?php
/**
 * On-page editing (Edit mode): te() prints wording an admin can change on the page,
 * cms_admin() marks what is changed on an admin page, t_lookup() is the text lookup
 * behind t_or() and te(), with the wording edited on the page laid over strings.json.
 *
 * Loaded by config.php and again by templates/header.php: a site that kept its own
 * older config.php through an update (the updater keeps files a site has changed)
 * still gets these, so the new pages never call a function that isn't there.
 * Each one is guarded, so loading the file twice is harmless.
 */

/** The wording edited on the page for one language (includes/ui_wording.php), read once per request. */
if (!function_exists('ui_wording_layer')) {
function ui_wording_layer(string $lang): array {
    static $cache = [];
    if (array_key_exists('_om_ui_wording', $GLOBALS)) return (array) ($GLOBALS['_om_ui_wording'][$lang] ?? []);
    if (!isset($cache[$lang])) {
        require_once __DIR__ . '/ui_wording.php';
        $cache[$lang] = ui_wording_all()[$lang] ?? [];
    }
    return $cache[$lang];
}
}

/** t_or()'s lookup, for t_or() and te(). Call those: their literal keys are what tests check against strings.json. */
if (!function_exists('t_lookup')) {
function t_lookup(string $key, string $bg, string $en, ?string $lang = null, array $vars = []): string {
    $lang = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    // Wording an admin changed on the page in Edit mode wins over strings.json and strings.site.json.
    $v    = ui_wording_layer($lang)[$key] ?? lang_strings($lang)[$key] ?? null;
    $text = (is_string($v) && $v !== '') ? $v : ($lang === 'en' ? $en : $bg);
    if ($vars) {
        $map = [];
        foreach ($vars as $k => $val) $map['{' . $k . '}'] = (string) $val;
        $text = strtr($text, $map);
    }
    return $text;
}
}

/**
 * A UI string the admin can reword on the page in Edit mode: t_or()'s text, escaped,
 * in a <span> the on-page editor saves under $key (section "ui", includes/ui_wording.php).
 *
 * Without $bg/$en the key's text in strings.json is the default. $vars fills {name}
 * placeholders as in t_or(); Edit mode shows the placeholders while editing.
 *
 * Use it for every fixed word a visitor reads in a page's body — titles, labels,
 * headings, form labels and buttons, "nothing here yet" messages. Not inside an
 * attribute, <title> or <option> (no markup there): use t_or() and keep the key.
 * tests/EditModeCoverageTest.php fails for visible text that is neither editable
 * on the page nor marked with cms_admin().
 */
if (!function_exists('te')) {
function te(string $key, string $bg = '', string $en = '', ?string $lang = null, array $vars = []): string {
    $lang = ($lang ?? get_lang()) === 'en' ? 'en' : 'bg';
    $out  = '<span data-cms-section="ui" data-cms-field="' . h($key) . '" data-cms-type="text"';
    // Both languages ride along only for an admin, so switching BG/EN in the bar shows the other one.
    // With $vars they are the templates ("Остават {n} места."): Edit mode shows and saves those.
    if ($GLOBALS['_show_admin_bar'] ?? false) {
        $out .= ' data-cms-bg="' . h(t_lookup($key, $bg, $en, 'bg')) . '" data-cms-en="' . h(t_lookup($key, $bg, $en, 'en')) . '"';
        if ($vars) $out .= ' data-cms-vars="' . h((string) json_encode(array_map('strval', $vars), JSON_UNESCAPED_UNICODE)) . '"';
    }
    return $out . '>' . h(t_lookup($key, $bg, $en, $lang, $vars)) . '</span>';
}
}

/**
 * Attributes for something shown on the page but changed elsewhere in the admin —
 * the organisation's email, IBAN, address and social links, a product's name and
 * options. In Edit mode it is outlined and a click says where to change it, with a link.
 *
 * $where: a key of cms_admin_places(), or [label, admin path] for a one-off.
 */
if (!function_exists('cms_admin')) {
function cms_admin(string|array $where): string {
    [$label, $path] = is_array($where) ? $where : (cms_admin_places()[$where] ?? cms_admin_places()['organisation']);
    return ' data-cms-admin="' . h($label) . '" data-cms-admin-url="' . h($path) . '"';
}
}

/** Where things shown on the public pages are changed: key => [what the admin calls it, admin path]. */
if (!function_exists('cms_admin_places')) {
function cms_admin_places(): array {
    return [
        'organisation' => ['Организация', '/admin/organisation.php'],
        'launch'       => ['Пускане на сайта', '/admin/launch.php'],
        'products'     => ['Продукти', '/admin/products.php'],
        'articles'     => ['Статии', '/admin/articles.php'],
        'events'       => ['Събития', '/admin/events.php'],
        'pages'        => ['Съдържание', '/admin/pages.php'],
        'menus'        => ['Менюта', '/admin/menus.php'],
    ];
}
}
