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
}
