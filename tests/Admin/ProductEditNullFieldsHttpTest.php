<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression: admin/product-edit.php must render the full form for a product
 * whose nullable columns (image, description_bg, description_en) are NULL.
 *
 * h() is typed `string`, so h(NULL) threw a TypeError mid-render. Because the
 * admin header had already been sent, the 500 error page got injected inside
 * the form and everything below the image field (hidden image input, save
 * area) never rendered — the product could not be edited at all.
 *
 * Boots its own throwaway `php -S` server, like AdminUpdatesPageTest, because
 * the page needs a real login session round trip.
 */
#[Group('http')]
#[Group('admin')]
final class ProductEditNullFieldsHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $adminEmail    = 'test.productedit.admin@example.test';
    private static string $adminPassword = 'ProductEditTest_Admin_42!';
    private static ?int   $adminUid      = null;
    private static ?int   $productId     = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";

        // Log to files, not pipes: an undrained pipe fills and blocks the server.
        $outLog = tempnam(sys_get_temp_dir(), 'om_productedit_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_productedit_srv_err_');
        self::$serverLogs = [$outLog, $errLog];
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root],
            [1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']],
            $pipes,
            self::$root
        );
        if (is_resource($proc)) {
            self::$serverProc = $proc;
        }

        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) {
                self::$serverReady = true;
                break;
            }
            usleep(100_000);
        }

        if (!self::$serverReady || !test_db_available()) {
            return;
        }

        $pdo = get_pdo();

        // Login rate limiter is keyed on action+ip and shared with other HTTP tests.
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException $e) {
            // Table doesn't exist yet — nothing to clear.
        }

        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Product Edit Test Admin', self::$adminEmail, password_hash(self::$adminPassword, PASSWORD_BCRYPT), 'admin']);
        self::$adminUid = (int) $pdo->lastInsertId();

        // image, description_bg and description_en left NULL on purpose.
        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['test-productedit-null-' . bin2hex(random_bytes(4)), 'Тест без снимка', 'No image test', 5.00, 1, 0]);
        self::$productId = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (test_db_available()) {
            $pdo = get_pdo();
            if (self::$productId !== null) {
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([self::$productId]);
            }
            if (self::$adminUid !== null) {
                $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$adminUid]);
            }
        }

        if (is_resource(self::$serverProc)) {
            $status = proc_get_status(self::$serverProc);
            proc_terminate(self::$serverProc, 9);
            for ($i = 0; $i < 20; $i++) {
                if (!proc_get_status(self::$serverProc)['running']) break;
                usleep(50_000);
            }
            if (!empty($status['pid'])) {
                @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
            }
        }

        foreach (self::$serverLogs as $log) {
            @unlink($log);
        }
    }

    public function testEditPageRendersFullFormWhenNullableFieldsAreNull(): void
    {
        if (!self::$serverReady) {
            $this->markTestSkipped('Could not start local php -S test server.');
        }
        if (!test_db_available() || self::$productId === null) {
            $this->markTestSkipped('DB not available — skipping product-edit HTTP test.');
        }

        $jar = tempnam(sys_get_temp_dir(), 'om_productedit_test_');
        $ch  = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['email' => self::$adminEmail, 'password' => self::$adminPassword]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_exec($ch);
        curl_close($ch);

        $ch = curl_init(self::$base . '/admin/product-edit.php?id=' . self::$productId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);

        $this->assertSame(200, $code);
        $this->assertStringContainsString('Тест без снимка', $body, 'Should be logged in and viewing the test product');
        $this->assertStringNotContainsString('TypeError', $body);
        $this->assertStringNotContainsString('<title>500', $body, 'The 500 error page must not be injected into the form');
        $this->assertStringContainsString('id="imageFilename"', $body, 'Form must render past the image field');
        $this->assertStringContainsString('</form>', $body, 'Form must render to the end');
    }
}
