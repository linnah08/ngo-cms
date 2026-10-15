<?php
/**
 * A site's own extensions to the shared code.
 *
 * includes/site-extensions.php is site-owned: no release ships it, so the
 * updater never touches it (like includes/themes-site.php). Without it every
 * site behaves exactly as ngo-cms does on its own. With it, a site can add:
 *
 *   'recipients'     => donation recipients beside the organisation itself
 *                       (more than one → donors choose; see donation_recipients())
 *   'roles'          => admin roles of its own (see site_admin_roles())
 *   'order_filters'  => filter buttons on Админ → Поръчки (site_order_filters())
 *   'reports'        => extra monthly report files (site_reports())
 *   'document_types' => its own document kinds and numbering (site_document_types())
 *   'admin_nav'      => its own links in the admin menu (site_admin_nav())
 *
 * The file returns an array with any of those keys. Each entry is checked; a
 * broken one is logged and skipped — a mistake in that file must never take the
 * site down.
 */

/** Where a site keeps its extensions. Site-owned: never in a release (tests point it elsewhere). */
function site_extensions_file(): string
{
    return (string) ($GLOBALS['_om_site_extensions_file'] ?? dirname(__DIR__) . '/includes/site-extensions.php');
}

/** The raw array the site's file returns, or [] (missing, unreadable or not an array). */
function site_extensions(): array
{
    static $cache = [];
    $file = site_extensions_file();
    if (array_key_exists($file, $cache)) return $cache[$file];
    if ($file === '' || !is_file($file)) return $cache[$file] = [];
    try {
        $ext = (static fn(string $f) => require $f)($file);
    } catch (Throwable $e) {
        error_log('site_extensions: ' . $file . ' failed to load — ' . $e->getMessage());
        return $cache[$file] = [];
    }
    if (!is_array($ext)) {
        error_log('site_extensions: ' . $file . ' does not return an array — ignored');
        return $cache[$file] = [];
    }
    return $cache[$file] = $ext;
}

/** One section of the site's extensions, as a [key => array] map (non-arrays dropped). */
function site_extension_section(string $section): array
{
    $list = site_extensions()[$section] ?? [];
    if (!is_array($list)) {
        error_log("site_extensions: '$section' is not a list — ignored");
        return [];
    }
    return array_filter($list, static fn($v, $k) => is_string($k) && is_array($v), ARRAY_FILTER_USE_BOTH);
}

/** A key a site may use for its own entries: lowercase letters, digits, _ or -. */
function site_extension_key_ok(string $key): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $key);
}

/**
 * $path when it is a path on this site ("/quiz/", "/admin/quiz.php?tab=2"), else
 * null. Never another host: "//evil.example", "/\evil", "https://…", spaces and
 * control characters are all refused.
 */
function site_extension_local_path(mixed $path): ?string
{
    if (!is_string($path) || $path === '' || strlen($path) > 500) return null;
    if (!preg_match('#^/(?![/\\\\])[^\s\\\\\x00-\x1f\x7f]*$#u', $path)) return null;
    return $path;
}

// ── Admin menu ───────────────────────────────────────────────────────────────

/**
 * A site's own links in the admin menu: key => [label, href, roles, active_nav].
 * `href` is a path on this site (its own admin page or any other page), `roles`
 * the roles that see the link (default: administrators only), `active_nav` the
 * $active_nav value of the page it opens (default: the key) so the link lights
 * up there. With $role, only the links that role sees.
 */
function site_admin_nav(?string $role = null): array
{
    $out = [];
    foreach (site_extension_section('admin_nav') as $key => $n) {
        $label = trim((string) ($n['label'] ?? ''));
        $href  = site_extension_local_path($n['href'] ?? null);
        if (!site_extension_key_ok($key) || $label === '' || $href === null) {
            error_log("site_extensions: admin menu link '$key' needs a label and a link to a page on this site — skipped");
            continue;
        }
        $roles = array_values(array_filter((array) ($n['roles'] ?? ['admin']), 'is_string'));
        if (!$roles) $roles = ['admin'];
        $active = (string) ($n['active_nav'] ?? $key);
        $out[$key] = ['label' => $label, 'href' => $href, 'roles' => $roles,
                      'active_nav' => preg_match('/^[a-z0-9_-]{1,40}$/i', $active) ? $active : $key];
    }
    if ($role === null) return $out;
    return array_filter($out, static fn($n) => in_array($role, $n['roles'], true));
}

// ── Donation recipients ──────────────────────────────────────────────────────

/** The recipient every donation goes to unless a site lists others. */
const DONATION_RECIPIENT_DEFAULT = 'foundation';

/**
 * Who a donation can go to: key => [label_bg, label_en, hint_bg, hint_en,
 * hint_url, hint_url_bg, hint_url_en, purpose_bg, purpose_en, default]. Without site recipients there is one — the
 * organisation itself, with the purpose from site.config (DONATION_PURPOSE_BG/EN)
 * — and donors are never asked to choose. A site's list replaces it; exactly one
 * entry is the default (the first, unless one says 'default' => true).
 * hint_bg/hint_en is plain text shown under the choice, followed by a "learn more"
 * link: hint_url_bg on the Bulgarian site, hint_url_en on the English one, each
 * falling back to hint_url (one link for both). A link is a path on the site
 * ("/novini/…") or a full http(s) address; anything else is dropped.
 */
function donation_recipients(): array
{
    static $cache = null;
    $file = site_extensions_file();
    if (is_array($cache) && $cache['file'] === $file) return $cache['list'];

    $site_bg = defined('SITE_NAME_BG') ? (string) SITE_NAME_BG : '';
    $site_en = defined('SITE_NAME_EN') ? (string) SITE_NAME_EN : $site_bg;
    $own = [DONATION_RECIPIENT_DEFAULT => [
        'label_bg'   => $site_bg,
        'label_en'   => $site_en,
        'hint_bg'    => '', 'hint_en' => '', 'hint_url' => '', 'hint_url_bg' => '', 'hint_url_en' => '',
        'purpose_bg' => defined('DONATION_PURPOSE_BG') ? (string) DONATION_PURPOSE_BG : 'За дейността и програмите на ' . $site_bg,
        'purpose_en' => defined('DONATION_PURPOSE_EN') ? (string) DONATION_PURPOSE_EN : 'For the activities and programmes of ' . $site_en,
        'default'    => true,
    ]];

    $list = [];
    foreach (site_extension_section('recipients') as $key => $r) {
        if (!site_extension_key_ok($key)) { error_log("site_extensions: recipient key '$key' is not allowed — skipped"); continue; }
        $label_bg = trim((string) ($r['label_bg'] ?? ''));
        $purpose_bg = trim((string) ($r['purpose_bg'] ?? ''));
        if ($label_bg === '' || $purpose_bg === '') { error_log("site_extensions: recipient '$key' needs label_bg and purpose_bg — skipped"); continue; }
        $base = $own[$key] ?? [];
        $list[$key] = [
            'label_bg'   => $label_bg,
            'label_en'   => trim((string) ($r['label_en'] ?? '')) ?: $label_bg,
            'hint_bg'    => trim((string) ($r['hint_bg'] ?? '')),
            'hint_en'    => trim((string) ($r['hint_en'] ?? '')),
            'hint_url'   => $url = donation_recipient_url_clean($r['hint_url'] ?? ''),
            'hint_url_bg'=> donation_recipient_url_clean($r['hint_url_bg'] ?? '') ?: $url,
            'hint_url_en'=> donation_recipient_url_clean($r['hint_url_en'] ?? '') ?: $url,
            'purpose_bg' => $purpose_bg,
            'purpose_en' => trim((string) ($r['purpose_en'] ?? '')) ?: ($base['purpose_en'] ?? $purpose_bg),
            'default'    => !empty($r['default']),
        ];
    }
    if (!$list) $list = $own;

    // Exactly one default.
    $defaults = array_keys(array_filter($list, static fn($r) => $r['default']));
    $default  = $defaults[0] ?? array_key_first($list);
    foreach ($list as $k => &$r) $r['default'] = ($k === $default);
    unset($r);

    $cache = ['file' => $file, 'list' => $list];
    return $list;
}

/** A recipient's "learn more" link as given, or '' unless it is a site path or an http(s) address. */
function donation_recipient_url_clean(mixed $url): string
{
    $url = is_string($url) ? trim($url) : '';
    return preg_match('#^(/(?![/\\\\])|https?://)[^\s"<>]*$#', $url) ? $url : '';
}

/** The recipient's "learn more" link for $lang ('' when there is none). */
function donation_recipient_hint_url(?string $key, string $lang = 'bg'): string
{
    $r = donation_recipients()[donation_recipient_clean($key)];
    return $lang === 'en' ? $r['hint_url_en'] : $r['hint_url_bg'];
}

/** Do donors choose a recipient? Only when the site lists more than one. */
function donation_recipients_choosable(): bool
{
    return count(donation_recipients()) > 1;
}

function donation_recipient_default(): string
{
    foreach (donation_recipients() as $k => $r) if ($r['default']) return $k;
    return DONATION_RECIPIENT_DEFAULT;
}

/** A posted or stored recipient, or the default when it isn't one of the site's. */
function donation_recipient_clean(mixed $value): string
{
    return (is_string($value) && isset(donation_recipients()[$value])) ? $value : donation_recipient_default();
}

/** The recipient's name in $lang. */
function donation_recipient_label(?string $key, string $lang = 'bg'): string
{
    $r = donation_recipients()[donation_recipient_clean($key)];
    return $lang === 'en' ? $r['label_en'] : $r['label_bg'];
}

/** What the donation is for — printed on the donation certificate. */
function donation_recipient_purpose(?string $key, string $lang = 'bg'): string
{
    $r = donation_recipients()[donation_recipient_clean($key)];
    return $lang === 'en' ? $r['purpose_en'] : $r['purpose_bg'];
}

/** The recipient of a donation line in an order's items JSON (old lines have none: the default). */
function donation_line_recipient(array $line): string
{
    return donation_recipient_clean($line['recipient'] ?? null);
}

// ── Admin roles ──────────────────────────────────────────────────────────────

/** The roles ngo-cms itself knows: key => label shown in Админ → Потребители. */
const ADMIN_BUILTIN_ROLES = ['admin' => 'Администратор', 'author' => 'Автор', 'shop_admin' => 'Магазин'];

/**
 * A site's own admin roles: key => [label, home, admin_pages]. Someone with such
 * a role logs in through the normal admin login but works only in the site's own
 * pages: every admin page sends them to `home` (a path on this site), except the
 * admin/*.php files listed in `admin_pages`. They never get the public site's
 * edit bar. A site's own page checks the role with admin_require_site_role().
 */
function site_admin_roles(): array
{
    $out = [];
    foreach (site_extension_section('roles') as $key => $r) {
        if (!site_extension_key_ok($key) || isset(ADMIN_BUILTIN_ROLES[$key])) { error_log("site_extensions: role '$key' is not allowed — skipped"); continue; }
        $home = (string) ($r['home'] ?? '');
        if (!preg_match('#^/[A-Za-z0-9/_.-]*$#', $home) || str_starts_with($home, '/admin')) { error_log("site_extensions: role '$key' needs a home outside /admin — skipped"); continue; }
        $out[$key] = [
            'label'       => trim((string) ($r['label'] ?? '')) ?: $key,
            'home'        => $home,
            'admin_pages' => array_values(array_filter((array) ($r['admin_pages'] ?? []), static fn($p) => is_string($p) && preg_match('/^[a-z0-9_-]+\.php$/', $p))),
        ];
    }
    return $out;
}

/** Every role a user can have: key => label (ngo-cms's own first). */
function admin_roles(): array
{
    return ADMIN_BUILTIN_ROLES + array_map(static fn($r) => $r['label'], site_admin_roles());
}

/** Is this one of the site's own roles (not admin, author or shop manager)? */
function admin_role_is_site_role(string $role): bool
{
    return isset(site_admin_roles()[$role]);
}

/**
 * Where a site-role user goes instead of $script (an admin/*.php basename), or
 * null when they may stay (or aren't a site-role user).
 */
function site_role_redirect(string $role, string $script): ?string
{
    $r = site_admin_roles()[$role] ?? null;
    if ($r === null || in_array($script, $r['admin_pages'], true)) return null;
    return $r['home'];
}

/** For a site's own page: only someone with $role (or an administrator) gets in. */
function admin_require_site_role(string $role, string $login = '/admin/login.php'): void
{
    if (!admin_logged_in()) { header('Location: ' . $login); exit; }
    $mine = (string) (admin_user()['role'] ?? '');
    if ($mine !== $role && $mine !== 'admin') { http_response_code(403); exit('Нямате достъп до тази страница.'); }
}

// ── Order list filters ───────────────────────────────────────────────────────

/**
 * A site's own filter buttons on Админ → Поръчки: key => [label, where, params].
 * `where` is an SQL condition on the orders table written by the site itself
 * (never built from visitor input), e.g. a product the site follows closely.
 */
function site_order_filters(): array
{
    $out = [];
    foreach (site_extension_section('order_filters') as $key => $f) {
        $label = trim((string) ($f['label'] ?? ''));
        $where = trim((string) ($f['where'] ?? ''));
        if (!site_extension_key_ok($key) || $label === '' || $where === '' || str_contains($where, ';')) {
            error_log("site_extensions: order filter '$key' needs a label and a where condition — skipped");
            continue;
        }
        $out[$key] = ['label' => $label, 'where' => $where, 'params' => array_values(array_filter((array) ($f['params'] ?? []), 'is_scalar'))];
    }
    return $out;
}

/**
 * SQL for "has a donation line for $recipient". Old lines without a recipient
 * belong to the default one, so the default matches every donation that names
 * none of the other recipients. Returns [condition, params].
 */
function donation_recipient_sql(string $recipient): array
{
    $search = "JSON_SEARCH(items, 'one', ?, NULL, '$[*].recipient') IS NOT NULL";
    if ($recipient !== donation_recipient_default()) return [$search, [$recipient]];
    $others = array_values(array_diff(array_keys(donation_recipients()), [$recipient]));
    if (!$others) return ['1=1', []];
    return ['NOT (' . implode(' OR ', array_fill(0, count($others), $search)) . ')', $others];
}

// ── Monthly reports ──────────────────────────────────────────────────────────

/**
 * A site's own monthly report files: key => [label, filename, csv, summary].
 * `csv` is a function (int $year, int $month): string returning the CSV text;
 * `summary` optionally returns one plain-text line for the monthly email
 * (e.g. an amount owed to a partner). `filename` is a sprintf pattern given
 * year and month, e.g. "partner-%04d-%02d.csv". Offered on Админ → Месечен
 * отчет and attached to the monthly report email.
 */
function site_reports(): array
{
    $out = [];
    foreach (site_extension_section('reports') as $key => $r) {
        $label = trim((string) ($r['label'] ?? ''));
        $file  = (string) ($r['filename'] ?? '');
        if (!site_extension_key_ok($key) || $label === '' || !is_callable($r['csv'] ?? null)
            || !preg_match('/^[A-Za-z0-9_%.-]+\.csv$/', $file)) {
            error_log("site_extensions: report '$key' needs a label, a filename ending in .csv and a csv function — skipped");
            continue;
        }
        $out[$key] = ['label' => $label, 'filename' => $file, 'csv' => $r['csv'],
                      'summary' => is_callable($r['summary'] ?? null) ? $r['summary'] : null];
    }
    return $out;
}

/** One site report for a month: ['name' => file name, 'csv' => text], or null when it fails (logged). */
function site_report_build(string $key, int $year, int $month): ?array
{
    $r = site_reports()[$key] ?? null;
    if ($r === null) return null;
    try {
        $csv = ($r['csv'])($year, $month);
        if (!is_string($csv)) throw new UnexpectedValueException('did not return text');
        return ['name' => sprintf($r['filename'], $year, $month), 'csv' => $csv];
    } catch (Throwable $e) {
        error_log("site_extensions: report '$key' failed — " . $e->getMessage());
        return null;
    }
}

/** A site report's line for the monthly email, or '' (none, or it failed). */
function site_report_summary(string $key, int $year, int $month): string
{
    $fn = site_reports()[$key]['summary'] ?? null;
    if ($fn === null) return '';
    try {
        return trim((string) $fn($year, $month));
    } catch (Throwable $e) {
        error_log("site_extensions: report '$key' summary failed — " . $e->getMessage());
        return '';
    }
}

// ── Document types ───────────────────────────────────────────────────────────

/**
 * A site's own document kinds (e.g. a certificate its own pages issue):
 * key => [label, plural, digits]. The site's pages create and number them
 * (document_sequence_next()); ngo-cms lists their counter on Админ → Плащания
 * and names their files when an admin downloads them. documents.type and
 * document_sequences.type take the key as it is.
 */
function site_document_types(): array
{
    $builtin = ['invoice', 'receipt', 'donation_cert', 'credit_note', 'storno_receipt', 'distribution_protocol'];
    $out = [];
    foreach (site_extension_section('document_types') as $key => $d) {
        $label = trim((string) ($d['label'] ?? ''));
        if (!site_extension_key_ok($key) || strlen($key) > 30 || in_array($key, $builtin, true) || $label === '') {
            error_log("site_extensions: document type '$key' needs a label and a new key of up to 30 characters — skipped");
            continue;
        }
        $digits = (int) ($d['digits'] ?? 5);
        $out[$key] = ['label' => $label, 'plural' => trim((string) ($d['plural'] ?? '')) ?: $label, 'digits' => max(1, min(12, $digits))];
    }
    return $out;
}

/** What a document of $type is called in the admin ("Фактура", or a site's own label). */
function document_type_label(string $type): string
{
    $builtin = ['invoice' => 'Фактура', 'receipt' => 'Бележка', 'donation_cert' => 'Сертификат',
                'credit_note' => 'Кредитно известие', 'storno_receipt' => 'Сторно бележка'];
    return $builtin[$type] ?? (site_document_types()[$type]['label'] ?? 'Документ');
}

/**
 * Claim the next number of $type atomically (creating its counter on first use)
 * and return it. Call inside the caller's transaction.
 */
function document_sequence_next(PDO $pdo, string $type): int
{
    $pdo->prepare('INSERT IGNORE INTO document_sequences (type, last_number) VALUES (?, 0)')->execute([$type]);
    $lock = $pdo->prepare('SELECT last_number FROM document_sequences WHERE type = ? FOR UPDATE');
    $lock->execute([$type]);
    $next = (int) $lock->fetchColumn() + 1;
    $pdo->prepare('UPDATE document_sequences SET last_number = ? WHERE type = ?')->execute([$next, $type]);
    return $next;
}
