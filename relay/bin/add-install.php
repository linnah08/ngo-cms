<?php
declare(strict_types=1);

// Usage: php bin/add-install.php "Customer Name" <trello_list_id | list ARI>
// Generates a new support code, stores only its SHA-256 hash, prints the code ONCE.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/add-install.php \"Customer Name\" <trello_list_id>\n");
    exit(2);
}

try {
    $config = relay_load_config(true);
    $registry = new SupportRelay\InstallRegistry((string) $config['installs_file']);
    $code = $registry->add($argv[1], $argv[2]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$hash = SupportRelay\InstallRegistry::hashCode($code);
echo "Install added: " . trim($argv[1]) . " (hash " . substr($hash, 0, 12) . "…)\n\n";
echo "SUPPORT CODE (send this to the NGO — it is shown only once and cannot be recovered):\n\n";
echo "    " . $code . "\n\n";
echo "If it is lost, deactivate this install and run add-install again.\n";
