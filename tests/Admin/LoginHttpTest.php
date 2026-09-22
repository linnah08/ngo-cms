<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end login smoke test: verifies the full login round-trip through the
 * real web server (example.test), including document-root resolution, session
 * creation, and redirect to the admin dashboard.
 */
#[Group('http')]
#[Group('admin')]
final class LoginHttpTest extends TestCase
{
    private static string $base     = 'http://example.test';
    private static string $email    = 'test.login.http@example.test';
    private static string $password = 'LoginHttpTest_Pass_42!';
    private static ?int   $uid      = null;

    /** Null when example.test is serving this site; otherwise why the tests skip. */
    private static ?string $unavailable = null;

    public static function setUpBeforeClass(): void
    {
        // Require HTTP 200 on /, not just a successful curl: a catch-all dev server
        // (e.g. Laravel Herd) answers unknown hosts with its own 404, and the tests
        // would then fail as though login were broken. See HttpTest::setUpBeforeClass().
        $ch = curl_init(self::$base . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            self::$unavailable = 'example.test is not reachable.';
            return;
        }
        if ($code !== 200) {
            self::$unavailable = "example.test answered HTTP $code for / — something other than this site is serving that host.";
            return;
        }

        if (!test_db_available()) {
            self::$unavailable = 'DB not available.';
            return;
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

        $pdo  = get_pdo();
        $hash = password_hash(self::$password, PASSWORD_BCRYPT);
        $pdo->prepare(
            'INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        )->execute(['HTTP Login Test', self::$email, $hash, 'author']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$uid === null) return;
        get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
    }

    private function skip(): void
    {
        if (self::$unavailable !== null) {
            $this->markTestSkipped(self::$unavailable);
        }
    }

    public function testLoginPageLoads(): void
    {
        $this->skip();

        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertSame(200, $code);
    }

    public function testWrongCredentialsShowError(): void
    {
        $this->skip();

        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'email'    => self::$email,
            'password' => 'WrongPassword!',
        ]));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $code, 'Wrong-credentials POST must return 200');
        $this->assertStringContainsString('admin-alert--error', $body,
            'Wrong-credentials response must contain the error alert');
    }

    public function testCorrectCredentialsRedirectToDashboard(): void
    {
        $this->skip();

        $cookieJar = tempnam(sys_get_temp_dir(), 'om_login_test_');

        // POST login.
        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'email'    => self::$email,
            'password' => self::$password,
        ]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp     = curl_exec($ch);
        $postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(302, $postCode,
            'Correct-credentials POST must redirect (302) to dashboard');
        $this->assertStringContainsString('/admin/dashboard.php', $resp,
            'Redirect Location must point to the admin dashboard');

        // Follow to dashboard with the session cookie.
        $ch = curl_init(self::$base . '/admin/dashboard.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_exec($ch);
        $dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @unlink($cookieJar);

        $this->assertSame(200, $dashCode,
            'Dashboard must return 200 after successful login (session must persist across redirect)');
    }
}
