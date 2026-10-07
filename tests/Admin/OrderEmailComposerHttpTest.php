<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * "Имейл до клиента" over real HTTP: the send action on admin/order-view.php
 * must refuse anyone who is not logged in as a shop manager and any request
 * without the page's CSRF token — and in every refused case nothing may be sent
 * or recorded in the order's email history.
 *
 * Only refusals are exercised with a POST: an accepted send would really email
 * through whatever transport the local database is set up with.
 *
 * Boots its own throwaway `php -S` server, like ProductEditNullFieldsHttpTest.
 */
#[Group('http')]
#[Group('admin')]
final class OrderEmailComposerHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'OrderEmailTest_Pass_42!';
    /** @var array<string,int> role => admin_users.id */
    private static array $users   = [];
    private static ?int  $orderId = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";

        $outLog = tempnam(sys_get_temp_dir(), 'om_orderemail_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_orderemail_srv_err_');
        self::$serverLogs = [$outLog, $errLog];
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root],
            [1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']],
            $pipes,
            self::$root
        );
        if (is_resource($proc)) self::$serverProc = $proc;

        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) { self::$serverReady = true; break; }
            usleep(100_000);
        }
        if (!self::$serverReady || !test_db_available()) return;

        $pdo = get_pdo();
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException) {
        }

        // A run that was killed before tearDown leaves its users behind; clear them first.
        $pdo->prepare('DELETE FROM admin_users WHERE email IN (?, ?)')->execute(['test.orderemail.admin@example.test', 'test.orderemail.author@example.test']);
        foreach (['admin', 'author'] as $role) {
            $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute(["Order Email Test $role", "test.orderemail.$role@example.test", password_hash(self::$password, PASSWORD_BCRYPT), $role]);
            self::$users[$role] = (int)$pdo->lastInsertId();
        }

        $pdo->prepare(
            "INSERT INTO orders (order_number,type,status,lang,customer_name,customer_email,items,
                                 subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute(['TOE' . substr(uniqid(), -10), 'physical', 'new', 'bg', 'Тест Имейл', 'buyer@example.test',
                    '[]', 20.00, 0, 20.00, 'cod', 'pending']);
        self::$orderId = (int)$pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (test_db_available()) {
            $pdo = get_pdo();
            if (self::$orderId !== null) {
                $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([self::$orderId]); // cascades order_emails
            }
            foreach (self::$users as $uid) {
                $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$uid]);
            }
        }
        if (is_resource(self::$serverProc)) {
            $status = proc_get_status(self::$serverProc);
            proc_terminate(self::$serverProc, 9);
            for ($i = 0; $i < 20; $i++) {
                if (!proc_get_status(self::$serverProc)['running']) break;
                usleep(50_000);
            }
            if (!empty($status['pid'])) @exec('kill -9 ' . (int)$status['pid'] . ' 2>/dev/null');
        }
        foreach (self::$serverLogs as $log) @unlink($log);
    }

    private function requireSetup(): void
    {
        if (!self::$serverReady) $this->markTestSkipped('Could not start local php -S test server.');
        if (!test_db_available() || self::$orderId === null) $this->markTestSkipped('DB not available.');
    }

    /** Logs in as $role and returns the cookie jar path. */
    private function login(string $role): string
    {
        $jar = tempnam(sys_get_temp_dir(), 'om_orderemail_jar_');
        $ch  = curl_init(self::$base . '/admin/login.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['email' => "test.orderemail.$role@example.test", 'password' => self::$password]),
            CURLOPT_COOKIEJAR => $jar,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return $jar;
    }

    /** @return array{code:int,body:string,location:string} */
    private function request(string $method, string $url, ?string $jar, array $post = []): array
    {
        $ch = curl_init(self::$base . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $hlen);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        return ['code' => $code, 'body' => substr($raw, $hlen), 'location' => $m[1] ?? ''];
    }

    private function sendPost(array $extra = []): array
    {
        return $extra + [
            'action'        => 'send_customer_email',
            'email_preset'  => 'admin-order-delayed',
            'email_subject' => 'Тема от теста',
            'email_body'    => '<p>Текст от теста</p>',
        ];
    }

    private function historyCount(): int
    {
        $s = get_pdo()->prepare('SELECT COUNT(*) FROM order_emails WHERE order_id = ?');
        $s->execute([self::$orderId]);
        return (int)$s->fetchColumn();
    }

    public function testLoggedOutSendIsSentToLoginAndNothingIsSent(): void
    {
        $this->requireSetup();
        $r = $this->request('POST', '/admin/order-view.php?id=' . self::$orderId, null, $this->sendPost(['csrf_token' => 'x']));

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/login.php', $r['location']);
        $this->assertSame(0, $this->historyCount());
    }

    public function testNonShopRoleIsRefused(): void
    {
        $this->requireSetup();
        $jar = $this->login('author');
        $r   = $this->request('POST', '/admin/order-view.php?id=' . self::$orderId, $jar, $this->sendPost());
        @unlink($jar);

        $this->assertSame(403, $r['code']);
        $this->assertSame(0, $this->historyCount());
    }

    public function testSendWithoutValidCsrfTokenIsRefused(): void
    {
        $this->requireSetup();
        $jar = $this->login('admin');
        // Open the page first, as a real admin would, so the session holds a token to compare against.
        $this->request('GET', '/admin/order-view.php?id=' . self::$orderId, $jar);
        $missing = $this->request('POST', '/admin/order-view.php?id=' . self::$orderId, $jar, $this->sendPost());
        $wrong   = $this->request('POST', '/admin/order-view.php?id=' . self::$orderId, $jar, $this->sendPost(['csrf_token' => str_repeat('0', 64)]));
        @unlink($jar);

        $this->assertSame(400, $missing['code']);
        $this->assertSame(400, $wrong['code']);
        $this->assertSame(0, $this->historyCount());
    }

    public function testOrderPageShowsTheComposerAboveTheHistory(): void
    {
        $this->requireSetup();
        $jar = $this->login('admin');
        $r   = $this->request('GET', '/admin/order-view.php?id=' . self::$orderId, $jar);
        @unlink($jar);

        $this->assertSame(200, $r['code']);
        $body = $r['body'];
        $this->assertStringContainsString('id="customerEmailForm"', $body);
        $this->assertStringContainsString('name="csrf_token"', $body);
        $this->assertStringContainsString('value="send_customer_email"', $body);
        $this->assertStringContainsString('<label for="emailPreset"', $body);
        $this->assertStringContainsString('<label for="emailSubject"', $body);
        $this->assertStringContainsString('<label for="adminEmailBody"', $body);
        $this->assertStringContainsString('value="admin-order-delayed"', $body);
        $this->assertStringContainsString('tinymce.min.js', $body, 'TinyMCE is loaded for the message box');
        $this->assertLessThan(strpos($body, 'id="email-history"'), strpos($body, 'id="customer-email"'));
        $this->assertStringNotContainsString('window.confirm(', $body);
    }
}
