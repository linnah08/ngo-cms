<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end login smoke test: verifies the full login round-trip through the
 * real web server (oddminds.test), including document-root resolution, session
 * creation, and redirect to the admin dashboard.
 */
#[Group('http')]
#[Group('admin')]
final class LoginHttpTest extends TestCase
{
    private static string $base     = 'http://oddminds.test';
    private static string $email    = 'test.login.http@oddminds.test';
    private static string $password = 'LoginHttpTest_Pass_42!';
    private static ?int   $uid      = null;

    public static function setUpBeforeClass(): void
    {
        // Skip when oddminds.test is unreachable.
        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return; // skip flag set per-test via test_db_available + reachability
        }

        if (!test_db_available()) return;

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
        if (self::$uid === null || !test_db_available()) return;
        get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
    }

    private function skip(): void
    {
        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !test_db_available() || self::$uid === null) {
            $this->markTestSkipped('oddminds.test or DB not available.');
        }
    }

    public function testLoginPageLoads(): void
    {
        $ch = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $errno = curl_errno($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) $this->markTestSkipped('oddminds.test not reachable.');
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
