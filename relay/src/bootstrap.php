<?php
declare(strict_types=1);

// Standalone loader for the support relay. Deliberately independent of the CMS (no config.php, no DB).
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/InstallRegistry.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/TicketValidator.php';
require_once __DIR__ . '/CardFormatter.php';
require_once __DIR__ . '/TrelloClient.php';
require_once __DIR__ . '/Relay.php';

/**
 * Loads the relay config. CLI tools may override the path with RELAY_CONFIG (used by tests);
 * the web endpoint always uses relay/config.php.
 */
function relay_load_config(bool $allowEnvOverride = false): array
{
    $path = dirname(__DIR__) . '/config.php';
    if ($allowEnvOverride && PHP_SAPI === 'cli' && ($env = getenv('RELAY_CONFIG')) !== false && $env !== '') {
        $path = $env;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Relay config not found: ' . $path);
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Relay config must return an array');
    }
    return $config + [
        'trello_key'          => '',
        'trello_token'        => '',
        'installs_file'       => dirname(__DIR__) . '/installs.json',
        'data_dir'            => dirname(__DIR__) . '/data',
        'rate_limit_per_hour' => 10,
    ];
}
