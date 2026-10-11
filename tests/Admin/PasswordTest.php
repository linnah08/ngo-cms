<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The password helpers in admin/includes/auth.php: rules for a new password,
 * changing your own, and the emailed one-time links. Sending mail is covered
 * by PasswordPagesTest, which runs the pages in a process with no mail set up.
 */
#[Group('admin')]
final class PasswordTest extends TestCase
{
    private static \PDO $pdo;
    private static int $uid = 0;
    private const EMAIL = 'test.password@example.test';
    private const PASS  = 'PasswordTest_Old_42!';

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
    }

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        self::$pdo = get_pdo();
        self::$pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::EMAIL]);
        self::$pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)')
            ->execute(['Password Test', self::EMAIL, password_hash(self::PASS, PASSWORD_BCRYPT), 'author']);
        self::$uid = (int) self::$pdo->lastInsertId();
        start_session();
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        if (test_db_available()) {
            get_pdo()->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::EMAIL]);
        }
    }

    private function row(): array
    {
        $st = self::$pdo->prepare('SELECT password_hash, reset_token, reset_expires FROM admin_users WHERE id = ?');
        $st->execute([self::$uid]);
        return $st->fetch();
    }

    private function canLogIn(string $password): bool
    {
        $ok = admin_login(self::EMAIL, $password);
        unset($_SESSION[ADMIN_SESSION_NAME]);
        return $ok;
    }

    // ── new-password rules ────────────────────────────────────────────────

    public function testTooShortPointsAtNewPassword(): void
    {
        $p = admin_password_problem('short', 'short');
        $this->assertSame('new_password', $p['field']);
        $this->assertStringContainsString((string) ADMIN_PASSWORD_MIN, $p['message']);
    }

    public function testMismatchPointsAtConfirmation(): void
    {
        $this->assertSame('new_password2', admin_password_problem('LongEnough1', 'LongEnough2')['field']);
    }

    public function testSpacesAroundTheConfirmationDontCountAsAMismatch(): void
    {
        $this->assertNull(admin_password_problem('LongEnough1', ' LongEnough1 '));
    }

    public function testEightCyrillicLettersAreLongEnough(): void
    {
        // mb_strlen, not strlen: 8 Cyrillic letters are 16 bytes but still 8 characters.
        $this->assertNull(admin_password_problem('паролаАБ', 'паролаАБ'));
        $this->assertSame('new_password', admin_password_problem('парола', 'парола')['field']);
    }

    // ── changing your own password ────────────────────────────────────────

    public function testWrongCurrentPasswordIsRefusedAndNothingChanges(): void
    {
        $before = $this->row()['password_hash'];
        $p = admin_change_password(self::$uid, 'not-it', 'NewPassword_99', 'NewPassword_99');
        $this->assertSame('current_password', $p['field']);
        $this->assertSame($before, $this->row()['password_hash']);
    }

    public function testChangeSwapsWhichPasswordLogsIn(): void
    {
        $this->assertNull(admin_change_password(self::$uid, self::PASS, 'NewPassword_99', 'NewPassword_99'));
        $this->assertTrue($this->canLogIn('NewPassword_99'));
        $this->assertFalse($this->canLogIn(self::PASS));
    }

    public function testNewPasswordProblemsAreReportedAfterCurrentIsChecked(): void
    {
        $this->assertSame('new_password2', admin_change_password(self::$uid, self::PASS, 'NewPassword_99', 'Other_99')['field']);
        $this->assertTrue($this->canLogIn(self::PASS));
    }

    public function testPasswordWithStraySpacesCanStillBeTypedAtLogin(): void
    {
        // login.php trims what is typed, so a password saved with spaces around it must be saved trimmed.
        $this->assertNull(admin_change_password(self::$uid, self::PASS, ' NewPassword_99 ', ' NewPassword_99 '));
        $this->assertTrue($this->canLogIn('NewPassword_99'));
    }

    public function testChangeCancelsAWaitingResetLink(): void
    {
        $token = admin_generate_reset_token(self::EMAIL);
        admin_change_password(self::$uid, self::PASS, 'NewPassword_99', 'NewPassword_99');
        $this->assertNull($this->row()['reset_token']);
        $this->assertNull(admin_reset_password($token, 'Attacker_Pass_1'));
    }

    public function testUnknownUserIsRefused(): void
    {
        $this->assertSame('', admin_change_password(0, self::PASS, 'NewPassword_99', 'NewPassword_99')['field']);
    }

    // ── emailed links ─────────────────────────────────────────────────────

    public function testLinkLifetimeFollowsTheHoursAsked(): void
    {
        foreach ([1 => [0, 1], 24 => [23, 24], 168 => [167, 168]] as $hours => [$lo, $hi]) {
            admin_generate_reset_token(self::EMAIL, $hours);
            $st = self::$pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, NOW(), reset_expires) FROM admin_users WHERE id = ?');
            $st->execute([self::$uid]);
            $minutes = (int) $st->fetchColumn();
            $this->assertGreaterThanOrEqual($lo * 60, $minutes, "$hours h link");
            $this->assertLessThanOrEqual($hi * 60, $minutes, "$hours h link");
        }
    }

    public function testNoTokenForUnknownEmail(): void
    {
        $this->assertFalse(admin_generate_reset_token('nobody@nowhere.test'));
    }

    public function testResetLinkSetsPasswordOnceAndReturnsTheUser(): void
    {
        $token = admin_generate_reset_token(self::EMAIL);
        $user  = admin_reset_password($token, 'ResetPassword_7');
        $this->assertSame(self::EMAIL, $user['email']);
        $this->assertTrue($this->canLogIn('ResetPassword_7'));
        $this->assertNull(admin_reset_password($token, 'Again_Pass_77'), 'a link works only once');
    }

    public function testExpiredLinkIsRefused(): void
    {
        $token = admin_generate_reset_token(self::EMAIL);
        self::$pdo->prepare('UPDATE admin_users SET reset_expires = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([self::$uid]);
        $this->assertNull(admin_reset_password($token, 'ResetPassword_7'));
        $this->assertTrue($this->canLogIn(self::PASS));
    }

    public function testEmptyTokenNeverMatches(): void
    {
        $this->assertNull(admin_reset_password('', 'ResetPassword_7'));
    }
}
