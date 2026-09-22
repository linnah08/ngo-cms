<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeFieldsTest extends TestCase
{
    public static function goodLinks(): array
    {
        return [
            ['/za-nas/'], ['/en/how-to-help/'], ['/magazin/?cat=2#top'],
            ['https://example.org/page'], ['http://example.org'],
        ];
    }

    #[DataProvider('goodLinks')]
    public function test_accepts_site_paths_and_web_addresses(string $url): void
    {
        $this->assertSame($url, home_clean_link($url));
    }

    public static function badLinks(): array
    {
        return [
            ['javascript:alert(1)'], ['JAVASCRIPT:alert(1)'], ['data:text/html,x'],
            ['//evil.example/x'], ['/\\evil.example'], ['ftp://example.org'],
            ['https://exa mple.org'], ['"><script>'], ['vbscript:x'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_rejects_dangerous_or_malformed_links(string $url): void
    {
        $this->assertNull(home_clean_link($url));
    }

    public function test_empty_link_is_allowed_and_trimmed(): void
    {
        $this->assertSame('', home_clean_link('   '));
        $this->assertSame('/za-nas/', home_clean_link('  /za-nas/ '));
    }

    public function test_image_paths_must_live_under_assets_images(): void
    {
        $this->assertTrue(home_valid_image_path('/assets/images/pages/home/s_ab12-image-1.webp'));
        $this->assertTrue(home_valid_image_path('/assets/images/hero.webp'));
        $this->assertFalse(home_valid_image_path('/config.php'));
        $this->assertFalse(home_valid_image_path('/assets/images/../../config.php'));
        $this->assertFalse(home_valid_image_path('/assets/images/a.jpg?x=1'));
        $this->assertFalse(home_valid_image_path('https://evil.example/a.jpg'));
        $this->assertFalse(home_valid_image_path(''));
    }

    public function test_html_keeps_formatting_and_cyrillic(): void
    {
        $in = '<p>Здравейте, <strong>свят</strong> <em>и</em> <a href="/za-nas/">нас</a></p><ul><li>едно</li></ul>';
        $this->assertSame($in, home_clean_html($in));
    }

    public function test_html_strips_scripts_handlers_and_bad_hrefs(): void
    {
        $out = home_clean_html(
            '<p onclick="x()" style="color:red">A<script>alert(1)</script></p>'
            . '<a href="javascript:alert(1)">B</a><img src="x" onerror="y()"><div>C</div>'
        );
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img src="x"', $out);   // src outside /assets/images and not https
        $this->assertStringContainsString('<p>A', $out);
        $this->assertStringContainsString('C', $out);                  // <div> dropped, text kept
    }

    public function test_html_external_links_open_safely(): void
    {
        $out = home_clean_html('<a href="https://example.org">x</a>');
        $this->assertSame('<a href="https://example.org" target="_blank" rel="noopener noreferrer">x</a>', $out);
    }

    public function test_html_keeps_library_images_with_alt(): void
    {
        $out = home_clean_html('<img src="/assets/images/pages/a.jpg" alt="Деца" class="x">');
        $this->assertSame('<img src="/assets/images/pages/a.jpg" alt="Деца">', $out);
    }

    public function test_html_empty_stays_empty(): void
    {
        $this->assertSame('', home_clean_html('   '));
    }

    public static function videoUrls(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=10', 'youtube', 'dQw4w9WgXcQ'],
            ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://www.youtube.com/live/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
            ['https://vimeo.com/76979871', 'vimeo', '76979871'],
            ['https://vimeo.com/76979871?share=copy', 'vimeo', '76979871'],
            ['https://player.vimeo.com/video/76979871', 'vimeo', '76979871'],
        ];
    }

    #[DataProvider('videoUrls')]
    public function test_parses_video_urls(string $url, string $provider, string $id): void
    {
        $this->assertSame(['provider' => $provider, 'id' => $id], home_parse_video_url($url));
    }

    public function test_rejects_non_video_urls(): void
    {
        foreach ([
            '', 'https://example.org/watch?v=dQw4w9WgXcQ', 'https://youtube.com/watch?v=short',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)',
            'https://vimeo.com/channels/staffpicks',
        ] as $url) {
            $this->assertNull(home_parse_video_url($url), $url);
        }
    }

    public function test_video_urls_round_trip(): void
    {
        $yt = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'];
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', home_video_watch_url($yt));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', home_video_embed_url($yt));
        $vm = ['provider' => 'vimeo', 'id' => '76979871'];
        $this->assertSame('https://player.vimeo.com/video/76979871?autoplay=1&dnt=1', home_video_embed_url($vm));
        $this->assertFalse(home_video_valid(['provider' => 'youtube', 'id' => 'x"><']));
    }

    public function test_pair_normalises_any_input(): void
    {
        $this->assertSame(['bg' => 'a', 'en' => ''], home_pair('a'));
        $this->assertSame(['bg' => '', 'en' => ''], home_pair(null));
        $this->assertSame(['bg' => '', 'en' => 'b'], home_pair(['bg' => ['x'], 'en' => 'b']));
    }

    public function test_cyrillic_web_addresses_are_accepted_as_typed(): void
    {
        if (!function_exists('idn_to_ascii')) $this->markTestSkipped('intl extension not installed');
        $this->assertSame('https://пример.бг/път?x=1', home_clean_link('https://пример.бг/път?x=1'));
        $this->assertSame('https://bg.wikipedia.org/wiki/България', home_clean_link('https://bg.wikipedia.org/wiki/България') ?? 'null');
        $this->assertNull(home_clean_link('https://-.бг'));
        $this->assertNull(home_clean_link('javascript://пример.бг'));
    }
}
