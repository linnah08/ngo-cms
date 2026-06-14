<?php
/**
 * Seed a deterministic LOCAL admin for browser tests.
 *
 * Browser tests (Playwright) need an admin login. Rather than depend on an
 * ADMIN_PASSWORD env var that drifts out of sync with the local DB snapshot,
 * the test setup runs this script to guarantee a known account exists.
 *
 * Credentials (local only): playwright@test.local / playwright-local-test
 *
 * SAFETY: refuses to run unless the DB host is local. This file also lives
 * under tests/ which is excluded from the production rsync deploy, so it can
 * never reach the live server — the host guard is defence in depth.
 */

require_once __DIR__ . '/../db.config.php';

const TEST_EMAIL    = 'playwright@test.local';
const TEST_PASSWORD = 'playwright-local-test';

// ── Host guard: never seed an admin against a non-local database ──────────────
$host = strtolower((string) DB_HOST);
if (!in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)) {
    fwrite(STDERR, "seed-admin: refusing to run against non-local DB host '" . DB_HOST . "'\n");
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "seed-admin: DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);

$existing = $pdo->prepare("SELECT id FROM admin_users WHERE email = ?");
$existing->execute([TEST_EMAIL]);
$id = $existing->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE admin_users SET password_hash = ?, role = 'admin' WHERE id = ?")
        ->execute([$hash, $id]);
    echo "seed-admin: updated " . TEST_EMAIL . " (id $id)\n";
} else {
    $pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
        ->execute(['Playwright Test', TEST_EMAIL, $hash]);
    echo "seed-admin: created " . TEST_EMAIL . "\n";
}
