<?php
/**
 * Menus: the BG ↔ EN page-address map, and the paired-row model behind
 * Admin → Менюта.
 *
 * content/menus.json keeps its shape — {section: {bg: [{url,label}], en: [...]}} —
 * so the header and footer read it unchanged. The admin edits ONE list per
 * section where each row is a BG item and its EN twin; the EN menu always
 * mirrors the BG one (same items, same order).
 *
 * The address map is the single source for both the language switcher in
 * templates/header.php and the EN address the menu editor fills in.
 */

/** BG path prefix → EN path prefix (longest match wins). */
function path_map_bg_to_en(): array
{
    $map = [
        '/novini'                    => '/en/news',
        '/za-nas'                    => '/en/about',
        '/proekti'                   => '/en/projects',
        '/kak-da-pomogna'            => '/en/how-to-help',
        '/kontakti'                  => '/en/contacts',
        '/magazin'                   => '/en/shop',
        '/cart'                      => '/en/cart',
        '/checkout'                  => '/en/checkout',
        '/donation'                  => '/en/donation',
        '/campaign'                  => '/en/campaign',
        '/politika-za-poveritelnost' => '/en/privacy-policy',
        '/politika-za-biskvitki'     => '/en/cookie-policy',
        '/pravna-informaciya'        => '/en/legal',
        '/usloviya'                  => '/en/terms',
        '/finansovi-otcheti'         => '/en/financial-reports',
        '/'                          => '/en',
    ];
    // Campaign module off: the /campaign pair does not exist, so it must not
    // take part in longest-match path switching either.
    if (function_exists('feature_enabled') && !feature_enabled('campaign')) {
        unset($map['/campaign']);
    }
    uksort($map, fn($a, $b) => strlen($b) - strlen($a));
    return $map;
}

/**
 * The EN path of a BG path (prefix match, so /novini/<slug> → /en/news/<slug>).
 * A path nothing maps gets an /en prefix — what the language switcher wants.
 */
function path_bg_to_en(string $path): string
{
    $trailing = str_ends_with($path, '/') ? '/' : '';
    $p = rtrim($path, '/');
    foreach (path_map_bg_to_en() as $bg => $en) {
        $bg_clean = rtrim($bg, '/');
        if ($p === $bg_clean || str_starts_with($p, $bg_clean . '/')) {
            return $en . substr($p, strlen($bg_clean)) . $trailing;
        }
    }
    return '/en' . $path;
}

/** The BG path of an EN path (/en/... → BG). */
function path_en_to_bg(string $path): string
{
    $trailing   = str_ends_with($path, '/') ? '/' : '';
    $without_en = substr(rtrim($path, '/'), 3); // remove leading /en
    foreach (path_map_bg_to_en() as $bg => $en) {
        $en_part = substr(rtrim($en, '/'), 3);
        if ($without_en === $en_part || str_starts_with($without_en, $en_part . '/')) {
            $final = rtrim($bg, '/') . substr($without_en, strlen($en_part)) . $trailing;
            return $final !== '' ? $final : '/';
        }
    }
    return $without_en . $trailing ?: '/';
}

/**
 * The EN address for a BG menu address — the same rule as the language switcher
 * (path_bg_to_en): site paths the map knows are mapped, any other site path gets
 * /en in front, with any ?query or #anchor kept. External links, mailto:/tel:
 * and anchors stay exactly as they are.
 */
function menu_en_url(string $bg_url): string
{
    $url = trim($bg_url);
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) return $url;

    $cut  = strcspn($url, '?#');
    $path = substr($url, 0, $cut);
    $tail = substr($url, $cut);

    if (rtrim($path, '/') === '') return '/en/' . $tail;
    if ($path === '/en' || str_starts_with($path, '/en/')) return $url; // already English

    $p = rtrim($path, '/');
    foreach (path_map_bg_to_en() as $bg => $en) {
        $bg_clean = rtrim($bg, '/');
        if ($bg_clean === '') continue; // the home pair only matches "/" itself
        if ($p === $bg_clean || str_starts_with($p, $bg_clean . '/')) {
            return path_bg_to_en($path) . $tail;
        }
    }
    return '/en' . $path . $tail;
}

/**
 * True when a saved EN address is what v0.22's editor wrote for a page its map
 * did not know: the BG site path copied unchanged. Such an address is replaced
 * by menu_en_url() when the menu is opened, since the admin never typed it.
 */
function menu_en_url_is_stale_copy(string $bg_url, string $en_url): bool
{
    $u = trim($bg_url);
    return $u !== '' && $u[0] === '/' && !str_starts_with($u, '//')
        && trim($en_url) === $u && menu_en_url($u) !== $u;
}

/** Comparable form of a menu address: no trailing slash, "/" for home. */
function menu_url_key(string $url): string
{
    $u = trim($url);
    $cut  = strcspn($u, '?#');
    $path = rtrim(substr($u, 0, $cut), '/');
    return ($path === '' && ($u[0] ?? '') === '/' ? '/' : $path) . substr($u, $cut);
}

/**
 * BG label → EN label for the site's own page names, taken from the UI
 * strings both languages already have (nav.* first, then every other pair).
 */
function menu_known_labels(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $bg = function_exists('lang_strings') ? lang_strings('bg') : [];
    $en = function_exists('lang_strings') ? lang_strings('en') : [];
    $keys = array_keys($bg);
    usort($keys, fn($a, $b) => (int) !str_starts_with((string) $a, 'nav.') <=> (int) !str_starts_with((string) $b, 'nav.'));
    $out = [];
    foreach ($keys as $k) {
        $b = $bg[$k] ?? null;
        $e = $en[$k] ?? null;
        if (!is_string($b) || !is_string($e) || $b === '' || $e === '' || $b === $e) continue;
        if (mb_strlen($b) > 40 || str_contains($b, '{')) continue; // menu-sized names only
        $norm = mb_strtolower(trim($b));
        $out[$norm] ??= $e;
    }
    return $cache = $out;
}

function menu_known_label_en(string $bg_label): ?string
{
    return menu_known_labels()[mb_strtolower(trim($bg_label))] ?? null;
}

/** An EN label that is really the untranslated BG one (still has Cyrillic). */
function menu_label_untranslated(string $bg_label, string $en_label): bool
{
    return $en_label !== '' && trim($en_label) === trim($bg_label)
        && preg_match('/\p{Cyrillic}/u', $en_label) === 1;
}

/**
 * Rows for the editor from a stored section {bg:[...], en:[...]}.
 *
 * Pairs each BG item with an EN one: first by address (the EN item whose
 * address is the mapped BG address), then by position among the leftovers.
 * EN items that still have no BG twin become rows with an empty BG side so
 * nothing an admin already has is lost. A BG item with no EN twin gets its EN
 * side filled in (known name, else the BG label, flagged as untranslated).
 *
 * @return list<array{label_bg:string,url_bg:string,label_en:string,url_en:string}>
 */
function menu_rows_from_section(array $section): array
{
    $clean = function ($items): array {
        $out = [];
        foreach (is_array($items) ? $items : [] as $it) {
            if (!is_array($it)) continue;
            $out[] = ['label' => trim((string) ($it['label'] ?? '')), 'url' => trim((string) ($it['url'] ?? ''))];
        }
        return $out;
    };
    $bg = $clean($section['bg'] ?? []);
    $en = $clean($section['en'] ?? []);

    $pair = array_fill(0, count($bg), null); // bg index → en index
    $used = [];

    // 1. by mapped address
    foreach ($bg as $i => $b) {
        $want = menu_url_key(menu_en_url($b['url']));
        foreach ($en as $j => $e) {
            if (isset($used[$j])) continue;
            if (menu_url_key($e['url']) === $want || menu_en_url_is_stale_copy($b['url'], $e['url'])) {
                $pair[$i] = $j; $used[$j] = true; break;
            }
        }
    }
    // 2. by position among what is left
    $left_en = array_values(array_filter(array_keys($en), fn($j) => !isset($used[$j])));
    foreach ($bg as $i => $b) {
        if ($pair[$i] !== null || !$left_en) continue;
        $j = array_shift($left_en);
        $pair[$i] = $j;
        $used[$j] = true;
    }

    $rows   = [];
    $row_of = []; // en index → row index
    foreach ($bg as $i => $b) {
        if ($pair[$i] !== null) {
            $e = $en[$pair[$i]];
            $row_of[$pair[$i]] = count($rows);
            $url_en = menu_en_url_is_stale_copy($b['url'], $e['url']) ? menu_en_url($b['url']) : $e['url'];
            $rows[] = ['label_bg' => $b['label'], 'url_bg' => $b['url'], 'label_en' => $e['label'], 'url_en' => $url_en];
        } else {
            $rows[] = [
                'label_bg' => $b['label'],
                'url_bg'   => $b['url'],
                'label_en' => menu_known_label_en($b['label']) ?? $b['label'],
                'url_en'   => menu_en_url($b['url']),
            ];
        }
    }
    // 3. EN items with no BG twin: kept, with the BG side to fill in, placed
    //    right after the item they followed in the EN list.
    foreach (array_keys($en) as $j) {
        if (isset($used[$j])) continue;
        $at = 0;
        for ($k = $j - 1; $k >= 0; $k--) {
            if (isset($row_of[$k])) { $at = $row_of[$k] + 1; break; }
        }
        foreach ($row_of as $kk => $r) if ($r >= $at) $row_of[$kk] = $r + 1;
        array_splice($rows, $at, 0, [['label_bg' => '', 'url_bg' => '', 'label_en' => $en[$j]['label'], 'url_en' => $en[$j]['url']]]);
        $row_of[$j] = $at;
    }
    return $rows;
}

/**
 * Turn posted rows into the stored section. Fully empty rows are dropped. A
 * row must have its BG text and address; a missing EN side is filled from the
 * BG one, so the EN menu always has the same items in the same order.
 *
 * @param list<array{label_bg?:string,url_bg?:string,label_en?:string,url_en?:string}> $rows
 * @return array{section: array{bg:list<array>,en:list<array>}, rows: list<array>, errors: array<int,array<string,string>>}
 */
function menu_section_from_rows(array $rows): array
{
    $bg = $en = $kept = $errors = [];
    foreach ($rows as $row) {
        $r = [];
        foreach (['label_bg', 'url_bg', 'label_en', 'url_en'] as $f) {
            $v = $row[$f] ?? '';
            $r[$f] = is_string($v) ? mb_substr(trim(strip_tags($v)), 0, 300) : '';
        }
        if (implode('', $r) === '') continue;
        $i = count($kept);

        if ($r['label_bg'] === '') $errors[$i]['label_bg'] = 'Попълнете текста на български или премахнете реда.';
        if ($r['url_bg'] === '')   $errors[$i]['url_bg']   = 'Попълнете адреса на страницата.';
        elseif (!menu_url_ok($r['url_bg'])) $errors[$i]['url_bg'] = 'Адресът трябва да започва с „/“ или с https://';
        if ($r['url_en'] !== '' && !menu_url_ok($r['url_en'])) $errors[$i]['url_en'] = 'Адресът трябва да започва с „/“ или с https://';

        if ($r['url_en'] === '' && $r['url_bg'] !== '') $r['url_en'] = menu_en_url($r['url_bg']);
        if ($r['label_en'] === '') $r['label_en'] = menu_known_label_en($r['label_bg']) ?? $r['label_bg'];

        $kept[] = $r;
        $bg[] = ['url' => $r['url_bg'], 'label' => $r['label_bg']];
        $en[] = ['url' => $r['url_en'], 'label' => $r['label_en']];
    }
    return ['section' => ['bg' => $bg, 'en' => $en], 'rows' => $kept, 'errors' => $errors];
}

/** A menu address: a site path, an http(s) link, mailto:, tel: or #anchor — never javascript: and the like. */
function menu_url_ok(string $url): bool
{
    if ($url === '') return false;
    if ($url[0] === '#' || ($url[0] === '/' && !str_starts_with($url, '//'))) return true;
    return (bool) preg_match('~^(https?://[^\s/]+|mailto:\S+|tel:[+\d][\d\s\-()]*$)~i', $url);
}
