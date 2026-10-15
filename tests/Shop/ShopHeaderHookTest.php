<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * A site's own shop header (includes/site-extensions.php or its theme preset:
 * 'shop_header') in place of the plain one on /magazin/ and /en/shop/.
 */
#[Group('shop')]
final class ShopHeaderHookTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php';
        $this->dir = sys_get_temp_dir() . '/om-shophdr-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/templates/site', 0755, true);
        $GLOBALS['_om_site_root'] = $this->dir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file'], $GLOBALS['_om_site_root'], $GLOBALS['_om_site_themes_file']);
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function extensions(string $php): void
    {
        $f = $this->dir . '/ext-' . bin2hex(random_bytes(4)) . '.php';   // a new name: site_extensions() caches per file
        file_put_contents($f, $php);
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_no_site_header_by_default(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $this->assertNull(site_shop_header_file());
    }

    public function test_the_extensions_file_names_a_php_file_inside_the_site(): void
    {
        file_put_contents($this->dir . '/templates/site/shop-header.php', '<header>x</header>');
        $this->extensions('<?php return ["shop_header" => "templates/site/shop-header.php"];');
        $this->assertSame(realpath($this->dir . '/templates/site/shop-header.php'), site_shop_header_file());

        $this->extensions('<?php return ["shop_header" => "../outside.php"];');
        $this->assertNull(@site_shop_header_file());
        $this->extensions('<?php return ["shop_header" => "templates/site/missing.php"];');
        $this->assertNull(@site_shop_header_file());
    }

    public function test_the_theme_preset_can_name_it_too(): void
    {
        $this->assertStringContainsString("current_theme()['shop_header']", (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/site_extensions.php'));
        $this->assertStringContainsString('shop_header', (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/themes.php'), 'documented with the other theme keys');
    }

    private function renderShop(string $uri): string
    {
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        global $_path_map_bg_to_en;
        ob_start();
        require $_SERVER['DOCUMENT_ROOT'] . '/magazin/index.php';
        return (string) ob_get_clean();
    }

    #[RunInSeparateProcess]
    public function test_the_plain_header_shows_without_a_site_header(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $html = $this->renderShop('/magazin/');
        $this->assertStringContainsString('<!-- Hero -->', $html);
        $this->assertStringContainsString('data-cms-field="hero_title" data-cms-section="shop"', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_site_header_replaces_the_plain_one(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        file_put_contents($this->dir . '/templates/site/shop-header.php',
            '<section id="site-shop-header" data-lang="<?= $lang ?>"><h1><?= h($shop_hero["title"]) ?></h1></section>');
        $this->extensions('<?php return ["shop_header" => "templates/site/shop-header.php"];');
        $html = $this->renderShop('/en/shop/');
        $this->assertStringContainsString('<section id="site-shop-header" data-lang="en"><h1>', $html);
        $this->assertStringNotContainsString('<!-- Hero -->', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_failing_site_header_falls_back_to_the_plain_one(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        file_put_contents($this->dir . '/templates/site/shop-header.php', '<p>half</p><?php throw new RuntimeException("boom");');
        $this->extensions('<?php return ["shop_header" => "templates/site/shop-header.php"];');
        $html = @$this->renderShop('/magazin/');
        $this->assertStringNotContainsString('<p>half</p>', $html);
        $this->assertStringContainsString('<!-- Hero -->', $html);
    }
}
