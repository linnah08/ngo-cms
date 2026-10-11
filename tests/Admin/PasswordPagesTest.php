<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Моят профил“ (admin/account.php), the reset links on „Потребители“ and the
 * page an emailed link opens (admin/login-reset.php), each run as a real POST.
 * The pages run in a process with no mail transport, so every email they try
 * fails — which is also how the "email could not be sent" messages get tested.
 */
#[Group('admin')]
final class PasswordPagesTest extends TestCase
{
    private const EMAIL     = 'test.password.pages@example.test';
    private const NEW_EMAIL = 'test.password.pages.new@example.test';
    private const PASS      = 'PagesTest_Old_42!';
    private static int $uid = 0;

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM admin_users WHERE email IN (?,?)')->execute([self::EMAIL, self::NEW_EMAIL]);
        rate_limit_exceeded('admin_change_password', 1000, 1); // creates rate_limits on a fresh DB
        $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_change_password'");
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)')
            ->execute(['Pages Test', self::EMAIL, password_hash(self::PASS, PASSWORD_BCRYPT), 'author']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!test_db_available()) return;
        get_pdo()->prepare('DELETE FROM admin_users WHERE email IN (?,?)')->execute([self::EMAIL, self::NEW_EMAIL]);
    }

    private function row(string $email = self::EMAIL): array|false
    {
        $st = get_pdo()->prepare('SELECT * FROM admin_users WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch();
    }

    private function asMe(): array
    {
        return ['role' => 'author', 'user' => ['id' => self::$uid, 'name' => 'Pages Test', 'email' => self::EMAIL]];
    }

    // ── „Моят профил“ ─────────────────────────────────────────────────────

    public function testAccountPageNeedsLogin(): void
    {
        $r = run_admin_page('admin/account.php', [], ['role' => null, 'method' => 'GET']);
        $this->assertStringNotContainsString('Смяна на парола', $r['body']);
    }

    public function testAccountPageIsOpenToEveryRole(): void
    {
        foreach (['author', 'shop_admin', 'admin'] as $role) {
            $r = run_admin_page('admin/account.php', [], ['role' => $role, 'method' => 'GET']);
            $this->assertSame(200, $r['status'], $role);
            $this->assertStringContainsString('Смяна на парола', $r['body'], $role);
            $this->assertMatchesRegularExpression('/<label for="current_password">/', $r['body']);
        }
    }

    public function testAccountPostWithoutCsrfIsRejected(): void
    {
        $before = $this->row()['password_hash'];
        $r = run_admin_page('admin/account.php',
            ['current_password' => self::PASS, 'new_password' => 'NewPass_123', 'new_password2' => 'NewPass_123'],
            $this->asMe() + ['csrf' => false]);
        $this->assertSame(400, $r['status']);
        $this->assertSame($before, $this->row()['password_hash']);
    }

    public function testWrongCurrentPasswordShowsUnderItsField(): void
    {
        $before = $this->row()['password_hash'];
        $r = run_admin_page('admin/account.php',
            ['current_password' => 'wrong', 'new_password' => 'NewPass_123', 'new_password2' => 'NewPass_123'],
            $this->asMe());
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('id="current_password-err" role="alert"', $r['body']);
        $this->assertStringContainsString('Сегашната парола е грешна', $r['body']);
        $this->assertSame($before, $this->row()['password_hash']);
    }

    public function testSuccessfulChangeSavesAndConfirms(): void
    {
        $r = run_admin_page('admin/account.php',
            ['current_password' => self::PASS, 'new_password' => 'NewPass_123', 'new_password2' => 'NewPass_123'],
            $this->asMe());
        $this->assertSame(302, $r['status']);
        $this->assertSame('success', $r['flash'][0]['type'] ?? null);
        $this->assertTrue(password_verify('NewPass_123', $this->row()['password_hash']));
    }

    public function testRepeatedGuessesAreRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            run_admin_page('admin/account.php',
                ['current_password' => 'guess' . $i, 'new_password' => 'NewPass_123', 'new_password2' => 'NewPass_123'],
                $this->asMe());
        }
        $r = run_admin_page('admin/account.php',
            ['current_password' => self::PASS, 'new_password' => 'NewPass_123', 'new_password2' => 'NewPass_123'],
            $this->asMe());
        $this->assertStringContainsString('Твърде много опити', $r['body']);
        $this->assertTrue(password_verify(self::PASS, $this->row()['password_hash']));
    }

    // ── „Потребители“ ─────────────────────────────────────────────────────

    public function testOnlyAdminsCanSendLinks(): void
    {
        $r = run_admin_page('admin/users.php', ['action' => 'send_password_link', 'id' => self::$uid], ['role' => 'author']);
        $this->assertNull($this->row()['reset_token']);
        $this->assertStringNotContainsString('Изпратихме линк', $r['body']);
    }

    public function testAdminSendsALinkInsteadOfTypingAPassword(): void
    {
        $r = run_admin_page('admin/users.php', ['action' => 'send_password_link', 'id' => self::$uid]);
        $row = $this->row();
        $this->assertNotEmpty($row['reset_token'], 'a 24-hour link is created');
        $this->assertTrue(password_verify(self::PASS, $row['password_hash']), 'the current password keeps working');
        // No mail transport in the page's process: the admin is told plainly that the email failed.
        $this->assertStringContainsString('не можа да бъде изпратен', $r['body']);
        $this->assertStringNotContainsString('name="new_password"', $r['body'], 'no more typing passwords for others');
    }

    public function testNewUserGetsAWeekLongSetPasswordLinkAndNoKnownPassword(): void
    {
        $r = run_admin_page('admin/users.php', ['action' => 'add', 'name' => 'New Person', 'email' => self::NEW_EMAIL, 'role' => 'author']);
        $row = $this->row(self::NEW_EMAIL);
        $this->assertNotFalse($row, 'user created without a password field');
        $this->assertNotEmpty($row['reset_token']);
        $st = get_pdo()->prepare('SELECT TIMESTAMPDIFF(HOUR, NOW(), reset_expires) FROM admin_users WHERE id = ?');
        $st->execute([$row['id']]);
        $this->assertGreaterThanOrEqual(167, (int) $st->fetchColumn());
        $this->assertStringContainsString('Потребителят е добавен', $r['body']);
        $this->assertStringNotContainsString('Временна парола', $r['body']);
    }

    // ── the page an emailed link opens ────────────────────────────────────

    public function testResetLinkSetsTheNewPassword(): void
    {
        $token = admin_generate_reset_token(self::EMAIL);
        $r = run_admin_page('admin/login-reset.php',
            ['token' => $token, 'new_password' => 'Linked_Pass_1', 'new_password2' => 'Linked_Pass_1'],
            ['role' => null, 'csrf' => false]);
        $this->assertStringContainsString('Паролата е запазена', $r['body']);
        $this->assertTrue(password_verify('Linked_Pass_1', $this->row()['password_hash']));
    }

    public function testResetPageKeepsTheLinkWhenThePasswordIsTooShort(): void
    {
        $token = admin_generate_reset_token(self::EMAIL);
        $r = run_admin_page('admin/login-reset.php',
            ['token' => $token, 'new_password' => 'short', 'new_password2' => 'short'],
            ['role' => null, 'csrf' => false]);
        $this->assertStringContainsString('id="new_password-err" role="alert"', $r['body']);
        $this->assertSame($token, $this->row()['reset_token'], 'the link still works for a second try');
    }

    public function testExpiredLinkOffersANewOne(): void
    {
        $r = run_admin_page('admin/login-reset.php',
            ['token' => 'not-a-real-token', 'new_password' => 'Linked_Pass_1', 'new_password2' => 'Linked_Pass_1'],
            ['role' => null, 'csrf' => false]);
        $this->assertStringContainsString('невалиден или е изтекъл', $r['body']);
        $this->assertStringContainsString('/admin/login-forgot.php', $r['body']);
    }

    public function testWelcomeLinkIsWordedAsChoosingAPassword(): void
    {
        $r = run_admin_page('admin/login-reset.php', [], ['role' => null, 'method' => 'GET', 'get' => ['token' => 'abc', 'new' => '1']]);
        $this->assertStringContainsString('Изберете парола', $r['body']);
    }
}
