<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

/**
 * Partners only appear on the public site through the front page's Партньори
 * section, so the admin menu item follows that section (home_section_on()).
 */
final class HomePartnersMenuTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/home-partners-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    private function write(bool $partners_visible): void
    {
        $doc = home_seed([], [], [], $partners_visible ? [] : ['partners']);
        file_put_contents($GLOBALS['_om_home_file'], json_encode($doc + ['rev' => 1]));
    }

    public function test_on_when_the_partners_section_is_shown(): void
    {
        $this->write(true);
        $this->assertTrue(home_section_on('partners'));
    }

    public function test_off_when_the_partners_section_is_switched_off(): void
    {
        $this->write(false);
        $this->assertFalse(home_section_on('partners'));
        $this->assertTrue(home_section_on('hero'));
    }

    public function test_a_broken_home_file_never_hides_the_menu_item(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{broken');
        $this->expectErrorLog();
        $this->assertTrue(home_section_on('partners'));
    }

    public function test_admin_menu_follows_the_section_and_keeps_the_current_page(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/includes/admin-header.php');
        $this->assertStringContainsString("home_section_on('partners')", $src);
        $this->assertStringContainsString("\$_nav_partners = (\$active_nav ?? '') === 'partners';", $src,
            'on the partners page itself the item stays, so the admin is never on a page the menu does not show');
        $this->assertMatchesRegularExpression('/<\?php if \(\$_nav_partners\): \?>\s*<a href="\/admin\/partners\.php"/', $src);
    }
}
