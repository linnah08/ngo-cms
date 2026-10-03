<?php
/**
 * Release gate, step "does it load": the updater's own boot check
 * (updater_boot_check() from THIS checkout's includes/updater.php) run against
 * an installed site, in its own PHP process so nothing from that site's code
 * is loaded here.
 *
 *   php boot-check.php <site dir>
 *
 * Prints the result as JSON. Exit code 0 only if every page loaded.
 */
if (PHP_SAPI !== 'cli') { exit; }

$site = rtrim((string) ($argv[1] ?? ''), '/');
if ($site === '' || !is_dir($site)) {
    fwrite(STDERR, "usage: php boot-check.php <site dir>\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/includes/updater.php';

$check = updater_boot_check($site, null, 'cli', ['php' => PHP_BINARY]);
echo json_encode($check, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

$ok = $check['method'] === 'cli' && $check['pages'] !== []
    && !in_array(false, array_column($check['pages'], 'ok'), true);
exit($ok ? 0 : 1);
