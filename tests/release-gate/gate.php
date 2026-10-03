<?php
/**
 * Release gate: would updating a real site from the previous release to this
 * one leave that site working?
 *
 *   php tests/release-gate/gate.php --prev-zip=<file> --new-zip=<file> --new-version=<x.y.z>
 *                                   [--scenarios=fresh,fork] [--work=<dir>] [--keep]
 *
 * Database: GATE_DB_HOST (127.0.0.1), GATE_DB_USER (root), GATE_DB_PASS ('').
 * Each scenario gets its own throwaway database, dropped at the end.
 *
 * For each scenario:
 *   1. Install the previous release's ZIP into a temp folder, as the install
 *      wizard would: db.config.php, site.config.php, all migrations.
 *   2. fork only: customise config.php the way a fork does — the case that
 *      took lafetki.com down with v0.17.0, since the updater keeps a
 *      customised config.php. It gets an extra constant and function, and a
 *      top-level feature_enabled() as v0.16.0's config.php had it.
 *   3. The site must load before the update (otherwise the gate itself is
 *      broken, which is reported as such).
 *   4. Update it with the site's OWN updater (the previous release's code —
 *      what an adopter runs), from the new ZIP. See apply.php.
 *   5. The update must end as success/partial, on the new version, out of
 *      maintenance mode — and the site must load (see boot-check.php).
 *
 * Exit code 0 only if every scenario passed.
 */
if (PHP_SAPI !== 'cli') { exit; }

const V016_FEATURE_ENABLED = <<<'PHP'

/**
 * Copied verbatim from v0.16.0's config.php, where feature_enabled() lived
 * before v0.17.0 moved it to includes/organisation.php.
 */
function feature_enabled(string $name): bool {
    $const = 'FEATURE_' . strtoupper($name);
    return !defined($const) || (bool) constant($const);
}

PHP;

$opts = getopt('', ['prev-zip:', 'new-zip:', 'new-version:', 'scenarios::', 'work::', 'keep']);
$prevZip    = (string) ($opts['prev-zip'] ?? '');
$newZip     = (string) ($opts['new-zip'] ?? '');
$newVersion = ltrim((string) ($opts['new-version'] ?? ''), 'v');
$scenarios  = array_filter(explode(',', (string) ($opts['scenarios'] ?? 'fresh,fork')));
$keep       = isset($opts['keep']);
$work       = rtrim((string) ($opts['work'] ?? (sys_get_temp_dir() . '/ngo-release-gate-' . bin2hex(random_bytes(4)))), '/');

if (!is_file($prevZip) || !is_file($newZip) || $newVersion === '') {
    fwrite(STDERR, "usage: php gate.php --prev-zip=<file> --new-zip=<file> --new-version=<x.y.z> [--scenarios=fresh,fork] [--keep]\n");
    exit(2);
}

$db = [
    'host' => getenv('GATE_DB_HOST') ?: '127.0.0.1',
    'user' => getenv('GATE_DB_USER') ?: 'root',
    'pass' => (string) (getenv('GATE_DB_PASS') ?: ''),
];

function say(string $msg): void { fwrite(STDOUT, $msg . "\n"); }

function run(array $argv, ?string $cwd = null): array
{
    $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) return [127, '', 'could not start ' . $argv[0]];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}

function server_pdo(array $db): PDO
{
    return new PDO('mysql:host=' . $db['host'] . ';charset=utf8mb4', $db['user'], $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function boot_check(string $site): array
{
    [$code, $out, $err] = run([PHP_BINARY, __DIR__ . '/boot-check.php', $site]);
    $json = json_decode($out, true);
    return [$code === 0, is_array($json) ? $json : ['raw' => trim($out . "\n" . $err)]];
}

function summarise_check(array $check): string
{
    if (!isset($check['pages'])) return (string) ($check['raw'] ?? 'no output');
    $lines = [];
    foreach ($check['pages'] as $uri => $p) {
        $lines[] = sprintf('    %-18s %s', $uri, $p['ok'] ? 'loads (' . $p['status'] . ')' : 'BROKEN: ' . $p['error']);
    }
    return implode("\n", $lines);
}

/** @return string[] problems; empty = passed */
function run_scenario(string $name, string $prevZip, string $newZip, string $newVersion, string $work, array $db): array
{
    $site   = $work . '/' . $name . '/site';
    $dbName = 'ngo_release_gate_' . $name . '_' . bin2hex(random_bytes(3));
    @mkdir($site, 0755, true);

    try {
        // 1. Install the previous release.
        $zip = new ZipArchive();
        if ($zip->open($prevZip) !== true || !$zip->extractTo($site)) {
            return ['could not unpack the previous release'];
        }
        $zip->close();
        rrmdir($site . '/install'); // the wizard tells every adopter to delete it

        $pdo = server_pdo($db);
        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        file_put_contents($site . '/db.config.php', "<?php\n"
            . 'define(\'DB_HOST\', ' . var_export($db['host'], true) . ");\n"
            . 'define(\'DB_NAME\', ' . var_export($dbName, true) . ");\n"
            . 'define(\'DB_USER\', ' . var_export($db['user'], true) . ");\n"
            . 'define(\'DB_PASS\', ' . var_export($db['pass'], true) . ");\n"
            . 'define(\'SETTINGS_ENCRYPTION_KEY\', ' . var_export(bin2hex(random_bytes(32)), true) . ");\n");
        if (!is_file($site . '/site.config.example.php') || !copy($site . '/site.config.example.php', $site . '/site.config.php')) {
            return ['the previous release has no site.config.example.php to configure the site from'];
        }

        [$code, $out, $err] = run([PHP_BINARY, $site . '/migrate.php'], $site);
        if ($code !== 0) {
            return ['installing the previous release failed (migrations): ' . trim($err !== '' ? $err : $out)];
        }

        // 2. A fork's own config.php.
        if ($name === 'fork') {
            $cfg = rtrim((string) file_get_contents($site . '/config.php'));
            if (str_ends_with($cfg, '?>')) $cfg = rtrim(substr($cfg, 0, -2));
            $cfg .= "\n\n// ── This site's own additions (release gate: a fork's customised config.php) ──\n"
                  . "define('RELEASE_GATE_FORK_MARKER', true);\n"
                  . "function release_gate_fork_helper(): string { return 'fork'; }\n";
            if (!preg_match('/^\s*function\s+feature_enabled\s*\(/m', $cfg)) {
                $cfg .= V016_FEATURE_ENABLED;
            }
            file_put_contents($site . '/config.php', $cfg . "\n");
        }

        // 3. It must load before the update, or the gate proves nothing.
        [$ok, $before] = boot_check($site);
        if (!$ok) {
            return ["the PREVIOUS release does not load in the gate (a problem with the gate, not the release):\n" . summarise_check($before)];
        }

        // 4. Update with the site's own updater.
        $from = trim((string) file_get_contents($site . '/VERSION'));
        say("  updating {$from} → {$newVersion} with the site's own updater…");
        [$code, $out, $err] = run([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__ . '/apply.php', $site, $newZip, $newVersion], $site);
        $pos    = strrpos($out, '@@RELEASE_GATE_RESULT@@');
        $result = $pos === false ? null : json_decode(trim(substr($out, $pos + strlen('@@RELEASE_GATE_RESULT@@'))), true);

        $problems = [];
        if (!is_array($result)) {
            $problems[] = "the updater itself crashed (exit {$code}): " . trim(substr($err . "\n" . $out, -2000));
        } else {
            say('  updater finished: ' . $result['status'] . ($result['error'] ? ' — ' . $result['error'] : ''));
            if (!in_array($result['status'], ['success', 'partial'], true)) {
                $problems[] = 'the update ended as "' . $result['status'] . '": ' . (string) $result['error'];
            }
            if (!empty($result['maintenance'])) {
                $problems[] = 'the site was left in maintenance mode';
            }
        }
        $version = trim((string) @file_get_contents($site . '/VERSION'));
        if ($version !== $newVersion) {
            $problems[] = "the site is on version '{$version}', not {$newVersion}";
        }
        if ($name === 'fork' && !str_contains((string) file_get_contents($site . '/config.php'), 'RELEASE_GATE_FORK_MARKER')) {
            $problems[] = "the fork's customised config.php was overwritten (it must be kept)";
        }

        // 5. Does the updated site load?
        [$ok, $after] = boot_check($site);
        say("  after the update:\n" . summarise_check($after));
        if (!$ok) {
            $problems[] = 'the updated site does not load';
        }
        return $problems;
    } catch (\Throwable $e) {
        return ['gate error: ' . $e->getMessage()];
    } finally {
        try { server_pdo($db)->exec("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    }
}

say('Release gate: ' . basename($prevZip) . ' → ' . basename($newZip) . " ({$newVersion})");
$failed = false;
foreach ($scenarios as $name) {
    if (!in_array($name, ['fresh', 'fork'], true)) {
        fwrite(STDERR, "unknown scenario: {$name}\n");
        exit(2);
    }
    say("\n[{$name}] " . ($name === 'fork'
        ? 'a site with its own config.php (kept by the updater)'
        : 'a fresh install of the previous release'));
    $problems = run_scenario($name, $prevZip, $newZip, $newVersion, $work, $db);
    if ($problems === []) {
        say("[{$name}] PASS");
    } else {
        $failed = true;
        say("[{$name}] FAIL");
        foreach ($problems as $p) say('  - ' . $p);
    }
}

if ($keep) {
    say("\nSites kept in {$work}");
} else {
    rrmdir($work);
}
say($failed ? "\nRelease gate FAILED — do not publish this release." : "\nRelease gate passed.");
exit($failed ? 1 : 0);
