<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own footer block and sitemap addresses (includes/site_extensions.php). */
final class SiteFooterSitemapTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/om-siteext-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/templates/site', 0755, true);
        $GLOBALS['_om_site_root'] = $this->dir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file'], $GLOBALS['_om_site_root']);
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function site(string $php): void
    {
        $f = $this->dir . '/ext-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($f, $php);
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_nothing_without_a_site_file(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $this->assertSame([], site_footer_partials());
        $this->assertSame([], site_sitemap_paths());
    }

    public function test_footer_partials_must_be_php_files_inside_the_site(): void
    {
        file_put_contents($this->dir . '/templates/site/banner.php', '<p>hi</p>');
        file_put_contents($this->dir . '/templates/site/notes.txt', 'x');
        $this->site('<?php return ["footer_partial" => [
            "templates/site/banner.php", "templates/site/missing.php", "templates/site/notes.txt",
            "../etc/passwd.php", "/etc/x.php", "templates/site/../site/banner.php", ["nested"],
        ]];');
        $this->assertSame([realpath($this->dir . '/templates/site/banner.php')], site_footer_partials());

        $this->site('<?php return ["footer_partial" => "templates/site/banner.php"];');
        $this->assertCount(1, site_footer_partials(), 'one path works as well as a list');
    }

    public function test_a_partial_sees_only_what_it_is_given_and_a_broken_one_shows_nothing(): void
    {
        $ok = $this->dir . '/ok.php';
        file_put_contents($ok, '<p><?= $lang ?></p>');
        ob_start();
        $this->assertTrue(site_extension_render($ok, ['lang' => 'en']));
        $this->assertSame('<p>en</p>', ob_get_clean());

        $bad = $this->dir . '/bad.php';
        file_put_contents($bad, '<p>half</p><?php ob_start(); echo "more"; throw new RuntimeException("boom");');
        $level = ob_get_level();
        ob_start();
        $this->assertFalse(@site_extension_render($bad));
        $this->assertSame('', ob_get_clean(), 'nothing a failing partial printed reaches the page');
        $this->assertSame($level, ob_get_level(), 'output buffers are left as they were');
    }

    public function test_sitemap_paths_are_local_and_listed_once(): void
    {
        $this->site('<?php return ["sitemap_urls" => [
            "/quiz/", ["bg" => "/test/", "en" => "/en/quiz/"], "/quiz/",
            "https://evil.example/", "//evil.example/", "relative/", "/a#b", 5,
        ]];');
        $this->assertSame(['/quiz/', '/test/', '/en/quiz/'], site_sitemap_paths());
    }

    public function test_footer_and_sitemap_use_them_and_load_the_helpers_themselves(): void
    {
        $root   = dirname(__DIR__, 2);
        $footer = (string) file_get_contents("$root/templates/footer.php");
        $this->assertStringContainsString('includes/site_extensions.php', $footer);
        $this->assertStringContainsString('site_footer_partials()', $footer);
        $this->assertLessThan(strpos($footer, '<section class="newsletter-banner"'), strpos($footer, 'site_footer_partials()'), 'above the sign-up band');
        $map = (string) file_get_contents("$root/sitemap.php");
        $this->assertStringContainsString('includes/site_extensions.php', $map);
        $this->assertLessThan(strpos($map, 'module_link_visible'), strpos($map, 'site_sitemap_paths()'), 'switched-off modules still filter site addresses');
    }

    public function test_the_sitemap_lists_the_sites_addresses(): void
    {
        $this->site('<?php return ["sitemap_urls" => ["/quiz/", ["bg" => "/test/", "en" => "/en/quiz/"], "/za-nas/"]];');
        $root = dirname(__DIR__, 2);
        $code = sprintf('$_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["REQUEST_URI"] = "/sitemap.xml"; $_SERVER["HTTP_HOST"] = "localhost";'
            . '$GLOBALS["_om_site_extensions_file"] = %s; require %s;',
            var_export($root, true), var_export($GLOBALS['_om_site_extensions_file'], true), var_export("$root/sitemap.php", true));
        $xml = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $this->assertStringContainsString('<urlset', $xml, $xml);
        $base = rtrim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(sprintf('$_SERVER["DOCUMENT_ROOT"]=%s; $_SERVER["HTTP_HOST"]="localhost"; require %s; echo SITE_URL;', var_export($root, true), var_export("$root/config.php", true)))), '/');
        foreach (['/quiz/', '/test/', '/en/quiz/'] as $p) {
            $this->assertStringContainsString('<loc>' . $base . $p . '</loc>', $xml);
        }
        $this->assertSame(1, substr_count($xml, '<loc>' . $base . '/za-nas/</loc>'), 'an address the sitemap has already is not repeated');
    }
}
