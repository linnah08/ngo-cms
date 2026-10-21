<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/files.php';

/**
 * The „Файлове за изтегляне“ box under an article (file_links_in_html(),
 * templates/article-downloads.php): the site's own PDFs the text links to, once each,
 * named by the link's title or text, with their real size. Other links are left alone.
 */
final class ArticleDownloadsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ngo_dl_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/assets/files/uploads', 0755, true);
        file_put_contents($this->root . '/assets/files/uploads/vizitka-bg-8bc091.pdf', str_repeat('x', 849 * 1024));
        file_put_contents($this->root . '/assets/files/uploads/intro-en-d066cd.pdf', str_repeat('x', 2048));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/assets/files/uploads/*') ?: []);
        foreach (['/assets/files/uploads', '/assets/files', '/assets', ''] as $d) @rmdir($this->root . $d);
    }

    private function site(): string
    {
        return rtrim(SITE_URL, '/');
    }

    public function test_inline_links_become_named_buttons_with_their_size(): void
    {
        $html = '<p>Споделете визитките ни на <a title="Визитка ФРИ БГ (PDF, 849 KB)" href="' . $this->site() . '/assets/files/uploads/vizitka-bg-8bc091.pdf">български</a>'
              . ' и <a href="/assets/files/uploads/intro-en-d066cd.pdf">английски</a>.</p>';

        $this->assertSame([
            ['href' => '/assets/files/uploads/vizitka-bg-8bc091.pdf', 'name' => 'Визитка ФРИ БГ', 'size' => '849 KB'],
            ['href' => '/assets/files/uploads/intro-en-d066cd.pdf', 'name' => 'английски', 'size' => '2 KB'],
        ], file_links_in_html($html, $this->root));
    }

    public function test_a_file_linked_twice_gets_one_button(): void
    {
        $html = '<a href="/assets/files/uploads/intro-en-d066cd.pdf">A</a> <a href="/assets/files/uploads/intro-en-d066cd.pdf">B</a>';
        $this->assertCount(1, file_links_in_html($html, $this->root));
    }

    public function test_other_sites_missing_files_and_other_paths_are_left_alone(): void
    {
        $html = '<a href="https://example.net/assets/files/uploads/intro-en-d066cd.pdf">elsewhere</a>'
              . '<a href="/assets/files/uploads/gone-123456.pdf">gone</a>'
              . '<a href="/assets/files/uploads/../../config.php.pdf">sneaky</a>'
              . '<a href="/novini/some-article/">article</a>';
        $this->assertSame([], file_links_in_html($html, $this->root));
    }

    public function test_without_title_or_text_the_file_name_is_used(): void
    {
        $html = '<a href="/assets/files/uploads/intro-en-d066cd.pdf"><img src="/x.png" alt=""></a>';
        $this->assertSame('intro-en', file_links_in_html($html, $this->root)[0]['name']);
    }

    /** Both article pages show the buttons twice: a row under the title and the box after the text. */
    public function test_both_article_pages_show_the_buttons_on_top_and_after_the_text(): void
    {
        foreach (['novini/index.php', 'en/news/index.php'] as $page) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $page);
            $this->assertSame(2, substr_count($src, 'templates/article-downloads.php'), "$page shows the downloads twice");
            $this->assertMatchesRegularExpression('#</h1>\s*<\?php [^?]*\$downloads_compact = true#', $src, "$page: the row sits under the title");
        }
    }

    public function test_empty_lines_at_the_end_of_an_article_are_not_shown(): void
    {
        require_once dirname(__DIR__) . '/includes/articles.php';
        $this->assertSame('<p>Благодарим 🙏</p>', article_trim_trailing_blank("<p>Благодарим 🙏</p>\n<p>&nbsp;</p>\n<p> <br></p>\n<p>\u{00A0}</p>\n"));
        $this->assertSame('<p>&nbsp;</p><p>Текст</p>', article_trim_trailing_blank('<p>&nbsp;</p><p>Текст</p>'), 'only the end is trimmed');
    }
}
