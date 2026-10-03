<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The newsletter band colour an admin picks in Admin → Организация → Визия:
 * the choice is whitelisted, the text colour is chosen for contrast, and the
 * admin's choice wins over a theme's palette, which wins over the fallback.
 */
final class NewsletterBandTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
    }

    private function validate(array $over): array
    {
        return org_validate(array_merge([
            'site_name_bg' => 'Фондация Тест', 'site_name_en' => 'Test Foundation', 'site_email' => 'info@example.org',
            'brand_theme'  => 'classic', 'brand_primary' => '#0387A5', 'brand_accent' => '#04ADBF',
        ], $over), array_keys(brand_themes()));
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_nothing_chosen_means_as_it_is_now(): void
    {
        $r = $this->validate([]);
        $this->assertArrayNotHasKey('newsletter_band', $r['errors']);
        $this->assertSame('default', $r['values']['newsletter_band']);
        $this->assertSame('', $r['values']['newsletter_band_color']);
    }

    public function test_each_choice_is_accepted(): void
    {
        foreach (['default', 'primary', 'accent'] as $c) {
            $r = $this->validate(['newsletter_band' => $c]);
            $this->assertSame([], $r['errors'], $c);
            $this->assertSame($c, $r['values']['newsletter_band']);
        }
        $r = $this->validate(['newsletter_band' => 'custom', 'newsletter_band_color' => '#fbb04a']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('#FBB04A', $r['values']['newsletter_band_color']);
    }

    public function test_an_unknown_choice_is_refused(): void
    {
        $r = $this->validate(['newsletter_band' => 'red']);
        $this->assertArrayHasKey('newsletter_band', $r['errors']);
        $this->assertSame('default', $r['values']['newsletter_band']);
    }

    public static function badColours(): array
    {
        return [[''], ['red'], ['#FFF'], ['0387A5'], ['#0387A5;background:url(x)']];
    }

    #[DataProvider('badColours')]
    public function test_a_custom_band_needs_a_real_hex_colour(string $colour): void
    {
        $r = $this->validate(['newsletter_band' => 'custom', 'newsletter_band_color' => $colour]);
        $this->assertArrayHasKey('newsletter_band_color', $r['errors']);
    }

    public function test_a_bad_colour_is_dropped_quietly_when_not_used(): void
    {
        $r = $this->validate(['newsletter_band' => 'primary', 'newsletter_band_color' => 'red']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('', $r['values']['newsletter_band_color']);
    }

    public function test_both_fields_are_stored_like_the_other_brand_fields(): void
    {
        $this->assertSame('BRAND_NEWSLETTER_BAND', org_fields()['newsletter_band']);
        $this->assertSame('BRAND_NEWSLETTER_COLOR', org_fields()['newsletter_band_color']);
    }

    // ── Contrast ─────────────────────────────────────────────────────────────

    public function test_light_band_gets_dark_text_and_dark_band_gets_white(): void
    {
        $this->assertSame(THEME_TEXT_DARK, theme_text_on('#FBB04A')['fg']);
        $this->assertTrue(theme_text_on('#FBB04A')['readable']);
        $this->assertSame(THEME_TEXT_LIGHT, theme_text_on('#241937')['fg']);
        $this->assertTrue(theme_text_on('#241937')['readable']);
        $this->assertSame(THEME_TEXT_DARK, theme_text_on('#FFFFFF')['fg']);
    }

    public function test_a_mid_tone_that_neither_text_colour_reads_on_is_flagged(): void
    {
        // Neither white nor dark reaches 4.5:1 here — the better one is still used,
        // and the admin preview says the text is hard to read.
        $r = theme_text_on('#0387A5');
        $this->assertFalse($r['readable']);
        $this->assertGreaterThan(4.0, $r['ratio']);
        $this->assertLessThan(4.5, $r['ratio']);
    }

    public function test_contrast_ratio_matches_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, theme_contrast('#000000', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(1.0, theme_contrast('#777777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(theme_contrast('#123456', '#FFFFFF'), theme_contrast('#FFFFFF', '#123456'), 0.0001);
    }

    // ── Which colour wins ────────────────────────────────────────────────────

    public function test_the_choice_picks_its_colour(): void
    {
        $this->assertNull(newsletter_band_colors('default', '#111111', '#222222', '#333333'));
        $this->assertSame('#222222', newsletter_band_colors('primary', '', '#222222', '#333333')['bg']);
        $this->assertSame('#333333', newsletter_band_colors('accent', '', '#222222', '#333333')['bg']);
        $this->assertSame('#ABCDEF', newsletter_band_colors('custom', '#abcdef', '#222222', '#333333')['bg']);
        $this->assertNull(newsletter_band_colors('custom', 'red', '#222222', '#333333'), 'a broken saved colour falls back');
        $this->assertNull(newsletter_band_colors('nonsense', '#abcdef', '#222222', '#333333'));
    }

    public function test_admin_choice_beats_the_theme_palette_which_beats_the_fallback(): void
    {
        $theme = ['palette' => ['--newsletter-bg' => '#FBB04A', '--newsletter-fg' => '#241937', '--x' => '#000000']];

        // Admin chose: its colours, once each, and the palette's other entries stay.
        $css = theme_palette_css($theme, newsletter_band_colors('custom', '#241937', '', ''));
        $this->assertStringContainsString('--newsletter-bg:#241937;', $css);
        $this->assertStringContainsString('--newsletter-fg:' . THEME_TEXT_LIGHT . ';', $css);
        $this->assertSame(1, substr_count($css, '--newsletter-bg'));
        $this->assertStringContainsString('--x:#000000;', $css);

        // No admin choice: the palette's.
        $css = theme_palette_css($theme, null);
        $this->assertStringContainsString('--newsletter-bg:#FBB04A;', $css);
        $this->assertSame(['bg' => '#FBB04A', 'fg' => '#241937'], array_intersect_key(theme_newsletter_default($theme), ['bg' => 1, 'fg' => 1]));

        // Neither: nothing emitted, the footer's fallback applies.
        $this->assertSame('', theme_palette_css(['primary' => '#000000'], null));
        $this->assertSame(['bg' => THEME_NEWSLETTER_FALLBACK, 'fg' => THEME_TEXT_LIGHT],
            array_intersect_key(theme_newsletter_default(['primary' => '#000000']), ['bg' => 1, 'fg' => 1]));
        $footer = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php');
        $this->assertStringContainsString('var(--newsletter-bg,' . THEME_NEWSLETTER_FALLBACK . ')', $footer);
    }

    public function test_header_feeds_the_admin_choice_into_the_palette(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/header.php');
        $this->assertStringContainsString('theme_palette_css($_theme, site_newsletter_band($_primary, $_accent))', $src);
    }

    public function test_admin_preview_uses_the_same_rule_as_the_site(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/organisation.php');
        $this->assertStringContainsString('json_encode(THEME_TEXT_LIGHT)', $src);
        $this->assertStringContainsString('json_encode(THEME_TEXT_DARK)', $src);
        $this->assertStringContainsString('>= 4.5', $src);
        // The preview follows the brand colours as they are being changed.
        $this->assertMatchesRegularExpression('/\[primary, accent, nlColor\]\.forEach/', $src);
    }
}
