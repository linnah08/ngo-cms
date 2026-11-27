<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * After the session runs out, logging in again returns to the page the person was
 * on (admin_login_return_path / admin_login_url_for_request in config.php) — never
 * to another site.
 */
#[Group('admin')]
final class LoginReturnPathTest extends TestCase
{
    private array $server;

    protected function setUp(): void    { $this->server = $_SERVER; }
    protected function tearDown(): void { $_SERVER = $this->server; }

    public static function unsafe(): array
    {
        return [
            'empty'             => [''],
            'other host'        => ['https://evil.example/admin/'],
            'protocol-relative' => ['//evil.example/admin/'],
            'backslash'         => ['/admin/\\evil.example'],
            'newline'           => ["/admin/dashboard.php\r\nSet-Cookie: x=1"],
            'public page'       => ['/novini/'],
            'dot dot'           => ['/admin/../config.php'],
            'login loop'        => ['/admin/login.php?next=/admin/x.php'],
            'logout'            => ['/admin/logout.php'],
            'ping endpoint'     => ['/admin/session-ping.php'],
        ];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeReturnGoesToDashboard(string $raw): void
    {
        $this->assertSame('/admin/dashboard.php', admin_login_return_path($raw));
    }

    public function testAdminPageWithQueryIsKept(): void
    {
        $this->assertSame('/admin/article-edit.php?slug=a-b', admin_login_return_path('/admin/article-edit.php?slug=a-b'));
        $this->assertSame('/admin/dashboard.php', admin_login_return_path(null));
    }

    public function testGetRequestReturnsToItself(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/admin/products.php?q=x';
        $this->assertSame('/admin/login.php?next=' . rawurlencode('/admin/products.php?q=x'), admin_login_url_for_request());
    }

    public function testFormSentAfterExpiryReturnsToTheFormsPage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/admin/article-edit.php';
        $_SERVER['HTTP_HOST']      = 'site.test:8080';
        $_SERVER['HTTP_REFERER']   = 'http://site.test:8080/admin/article-edit.php?slug=my-post';
        $this->assertSame('/admin/login.php?next=' . rawurlencode('/admin/article-edit.php?slug=my-post'), admin_login_url_for_request());
    }

    public function testFormFromAnotherSiteGoesToPlainLogin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/admin/article-edit.php';
        $_SERVER['HTTP_HOST']      = 'site.test';
        $_SERVER['HTTP_REFERER']   = 'https://evil.example/admin/article-edit.php';
        $this->assertSame('/admin/login.php', admin_login_url_for_request());
    }

    public function testDashboardNeedsNoNext(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/admin/dashboard.php';
        $this->assertSame('/admin/login.php', admin_login_url_for_request());
    }
}
