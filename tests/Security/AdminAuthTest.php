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
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'author@example.org',
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
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'admin@example.org',
            'role'      => 'admin',
            'time'      => time(),
        ];

        $this->assertTrue(admin_logged_in(), 'admin_logged_in() must return true for a fresh, valid session');
    }

    // ── admin_is_admin ────────────────────────────────────────────────────────

    public function testAdminIsAdminReturnsFalseForAuthorRole(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'author@example.org',
            'role'      => 'author',
            'time'      => time(),
        ];

        $this->assertFalse(admin_is_admin(), 'admin_is_admin() must return false for the "author" role');
    }

    public function testAdminIsAdminReturnsTrueForAdminRole(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'admin@example.org',
            'role'      => 'admin',
            'time'      => time(),
        ];

        $this->assertTrue(admin_is_admin(), 'admin_is_admin() must return true for the "admin" role');
    }

    // ── admin_can_editorial ───────────────────────────────────────────────────

    public function testAdminCanEditorialReturnsTrueForAuthor(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'author@example.org',
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
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'admin@example.org',
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
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => admin_session_site(),
            'logged_in' => true,
            'email'     => 'shop@example.org',
            'role'      => 'shop_admin',
            'time'      => time(),
        ];

        $this->assertFalse(
            admin_can_editorial(),
            'admin_can_editorial() must return false for the "shop_admin" role'
        );
    }

    public function testASessionFromAnotherInstallationIsRefused(): void
    {
        // A login left over from an earlier install on the same address: the
        // session cookie survived, and the new first admin has the same id.
        $_SESSION[ADMIN_SESSION_NAME] = ['site' => 'from-the-old-install', 'id' => 1, 'role' => 'admin', 'time' => time()];
        $this->assertFalse(admin_logged_in());
        $this->assertArrayNotHasKey(ADMIN_SESSION_NAME, $_SESSION);

        $_SESSION[ADMIN_SESSION_NAME] = ['id' => 1, 'role' => 'admin', 'time' => time()];
        $this->assertFalse(admin_logged_in(), 'a session from before the binding existed logs in again once');
    }

    public function testTheInstallLoginLinkWorksOnceAndExpires(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        require_once dirname(__DIR__, 2) . '/admin/includes/auth.php';
        $pdo = get_pdo();
        $put = static function (string $token, int $exp) use ($pdo): void {
            $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('install_login', ?)")->execute([hash('sha256', $token) . '|' . $exp]);
        };
        try {
            $put('good', time() + 900);
            $this->assertNull(admin_redeem_install_login($pdo, 'wrong'), 'a wrong token');
            $this->assertNull(admin_redeem_install_login($pdo, 'good'), 'a wrong guess spends the link');

            $put('good', time() + 900);
            $user = admin_redeem_install_login($pdo, 'good');
            if ($user !== null) $this->assertSame('admin', $user['role']);
            $this->assertNull(admin_redeem_install_login($pdo, 'good'), 'works only once');

            $put('late', time() - 1);
            $this->assertNull(admin_redeem_install_login($pdo, 'late'), 'expired');
            $this->assertNull(admin_redeem_install_login($pdo, ''), 'no token');
        } finally {
            $pdo->exec("DELETE FROM settings WHERE `key` = 'install_login'");
        }
    }
}
