<?php
/**
 * First-time installer (CLI only).
 *
 *   1. Creates the database schema by running all migrations.
 *   2. Creates the first admin account (if none exists yet).
 *
 * Prerequisites: copy db.config.php.example → db.config.php and fill it in,
 * and create the (empty) database it points at.
 *
 * Usage:
 *   php install.php                                  # interactive prompts
 *   ADMIN_EMAIL=you@org.bg ADMIN_PASSWORD=secret php install.php
 *
 * Safe to re-run: migrations are idempotent and the admin is only created
 * when the admin_users table is empty.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("install.php is CLI only.\n");
}

$root = __DIR__;

if (!is_file($root . '/db.config.php')) {
    fwrite(STDERR, "Missing db.config.php — copy db.config.php.example to db.config.php and fill it in first.\n");
    exit(1);
}
if (!is_file($root . '/site.config.php')) {
    fwrite(STDOUT, "Note: site.config.php not found — the site will fall back to neutral placeholder values.\n"
                 . "      Copy site.config.example.php to site.config.php and edit it to rebrand.\n\n");
}

// ── 1. Schema ─────────────────────────────────────────────────────────────────
echo "Running migrations...\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/migrate.php'), $code);
if ($code !== 0) {
    fwrite(STDERR, "Migrations failed — aborting.\n");
    exit(1);
}

require_once $root . '/db.config.php';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── 2. First admin ────────────────────────────────────────────────────────────
$count = (int) $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
if ($count > 0) {
    echo "\nAdmin user(s) already exist — skipping admin creation.\nDone.\n";
    exit(0);
}

echo "\nCreate the first administrator account:\n";
$name  = getenv('ADMIN_NAME')     ?: 'Administrator';
$email = getenv('ADMIN_EMAIL')    ?: prompt('  Email: ');
$pass  = getenv('ADMIN_PASSWORD') ?: prompt('  Password (min 8 chars): ', true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (strlen($pass) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);

echo "\nCreated admin: $email\n";
echo "Done. Log in at /admin/\n";

/**
 * Read a line from STDIN, optionally without echoing (for passwords).
 */
function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        $value = rtrim((string) fgets(STDIN), "\r\n");
    }
    return $value;
}
