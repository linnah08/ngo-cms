<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/support.php ("report a problem" → vendor support relay).
 *
 * No real network: every send goes through an injected $httpPost fake.
 * The encrypted-storage test needs the local DB and self-skips without it.
 */
final class SupportTest extends TestCase
{
    private const FAKE_SECRET = 'zz-fake-support-test-secret-9f3a';
    private const CODE        = 'om-sup-abcdef1234567890';

    /** @var string[] */
    private array $tmpFiles = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/support.php';
        if (!defined('SUPPORT_TEST_FAKE_SECRET')) {
            define('SUPPORT_TEST_FAKE_SECRET', self::FAKE_SECRET);
        }
        if (!defined('SUPPORT_TEST_API_TOKEN')) {
            define('SUPPORT_TEST_API_TOKEN', 'tok-1234567890-should-never-leak');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) @unlink($f);
        $this->tmpFiles = [];
    }

    private function tmpFile(string $contents, string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . '/support-test-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function pngFile(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        return $this->tmpFile($bytes, '.png');
    }

    private function baseContext(array $extra = []): array
    {
        return array_merge([
            'page'           => '/admin/orders.php?id=5',
            'user'           => ['id' => 7, 'name' => 'Мария', 'email' => 'maria@example.org', 'role' => 'author',
                                 'password_hash' => '$2y$10$shouldneverbesent', 'time' => 123],
            'user_agent'     => 'Mozilla/5.0 Test',
            'recent_errors'  => [],
            'version'        => '1.2.3',
            'mail_transport' => 'smtp',
            'now'            => 1_700_000_000,
        ], $extra);
    }

    /** Returns [callable, &$captured] */
    private function fakeHttp(int $status, string $body, ?string $error = null, ?array &$captured = null): callable
    {
        return function (string $url, array $headers, array $fields) use ($status, $body, $error, &$captured): array {
            $captured = ['url' => $url, 'headers' => $headers, 'fields' => $fields];
            return ['status' => $status, 'body' => $body, 'error' => $error];
        };
    }

    // ── Relay URL ──────────────────────────────────────────────────────────────

    public function test_relay_url_default(): void
    {
        if (defined('SUPPORT_RELAY_URL')) {
            $this->assertSame(SUPPORT_RELAY_URL, support_relay_url());
        } else {
            $this->assertSame('https://support.oddminds.org/ticket.php', support_relay_url());
        }
    }

    // ── Diagnostics whitelist ──────────────────────────────────────────────────

    public function test_diagnostics_contains_only_whitelisted_keys(): void
    {
        $d = support_collect_diagnostics($this->baseContext());
        $this->assertSame(
            ['site_url', 'site_name', 'version', 'php_version', 'mail_transport', 'reporter',
             'page', 'user_agent', 'server_time', 'recent_errors'],
            array_keys($d)
        );
        $this->assertSame(['name', 'email', 'role'], array_keys($d['reporter']));
        $this->assertSame('Мария', $d['reporter']['name']);
        $this->assertSame('author', $d['reporter']['role']);
        $this->assertSame(SITE_URL, $d['site_url']);
        $this->assertSame(PHP_VERSION, $d['php_version']);
        $this->assertSame('1.2.3', $d['version']);
        $this->assertSame('/admin/orders.php?id=5', $d['page']);
    }

    public function test_diagnostics_never_leaks_secrets(): void
    {
        $ctx = $this->baseContext([
            'user_agent'    => 'UA ' . self::FAKE_SECRET,
            'recent_errors' => [
                ['id' => 1, 'created_at' => '2026-09-18 10:00:00',
                 'message' => 'PDO failed with ' . self::FAKE_SECRET . ' and ' . SUPPORT_TEST_API_TOKEN,
                 'file' => '/var/www/secret/path.php', 'line' => 12,
                 'url' => '/admin/login.php?password=hunter2', 'user_agent' => 'x'],
            ],
        ]);
        $json = support_encode_diagnostics(support_collect_diagnostics($ctx));

        $this->assertStringNotContainsString(self::FAKE_SECRET, $json);
        $this->assertStringNotContainsString(SUPPORT_TEST_API_TOKEN, $json);
        $this->assertStringNotContainsString('SUPPORT_TEST_FAKE_SECRET', $json);
        $this->assertStringNotContainsString('password_hash', $json);
        $this->assertStringNotContainsString('shouldneverbesent', $json);
        // error rows: only time + message; file/url/request data dropped
        $this->assertStringNotContainsString('/var/www/secret/path.php', $json);
        $this->assertStringNotContainsString('hunter2', $json);

        // No value of any secret-named constant (DB_PASS, SETTINGS_ENCRYPTION_KEY,
        // GRAPH_CLIENT_SECRET, …) appears anywhere in the payload.
        foreach (get_defined_constants(true)['user'] as $name => $value) {
            if (!is_scalar($value) || !preg_match('/KEY|SECRET|PASS|TOKEN/i', $name)) continue;
            if (strlen((string)$value) < 8) continue;
            $this->assertStringNotContainsString((string)$value, $json, "Value of {$name} leaked");
        }
        $this->assertStringNotContainsString('DB_PASS', $json);
    }

    public function test_diagnostics_errors_limited_to_five_and_truncated(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['created_at' => "2026-09-18 10:0{$i}:00", 'message' => str_repeat('я', 1000)];
        }
        $d = support_collect_diagnostics($this->baseContext(['recent_errors' => $rows]));
        $this->assertCount(5, $d['recent_errors']);
        foreach ($d['recent_errors'] as $e) {
            $this->assertSame(['time', 'message'], array_keys($e));
            $this->assertLessThanOrEqual(301, mb_strlen($e['message']));
        }
    }

    public function test_diagnostics_invalid_page_is_blanked(): void
    {
        $d = support_collect_diagnostics($this->baseContext(['page' => 'https://evil.com/x']));
        $this->assertSame('', $d['page']);
    }

    public function test_diagnostics_mail_transport_unknown_when_function_missing(): void
    {
        $ctx = $this->baseContext();
        unset($ctx['mail_transport']);
        $d = support_collect_diagnostics($ctx);
        if (function_exists('mail_transport')) {
            $this->assertIsString($d['mail_transport']);
        } else {
            $this->assertSame('unknown', $d['mail_transport']);
        }
    }

    public function test_encoded_diagnostics_fit_limit(): void
    {
        $json = support_encode_diagnostics(support_collect_diagnostics($this->baseContext()));
        $this->assertLessThanOrEqual(SUPPORT_DIAGNOSTICS_MAX, strlen($json));
        $this->assertIsArray(json_decode($json, true));
    }

    // ── Error-code mapping ─────────────────────────────────────────────────────

    public function test_error_code_messages(): void
    {
        $this->assertStringContainsString('Кодът за поддръжка не е валиден', support_error_message('unauthorized'));
        $this->assertStringContainsString('опитайте отново', support_error_message('rate_limited'));
        $this->assertStringContainsString('Не успяхме да се свържем със сървъра за поддръжка', support_error_message('network'));
        $this->assertStringContainsString('временен проблем', support_error_message('server_error'));
        $this->assertSame(support_error_message('server_error'), support_error_message('something_new'));
        $this->assertNotSame(support_error_message('server_error'), support_error_message('invalid'));
    }

    /** @return array<string, array{int, string, ?string, string}> */
    public static function relayResponses(): array
    {
        return [
            'unauthorized'      => [401, '{"ok":false,"error":"unauthorized"}', null, 'Кодът за поддръжка не е валиден'],
            'rate limited'      => [429, '{"ok":false,"error":"rate_limited"}', null, 'много сигнали'],
            'invalid'           => [400, '{"ok":false,"error":"invalid"}', null, 'не прие сигнала'],
            'server error'      => [500, '{"ok":false,"error":"server_error"}', null, 'временен проблем'],
            'html 502'          => [502, '<html>Bad gateway</html>', null, 'временен проблем'],
            'bare 403'          => [403, '', null, 'Кодът за поддръжка не е валиден'],
            'network failure'   => [0, '', 'Could not resolve host', 'Не успяхме да се свържем'],
            '200 but not ok'    => [200, '{"ok":false}', null, 'временен проблем'],
            '200 no reference'  => [200, '{"ok":true}', null, 'временен проблем'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relayResponses')]
    public function test_relay_response_mapping(int $status, string $body, ?string $netErr, string $expect): void
    {
        $r = support_send_ticket('Тема', 'Описание', '/admin/dashboard.php', null,
            $this->fakeHttp($status, $body, $netErr),
            ['code' => self::CODE, 'diagnostics_context' => $this->baseContext()]);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['reference']);
        $this->assertStringContainsString($expect, (string)$r['error']);
    }

    public function test_http_callable_throwing_is_network_error(): void
    {
        $r = support_send_ticket('Тема', 'Описание', '', null,
            function () { throw new RuntimeException('boom'); },
            ['code' => self::CODE, 'diagnostics_context' => $this->baseContext()]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Не успяхме да се свържем', (string)$r['error']);
    }

    // ── page param validation ──────────────────────────────────────────────────

    public function test_page_validation_accepts_admin_paths(): void
    {
        $this->assertSame('/admin/dashboard.php', support_validate_page('/admin/dashboard.php'));
        $this->assertSame('/admin/order-view.php?id=12', support_validate_page('/admin/order-view.php?id=12'));
    }

    /** @return array<string, array{string}> */
    public static function badPages(): array
    {
        return [
            'empty'              => [''],
            'absolute url'       => ['https://evil.com/admin/x.php'],
            'protocol relative'  => ['//evil.com'],
            'protocol rel admin' => ['//evil.com/admin/'],
            'admin double slash' => ['/admin//evil.com'],
            'backslash'          => ['/admin/\\evil.com'],
            'public page'        => ['/novini/'],
            'root'               => ['/'],
            'no leading slash'   => ['admin/dashboard.php'],
            'admin prefix trick' => ['/administrator/x.php'],
            'traversal'          => ['/admin/../config.php'],
            'javascript'         => ['javascript:alert(1)'],
            'crlf'               => ["/admin/x.php\r\nLocation: https://evil.com"],
            'too long'           => ['/admin/' . str_repeat('a', 400)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPages')]
    public function test_page_validation_rejects(string $page): void
    {
        $this->assertSame('', support_validate_page($page));
    }

    // ── subject / description validation ──────────────────────────────────────

    public function test_text_validation(): void
    {
        $this->assertNull(support_validate_text('Тема', 'Описание'));
        $this->assertNotNull(support_validate_text('', 'Описание'));
        $this->assertNotNull(support_validate_text('   ', 'Описание'));
        $this->assertNotNull(support_validate_text('Тема', ''));
        $this->assertNull(support_validate_text(str_repeat('я', 150), 'x'));
        $this->assertNotNull(support_validate_text(str_repeat('я', 151), 'x'));
        $this->assertNull(support_validate_text('x', str_repeat('я', 5000)));
        $this->assertNotNull(support_validate_text('x', str_repeat('я', 5001)));
    }

    public function test_send_rejects_invalid_text_without_calling_http(): void
    {
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 200, 'body' => '', 'error' => null]; };
        $r = support_send_ticket(str_repeat('a', 151), 'desc', '', null, $http, ['code' => self::CODE]);
        $this->assertFalse($r['ok']);
        $this->assertFalse($called);
    }

    public function test_send_without_code_is_not_configured(): void
    {
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 200, 'body' => '', 'error' => null]; };
        $r = support_send_ticket('Тема', 'Описание', '', null, $http, ['code' => '']);
        $this->assertFalse($r['ok']);
        $this->assertSame(support_error_message('not_configured'), $r['error']);
        $this->assertFalse($called);
    }

    // ── Sending ────────────────────────────────────────────────────────────────

    public function test_send_success_uses_injected_http_and_key_header(): void
    {
        $captured = null;
        $http = $this->fakeHttp(200, '{"ok":true,"reference":"T-42"}', null, $captured);
        $r = support_send_ticket('  Не се запазва статия ', 'Натискам „Запази“ и нищо.', '/admin/article-edit.php?id=3', null,
            $http, ['code' => self::CODE, 'diagnostics_context' => $this->baseContext()]);

        $this->assertSame(['ok' => true, 'reference' => 'T-42', 'error' => null], $r);
        $this->assertSame(support_relay_url(), $captured['url']);
        $this->assertContains('X-Support-Key: ' . self::CODE, $captured['headers']);
        $this->assertSame('Не се запазва статия', $captured['fields']['subject']);
        $this->assertSame('/admin/article-edit.php?id=3', $captured['fields']['page']);
        $this->assertArrayNotHasKey('screenshot', $captured['fields']);
        $diag = json_decode($captured['fields']['diagnostics'], true);
        $this->assertIsArray($diag);
        $this->assertSame('/admin/article-edit.php?id=3', $diag['page']);
        // The code itself must not be duplicated into the body
        $this->assertStringNotContainsString(self::CODE, $captured['fields']['diagnostics']);
    }

    public function test_send_drops_invalid_page(): void
    {
        $captured = null;
        support_send_ticket('Тема', 'Описание', '//evil.com', null,
            $this->fakeHttp(200, '{"ok":true,"reference":"R1"}', null, $captured),
            ['code' => self::CODE, 'diagnostics_context' => ['recent_errors' => [], 'user' => []]]);
        $this->assertSame('', $captured['fields']['page']);
    }

    public function test_send_attaches_screenshot_as_curlfile(): void
    {
        $captured = null;
        $upload = ['name' => 'evil.php', 'type' => 'text/x-php', 'tmp_name' => $this->pngFile(),
                   'error' => UPLOAD_ERR_OK, 'size' => 100];
        $r = support_send_ticket('Тема', 'Описание', '', $upload,
            $this->fakeHttp(200, '{"ok":true,"reference":"R2"}', null, $captured),
            ['code' => self::CODE, 'diagnostics_context' => $this->baseContext(), 'require_uploaded' => false]);
        $this->assertTrue($r['ok']);
        $file = $captured['fields']['screenshot'];
        $this->assertInstanceOf(CURLFile::class, $file);
        $this->assertSame('image/png', $file->getMimeType());
        $this->assertSame('screenshot.png', $file->getPostFilename()); // client name ignored
    }

    public function test_send_with_no_file_selected_skips_screenshot(): void
    {
        $captured = null;
        $upload = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $r = support_send_ticket('Тема', 'Описание', '', $upload,
            $this->fakeHttp(200, '{"ok":true,"reference":"R3"}', null, $captured),
            ['code' => self::CODE, 'diagnostics_context' => $this->baseContext()]);
        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('screenshot', $captured['fields']);
    }

    // ── Screenshot validation ──────────────────────────────────────────────────

    public function test_screenshot_real_png_accepted(): void
    {
        $r = support_validate_screenshot(['tmp_name' => $this->pngFile(), 'error' => UPLOAD_ERR_OK], false);
        $this->assertTrue($r['ok']);
        $this->assertSame('image/png', $r['mime']);
    }

    public function test_screenshot_must_be_real_upload(): void
    {
        $r = support_validate_screenshot(['tmp_name' => $this->pngFile(), 'error' => UPLOAD_ERR_OK], true);
        $this->assertFalse($r['ok']);
    }

    public function test_screenshot_fake_image_rejected_despite_client_mime(): void
    {
        $path = $this->tmpFile('<?php echo "hi"; ?>', '.png');
        $r = support_validate_screenshot(['name' => 'shot.png', 'type' => 'image/png',
                                          'tmp_name' => $path, 'error' => UPLOAD_ERR_OK], false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('PNG, JPG или WEBP', (string)$r['error']);
    }

    public function test_screenshot_gif_rejected(): void
    {
        $img = imagecreatetruecolor(2, 2);
        ob_start(); imagegif($img); $bytes = (string) ob_get_clean();
        $r = support_validate_screenshot(['tmp_name' => $this->tmpFile($bytes, '.png'), 'error' => UPLOAD_ERR_OK], false);
        $this->assertFalse($r['ok']);
    }

    public function test_screenshot_too_big_rejected(): void
    {
        // Valid PNG header followed by padding past 5 MB
        $path = $this->pngFile();
        file_put_contents($path, str_repeat("\0", SUPPORT_SCREENSHOT_MAX_BYTES), FILE_APPEND);
        $r = support_validate_screenshot(['tmp_name' => $path, 'error' => UPLOAD_ERR_OK], false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('5 MB', (string)$r['error']);

        $r = support_validate_screenshot(['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE], false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('5 MB', (string)$r['error']);
    }

    public function test_send_rejects_bad_screenshot_without_calling_http(): void
    {
        $called = false;
        $http = function () use (&$called) { $called = true; return ['status' => 200, 'body' => '', 'error' => null]; };
        $upload = ['tmp_name' => $this->tmpFile('not an image'), 'error' => UPLOAD_ERR_OK];
        $r = support_send_ticket('Тема', 'Описание', '', $upload, $http,
            ['code' => self::CODE, 'diagnostics_context' => $this->baseContext(), 'require_uploaded' => false]);
        $this->assertFalse($r['ok']);
        $this->assertFalse($called);
    }

    // ── Support code format + encrypted storage ────────────────────────────────

    public function test_code_format_blocks_header_injection(): void
    {
        $this->assertTrue(support_code_is_valid_format(self::CODE));
        $this->assertFalse(support_code_is_valid_format("abcdefgh\r\nX-Evil: 1"));
        $this->assertFalse(support_code_is_valid_format('has space inside'));
        $this->assertFalse(support_code_is_valid_format('short'));
        $this->assertFalse(support_set_code("abcdefgh\nX-Evil: 1"));
    }

    public function test_code_is_stored_encrypted(): void
    {
        if (!test_db_available() || !function_exists('settings_encrypt')) {
            $this->markTestSkipped('DB not available — skipping encrypted storage test.');
        }
        $pdo = get_pdo();
        settings_ensure_table();
        $sel = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $sel->execute([SUPPORT_SETTING_NAME]);
        $previous = $sel->fetchColumn();

        try {
            $this->assertTrue(support_set_code(self::CODE));
            $sel->execute([SUPPORT_SETTING_NAME]);
            $raw = (string) $sel->fetchColumn();

            $this->assertNotSame('', $raw);
            $this->assertStringNotContainsString(self::CODE, $raw);
            $this->assertStringNotContainsString(self::CODE, (string) base64_decode($raw));
            $this->assertSame(self::CODE, settings_decrypt($raw));
            $this->assertSame(self::CODE, support_get_code());
            $this->assertTrue(support_is_configured());
        } finally {
            if ($previous === false) {
                $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([SUPPORT_SETTING_NAME]);
            } else {
                $pdo->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?')->execute([$previous, SUPPORT_SETTING_NAME]);
            }
        }
    }
}
