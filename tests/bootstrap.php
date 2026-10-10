<?php
$root = dirname(__DIR__);

// ── Server vars needed by config.php ─────────────────────────────────────────
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_URI']   = '/';   // makes get_lang() return 'bg'
$_SERVER['HTTP_HOST']     = 'localhost';

require_once $root . '/vendor/autoload.php';

// ── courier.config.php — real credentials or stubs ───────────────────────────
if (file_exists($root . '/courier.config.php')) {
    require_once $root . '/courier.config.php';
} else {
    define('ECONT_USER',           'test');
    define('ECONT_PASS',           'test');
    define('ECONT_TEST_MODE',      true);
    define('SPEEDY_USER',          'test');
    define('SPEEDY_PASS',          'test');
    define('SPEEDY_CLIENT_ID',     0);
    define('SPEEDY_TEST_MODE',     true);
    define('BOXNOW_CLIENT_ID',     'test');
    define('BOXNOW_CLIENT_SECRET', 'test');
    define('BOXNOW_PARTNER_ID',    'test');
    define('BOXNOW_WAREHOUSE_ID',  'test');
    define('BOXNOW_TEST_MODE',     true);
    // The values above are placeholders; live courier tests skip on them.
    define('TEST_COURIER_STUB_CREDENTIALS', true);
    define('SENDER_NAME',          'Test Sender');
    define('SENDER_PHONE',         '0888000000');
    define('SENDER_CITY',          'София');
    define('SENDER_ADDRESS',       'ул. Тест 1');
}

// ── graph.config.php — real credentials or stubs ─────────────────────────────
// Required by mailer.php; stubs prevent network calls in unit tests.
if (file_exists($root . '/graph.config.php')) {
    require_once $root . '/graph.config.php';
} else {
    define('GRAPH_TENANT_ID',     'test-tenant');
    define('GRAPH_CLIENT_ID',     'test-client');
    define('GRAPH_CLIENT_SECRET', 'test-secret');
    define('GRAPH_FROM',          'test@example.org');
    define('GRAPH_FROM_NAME',     'Test');
}

// ── db.config.php — optional; DB tests self-skip when absent ─────────────────
if (file_exists($root . '/db.config.php')) {
    require_once $root . '/db.config.php';
}

// ── Core site config + mailer ─────────────────────────────────────────────────
require_once $root . '/config.php';
require_once $root . '/includes/mailer.php';

// ── Settings (reads courier/payment credentials from DB when available) ────────
if (defined('DB_HOST')) {
    require_once $root . '/includes/settings.php';
} else {
    // Stubs so classes that call setting_get() can be loaded/instantiated without DB.
    // All return defaults — integration tests will self-skip via test_dsk_available().
    function setting_get(string $key, string $default = ''): string { return $default; }
    function setting_is_set(string $key): bool { return false; }
    function setting_set(string $key, string $value): void {}
    function setting_resolve(string $key, string $constant, string $default = ''): string {
        if (defined($constant)) return (string) constant($constant);
        return $default;
    }
    function setting_resolve_bool(string $key, string $constant, bool $default = true): bool {
        if (defined($constant)) return (bool) constant($constant);
        return $default;
    }
}

// ── Courier classes (existing tests) ─────────────────────────────────────────
require_once $root . '/includes/couriers/EcontCourier.php';
require_once $root . '/includes/couriers/SpeedyCourier.php';
require_once $root . '/includes/couriers/BoxNowCourier.php';

// ── Payment classes ────────────────────────────────────────────────────────────
require_once $root . '/includes/payment/DSKBankPayment.php';
require_once $root . '/includes/payment/process_payment.php';

// ── Print helpers ─────────────────────────────────────────────────────────────
require_once $root . '/includes/print_helpers.php';

/**
 * Admin-editable content files (content/pages.json, menus.json, organisation.json)
 * are gitignored because the server is their source of truth, so a fresh clone
 * does not have them and any test that reads one has nothing to read. Call this
 * first in such a test to skip rather than fail on a missing file.
 */
function test_content_files_available(string ...$paths): bool {
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) return false;
    }
    return true;
}

// ── Donation flow helpers (donation_path(), donation_validate()) ──────────────
require_once $root . '/includes/donation.php';

// ── Test DB helper ────────────────────────────────────────────────────────────
function test_db_available(): bool {
    if (!defined('DB_HOST')) return false;
    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $pdo = get_pdo();
        // Verify Phase 2 tables exist (setup.php must have been run)
        $pdo->query('SELECT 1 FROM products LIMIT 1');
        $pdo->query('SELECT 1 FROM orders LIMIT 1');
        $pdo->query('SELECT 1 FROM shipping_rates LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Returns true when DSK Bank UAT credentials are stored in the settings table.
 * Run `vendor/bin/phpunit --group dsk-integration` only when this returns true.
 */
function test_dsk_available(): bool {
    if (!test_db_available()) return false;
    try {
        return setting_get('dsk_merchant') !== ''
            && setting_get('dsk_password') !== '';
    } catch (Throwable) {
        return false;
    }
}

/**
 * Returns true when a courier has real credentials for live API tests: either
 * courier.config.php exists, or the admin settings hold a value for $settingKey.
 * Without either, the bootstrap's placeholder credentials are in use, and a live
 * call can only fail (401, or an unreachable test host), so those tests skip.
 */
function test_courier_live(string $settingKey): bool {
    if (!defined('TEST_COURIER_STUB_CREDENTIALS')) return true;
    try {
        return setting_is_set($settingKey);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Run $fn with a setting temporarily overridden, restoring the original even if
 * an assertion inside fails — the local DB holds real API keys and a lost one
 * silently breaks the dev site. Without a DB, setting_get() already returns ''.
 */
function with_setting(string $key, string $value, callable $fn): mixed {
    if (!test_db_available()) return $fn();
    $original = setting_get($key);
    setting_set($key, $value);
    try {
        return $fn();
    } finally {
        setting_set($key, $original);
    }
}

/**
 * Run an admin page in its own PHP process, the way a browser POST would hit
 * it: a logged-in session with the given role, a valid CSRF token (unless
 * $opts['csrf'] says otherwise), and $post as the form body. Pages exit() and
 * send headers, so they can't be included in-process. Returns the HTTP status
 * the page set (200 if none), its output, the flash messages it queued and
 * the final session.
 *
 * $opts: role ('admin' | 'author' | 'shop_admin' | null = logged out),
 *        csrf (true = valid token, false = none, string = that token),
 *        get (query params), method ('POST'), ip (REMOTE_ADDR),
 *        json (array: sent as the raw JSON body, with the CSRF token inside it).
 */
function run_admin_page(string $rel, array $post = [], array $opts = []): array {
    $spec = [
        'root'   => dirname(__DIR__),
        'rel'    => $rel,
        'post'   => $post,
        'get'    => $opts['get'] ?? [],
        'method' => $opts['method'] ?? 'POST',
        'role'   => array_key_exists('role', $opts) ? $opts['role'] : 'admin',
        'csrf'   => $opts['csrf'] ?? true,
        'ip'     => $opts['ip'] ?? '127.0.0.1',
        'json'   => $opts['json'] ?? null,
    ];
    $code = <<<'PHP'
$s = json_decode($argv[1], true);
$_SERVER['DOCUMENT_ROOT']  = $s['root'];
$_SERVER['REQUEST_METHOD'] = $s['method'];
$_SERVER['REQUEST_URI']    = '/' . $s['rel'];
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REMOTE_ADDR']    = $s['ip'];
$_GET  = $s['get'];
$_POST = $s['post'];
session_id('phpunit' . bin2hex(random_bytes(8)));
require $s['root'] . '/config.php';
start_session();
if ($s['role'] !== null) {
    $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(), 'logged_in' => true, 'id' => 0, 'name' => 'PHPUnit',
        'email' => 'phpunit@test.local', 'role' => $s['role'], 'time' => time()];
}
$token = csrf_token();
if ($s['csrf'] === true) $_POST['csrf_token'] = $token;
elseif (is_string($s['csrf'])) $_POST['csrf_token'] = $s['csrf'];
if (is_array($s['json'])) {
    // A JSON-body endpoint (the inline editors): the token travels inside the body.
    if (isset($_POST['csrf_token'])) $s['json']['csrf_token'] = $_POST['csrf_token'];
    $GLOBALS['_om_raw_input'] = json_encode($s['json']);
}
register_shutdown_function(function () {
    echo "\n@@RESULT@@" . json_encode(['status' => http_response_code() ?: 200, 'flash' => $_SESSION['flash'] ?? [], 'session' => $_SESSION ?? []]);
    session_destroy();
});
include $s['root'] . '/' . $s['rel'];
PHP;
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg(json_encode($spec)) . ' 2>&1');
    $pos = strrpos($out, "\n@@RESULT@@");
    if ($pos === false) return ['status' => 500, 'body' => $out, 'flash' => [], 'session' => []];
    $meta = json_decode(substr($out, $pos + 11), true) ?: [];
    return ['status' => (int) ($meta['status'] ?? 500), 'body' => substr($out, 0, $pos), 'flash' => $meta['flash'] ?? [], 'session' => $meta['session'] ?? []];
}
