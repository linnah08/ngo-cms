<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('security')]
#[Group('csrf')]
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        // Reset CSRF token so each test starts fresh
        unset($_SESSION['csrf_token']);
        unset($_POST['csrf_token']);
    }

    protected function tearDown(): void
    {
        unset($_POST['csrf_token']);
    }

    // ── csrf_token ────────────────────────────────────────────────────────────

    public function testCsrfTokenIsHexString(): void
    {
        $token = csrf_token();

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token), 'CSRF token must be exactly 64 characters');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $token,
            'CSRF token must be lowercase hex'
        );
    }

    public function testCsrfTokenIsSessionStable(): void
    {
        $first  = csrf_token();
        $second = csrf_token();

        $this->assertSame(
            $first,
            $second,
            'Calling csrf_token() twice must return the same cached value'
        );
    }

    // ── csrf_verify ───────────────────────────────────────────────────────────

    public function testCsrfVerifyAcceptsValidToken(): void
    {
        $token = csrf_token();
        $_POST['csrf_token'] = $token;

        $this->assertTrue(csrf_verify(), 'csrf_verify() must return true for the correct token');
    }

    public function testCsrfVerifyRejectsEmptyToken(): void
    {
        csrf_token(); // ensure a real token is seeded in the session
        $_POST['csrf_token'] = '';

        $this->assertFalse(csrf_verify(), 'csrf_verify() must return false for an empty token');
    }

    public function testCsrfVerifyRejectsMissingToken(): void
    {
        csrf_token(); // ensure a real token is seeded in the session
        unset($_POST['csrf_token']);

        $this->assertFalse(csrf_verify(), 'csrf_verify() must return false when $_POST[csrf_token] is absent');
    }

    public function testCsrfVerifyRejectsTamperedToken(): void
    {
        csrf_token(); // seed session with a real (non-'aaa...') token
        $_POST['csrf_token'] = str_repeat('a', 64);

        $this->assertFalse(csrf_verify(), 'csrf_verify() must return false for a wrong token');
    }
}
