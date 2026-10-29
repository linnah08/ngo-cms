<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__) . '/Support/TestServer.php';

/**
 * Admin pages visited the ordinary way must not log PHP warnings or
 * deprecations (every one lands in logs/errors.log and can raise an alert).
 *
 * - newsletter-subscribers.php without ?status= read $_GET['status'] after
 *   `?? 'all'` had already matched the whitelist — a warning on every visit.
 * - order-view.php json_decode()d products.variants, which is NULL for every
 *   ordinary product — a deprecation on every order with such a product.
 */
#[Group('http')]
#[Group('admin')]
final class AdminPagesNoWarningsHttpTest extends TestCase
{
    private static string $base     = '';
    private static string $email    = 'test.nowarnings.http@example.test';
    private static string $password = 'NoWarningsHttpTest_Pass_42!';
    private static ?int   $uid      = null;
    private static string $jar      = '';
    private static ?string $unavailable = null;
    private static ?int   $productId = null;
    private static ?int   $orderId   = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') { self::$unavailable = 'Could not start a local test server.'; return; }
        if (!test_db_available()) { self::$unavailable = 'DB not available.'; return; }

        $pdo = get_pdo();
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException) {}
        $pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::$email]);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['No Warnings Test', self::$email, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();

        // An ordinary product: variants left NULL on purpose.
        $pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['test-nowarn-' . bin2hex(random_bytes(4)), 'Тест без варианти', 'No variants', 5.00, 1, 0]);
        self::$productId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?, 'physical', 'new', 'Тест', 't@example.test', ?, 5, 0, 5, 'cod', 'pending')"
        )->execute(['NW' . substr(bin2hex(random_bytes(7)), 0, 12),
            json_encode([['product_id' => self::$productId, 'name' => 'Тест без варианти', 'price' => 5, 'quantity' => 1]])]);
        self::$orderId = (int) $pdo->lastInsertId();

        self::$jar = (string) tempnam(sys_get_temp_dir(), 'ngo_nowarn_jar_');
        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['email' => self::$email, 'password' => self::$password]),
            CURLOPT_COOKIEJAR => self::$jar, CURLOPT_COOKIEFILE => self::$jar,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        if (self::$jar !== '') @unlink(self::$jar);
        if (self::$orderId !== null) get_pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([self::$orderId]);
        if (self::$productId !== null) get_pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([self::$productId]);
        if (self::$uid !== null) get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
    }

    /** GET $path logged in; returns [status, body, what the request added to the error log]. */
    private function visit(string $path): array
    {
        if (self::$unavailable !== null) $this->markTestSkipped(self::$unavailable);
        clearstatcache();
        $before = is_file(ERROR_LOG_FILE) ? (int) filesize(ERROR_LOG_FILE) : 0;
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEFILE => self::$jar, CURLOPT_COOKIEJAR => self::$jar,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        clearstatcache();
        $log = is_file(ERROR_LOG_FILE) ? (string) file_get_contents(ERROR_LOG_FILE, false, null, $before) : '';
        return [$code, $body, $log];
    }

    private function assertNoWarningsFrom(string $file, string $log): void
    {
        foreach (explode(PHP_EOL, $log) as $line) {
            $this->assertStringNotContainsString($file, $line, 'logged: ' . $line);
        }
    }

    public function testNewsletterSubscribersWithoutFiltersLogsNoWarning(): void
    {
        [$code, , $log] = $this->visit('/admin/newsletter-subscribers.php');
        if ($code === 404) $this->markTestSkipped('Newsletter module is off here.');
        $this->assertSame(200, $code);
        $this->assertNoWarningsFrom('newsletter-subscribers.php', $log);
    }

    public function testNewsletterSubscribersRejectsUnknownFilters(): void
    {
        [$code, , $log] = $this->visit('/admin/newsletter-subscribers.php?status=bogus&source[]=x');
        if ($code === 404) $this->markTestSkipped('Newsletter module is off here.');
        $this->assertSame(200, $code);
        $this->assertNoWarningsFrom('newsletter-subscribers.php', $log);
    }

    public function testOrderWithAProductWithoutVariantsLogsNoDeprecation(): void
    {
        [$code, , $log] = $this->visit('/admin/order-view.php?id=' . self::$orderId);
        if ($code === 404) $this->markTestSkipped('Shop module is off here.');
        $this->assertSame(200, $code);
        $this->assertNoWarningsFrom('order-view.php', $log);
    }

    /**
     * `in_array($_GET['k'] ?? 'x', [..'x'..]) ? $_GET['k'] : 'x'` reads the missing
     * key whenever the default is itself allowed. Nowhere in the code may do that.
     */
    public function testNoWhitelistTernaryRereadsAMissingKey(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->getExtension() !== 'php' || preg_match('#/(vendor|node_modules|tests)/#', $path)) continue;
            $src = (string) file_get_contents($path);
            if (preg_match_all('/in_array\(\$_(GET|POST|REQUEST)\[\'(\w+)\'\] \?\? \'[^\']+\'[^;]*?\) \? \$_\1\[\'\2\'\]/', $src, $m)) {
                foreach ($m[0] as $hit) $hits[] = substr($path, strlen($root) + 1) . ': ' . $hit;
            }
        }
        $this->assertSame([], $hits);
    }
}
