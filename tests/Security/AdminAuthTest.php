<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('security')]
#[Group('admin-auth')]
final class AdminAuthTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
    }

    // ── admin_logged_in ───────────────────────────────────────────────────────

    public function testAdminLoggedInReturnsFalseWhenNoSession(): void
    {
        // ADMIN_SESSION_NAME key is entirely absent from $_SESSION
        unset($_SESSION[ADMIN_SESSION_NAME]);

        $this->assertFalse(admin_logged_in(), 'admin_logged_in() must return false when no session data exists');
    }

    public function testAdminLoggedInReturnsFalseWhenExpired(): void
    {
        // Session was set more than 8 hours ago (ADMIN_SESSION_HOURS = 8)
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'author@oddminds.org',
            'role'      => 'author',
            'time'      => time() - (ADMIN_SESSION_HOURS * 3600 + 1),
        ];

        $this->assertFalse(admin_logged_in(), 'admin_logged_in() must return false when session has expired');
        // The function should also have wiped the stale session entry
        $this->assertArrayNotHasKey(
            ADMIN_SESSION_NAME,
            $_SESSION,
            'admin_logged_in() must unset the expired session data'
        );
    }

    public function testAdminLoggedInReturnsTrueWhenValidSession(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@oddminds.org',
            'role'      => 'admin',
            'time'      => time(),
        ];

        $this->assertTrue(admin_logged_in(), 'admin_logged_in() must return true for a fresh, valid session');
    }

    // ── admin_is_admin ────────────────────────────────────────────────────────

    public function testAdminIsAdminReturnsFalseForAuthorRole(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'author@oddminds.org',
            'role'      => 'author',
            'time'      => time(),
        ];

        $this->assertFalse(admin_is_admin(), 'admin_is_admin() must return false for the "author" role');
    }

    public function testAdminIsAdminReturnsTrueForAdminRole(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@oddminds.org',
            'role'      => 'admin',
            'time'      => time(),
        ];

        $this->assertTrue(admin_is_admin(), 'admin_is_admin() must return true for the "admin" role');
    }

    // ── admin_can_editorial ───────────────────────────────────────────────────

    public function testAdminCanEditorialReturnsTrueForAuthor(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'author@oddminds.org',
            'role'      => 'author',
            'time'      => time(),
        ];

        $this->assertTrue(
            admin_can_editorial(),
            'admin_can_editorial() must return true for the "author" role'
        );
    }

    public function testAdminCanEditorialReturnsTrueForAdmin(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@oddminds.org',
            'role'      => 'admin',
            'time'      => time(),
        ];

        $this->assertTrue(
            admin_can_editorial(),
            'admin_can_editorial() must return true for the "admin" role'
        );
    }

    public function testAdminCanEditorialReturnsFalseForShopAdmin(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'shop@oddminds.org',
            'role'      => 'shop_admin',
            'time'      => time(),
        ];

        $this->assertFalse(
            admin_can_editorial(),
            'admin_can_editorial() must return false for the "shop_admin" role'
        );
    }
}
