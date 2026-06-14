<?php
/**
 * Database migration runner.
 * Called automatically by .cpanel.yml on every deploy.
 * Also safe to run manually: php migrate.php
 */

$root = __DIR__;
require_once $root . '/db.config.php';

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "migrate: DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Ensure migrations tracking table exists ───────────────────────────────────
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
            fwrite(STDERR, "migrate: FAILED $name: $msg\n");
            echo "FAILED: $msg\n";
            exit(1); // real error — stop so we don't mark it as applied
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
