<?php
/**
 * Pages an admin creates from Admin → Страници (as opposed to the built-in
 * pages, which are PHP files). One JSON file per page, content/pages/<id>.json:
 *
 *   { version, rev, id, status: draft|published, title_bg, title_en,
 *     slug_bg, slug_en, sections: [...same as content/home.json...], created, updated }
 *
 * The sections are edited and drawn by the front page's own section editor and
 * renderer (includes/home.php, admin/home-sections.php) pointed at this file —
 * see home_target_page(). The page is served at /<slug_bg>/ and /en/<slug_en>/
 * by page.php, which .htaccess reaches only when no real file or folder has
 * that name: a built-in page always wins.
 *
 * content/pages/ belongs to the site, like content/home.json: never in git,
 * never in a release ZIP, never touched by the updater.
 */

const CPAGE_ID_RE   = '/^p_[a-z0-9]{8}$/';
const CPAGE_SLUG_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
const CPAGE_SLUG_MAX  = 80;
const CPAGE_TITLE_MAX = 150;

/**
 * Addresses a created page may never take, beyond the site's own folders and
 * files: old addresses .htaccess redirects elsewhere, and names that are, or
 * may one day be, part of the site itself.
 */
const CPAGE_RESERVED_COMMON = ['en', 'bg', 'admin', 'assets', 'api', 'content', 'includes', 'templates', 'vendor',
    'node_modules', 'errors', 'install', 'logs', 'backups', 'documents', 'uploads', 'data', 'cron', 'relay', 'tests',
    'docs', 'index', 'page', 'sitemap', 'robots', 'config', 'migrate', 'migrations', 'cgi-bin', 'well-known',
    'wp-admin', 'wp-login', 'wp-content', 'login', 'logout', 'search', 'feed', 'rss'];
const CPAGE_RESERVED_BG = ['about', 'how-to-help', 'programs', 'projects', 'contacts', 'legal-info', 'legal-notice',
    'home', '3-months', 'produkt', 'produkt-kategoriya', 'porachka', 'author', 'category'];
const CPAGE_RESERVED_EN = ['programs', 'home', 'product', 'product-category', 'porachka', 'author', 'category', '3-months'];

function cpage_dir(): string
{
    return $GLOBALS['_om_pages_dir'] ?? CONTENT_PATH . '/pages';
}

function cpage_valid_id(string $id): bool
{
    return preg_match(CPAGE_ID_RE, $id) === 1;
}

/** The page's file, or null for anything that is not a page id (so never a path outside content/pages/). */
function cpage_file(string $id): ?string
{
    return cpage_valid_id($id) ? cpage_dir() . '/' . $id . '.json' : null;
}

/** A stored page with every key present and every value of the right kind, or null. */
function cpage_normalize(mixed $raw, string $id): ?array
{
    if (!is_array($raw) || ($raw['id'] ?? null) !== $id) return null;
    $str = fn(string $k): string => is_string($raw[$k] ?? null) ? $raw[$k] : '';
    $slug = fn(string $k): string => preg_match(CPAGE_SLUG_RE, $str($k)) ? $str($k) : '';
    return [
        'version'  => 1,
        'rev'      => (int) ($raw['rev'] ?? 0),
        'id'       => $id,
        'status'   => ($raw['status'] ?? '') === 'published' ? 'published' : 'draft',
        'title_bg' => $str('title_bg'),
        'title_en' => $str('title_en'),
        'slug_bg'  => $slug('slug_bg'),
        'slug_en'  => $slug('slug_en'),
        'sections' => is_array($raw['sections'] ?? null) ? array_values($raw['sections']) : [],
        'created'  => $str('created'),
        'updated'  => $str('updated'),
    ];
}

/** Every created page, by id, sorted by Bulgarian title. Cached per request; writes reset it. */
function &cpage_cache(): array
{
    static $cache = [];
    return $cache;
}

function cpage_all(): array
{
    $cache = &cpage_cache();
    $dir   = cpage_dir();
    if (isset($cache[$dir])) return $cache[$dir];
    $out = [];
    foreach (glob($dir . '/p_*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (!cpage_valid_id($id)) continue;
        $page = cpage_normalize(json_decode((string) @file_get_contents($file), true), $id);
        if ($page !== null) $out[$id] = $page;
    }
    uasort($out, fn($a, $b) => strcoll(mb_strtolower($a['title_bg']), mb_strtolower($b['title_bg'])));
    return $cache[$dir] = $out;
}

function cpage_cache_reset(): void
{
    $cache = &cpage_cache();
    $cache = [];
}

function cpage_get(string $id): ?array
{
    return cpage_valid_id($id) ? (cpage_all()[$id] ?? null) : null;
}

function cpage_by_slug(string $lang, string $slug): ?array
{
    if ($slug === '' || !preg_match(CPAGE_SLUG_RE, $slug)) return null;
    $key = $lang === 'en' ? 'slug_en' : 'slug_bg';
    foreach (cpage_all() as $p) {
        if ($p[$key] === $slug) return $p;
    }
    return null;
}

/** Public addresses of a page. */
function cpage_url(array $page, string $lang): string
{
    return $lang === 'en' ? '/en/' . $page['slug_en'] . '/' : '/' . $page['slug_bg'] . '/';
}

function cpage_title(array $page, string $lang): string
{
    return $lang === 'en' && trim($page['title_en']) !== '' ? $page['title_en'] : $page['title_bg'];
}

/**
 * [lang, slug] for a request path that has the shape of a created page's
 * address — /<slug>/ or /en/<slug>/ — else null.
 */
function cpage_parse_path(string $path): ?array
{
    if (preg_match('#^/en/([a-z0-9-]+)/?$#', $path, $m)) return ['en', $m[1]];
    if (preg_match('#^/([a-z0-9-]+)/?$#', $path, $m) && $m[1] !== 'en') return ['bg', $m[1]];
    return null;
}

// ── Addresses ─────────────────────────────────────────────────────────────────

/** Latin address from a title: Cyrillic transliterated, then only a-z 0-9 and single hyphens. */
function cpage_slugify(string $text): string
{
    $s = ascii_slug($text);   // config.php: Bulgarian transliteration + slug()
    if (class_exists('Normalizer')) {
        $s = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D));
    }
    $s = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($s))), '-');
    if (strlen($s) > 60) {
        $s = substr($s, 0, 60);
        $cut = strrpos($s, '-');
        $s = trim($cut !== false && $cut > 20 ? substr($s, 0, $cut) : $s, '-');
    }
    return $s;
}

/**
 * Names a created page cannot use as its address in $lang: every file and folder
 * of the site (so every built-in page), the page-address map, and the fixed lists above.
 */
function cpage_reserved_slugs(string $lang): array
{
    static $cache = [];
    $key = $lang . '|' . ROOT_PATH;
    if (isset($cache[$key])) return $cache[$key];
    $names = CPAGE_RESERVED_COMMON;
    $names = array_merge($names, $lang === 'en' ? CPAGE_RESERVED_EN : CPAGE_RESERVED_BG);
    $dir = $lang === 'en' ? ROOT_PATH . '/en' : ROOT_PATH;
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $names[] = strtolower($entry);
        $names[] = strtolower((string) preg_replace('/\.[a-z0-9]+$/i', '', ltrim($entry, '.')));
    }
    require_once __DIR__ . '/menus.php';
    foreach (path_map_builtin() as $bg => $en) {
        $parts = explode('/', trim($lang === 'en' ? substr($en, 3) : $bg, '/'));
        if ($parts[0] !== '') $names[] = $parts[0];
    }
    return $cache[$key] = array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
}

/** Why $slug cannot be this page's address in $lang, or null when it can. */
function cpage_slug_error(string $lang, string $slug, string $except_id = ''): ?string
{
    if ($slug === '') return 'Попълнете адреса.';
    if (strlen($slug) > CPAGE_SLUG_MAX) return 'Адресът е твърде дълъг — най-много ' . CPAGE_SLUG_MAX . ' знака.';
    if (!preg_match(CPAGE_SLUG_RE, $slug)) {
        return 'Адресът може да съдържа само малки латински букви, цифри и тирета между тях (например nashata-istoriya).';
    }
    if (in_array($slug, cpage_reserved_slugs($lang), true)) {
        return 'Този адрес вече се използва от страница на сайта. Изберете друг.';
    }
    $key = $lang === 'en' ? 'slug_en' : 'slug_bg';
    foreach (cpage_all() as $id => $p) {
        if ($id !== $except_id && $p[$key] === $slug) {
            return "Страницата \u{201E}" . $p['title_bg'] . "\u{201C} вече използва този адрес. Изберете друг.";
        }
    }
    return null;
}

/** A free address built from $text: the slug itself, else with -2, -3… after it. */
function cpage_unique_slug(string $lang, string $text, string $except_id = ''): string
{
    $base = cpage_slugify($text);
    if ($base === '') $base = $lang === 'en' ? 'page' : 'stranitsa';
    if (cpage_slug_error($lang, $base, $except_id) === null) return $base;
    for ($n = 2; $n < 1000; $n++) {
        $try = $base . '-' . $n;
        if (cpage_slug_error($lang, $try, $except_id) === null) return $try;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

// ── Writing ───────────────────────────────────────────────────────────────────

function cpage_clean_title(mixed $raw): string
{
    $t = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw))) : '';
    return mb_substr($t, 0, CPAGE_TITLE_MAX);
}

/** Write $page atomically. The caller holds the lock. */
function cpage_write_file(string $path, array $page): bool
{
    $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));
    if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    cpage_cache_reset();
    return true;
}

/**
 * Create a draft page. $slug_bg / $slug_en are used when given and free;
 * otherwise addresses are built from the titles.
 * @return array{ok: bool, page?: array, error?: string}
 */
function cpage_create(string $title_bg, string $title_en = '', string $slug_bg = '', string $slug_en = ''): array
{
    $title_bg = cpage_clean_title($title_bg);
    $title_en = cpage_clean_title($title_en);
    if ($title_bg === '') return ['ok' => false, 'error' => 'Напишете заглавие на страницата.'];
    $dir = cpage_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Страницата не можа да се създаде. Опитайте отново след малко.'];
    }
    // content/ is already closed to the web by the root .htaccess; this keeps
    // drafts unreadable even on a host that ignores that rule.
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");

    $slug_bg = $slug_bg !== '' && cpage_slug_error('bg', $slug_bg) === null ? $slug_bg : cpage_unique_slug('bg', $title_bg);
    $slug_en = $slug_en !== '' && cpage_slug_error('en', $slug_en) === null ? $slug_en : cpage_unique_slug('en', $title_en !== '' ? $title_en : $slug_bg);

    do { $id = 'p_' . bin2hex(random_bytes(4)); } while (is_file((string) cpage_file($id)));
    $now  = date('c');
    $page = ['version' => 1, 'rev' => 0, 'id' => $id, 'status' => 'draft',
             'title_bg' => $title_bg, 'title_en' => $title_en, 'slug_bg' => $slug_bg, 'slug_en' => $slug_en,
             'sections' => [], 'created' => $now, 'updated' => $now];
    if (!cpage_write_file((string) cpage_file($id), $page)) {
        return ['ok' => false, 'error' => 'Страницата не можа да се създаде. Опитайте отново след малко.'];
    }
    return ['ok' => true, 'page' => $page];
}

/**
 * Change one page under its lock. $fn gets the page as stored and returns the
 * new page, or a string: the message to show instead of saving.
 * @return array{ok: bool, page?: array, error?: string}
 */
function cpage_update(string $id, callable $fn): array
{
    $path = cpage_file($id);
    if ($path === null || !is_file($path)) return ['ok' => false, 'error' => 'Страницата не е намерена — може би е изтрита междувременно.'];
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) return ['ok' => false, 'error' => 'Промените не можаха да се запазят. Опитайте отново след малко.'];
    try {
        clearstatcache(true, $path);
        $cur = cpage_normalize(json_decode((string) @file_get_contents($path), true), $id);
        if ($cur === null) return ['ok' => false, 'error' => 'Файлът на страницата е повреден. Обърнете се към поддръжката.'];
        $new = $fn($cur);
        if (is_string($new)) return ['ok' => false, 'error' => $new];
        $new['id']      = $id;
        $new['version'] = 1;
        $new['rev']     = $cur['rev'] + 1;
        $new['updated'] = date('c');
        if (!cpage_write_file($path, $new)) return ['ok' => false, 'error' => 'Промените не можаха да се запазят. Опитайте отново след малко.'];
        return ['ok' => true, 'page' => $new];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cpage_set_status(string $id, string $status): array
{
    $status = $status === 'published' ? 'published' : 'draft';
    return cpage_update($id, function (array $p) use ($status) {
        if ($status === 'published' && ($p['slug_bg'] === '' || $p['slug_en'] === '')) {
            return 'Страницата няма адрес. Попълнете адресите в настройките ѝ и опитайте отново.';
        }
        $p['status'] = $status;
        return $p;
    });
}

/**
 * Save the titles and addresses from the page settings form.
 * @return array{ok: bool, page?: array, errors: array<string,string>, values: array}
 */
function cpage_save_settings(string $id, array $in): array
{
    $v = [
        'title_bg' => cpage_clean_title($in['title_bg'] ?? ''),
        'title_en' => cpage_clean_title($in['title_en'] ?? ''),
        'slug_bg'  => is_string($in['slug_bg'] ?? null) ? strtolower(trim($in['slug_bg'], " \t\n\r\0\x0B/")) : '',
        'slug_en'  => is_string($in['slug_en'] ?? null) ? strtolower(trim($in['slug_en'], " \t\n\r\0\x0B/")) : '',
    ];
    $errors = [];
    if ($v['title_bg'] === '') $errors['title_bg'] = 'Напишете заглавие на български.';
    foreach (['bg', 'en'] as $l) {
        $e = cpage_slug_error($l, $v["slug_$l"], $id);
        if ($e !== null) $errors["slug_$l"] = $e;
    }
    if ($errors) return ['ok' => false, 'errors' => $errors, 'values' => $v];
    $r = cpage_update($id, fn(array $p) => array_merge($p, $v));
    return $r['ok'] ? ['ok' => true, 'page' => $r['page'], 'errors' => [], 'values' => $v]
                    : ['ok' => false, 'errors' => ['_form' => $r['error']], 'values' => $v];
}

function cpage_delete(string $id): bool
{
    $path = cpage_file($id);
    if ($path === null || !is_file($path)) return false;
    $ok = @unlink($path);
    @unlink($path . '.lock');
    cpage_cache_reset();
    return $ok;
}

// ── Where a page is used ─────────────────────────────────────────────────────

/** Names of the menus (as Admin → Менюта calls them) that link to this page. */
function cpage_menus_using(array $page): array
{
    $names = ['header' => 'Хедър навигация', 'footer_nav' => 'Футър — Навигация', 'footer_help' => 'Футър — Как да помогна'];
    $menus = load_json(CONTENT_PATH . '/menus.json');
    $want  = ['/' . $page['slug_bg'], '/en/' . $page['slug_en']];
    $out   = [];
    foreach ($names as $key => $name) {
        foreach (['bg', 'en'] as $l) {
            foreach (is_array($menus[$key][$l] ?? null) ? $menus[$key][$l] : [] as $item) {
                $path = rtrim((string) strtok((string) ($item['url'] ?? ''), '?#'), '/');
                if (in_array($path, $want, true)) { $out[$key] = $name; continue 3; }
            }
        }
    }
    return array_values($out);
}

/**
 * After a page's address changed: point the menu links that went to the old
 * address at the new one, so no menu is left linking to nothing.
 * @return bool whether any menu changed
 */
function cpage_menus_follow(array $old, array $new): bool
{
    $swap = [];
    if ($old['slug_bg'] !== '' && $old['slug_bg'] !== $new['slug_bg']) $swap['/' . $old['slug_bg']] = '/' . $new['slug_bg'];
    if ($old['slug_en'] !== '' && $old['slug_en'] !== $new['slug_en']) $swap['/en/' . $old['slug_en']] = '/en/' . $new['slug_en'];
    if (!$swap) return false;
    $file  = CONTENT_PATH . '/menus.json';
    $menus = load_json($file);
    $changed = false;
    foreach ($menus as $key => $section) {
        if (!is_array($section)) continue;
        foreach (['bg', 'en'] as $l) {
            if (!is_array($section[$l] ?? null)) continue;
            foreach ($section[$l] as $i => $item) {
                $url  = is_array($item) ? (string) ($item['url'] ?? '') : '';
                $cut  = strcspn($url, '?#');
                $path = substr($url, 0, $cut);
                $bare = rtrim($path, '/');
                if (!isset($swap[$bare])) continue;
                $menus[$key][$l][$i]['url'] = $swap[$bare] . (str_ends_with($path, '/') ? '/' : '') . substr($url, $cut);
                $changed = true;
            }
        }
    }
    return $changed && save_json($file, $menus);
}

/** A short description for search engines: the first text on the page, else its title. */
function cpage_description(array $page, string $lang): string
{
    foreach ($page['sections'] as $s) {
        if (empty($s['visible']) || !is_array($s['fields'] ?? null)) continue;
        foreach (['text', 'body', 'intro', 'caption'] as $k) {
            $v = $s['fields'][$k] ?? null;
            if (!is_array($v)) continue;
            $t = (string) (($lang === 'en' && trim(strip_tags((string) ($v['en'] ?? ''))) !== '') ? $v['en'] : ($v['bg'] ?? ''));
            $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t !== '') return mb_strimwidth($t, 0, 160, '…');
        }
    }
    return cpage_title($page, $lang);
}
