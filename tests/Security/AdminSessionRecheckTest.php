<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A signed-in user's row is read again on every request (admin_session_recheck in
 * config.php), so a role change or a removed account takes effect on the next
 * click. Before, the role remembered at login stayed until the person signed out:
 * someone moved to a more limited role kept their old access all day.
 */
final class AdminSessionRecheckTest extends TestCase
{
    private PDO $pdo;
    private array $ids = [];

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $this->pdo = get_pdo();
        if (session_status() === PHP_SESSION_NONE) @session_start();
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        if (!isset($this->pdo)) return;
        foreach ($this->ids as $id) $this->pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    }

    private function user(string $role, string $name = 'Recheck Test'): int
    {
        $this->pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$name, 'recheck-' . bin2hex(random_bytes(4)) . '@test.local', password_hash('x', PASSWORD_DEFAULT), $role]);
        return $this->ids[] = (int) $this->pdo->lastInsertId();
    }

    private function signIn(int $id, string $role): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(), 'id' => $id, 'name' => 'Old name',
            'email' => 'old@test.local', 'role' => $role, 'time' => time()];
    }

    public function test_a_role_changed_after_login_applies_at_once(): void
    {
        $id = $this->user('author', 'Нова Роля');
        $this->signIn($id, 'admin');          // signed in back when they were an admin

        $this->assertTrue(admin_session_recheck($this->pdo));

        $this->assertSame('author', $_SESSION[ADMIN_SESSION_NAME]['role']);
        $this->assertSame('Нова Роля', $_SESSION[ADMIN_SESSION_NAME]['name']);
        $this->assertFalse(admin_is_admin());
    }

    public function test_a_removed_account_is_signed_out(): void
    {
        $id = $this->user('admin');
        $this->signIn($id, 'admin');
        $this->pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);

        $this->assertFalse(admin_session_recheck($this->pdo));
        $this->assertArrayNotHasKey(ADMIN_SESSION_NAME, $_SESSION);
    }

    public function test_an_unchanged_session_stays_as_it_is(): void
    {
        $id = $this->user('admin');
        $this->signIn($id, 'admin');
        $time = $_SESSION[ADMIN_SESSION_NAME]['time'];

        $this->assertTrue(admin_session_recheck($this->pdo));
        $this->assertSame('admin', $_SESSION[ADMIN_SESSION_NAME]['role']);
        $this->assertSame($time, $_SESSION[ADMIN_SESSION_NAME]['time']);
        $this->assertSame($id, $_SESSION[ADMIN_SESSION_NAME]['id']);
    }

    public function test_an_admin_page_refuses_someone_demoted_while_signed_in(): void
    {
        $id = $this->user('author');

        // The session still says "admin", as it did for anyone signed in before the change.
        $r = run_admin_page('admin/users.php', [], ['method' => 'GET', 'role' => 'admin', 'recheck' => true,
            'user' => ['id' => $id, 'email' => 'old@test.local']]);

        $this->assertNotSame(200, $r['status'], 'users.php opened for an author: ' . substr(strip_tags($r['body']), 0, 300));
        $this->assertSame('author', $r['session'][ADMIN_SESSION_NAME]['role'] ?? null);
    }

    public function test_an_admin_page_sends_a_removed_account_to_the_login(): void
    {
        $r = run_admin_page('admin/dashboard.php', [], ['method' => 'GET', 'role' => 'admin', 'recheck' => true,
            'user' => ['id' => 999999999]]);

        $this->assertArrayNotHasKey(ADMIN_SESSION_NAME, $r['session']);
        $this->assertStringNotContainsString('</nav>', $r['body']);
    }
}
