<?php
declare(strict_types=1);

// Usage: php bin/deactivate-install.php "<customer name | hash prefix (8+ hex chars)>"
// Revokes a support code. The entry is kept (active=false) for the audit trail.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/deactivate-install.php \"<customer name | hash prefix>\"\n");
    exit(2);
}

try {
    $config = relay_load_config(true);
    $registry = new SupportRelay\InstallRegistry((string) $config['installs_file']);
    $hashes = $registry->deactivate($argv[1]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($hashes as $h) {
    echo 'Deactivated install ' . substr($h, 0, 12) . "…\n";
}
