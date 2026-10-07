<?php
declare(strict_types=1);

/**
 * A throwaway `php -S` server for this checkout, for tests that need real HTTP
 * (redirects, exit(), session cookies) — the same approach playwright.config.js
 * uses, plus a router (router.php) so unknown addresses 404 as they do on
 * Apache. It works on any machine, unlike a host such as example.test that only
 * some machines park, and that a catch-all dev server (e.g. Laravel Herd) may be
 * answering for a different site.
 *
 *   self::$base = TestServer::start();   // in setUpBeforeClass(); '' if it failed
 *   TestServer::stop();                   // in tearDownAfterClass()
 */
final class TestServer
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    /** @var string[] */
    private static array $logs = [];

    /** Starts the server (once per class) and returns its base URL, or '' if it did not answer. */
    public static function start(): string
    {
        if (self::$base !== '') return self::$base;

        $root = dirname(__DIR__, 2);
        $port = self::freePort();
        if ($port === 0) return '';

        // Logs go to files, not pipes: a pipe nobody drains fills up once the
        // server has logged enough requests and then blocks it forever.
        $out = tempnam(sys_get_temp_dir(), 'ngo_test_srv_out_');
        $err = tempnam(sys_get_temp_dir(), 'ngo_test_srv_err_');
        self::$logs = [$out, $err];
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, __DIR__ . '/router.php'],
            [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            $root
        );
        if (!is_resource($proc)) return '';
        self::$proc = $proc;

        $base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $ch = curl_init($base . '/admin/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) return self::$base = $base;
            usleep(100_000);
        }
        self::stop();
        return '';
    }

    public static function stop(): void
    {
        if (is_resource(self::$proc)) {
            $pid = (int) (proc_get_status(self::$proc)['pid'] ?? 0);
            proc_terminate(self::$proc, 9);
            for ($i = 0; $i < 20 && proc_get_status(self::$proc)['running']; $i++) usleep(50_000);
            if ($pid > 0) @exec('kill -9 ' . $pid . ' 2>/dev/null');
        }
        foreach (self::$logs as $log) @unlink($log);
        self::$proc = null;
        self::$base = '';
        self::$logs = [];
    }

    /** A port nothing is listening on, chosen by the OS rather than at random. */
    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) return 0;
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
