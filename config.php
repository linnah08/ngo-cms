<?php
// ── Error logging ─────────────────────────────────────────────────────────────
// Log lives inside the project under logs/ (blocked from public by .htaccess).
// Tail it on the server with: tail -f /path/to/site/logs/errors.log
define('ERROR_LOG_FILE', __DIR__ . '/logs/errors.log');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function _om_log(string $level, string $message, string $file = '', int $line = 0): void
{
    $entry = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message
           . ($file ? ' in ' . $file . ':' . $line : '')
           . PHP_EOL;
    @file_put_contents(ERROR_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

// Catches E_WARNING, E_NOTICE, E_USER_*, etc.
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    _om_log($errno, $errstr, $errfile, $errline);
    return false; // let PHP also apply its default behaviour
});

// Catches uncaught exceptions (including PDOException, TypeError, etc.)
set_exception_handler(function(Throwable $e): void {
    _om_log(
        get_class($e),
        $e->getMessage() . "\n" . $e->getTraceAsString(),
        $e->getFile(),
        $e->getLine()
    );
    try {
        if (!function_exists('error_alert_capture')) {
            @require_once __DIR__ . '/includes/error-alerts.php';
        }
        if (function_exists('error_alert_capture')) {
            error_alert_capture(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        }
    } catch (\Throwable) {}
    http_response_code(500);
    // Show a branded error page; never expose the stack trace to the browser
    $err_page = $_SERVER['DOCUMENT_ROOT'] . '/errors/500.php';
    if (file_exists($err_page)) {
        require $err_page;
    } else {
        echo '<!DOCTYPE html><html><head><title>500</title></head><body><h1>500 — Something went wrong</h1><p>The error has been logged.</p></body></html>';
    }
    exit(1);
});

// Catches E_ERROR / E_PARSE / compile errors that bypass the handlers above
register_shutdown_function(function(): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        _om_log('FATAL', $e['message'], $e['file'], $e['line']);
        try {
            if (!function_exists('error_alert_capture')) {
                @require_once ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/includes/error-alerts.php';
            }
            if (function_exists('error_alert_capture')) {
                error_alert_capture('FATAL', $e['message'], $e['file'], $e['line']);
            }
        } catch (\Throwable) {}
        if (!headers_sent()) {
            http_response_code(500);
            $err_page = ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/errors/500.php';
            if (file_exists($err_page)) { require $err_page; }
        }
    }
});
// ─────────────────────────────────────────────────────────────────────────────

// ============================================
// SITE CONFIGURATION
// ============================================

// ── Organisation / site configuration ─────────────────────────────────────────
// All NGO-specific values (name, URL, contact, bank details, socials, analytics
// IDs, feature toggles) live in site.config.php. Copy site.config.example.php to
// site.config.php and edit it — that is the single file an adopter rebrands.
// The example is loaded as a fallback so a fresh clone still boots.
if (file_exists(__DIR__ . '/site.config.php')) {
    require_once __DIR__ . '/site.config.php';
} elseif (file_exists(__DIR__ . '/site.config.example.php')) {
    require_once __DIR__ . '/site.config.example.php';
}

// Derived display logic (not configuration).
define('SHOW_DUAL_CURRENCY', date('Y-m-d') < DUAL_CURRENCY_UNTIL);

// Start session early — must happen before any output so the cookie can be set.
// CSRF and cart both depend on $_SESSION being available before HTML is rendered.
// Set cookie domain to parent domain (.oddminds.org / .oddminds.test) so that
// subdomains like lafetki.oddminds.org share the same session — required for
// cross-subdomain form submissions (e.g. lafetki → /campaign/checkout.php).
if (session_status() === PHP_SESSION_NONE) {
    // Use a custom cookie name (not the default PHPSESSID). When the session
    // cookie switched from host-only to domain=.oddminds.org (for lafetki
    // subdomain sharing), browsers that had cached a host-only PHPSESSID ended
    // up holding TWO cookies of the same name; the stale host-only one took
    // precedence and silently broke login. A fresh name sidesteps the collision
    // entirely — old PHPSESSID cookies are ignored and the new name only ever
    // exists with domain=.oddminds.org.
    session_name('OMSESSID');
    if (str_starts_with(SITE_URL, 'https')) {
        // Production (HTTPS): set .domain so lafetki subdomain shares the session;
        // leave cookie_secure=1 as set by .user.ini.
        $__host  = $_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST) ?? '';
        $__parts = explode('.', $__host);
        if (count($__parts) >= 2) {
            ini_set('session.cookie_domain', '.' . implode('.', array_slice($__parts, -2)));
        }
    } else {
        // Local dev (HTTP): no domain override — host-only cookie works in all
        // browsers without .test TLD quirks; disable Secure flag from .user.ini.
        ini_set('session.cookie_secure', '0');
    }
    session_start();
}

// ── Cross-subdomain admin-bar token ───────────────────────────────────────────
// A separate HMAC-signed cookie (om_admin_tok) lets subdomains (e.g. lafetki.oddminds.org)
// know an admin is logged in without sharing the session cookie (which would
// cause session interference between the main site and subdomains).
// The token is set on login and cleared on logout; it is ONLY used to decide
// whether to render the read-only admin bar, never to authorise writes.

define('ADMIN_BAR_COOKIE', 'om_admin_tok');
define('ADMIN_BAR_TTL',    8 * 3600); // matches ADMIN_SESSION_HOURS

function _admin_bar_secret(): string {
    // Derive a stable secret from constants that are always defined.
    return hash('sha256', SITE_NAME_EN . SITE_PHONE . SITE_IBAN);
}

function _admin_bar_domain(): string {
    $host = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
    $parts = explode('.', $host);
    if (count($parts) >= 2 && $host !== 'localhost') {
        return '.' . implode('.', array_slice($parts, -2));
    }
    return '';
}

function admin_bar_token_set(): void {
    $exp     = time() + ADMIN_BAR_TTL;
    $payload = $exp . '|admin';
    $sig     = hash_hmac('sha256', $payload, _admin_bar_secret());
    $value   = $payload . '|' . $sig;
    $domain  = _admin_bar_domain();
    setcookie(ADMIN_BAR_COOKIE, $value, [
        'expires'  => $exp,
        'path'     => '/',
        'domain'   => $domain,
        'secure'   => str_starts_with(SITE_URL, 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function admin_bar_token_clear(): void {
    $domain = _admin_bar_domain();
    setcookie(ADMIN_BAR_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => $domain,
        'secure'   => str_starts_with(SITE_URL, 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[ADMIN_BAR_COOKIE]);
}

function admin_bar_token_verify(): bool {
    $raw = $_COOKIE[ADMIN_BAR_COOKIE] ?? '';
    if ($raw === '') return false;
    $parts = explode('|', $raw, 3);
    if (count($parts) !== 3) return false;
    [$exp, $role, $sig] = $parts;
    if ((int)$exp < time()) return false;
    $payload  = $exp . '|' . $role;
    $expected = hash_hmac('sha256', $payload, _admin_bar_secret());
    return hash_equals($expected, $sig);
}
// ─────────────────────────────────────────────────────────────────────────────

// Content paths — always relative to document root regardless of subfolder depth
define('ROOT_PATH',     $_SERVER['DOCUMENT_ROOT']);
define('CONTENT_PATH',  ROOT_PATH . '/content');
define('ARTICLES_PATH', CONTENT_PATH . '/articles');
define('PARTNERS_FILE', CONTENT_PATH . '/partners.json');
define('CENTRES_FILE',  CONTENT_PATH . '/centres.json');
define('IMPACT_FILE',   CONTENT_PATH . '/impact.json');
define('SETTINGS_FILE', CONTENT_PATH . '/settings.json');
define('TEMPLATES_PATH', ROOT_PATH . '/templates');

// Admin
define('ADMIN_SESSION_NAME', 'om_admin');
define('ADMIN_SESSION_HOURS', 8);

// Buffer (social media scheduling)
// Get IDs by running the curl commands in admin/payment.php → Buffer section
define('BUFFER_API_KEY',              '');
define('BUFFER_ORG_ID',               '');
define('BUFFER_LINKEDIN_CHANNEL_ID',  '');
define('BUFFER_FACEBOOK_CHANNEL_ID',  '');
define('BUFFER_INSTAGRAM_CHANNEL_ID', '');

// ============================================
// LANGUAGE HANDLING
// ============================================

function get_lang(): string {
    // URL segment takes priority: /en/... = English, everything else = Bulgarian
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (str_starts_with($uri, '/en') || str_starts_with($uri, '/en/')) {
        return 'en';
    }
    return 'bg';
}

function lang_url(string $path, string $lang = ''): string {
    if (!$lang) $lang = get_lang();
    $path = ltrim($path, '/');
    if ($lang === 'en') {
        return SITE_URL . '/en/' . $path;
    }
    return SITE_URL . '/' . $path;
}

function other_lang(): string {
    return get_lang() === 'bg' ? 'en' : 'bg';
}

function t(string $key): string {
    static $strings = null;
    if ($strings === null) {
        $lang = get_lang();
        $file = __DIR__ . "/content/{$lang}/strings.json";
        $strings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    }
    return $strings[$key] ?? $key;
}

// ============================================
// CURRENCY HELPERS
// ============================================

function format_eur(float $amount): string {
    return number_format($amount, 2, '.', ' ') . ' €';
}

function format_bgn(float $amount): string {
    return number_format($amount * EUR_BGN_RATE, 2, '.', ' ') . ' лв';
}

function price_html(float $eur): string {
    $eur_str = format_eur($eur);
    if (SHOW_DUAL_CURRENCY) {
        $bgn_str = format_bgn($eur);
        return '<span class="price">' . $eur_str . 
               '<span class="price__bgn">/ ' . $bgn_str . '</span></span>';
    }
    return '<span class="price">' . $eur_str . '</span>';
}

// ============================================
// CONTENT HELPERS
// ============================================

function load_json(string $path): array {
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function save_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function get_partners(): array {
    $partners = load_json(PARTNERS_FILE);
    return array_filter($partners, fn($p) => $p['active'] ?? true);
}

function get_centres(): array {
    return load_json(CENTRES_FILE);
}

function get_impact(): array {
    return load_json(IMPACT_FILE);
}

function get_settings(): array {
    return load_json(SETTINGS_FILE);
}

function get_articles(string $lang = '', int $limit = 0): array {
    if (!$lang) $lang = get_lang();
    $dir = ARTICLES_PATH . '/' . $lang;
    if (!is_dir($dir)) return [];

    $articles = [];
    foreach (glob($dir . '/*.json') as $file) {
        $data = load_json($file);
        if (!empty($data) && ($data['status'] ?? '') === 'published') {
            $data['slug'] = basename($file, '.json');
            $articles[] = $data;
        }
    }

    // Sort by date descending
    usort($articles, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

    if ($limit > 0) $articles = array_slice($articles, 0, $limit);
    return $articles;
}

function get_article(string $slug, string $lang = ''): ?array {
    if (!$lang) $lang = get_lang();
    $file = ARTICLES_PATH . '/' . $lang . '/' . $slug . '.json';
    if (!file_exists($file)) return null;
    $data = load_json($file);
    $data['slug'] = $slug;
    return $data;
}

function slug(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

// Transliterate Cyrillic → Latin then slugify — use for filenames, not URLs.
function ascii_slug(string $text): string {
    static $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',
        'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch',
        'ш'=>'sh','щ'=>'sht','ъ'=>'a','ь'=>'','ю'=>'yu','я'=>'ya',
        'А'=>'a','Б'=>'b','В'=>'v','Г'=>'g','Д'=>'d','Е'=>'e','Ж'=>'zh','З'=>'z',
        'И'=>'i','Й'=>'y','К'=>'k','Л'=>'l','М'=>'m','Н'=>'n','О'=>'o','П'=>'p',
        'Р'=>'r','С'=>'s','Т'=>'t','У'=>'u','Ф'=>'f','Х'=>'h','Ц'=>'ts','Ч'=>'ch',
        'Ш'=>'sh','Щ'=>'sht','Ъ'=>'a','Ь'=>'','Ю'=>'yu','Я'=>'ya',
    ];
    $text = strtr($text, $map);
    return slug($text);
}

function format_date(string $date, string $lang = ''): string {
    if (!$lang) $lang = get_lang();
    $ts = strtotime($date);
    if (!$ts) return $date;
    $locale = $lang === 'bg' ? 'bg_BG' : 'en_US';
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, null, null, 'd MMMM yyyy');
        return $fmt->format($ts);
    }
    return date('d.m.Y', $ts);
}

// ============================================
// ADMIN AUTH
// ============================================

function admin_logged_in(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION[ADMIN_SESSION_NAME])) return false;
    $sess = $_SESSION[ADMIN_SESSION_NAME];
    // Check session expiry
    if (time() - ($sess['time'] ?? 0) > ADMIN_SESSION_HOURS * 3600) {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        return false;
    }
    return true;
}

function admin_require_login(): void {
    if (!admin_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
    if (admin_is_iris_admin()) {
        header('Location: /iris/');
        exit;
    }
}

function admin_user(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION[ADMIN_SESSION_NAME] ?? [];
}

function admin_session_refresh(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION[ADMIN_SESSION_NAME])) {
        $_SESSION[ADMIN_SESSION_NAME]['time'] = time();
    }
}

function admin_is_admin(): bool {
    return (admin_user()['role'] ?? '') === 'admin';
}

function admin_is_iris_admin(): bool {
    return (admin_user()['role'] ?? '') === 'iris_admin';
}

function admin_can_manage_iris(): bool {
    return admin_is_admin() || admin_is_iris_admin();
}

function admin_require_iris(): void {
    if (!admin_logged_in() || !admin_can_manage_iris()) {
        header('Location: /iris/login.php');
        exit;
    }
}

function admin_is_shop_admin(): bool {
    return (admin_user()['role'] ?? '') === 'shop_admin';
}

function admin_can_manage_shop(): bool {
    return admin_is_admin() || admin_is_shop_admin();
}

function admin_require_admin(): void {
    admin_require_login();
    if (!admin_is_admin()) {
        http_response_code(403);
        exit('Access denied');
    }
}

function admin_require_shop(): void {
    admin_require_login();
    if (!admin_can_manage_shop()) {
        http_response_code(403);
        exit('Access denied');
    }
}

function admin_can_editorial(): bool {
    return in_array(admin_user()['role'] ?? '', ['admin', 'author']);
}

function admin_require_editorial(): void {
    admin_require_login();
    if (!admin_can_editorial()) {
        http_response_code(403);
        exit('Access denied');
    }
}

// ============================================
// SECURITY
// ============================================

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// Percent-encode a URL path so Cyrillic/special filenames load on all browsers.
function asset_url(string $path): string {
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    return htmlspecialchars($encoded, ENT_QUOTES, 'UTF-8');
}

// ============================================
// SESSION & FLASH
// ============================================

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function flash_set(string $type, string $message): void {
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Returns and clears all flash messages. */
function flash_get(): array {
    start_session();
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

// ============================================
// SHOP HELPERS
// ============================================

function generate_order_number(): string {
    return 'OM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function cart_count(): int {
    if (session_status() !== PHP_SESSION_ACTIVE) return 0;
    $n = 0;
    foreach ($_SESSION['cart'] ?? [] as $item) {
        $n += (int)($item['quantity'] ?? 0);
    }
    return $n;
}

/**
 * Ordered gallery of photo filenames for a product variant row.
 * Reads the `images` JSON column; falls back to the single primary `image`
 * (e.g. before migration 026's backfill has run, or for empty galleries).
 * The chosen primary lives in $pv['image'] — this returns display order only.
 */
function variant_gallery(array $pv): array {
    $imgs = [];
    if (!empty($pv['images'])) {
        $decoded = json_decode((string)$pv['images'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $f) {
                $f = trim((string)$f);
                if ($f !== '') $imgs[] = $f;
            }
        }
    }
    if (!$imgs && !empty($pv['image'])) {
        $imgs = [(string)$pv['image']];
    }
    return array_values(array_unique($imgs));
}

// SITE_BIC, SITE_BANK_NAME and SIGNING_ADMIN_EMAIL are defined in site.config.php.

function admin_can_sign(): bool {
    return admin_logged_in() && (admin_user()['email'] ?? '') === SIGNING_ADMIN_EMAIL;
}
