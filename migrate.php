<?php
/**
 * Database migration runner.
 * Called automatically by .cpanel.yml on every deploy.
 * Also safe to run manually: php migrate.php
 *
 * Core logic lives in run_migrations(), which RETURNS a result instead of
 * exiting — this file is `require`d in-process by includes/updater.php during
 * a self-update (via admin/updates.php), and an exit(1) in there would kill
 * the whole web request instead of just reporting a failure. The CLI entry
 * point at the bottom of this file is the only place that still calls exit(1).
 */

function run_migrations(): array
{
    $root = __DIR__;
    require_once $root . '/db.config.php';

    // ── Connect ───────────────────────────────────────────────────────────────
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $msg = 'migrate: DB connection failed: ' . $e->getMessage();
        fwrite(STDERR, $msg . "\n");
        return ['success' => false, 'error' => $msg];
    }

    // ── Ensure migrations tracking table exists ─────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `_migrations` (
            `name` varchar(255) NOT NULL,
            `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ── Find applied migrations ───────────────────────────────────────────────────
    $applied = $pdo->query("SELECT `name` FROM `_migrations`")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    // ── Collect migration files in order ─────────────────────────────────────────
    $files = glob($root . '/migrations/*.sql');
    if ($files === false) $files = [];
    sort($files);

    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue; // already applied
        }

        echo "Applying $name ... ";
        $sql = file_get_contents($file);

        // Strip full-line `--` comments first. Otherwise a semicolon that appears
        // inside a comment would be treated as a statement separator and the comment
        // tail would be exec'd as (invalid) SQL.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        // Split on semicolons so multi-statement files work
        try {
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }
        } catch (PDOException $e) {
            // "Duplicate column" / "already exists" errors are safe to ignore —
            // it means a previous partial run already applied the DDL.
            $msg = $e->getMessage();
            $ignorable = str_contains($msg, 'Duplicate column')
                      || str_contains($msg, 'already exists');
            if (!$ignorable) {
                $errMsg = "migrate: FAILED $name: $msg";
                fwrite(STDERR, $errMsg . "\n");
                echo "FAILED: $msg\n";
                return ['success' => false, 'error' => $errMsg]; // real error — stop so we don't mark it as applied
            }
            echo "(already applied, skipping) ";
        }

        $pdo->prepare("INSERT INTO `_migrations` (`name`) VALUES (?)")->execute([$name]);
        echo "done\n";
        $ran++;
    }

    if ($ran === 0) {
        echo "migrate: nothing to apply\n";
    } else {
        echo "migrate: applied $ran migration(s)\n";
    }

    return ['success' => true, 'error' => null];
}

// ── CLI entry point ───────────────────────────────────────────────────────────
// Only auto-run when this file is the script PHP was invoked with directly
// (`php migrate.php`), not when it's `require`d in-process by something else
// (includes/updater.php during a self-update, install/index.php's wizard, or
// tests) — otherwise the exit(1) below would kill the including request.
if (PHP_SAPI === 'cli' && isset($argv[0]) && @realpath($argv[0]) === __FILE__) {
    $result = run_migrations();
    if (!$result['success']) {
        exit(1);
    }
}
