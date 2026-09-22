<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';

final class HomeRenderTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
    }

    private function render(array $sections, string $lang = 'bg'): string
    {
        ob_start();
        home_render(['version' => 1, 'rev' => 1, 'sections' => $sections], $lang);
        return (string) ob_get_clean();
    }

    private function s(string $type, array $fields, bool $visible = true, string $id = ''): array
    {
        return ['id' => $id ?: 's_' . $type, 'type' => $type, 'visible' => $visible, 'fields' => $fields];
    }

    public function test_hf_falls_back_to_bulgarian(): void
    {
        $f = ['t' => ['bg' => 'Здравей', 'en' => ''], 'u' => ['bg' => 'А', 'en' => 'B'], 'img' => '/assets/images/x.jpg'];
        $this->assertSame('Здравей', hf($f, 't', 'en'));
        $this->assertSame('B', hf($f, 'u', 'en'));
        $this->assertSame('/assets/images/x.jpg', hf($f, 'img', 'en'));
        $this->assertSame('', hf($f, 'missing', 'bg'));
    }

    public function test_renders_the_right_language_and_skips_hidden_sections(): void
    {
        $html = $this->render([
            $this->s('cta', ['heading' => ['bg' => 'Помогнете', 'en' => 'Help us'], 'background' => 'teal']),
            $this->s('richtext', ['heading' => ['bg' => 'Скрито', 'en' => 'Hidden']], false),
        ], 'en');
        $this->assertStringContainsString('Help us', $html);
        $this->assertStringNotContainsString('Помогнете', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    public function test_unknown_type_is_skipped(): void
    {
        $this->assertStringNotContainsString('<section', $this->render([$this->s('from_the_future', [])]));
    }

    public function test_text_is_escaped_and_rich_text_is_not(): void
    {
        $html = $this->render([$this->s('richtext', [
            'heading' => ['bg' => '<script>x</script>', 'en' => ''],
            'body'    => ['bg' => '<p><strong>Здравей</strong></p>', 'en' => ''],
        ])]);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<p><strong>Здравей</strong></p>', $html);
    }

    public function test_buttons_need_label_and_link_and_open_external_links_safely(): void
    {
        $html = $this->render([$this->s('cta', [
            'heading'    => ['bg' => 'X', 'en' => ''],
            'btn1_label' => ['bg' => 'Дари', 'en' => ''], 'btn1_url' => ['bg' => 'https://example.org/give', 'en' => ''],
            'btn2_label' => ['bg' => 'Без линк', 'en' => ''], 'btn2_url' => ['bg' => '', 'en' => ''],
        ])]);
        $this->assertStringContainsString('href="https://example.org/give" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('Без линк', $html);
    }

    public function test_video_makes_no_third_party_request_before_click(): void
    {
        $html = $this->render([$this->s('video', [
            'heading' => ['bg' => 'Нашата история', 'en' => ''],
            'video'   => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            'thumb'   => '/assets/images/pages/home/s_video-video-dQw4w9WgXcQ.jpg',
        ])]);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src="https://', $html);
        $this->assertStringNotContainsString('i.ytimg.com', $html);
        $this->assertStringContainsString('<button type="button" class="home-video__play"', $html);
        $this->assertStringContainsString('aria-label="Пусни видео: Нашата история"', $html);
        $this->assertStringContainsString('data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0"', $html);
    }

    public function test_invalid_video_renders_nothing(): void
    {
        $this->assertStringNotContainsString('<section', $this->render([$this->s('video', ['video' => ['provider' => 'youtube', 'id' => 'bad']])]));
    }

    public function test_cards_render_as_a_list_with_headings(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => ''], 'text' => ['bg' => '', 'en' => ''], 'link' => ['bg' => '/za-nas/', 'en' => ''], 'image' => '', 'image_alt' => ['bg' => '', 'en' => '']];
        $html = $this->render([$this->s('cards', ['cards' => [$card('Едно'), $card('Две'), $card('Три')]])]);
        $this->assertStringContainsString('grid-template-columns:repeat(3,minmax(0,1fr))', $html);
        $this->assertSame(3, substr_count($html, '<h3'));
        $this->assertStringContainsString('<a href="/za-nas/">Едно</a>', $html);
    }

    public function test_empty_alt_is_rendered_as_decorative(): void
    {
        $html = $this->render([$this->s('text_image', ['image' => '/assets/images/a.jpg', 'image_alt' => ['bg' => '', 'en' => '']])]);
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_default_page_has_exactly_one_h1_and_no_database_sections(): void
    {
        $doc = home_seed([], [], []);
        foreach ($doc['sections'] as &$s) {
            if (in_array($s['type'], ['products', 'news', 'campaign'], true)) $s['visible'] = false;
        }
        unset($s);
        $html = $this->render($doc['sections']);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_inline_edit_attributes_point_at_the_section(): void
    {
        $html = $this->render([$this->s('cta', ['heading' => ['bg' => 'Помогнете', 'en' => 'Help']], true, 's_ab12')]);
        $this->assertStringContainsString('data-cms-section="home:s_ab12" data-cms-field="heading"', $html);
    }

    public function test_a_stored_teal_background_outside_the_cta_block_renders_as_the_default(): void
    {
        $html = $this->render([$this->s('text_image', ['heading' => ['bg' => 'Х', 'en' => ''], 'background' => 'teal'])]);
        $this->assertStringNotContainsString('section--teal', $html);
        $html = $this->render([$this->s('mission', ['title' => ['bg' => 'Х', 'en' => ''], 'background' => 'teal'])]);
        $this->assertStringNotContainsString('section--teal', $html);
        $this->assertStringContainsString('section--grey', $html);
    }

    public function test_cta_on_teal_uses_solid_white_text_and_white_buttons(): void
    {
        $html = $this->render([$this->s('cta', ['heading' => ['bg' => 'Х', 'en' => ''], 'text' => ['bg' => 'Кратък текст', 'en' => ''],
            'btn1_label' => ['bg' => 'Дарете', 'en' => ''], 'btn1_url' => ['bg' => '/magazin/', 'en' => ''], 'background' => 'teal'])]);
        $this->assertStringContainsString('section--teal', $html);
        $this->assertStringContainsString('color:#fff;', $html);
        $this->assertStringNotContainsString('rgba(255,255,255,0.85)', $html);
        $this->assertStringContainsString('btn btn--white', $html);
        $this->assertStringNotContainsString('btn--primary', $html);
    }

    public function test_shared_style_is_emitted_once(): void
    {
        $html = $this->render([$this->s('cta', []), $this->s('richtext', [])]);
        $this->assertSame(1, substr_count($html, 'id="home-sections-css"'));
    }
}
