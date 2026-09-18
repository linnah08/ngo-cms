<?php
declare(strict_types=1);

// Support relay endpoint: POST multipart ticket from an NGO install → Trello card on that customer's board.
// All logic lives in ../src (outside the web root). Keep this file thin.

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$respond = static function (int $status, array $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    // On cPanel the document root must live inside public_html, so the rest of
    // the relay (config, installs, logs) sits outside it; relay-root.php, written
    // at deploy time, points back to it. Absent locally, where layout is intact.
    $rootFile = __DIR__ . '/relay-root.php';
    $root = is_file($rootFile) ? (string) require $rootFile : dirname(__DIR__);
    require_once $root . '/src/bootstrap.php';
    $config = relay_load_config();

    $log = new SupportRelay\Logger((string) $config['data_dir']);
    $relay = new SupportRelay\Relay(
        new SupportRelay\InstallRegistry((string) $config['installs_file']),
        new SupportRelay\RateLimiter((string) $config['data_dir'], max(1, (int) $config['rate_limit_per_hour'])),
        new SupportRelay\TicketValidator(),
        static fn() => new SupportRelay\TrelloClient(
            new SupportRelay\CurlHttpClient(15),
            (string) $config['trello_key'],
            (string) $config['trello_token'],
        ),
        $log,
    );

    $res = $relay->handle($_SERVER, $_POST, $_FILES);
    $respond($res->status, $res->body, $res->headers);
} catch (Throwable $e) {
    // Config missing/broken etc. Goes to the PHP error log, never to the client.
    error_log('support-relay bootstrap failure: ' . get_class($e) . ': ' . $e->getMessage());
    $respond(500, ['ok' => false, 'error' => 'server_error']);
}
