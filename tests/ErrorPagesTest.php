<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Error pages pick their language from the URL, like every other page — never
 * from the browser. Most visitors are Bulgarian but many browse with an
 * English-language browser; they must still get Bulgarian on a Bulgarian URL.
 *
 * Each page is rendered in a separate PHP process, because the pages set a
 * response code and read $_SERVER the way Apache's ErrorDocument hands it over.
 */
final class ErrorPagesTest extends TestCase
{
    private function render(string $page, string $uri, string $acceptLanguage): string
    {
        $file = dirname(__DIR__) . '/errors/' . $page;
        $code = sprintf(
            '$_SERVER["REQUEST_URI"] = %s; $_SERVER["HTTP_ACCEPT_LANGUAGE"] = %s; require %s;',
            var_export($uri, true),
            var_export($acceptLanguage, true),
            var_export($file, true)
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertIsString($out, "Rendering $page produced no output");
        return $out;
    }

    public static function pages(): array
    {
        return [['403.php'], ['404.php'], ['500.php'], ['503.php']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_bulgarian_url_is_bulgarian_even_in_an_english_browser(string $page): void
    {
        $html = $this->render($page, '/story', 'en-US,en;q=0.9');
        $this->assertStringContainsString('<html lang="bg">', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_english_url_is_english_even_in_a_bulgarian_browser(string $page): void
    {
        $html = $this->render($page, '/en/story', 'bg-BG,bg;q=0.9');
        $this->assertStringContainsString('<html lang="en">', $html);
    }

    public function test_query_string_does_not_hide_the_english_prefix(): void
    {
        $this->assertStringContainsString('<html lang="en">', $this->render('404.php', '/en?x=1', 'bg'));
    }

    public function test_paths_that_merely_start_with_en_stay_bulgarian(): void
    {
        $this->assertStringContainsString('<html lang="bg">', $this->render('404.php', '/entry', 'en'));
    }

    public function test_bulgarian_page_links_home_and_contacts_in_bulgarian(): void
    {
        $html = $this->render('404.php', '/story', 'en-US,en;q=0.9');
        $this->assertStringContainsString('Страницата не е намерена', $html);
        $this->assertStringContainsString('href="/kontakti/"', $html);
    }

    // ── Fonts follow the site's theme ────────────────────────────────────────

    /** Render a page standalone (as the web server's ErrorDocument does) after $pre runs. */
    private function renderWith(string $pre, string $page = '404.php'): string
    {
        $code = '$_SERVER["REQUEST_URI"] = "/x"; ' . $pre . ' require ' . var_export(dirname(__DIR__) . '/errors/' . $page, true) . ';';
        $out  = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_log=/dev/null -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertIsString($out);
        return $out;
    }

    public function test_a_self_hosted_font_theme_makes_no_google_request_on_error_pages(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'om-err-theme-');
        file_put_contents($file, "<?php return ['acme' => ['primary' => '#112233', 'accent' => '#445566', 'font' => 'Acme Body',
            'font_css' => 'assets/css/fonts-acme.css', 'radius' => '2px', 'radius_lg' => '4px']];");
        try {
            foreach (['404.php', '500.php'] as $page) {
                $html = $this->renderWith('$GLOBALS["_om_site_themes_file"] = ' . var_export($file, true) . '; define("BRAND_THEME", "acme");', $page);
                $this->assertStringNotContainsString('googleapis', $html, $page);
                $this->assertStringNotContainsString('gstatic', $html, $page);
                $this->assertStringContainsString('href="/assets/css/fonts-acme.css"', $html, $page);
                $this->assertStringContainsString("font-family: 'Acme Body', system-ui, sans-serif;", $html, $page);
            }
        } finally {
            @unlink($file);
        }
    }

    public function test_a_google_font_theme_keeps_its_font(): void
    {
        $html = $this->renderWith('define("BRAND_THEME", "friendly");');
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Nunito', $html);
        $this->assertStringContainsString("font-family: 'Nunito', system-ui, sans-serif;", $html);
    }

    public function test_a_broken_theme_never_breaks_the_error_page(): void
    {
        // A site themes file that throws: the page still renders, in the system font.
        $file = tempnam(sys_get_temp_dir(), 'om-err-theme-');
        file_put_contents($file, '<?php throw new RuntimeException("broken theme");');
        try {
            $html = $this->renderWith('$GLOBALS["_om_site_themes_file"] = ' . var_export($file, true) . ';');
        } finally {
            @unlink($file);
        }
        $this->assertStringContainsString('Страницата не е намерена', $html);
        $this->assertStringContainsString('font-family: system-ui, sans-serif;', $html);
        $this->assertStringNotContainsString('googleapis', $html);
        $this->assertStringNotContainsString('Fatal', $html);
    }
}
