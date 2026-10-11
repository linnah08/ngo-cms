<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own admin roles (includes/site_extensions.php). */
final class SiteRolesTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    private function site(string $php): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, $php);
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_without_a_site_file_only_the_built_in_roles_exist(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $this->assertSame(['admin', 'author', 'shop_admin'], array_keys(admin_roles()));
        $this->assertNull(site_role_redirect('author', 'dashboard.php'));
        $this->assertFalse(admin_role_is_site_role('admin'));
    }

    public function test_a_site_role_lives_in_its_own_pages(): void
    {
        $this->site('<?php return ["roles" => [
            "partner_admin" => ["label" => "Партньор", "home" => "/partner/", "admin_pages" => ["profile.php", "../evil.php"]],
            "admin"         => ["label" => "Hijack", "home" => "/x/"],
            "bad_home"      => ["label" => "X", "home" => "/admin/orders.php"],
            "no_home"       => ["label" => "X"],
        ]];');
        $this->assertSame(['partner_admin'], array_keys(site_admin_roles()), 'built-in names, admin homes and missing homes are refused');
        $this->assertSame('Администратор', admin_roles()['admin'], 'a site cannot relabel a built-in role');
        $this->assertSame('Партньор', admin_roles()['partner_admin']);
        $this->assertSame('/partner/', site_role_redirect('partner_admin', 'dashboard.php'));
        $this->assertNull(site_role_redirect('partner_admin', 'profile.php'), 'a listed admin page stays open');
        $this->assertSame(['profile.php'], site_admin_roles()['partner_admin']['admin_pages'], 'only plain file names');
        $this->assertTrue(admin_role_is_site_role('partner_admin'));
    }

    public function test_site_roles_get_no_edit_bar_and_cannot_edit_pages(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString('admin_role_is_site_role', (string) file_get_contents("$root/admin/inline-save.php"));
        $this->assertStringContainsString('admin_role_is_site_role', (string) file_get_contents("$root/templates/header.php"));
        $this->assertStringContainsString('admin_role_is_site_role', (string) file_get_contents("$root/admin/includes/auth.php"));
        $this->assertStringContainsString('site_role_redirect', (string) file_get_contents("$root/config.php"));
    }

    public function test_the_role_and_document_type_columns_take_any_site_value(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/044_site_extensible_types.sql');
        $this->assertStringContainsString('MODIFY role VARCHAR', $sql);
        $this->assertStringContainsString('MODIFY type VARCHAR', $sql);
        $this->assertStringNotContainsString('IF NOT EXISTS', $sql, 'portable to MySQL and MariaDB');
    }
}
