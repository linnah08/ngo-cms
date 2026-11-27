<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__) . '/Support/TestServer.php';

/**
 * Admin → Напомняния за поръчки over real HTTP: who may open it, CSRF, the two
 * switches and their defaults (both off). The two settings are restored after.
 */
#[Group('http')]
#[Group('admin')]
final class CartRemindersAdminTest extends TestCase
{
    private static string $base = '';
    private static string $password = 'CartRem_Test_42!';
    /** @var array<string,string> role => email */
    private static array $emails = [
        'admin'      => 'test.cartrem.admin@example.test',
        'shop_admin' => 'test.cartrem.shop@example.test',
        'author'     => 'test.cartrem.author@example.test',
    ];
    /** @var int[] */
    private static array $uids = [];
    /** @var array<string,?string> */
    private static array $saved = [];
    private static ?string $unavailable = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') { self::$unavailable = 'Could not start a local test server.'; return; }
        if (!test_db_available()) { self::$unavailable = 'DB not available.'; return; }

        $pdo = get_pdo();
        try { $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'"); } catch (\PDOException) {}
        $pdo->prepare('DELETE FROM admin_users WHERE email IN (?, ?, ?)')->execute(array_values(self::$emails));
        foreach (self::$emails as $role => $email) {
            $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute(['CartRem ' . $role, $email, password_hash(self::$password, PASSWORD_BCRYPT), $role]);
            self::$uids[] = (int) $pdo->lastInsertId();
        }
        foreach (['cart_reminder_email', 'cart_reminder_notice'] as $k) {
            $st = $pdo->prepare('SELECT 1 FROM settings WHERE `key` = ?');
            $st->execute([$k]);
            self::$saved[$k] = $st->fetchColumn() ? setting_get($k, '') : null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (test_db_available()) {
            $pdo = get_pdo();
            if (self::$uids) {
                $in = implode(',', array_fill(0, count(self::$uids), '?'));
                $pdo->prepare("DELETE FROM admin_users WHERE id IN ($in)")->execute(self::$uids);
            }
            foreach (self::$saved as $k => $v) {
                if ($v === null) $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$k]);
                else setting_set($k, $v);
            }
        }
        TestServer::stop();
    }

    protected function setUp(): void
    {
        if (self::$unavailable !== null) $this->markTestSkipped(self::$unavailable);
        get_pdo()->exec("DELETE FROM settings WHERE `key` IN ('cart_reminder_email','cart_reminder_notice')");
    }

    /** @return array{0:int,1:string,2:string} [status, body, Location] */
    private function http(string $path, ?string $jar = null, ?array $post = null): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
        if ($jar !== null) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return [$code, substr($raw, $hs), $m[1] ?? ''];
    }

    private function login(string $role): string
    {
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_cartrem_jar_');
        $this->http('/admin/login.php', $jar, ['email' => self::$emails[$role], 'password' => self::$password]);
        return $jar;
    }

    private function csrf(string $body): string
    {
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m), 'form carries a CSRF token');
        return $m[1];
    }

    public function test_page_requires_login_and_shop_role(): void
    {
        [$code, , $loc] = $this->http('/admin/cart-reminders.php');
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);

        $jar = $this->login('author');
        [$code] = $this->http('/admin/cart-reminders.php', $jar);
        @unlink($jar);
        $this->assertSame(403, $code, 'an author may not open it');

        foreach (['admin', 'shop_admin'] as $role) {
            $jar = $this->login($role);
            [$code, $body] = $this->http('/admin/cart-reminders.php', $jar);
            @unlink($jar);
            $this->assertSame(200, $code, $role);
            $this->assertStringContainsString('Изпращай имейл напомняне за незавършени поръчки', $body);
        }
    }

    public function test_post_without_csrf_rejected(): void
    {
        $jar = $this->login('admin');
        [$code] = $this->http('/admin/cart-reminders.php', $jar, ['csrf_token' => 'deadbeef', 'cart_reminder_email' => '1', 'cart_reminder_notice' => '1']);
        @unlink($jar);
        $this->assertSame(400, $code);
        $this->assertSame('0', setting_get('cart_reminder_email', '0'));
        $this->assertSame('0', setting_get('cart_reminder_notice', '0'));
    }

    public function test_post_saves_both_switches(): void
    {
        $jar = $this->login('shop_admin');
        [, $body] = $this->http('/admin/cart-reminders.php', $jar);
        $csrf = $this->csrf($body);
        [$code] = $this->http('/admin/cart-reminders.php', $jar, ['csrf_token' => $csrf, 'cart_reminder_email' => '1']);
        $this->assertContains($code, [302, 303]);
        $this->assertSame('1', setting_get('cart_reminder_email', '0'));
        $this->assertSame('0', setting_get('cart_reminder_notice', '0'), 'an unticked box is saved as off');

        [, $body] = $this->http('/admin/cart-reminders.php', $jar);
        $csrf = $this->csrf($body);
        $this->http('/admin/cart-reminders.php', $jar, ['csrf_token' => $csrf, 'cart_reminder_notice' => '1']);
        @unlink($jar);
        $this->assertSame('0', setting_get('cart_reminder_email', '0'));
        $this->assertSame('1', setting_get('cart_reminder_notice', '0'));
    }

    public function test_defaults_are_off(): void
    {
        $jar = $this->login('admin');
        [, $body] = $this->http('/admin/cart-reminders.php', $jar);
        @unlink($jar);
        foreach (['cart_reminder_email', 'cart_reminder_notice'] as $name) {
            $this->assertSame(1, preg_match('/<input[^>]*name="' . $name . '"[^>]*>/', $body, $m), $name);
            $this->assertStringNotContainsString('checked', $m[0], "$name is off by default");
            $this->assertMatchesRegularExpression('/<label[^>]*for="' . $name . '"/', $body, "$name has a real label");
        }
    }
}
