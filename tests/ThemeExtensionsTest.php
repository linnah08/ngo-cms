<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What a theme can carry beyond colours and one Google font (separate display and
 * body faces, self-hosted fonts, an extra stylesheet, a palette), and how a site
 * registers its own theme in includes/themes-site.php — a file no release ships,
 * so a fork never has to edit the shared includes/themes.php.
 */
final class ThemeExtensionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/om-themes-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_themes_file']);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function siteFile(string $php): string
    {
        $f = $this->dir . '/themes-site-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($f, $php);
        return $GLOBALS['_om_site_themes_file'] = $f;
    }

    private const BUILTIN = ['classic', 'friendly', 'modern', 'editorial'];

    // ── Site theme registration ──────────────────────────────────────────────

    public function test_without_a_site_file_only_the_builtin_themes_exist(): void
    {
        $GLOBALS['_om_site_themes_file'] = $this->dir . '/missing.php';
        $this->assertSame(self::BUILTIN, array_keys(brand_themes()));
        $GLOBALS['_om_site_themes_file'] = '';
        $this->assertSame(self::BUILTIN, array_keys(brand_themes()));
    }

    public function test_the_default_site_file_is_site_owned_and_not_in_this_repo(): void
    {
        unset($GLOBALS['_om_site_themes_file']);
        $this->assertSame($_SERVER['DOCUMENT_ROOT'] . '/includes/themes-site.php', site_themes_file());
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/includes/themes-site.php',
            'includes/themes-site.php belongs to a site, never to ngo-cms — a release would overwrite it');
    }

    public function test_a_site_theme_joins_the_list_and_can_be_the_current_one(): void
    {
        $this->siteFile("<?php return ['acme' => [
            'label' => 'Acme', 'primary' => '#112233', 'accent' => '#445566', 'font' => 'Body Face',
            'font_display' => 'Display Face', 'font_css' => 'assets/css/fonts-acme.css', 'theme_css' => 'assets/css/theme-acme.css',
            'radius' => '3px', 'radius_lg' => '9px', 'palette' => ['--newsletter-bg' => '#ffcc00'],
        ]];");
        $themes = brand_themes();
        $this->assertSame([...self::BUILTIN, 'acme'], array_keys($themes));
        $this->assertSame('Acme', $themes['acme']['label']);
        $this->assertSame('', $themes['acme']['font_url'], 'a missing font_url defaults to none');
    }

    public function test_a_site_theme_cannot_replace_a_builtin_and_broken_entries_are_skipped(): void
    {
        $this->siteFile("<?php return [
            'classic'   => ['label' => 'Hijack', 'primary' => '#000', 'accent' => '#000', 'font' => 'X', 'radius' => '0', 'radius_lg' => '0'],
            'Bad Key'   => ['primary' => '#000', 'accent' => '#000', 'font' => 'X', 'radius' => '0', 'radius_lg' => '0'],
            'nocolour'  => ['font' => 'X', 'radius' => '0', 'radius_lg' => '0'],
            'notarray'  => 'x',
            'ok'        => ['primary' => '#000', 'accent' => '#111', 'font' => 'X', 'radius' => '0', 'radius_lg' => '0'],
        ];");
        $this->expectErrorLog(); // each skip is logged, so a mistake in the file can be found
        $themes = brand_themes();
        $this->assertSame('Classic', $themes['classic']['label']);
        $this->assertSame([...self::BUILTIN, 'ok'], array_keys($themes));
        $this->assertSame('ok', $themes['ok']['label'], 'a missing label falls back to the key');
    }

    public function test_a_site_file_that_returns_nothing_useful_is_ignored(): void
    {
        $this->siteFile('<?php // forgot the return');
        $this->expectErrorLog();
        $this->assertSame(self::BUILTIN, array_keys(brand_themes()));
    }

    // ── Stylesheets ──────────────────────────────────────────────────────────

    public function test_a_builtin_theme_loads_its_google_font_then_main_css(): void
    {
        $html = theme_stylesheet_links(brand_themes()['classic']);
        $this->assertStringContainsString('https://fonts.googleapis.com/css2?family=Jura', $html);
        $this->assertMatchesRegularExpression('#href="/assets/css/main\.css(\?v=\d+)?"#', $html);
        $this->assertLessThan(strpos($html, 'main.css'), strpos($html, 'fonts.googleapis.com'));
    }

    public function test_main_css_is_versioned_so_a_release_is_not_hidden_by_a_stale_cache(): void
    {
        $this->assertMatchesRegularExpression('#href="/assets/css/main\.css\?v=\d+"#', theme_stylesheet_links(brand_themes()['classic']));
    }

    public function test_font_css_self_hosts_and_makes_no_cdn_request(): void
    {
        $html = theme_stylesheet_links(['font_css' => 'assets/css/fonts-acme.css', 'font_url' => 'Jura:wght@400']);
        $this->assertStringContainsString('href="/assets/css/fonts-acme.css"', $html);
        $this->assertStringNotContainsString('googleapis', $html);
        $this->assertStringNotContainsString('gstatic', $html);
    }

    public function test_theme_css_loads_after_main_css(): void
    {
        $html = theme_stylesheet_links(['font_url' => '', 'theme_css' => 'assets/css/theme-acme.css']);
        $this->assertStringNotContainsString('googleapis', $html, 'no font_url, no CDN request');
        $this->assertNotFalse(strpos($html, 'theme-acme.css'));
        $this->assertGreaterThan(strpos($html, 'main.css'), strpos($html, 'theme-acme.css'));
    }

    public function test_paths_are_escaped(): void
    {
        $html = theme_stylesheet_links(['theme_css' => 'a"><script>x</script>.css']);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── Palette ──────────────────────────────────────────────────────────────

    public function test_palette_emits_only_css_shaped_properties(): void
    {
        $css = theme_palette_css(['palette' => [
            '--newsletter-bg' => '#FBB04A',
            '--mix'           => 'color-mix(in srgb, #fff 10%, #000)',
            '--evil'          => 'red;} body{display:none',
            'no-dashes'       => '#000',
            '--quote'         => "'x'",
            '--tag'           => '</style>',
            '--empty'         => '',
            '--num'           => 4,
        ]]);
        $this->assertSame("    --newsletter-bg:#FBB04A;\n    --mix:color-mix(in srgb, #fff 10%, #000);\n    --num:4;\n", $css);
        $this->assertSame('', theme_palette_css(['primary' => '#000']), 'no palette, nothing emitted');
    }

    public function test_header_uses_the_theme_helpers_and_separate_faces(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/header.php');
        $this->assertStringContainsString('theme_stylesheet_links(current_theme())', $src);
        $this->assertStringContainsString('theme_palette_css($_theme, ', $src);
        $this->assertStringContainsString("\$_theme['font_body']    ?? \$_theme['font']", $src);
        $this->assertStringContainsString("\$_theme['font_display'] ?? \$_theme['font']", $src);
        $this->assertStringNotContainsString('href="/assets/css/main.css"', $src, 'main.css goes through versioned_asset()');
    }

    // ── Release packaging ────────────────────────────────────────────────────

    public function test_the_release_never_ships_site_owned_files(): void
    {
        $build = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/tests/release-gate/build-zip.sh');
        foreach (['includes/themes-site.php', 'content/bg/strings.site.json', 'content/en/strings.site.json'] as $f) {
            // Once in the checksum exclusions, once in the zip -x list: the two must agree.
            $this->assertSame(2, substr_count($build, '"' . $f . '"'), "$f must be excluded from both checksums.json and the zip");
            $this->assertFileDoesNotExist(dirname(__DIR__) . '/' . $f, "$f is site-owned and must not be in ngo-cms");
        }
    }
}
