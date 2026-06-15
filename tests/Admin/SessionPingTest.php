<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class SessionPingTest extends TestCase
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

    public function testRefreshDoesNothingWhenNoSession(): void
    {
        admin_session_refresh();
        $this->assertArrayNotHasKey(
            ADMIN_SESSION_NAME, $_SESSION,
            'admin_session_refresh() must not create a session when none exists'
        );
    }

    public function testRefreshUpdatesSessionTime(): void
    {
        $old = time() - 3600;
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@example.org',
            'role'      => 'admin',
            'time'      => $old,
        ];

        admin_session_refresh();

        $this->assertGreaterThan(
            $old,
            $_SESSION[ADMIN_SESSION_NAME]['time'],
            'admin_session_refresh() must update the session time to now'
        );
        $this->assertEqualsWithDelta(
            time(),
            $_SESSION[ADMIN_SESSION_NAME]['time'],
            2,
            'Updated time must be within 2 seconds of now'
        );
    }

    public function testRefreshPreservesOtherSessionFields(): void
    {
        $_SESSION[ADMIN_SESSION_NAME] = [
            'logged_in' => true,
            'email'     => 'admin@example.org',
            'role'      => 'admin',
            'time'      => time() - 100,
        ];

        admin_session_refresh();

        $this->assertSame('admin@example.org', $_SESSION[ADMIN_SESSION_NAME]['email']);
        $this->assertSame('admin',              $_SESSION[ADMIN_SESSION_NAME]['role']);
    }
}
