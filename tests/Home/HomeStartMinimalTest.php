<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A site set up by the install wizard starts its front page with the banner and
 * the blocks of the modules it chose; older sites keep their full default page.
 */
final class HomeStartMinimalTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/home.php';
    }

    public function test_a_new_site_starts_with_the_banner_and_its_modules_only(): void
    {
        $hidden = home_start_hidden(true);
        foreach (['impact', 'centres', 'mission', 'news', 'partners'] as $t) $this->assertContains($t, $hidden);
        foreach (['hero', 'products', 'campaign'] as $t) {
            $this->assertNotContains($t, $hidden, "$t starts on (module blocks draw only while their module is on)");
        }
        $this->assertSame(!module_enabled_with_needs('donations'), in_array('cta', $hidden, true),
            'the donation call-to-action starts on only with Дарения');
        $doc = home_seed([], [], [], $hidden);
        $visible = array_column(array_filter($doc['sections'], fn($s) => $s['visible']), 'type');
        $this->assertSame('hero', $visible[0]);
        $this->assertCount(count(home_seed([], [], [], [])['sections']), $doc['sections'], 'nothing is removed, only switched off');
    }

    public function test_an_older_site_keeps_its_full_page(): void
    {
        $this->assertNotContains('news', home_start_hidden(false));
    }

    public function test_no_default_text_is_about_one_organisations_cause(): void
    {
        $text = json_encode(home_seed([], [], [], []), JSON_UNESCAPED_UNICODE);
        foreach (['деца', ' дете', 'терапи', 'children', ' child ', 'therap'] as $w) {
            $this->assertStringNotContainsStringIgnoringCase($w, $text);
        }
    }

    public function test_the_wizard_marks_new_sites(): void
    {
        require_once dirname(__DIR__, 2) . '/install/wizard-lib.php';
        $c = wizard_site_config([
            'account' => ['admin_email' => 'a@example.org'],
            'org'     => ['site_name_bg' => 'П', 'site_name_en' => '', 'site_url' => 'https://example.org', 'site_email' => 'a@example.org'],
            'look'    => ['brand_theme' => 'classic', 'brand_primary' => '#000000', 'brand_accent' => '#111111'],
            'modules' => ['modules' => []],
        ]);
        $this->assertTrue($c['HOME_START_MINIMAL']);
    }
}
