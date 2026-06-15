<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class LoginTest extends TestCase
{
    private static \PDO $pdo;
    private static int  $uid;
    private static string $email    = 'test.login@example.test';
    private static string $password = 'LoginTest_Pass_42!';

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;

        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';

        self::$pdo = get_pdo();
        $hash = password_hash(self::$password, PASSWORD_BCRYPT);
        self::$pdo->prepare(
            'INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        )->execute(['Login Test', self::$email, $hash, 'author']);
        self::$uid = (int) self::$pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (!test_db_available()) return;
        self::$pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
    }

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    public function testCorrectCredentialsReturnTrue(): void
    {
        $this->assertTrue(admin_login(self::$email, self::$password));
    }

    public function testCorrectCredentialsSetsSession(): void
    {
        admin_login(self::$email, self::$password);
        $this->assertArrayHasKey(ADMIN_SESSION_NAME, $_SESSION);
        $this->assertSame(self::$email, $_SESSION[ADMIN_SESSION_NAME]['email']);
    }

    public function testWrongPasswordReturnsFalse(): void
    {
        $this->assertFalse(admin_login(self::$email, 'WrongPassword!'));
    }

    public function testWrongPasswordDoesNotSetSession(): void
    {
        admin_login(self::$email, 'WrongPassword!');
        $this->assertArrayNotHasKey(ADMIN_SESSION_NAME, $_SESSION);
    }

    public function testUnknownEmailReturnsFalse(): void
    {
        $this->assertFalse(admin_login('nobody@nowhere.test', self::$password));
    }
}
