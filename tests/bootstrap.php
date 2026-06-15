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
