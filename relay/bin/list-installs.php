<?php
declare(strict_types=1);

// Usage: php bin/list-installs.php — shows every install (hash prefix, status, name, list id).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = relay_load_config(true);
    $data = (new SupportRelay\InstallRegistry((string) $config['installs_file']))->load();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($data as $hash => $e) {
    printf("%s  %-8s  %-24s  %s\n",
        substr((string) $hash, 0, 12),
        !empty($e['active']) ? 'active' : 'REVOKED',
        (string) ($e['trello_list_id'] ?? '?'),
        (string) ($e['name'] ?? '?'));
}
