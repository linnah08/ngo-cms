<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin users have a first and a last name (and both in Latin letters for the
 * English site) instead of one free-text name: the helpers, migration 051's
 * split of existing names, „Моят профил“, „Потребители“, the article byline
 * and the login page's hint that you log in with your email.
 */
#[Group('admin')]
final class AdminNamesTest extends TestCase
{
    private const EMAIL = 'test.admin.names@example.test';
    private const NEW   = 'test.admin.names.new@example.test';
    private static int $uid = 0;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/includes/auth.php';
    }

    protected function setUp(): void
    {
        if (!test_db_available()) return;
        $pdo = get_pdo();
        $pdo->prepare('DELETE FROM admin_users WHERE email IN (?,?)')->execute([self::EMAIL, self::NEW]);
        $pdo->prepare('INSERT INTO admin_users (name, first_name, last_name, email, password_hash, role) VALUES (?,?,?,?,?,?)')
            ->execute(['Мария Иванова', 'Мария', 'Иванова', self::EMAIL, 'x', 'author']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!test_db_available()) return;
        get_pdo()->prepare('DELETE FROM admin_users WHERE email IN (?,?)')->execute([self::EMAIL, self::NEW]);
    }

    private function needDb(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
    }

    private function row(string $email = self::EMAIL): array|false
    {
        $st = get_pdo()->prepare('SELECT * FROM admin_users WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch();
    }

    private function asMe(): array
    {
        return ['role' => 'author', 'user' => ['id' => self::$uid, 'name' => 'Мария Иванова', 'email' => self::EMAIL]];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function testFullNameIsFirstThenLast(): void
    {
        $this->assertSame('Мария Иванова', admin_full_name(' Мария ', 'Иванова '));
        $this->assertSame('Екип', admin_full_name('Екип', ''), 'no trailing space without a last name');
    }

    public function testSplitTakesTheFirstWordAsFirstName(): void
    {
        $this->assertSame(['Мария', 'Петрова Иванова'], admin_split_name('  Мария   Петрова Иванова '));
        $this->assertSame(['Администратор', ''], admin_split_name('Администратор'));
        $this->assertSame(['', ''], admin_split_name(''));
    }

    public function testOnlyTheFirstNameIsRequired(): void
    {
        $r = admin_names_from_input(['first_name' => '  ', 'last_name' => 'Иванова']);
        $this->assertNotNull($r['error']);

        $r = admin_names_from_input(['first_name' => ' Мария ', 'last_name_en' => ['not', 'a', 'string']]);
        $this->assertNull($r['error']);
        $this->assertSame(['first_name' => 'Мария', 'last_name' => '', 'first_name_en' => '', 'last_name_en' => ''], $r['names']);

        $r = admin_names_from_input(['first_name' => str_repeat('я', 150)]);
        $this->assertSame(100, mb_strlen($r['names']['first_name']), 'cut to the column size');
    }

    public function testBylineUsesTheLatinNameOnlyForEnglish(): void
    {
        $u = ['name' => 'Мария Иванова', 'name_en' => 'Maria Ivanova'];
        $this->assertSame('Мария Иванова', admin_byline($u, 'bg'));
        $this->assertSame('Maria Ivanova', admin_byline($u, 'en'));
        $this->assertSame('Мария Иванова', admin_byline(['name' => 'Мария Иванова', 'name_en' => ' '], 'en'), 'no Latin name: the Bulgarian one');
    }

    public function testSavingNamesKeepsTheDisplayNameInStep(): void
    {
        $this->needDb();
        admin_save_names(get_pdo(), self::$uid, ['first_name' => 'Ива', 'last_name' => 'Петрова', 'first_name_en' => 'Iva', 'last_name_en' => 'Petrova']);
        $row = $this->row();
        $this->assertSame('Ива Петрова', $row['name']);
        $this->assertSame('Petrova', $row['last_name_en']);
    }

    // ── Migration 051 ─────────────────────────────────────────────────────

    public function testMigrationSplitsExistingNamesLikeTheHelper(): void
    {
        $this->needDb();
        $pdo = get_pdo();
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/051_admin_user_names.sql');
        $this->assertSame(1, preg_match('/UPDATE admin_users.*?;/s', $sql, $m));
        $update = rtrim($m[0], ';') . ' AND email = ?';

        foreach (['  Мария Петрова Иванова ', 'Администратор', 'Ana Lee'] as $name) {
            $pdo->prepare("UPDATE admin_users SET name = ?, first_name = '', last_name = '' WHERE email = ?")->execute([$name, self::EMAIL]);
            $pdo->prepare($update)->execute([self::EMAIL]);
            $row = $this->row();
            $this->assertSame(admin_split_name($name), [$row['first_name'], $row['last_name']], $name);
        }

        // A name someone already split is never touched again.
        $pdo->prepare("UPDATE admin_users SET name = 'Друго Име', first_name = 'Мария', last_name = 'Иванова' WHERE email = ?")->execute([self::EMAIL]);
        $pdo->prepare($update)->execute([self::EMAIL]);
        $this->assertSame('Иванова', $this->row()['last_name']);
    }

    // ── „Моят профил“ ─────────────────────────────────────────────────────

    public function testAccountPageShowsTheNameFieldsAndSaysTheEmailIsTheLogin(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/account.php', [], $this->asMe() + ['method' => 'GET']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('С него влизате в панела.', $r['body']);
        $this->assertMatchesRegularExpression('/<label for="first_name">Име<\/label>/', $r['body']);
        $this->assertMatchesRegularExpression('/id="last_name"[^>]*value="Иванова"/', $r['body']);
        $this->assertMatchesRegularExpression('/id="first_name_en"[^>]*data-translate-from="first_name"/', $r['body']);
    }

    public function testAccountSavesOwnNames(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/account.php',
            ['action' => 'names', 'first_name' => 'Мария', 'last_name' => 'Петрова', 'first_name_en' => 'Maria', 'last_name_en' => 'Petrova'],
            $this->asMe());
        $this->assertSame(302, $r['status']);
        $row = $this->row();
        $this->assertSame('Мария Петрова', $row['name']);
        $this->assertSame('Maria', $row['first_name_en']);
    }

    public function testAccountRefusesAnEmptyFirstNameVisibly(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/account.php', ['action' => 'names', 'first_name' => ' ', 'last_name' => 'Петрова'], $this->asMe());
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Имената не бяха запазени.', $r['body']);
        $this->assertSame('Мария Иванова', $this->row()['name'], 'nothing saved');
    }

    public function testAccountNamesNeedCsrf(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/account.php', ['action' => 'names', 'first_name' => 'Хакер'], $this->asMe() + ['csrf' => false]);
        $this->assertSame(400, $r['status']);
        $this->assertSame('Мария', $this->row()['first_name']);
    }

    // ── „Потребители“ ─────────────────────────────────────────────────────

    public function testAddingAUserStoresBothNames(): void
    {
        $this->needDb();
        run_admin_page('admin/users.php',
            ['action' => 'add', 'first_name' => 'Ива', 'last_name' => 'Петрова', 'first_name_en' => 'Iva', 'last_name_en' => '', 'email' => self::NEW, 'role' => 'author']);
        $row = $this->row(self::NEW);
        $this->assertNotFalse($row);
        $this->assertSame(['Ива Петрова', 'Ива', 'Петрова', 'Iva'], [$row['name'], $row['first_name'], $row['last_name'], $row['first_name_en']]);
    }

    public function testAddingAUserWithoutAFirstNameIsRefused(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/users.php', ['action' => 'add', 'first_name' => '', 'last_name' => 'Петрова', 'email' => self::NEW, 'role' => 'author']);
        $this->assertStringContainsString('Моля попълнете име и имейл.', $r['body']);
        $this->assertFalse($this->row(self::NEW));
    }

    public function testAnAdminRenamesAUser(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/users.php',
            ['action' => 'rename', 'id' => (string) self::$uid, 'first_name' => 'Мими', 'last_name' => '', 'first_name_en' => 'Mimi', 'last_name_en' => '']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('Мими', $this->row()['name']);
    }

    public function testAnAuthorCannotRenameAnyone(): void
    {
        $this->needDb();
        run_admin_page('admin/users.php', ['action' => 'rename', 'id' => (string) self::$uid, 'first_name' => 'Мими'], ['role' => 'author']);
        $this->assertSame('Мария', $this->row()['first_name']);
    }

    public function testUsersPageOffersTheNameFields(): void
    {
        $this->needDb();
        $r = run_admin_page('admin/users.php', [], ['method' => 'GET']);
        $this->assertMatchesRegularExpression('/<label for="new-user-first_name">Име<\/label>/', $r['body']);
        $this->assertMatchesRegularExpression('/id="new-user-last_name_en"[^>]*data-translate-from="new-user-last_name"/', $r['body']);
        $this->assertStringContainsString('С този имейл ще влиза в панела.', $r['body']);
        $this->assertStringContainsString('Промени имената', $r['body']);
    }

    // ── Article byline and login ──────────────────────────────────────────

    public function testNewArticleOffersTheLatinNameAsEnglishAuthor(): void
    {
        $r = run_admin_page('admin/article-edit.php', [],
            ['method' => 'GET', 'user' => ['name' => 'Мария Иванова', 'name_en' => 'Maria Ivanova']]);
        $this->assertMatchesRegularExpression('/id="author"[^>]*\s+value="Мария Иванова"/', $r['body']);
        $this->assertMatchesRegularExpression('/id="author_en"[^>]*data-translate-from="author"[^>]*value="Maria Ivanova"/s', $r['body']);
    }

    public function testLoginPageSaysYouLogInWithYourEmail(): void
    {
        $r = run_admin_page('admin/login.php', [], ['role' => null, 'method' => 'GET']);
        $this->assertStringContainsString('Влизате с имейла си, не с името.', $r['body']);
        $this->assertMatchesRegularExpression('/id="email"[^>]*aria-describedby="email-hint"/', $r['body']);
    }
}
