<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The front page's title for search engines (includes/home_seo.php). */
final class HomeSeoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/home_seo.php';
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_empty_means_the_sites_name_as_before(): void
    {
        $none = array_fill_keys(HOME_SEO_FIELDS, '');
        $this->assertSame('Фондация', home_seo_title('bg', 'Фондация', $none));
        $this->assertSame('Foundation', home_seo_title('en', 'Foundation', $none));
    }

    public function test_saved_titles_are_used_per_language(): void
    {
        $v = ['home_seo_title_bg' => 'Подкрепа за деца', 'home_seo_title_en' => ''];
        $this->assertSame('Подкрепа за деца', home_seo_title('bg', 'Фондация', $v));
        $this->assertSame('Foundation', home_seo_title('en', 'Foundation', $v), 'an empty EN title falls back to the name, never to the BG text');
    }

    public function test_only_titles_are_kept_here(): void
    {
        // The description lives in one place: Админ → Организация → Кратко описание.
        $this->assertSame(['home_seo_title_bg', 'home_seo_title_en'], HOME_SEO_FIELDS);
    }

    public function test_posted_values_become_plain_text_and_long_ones_are_refused(): void
    {
        $r = home_seo_clean([
            'home_seo_title_bg' => "  <b>Заглавие</b>\n на два реда ",
            'home_seo_title_en' => str_repeat('я', HOME_SEO_TITLE_MAX + 1),
        ]);
        $this->assertSame('Заглавие на два реда', $r['values']['home_seo_title_bg']);
        $this->assertSame(['home_seo_title_en'], array_keys($r['errors']), 'the limit counts letters, not bytes');
        $this->assertStringContainsString('до ' . HOME_SEO_TITLE_MAX . ' знака', $r['errors']['home_seo_title_en']);
        $this->assertSame('', home_seo_clean(['home_seo_title_bg' => ['array']])['values']['home_seo_title_bg'], 'a wrong type counts as empty');
    }

    public function test_save_and_read_back(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $before = home_seo_values();
        try {
            home_seo_save(['home_seo_title_bg' => 'Т', 'home_seo_title_en' => '']);
            $this->assertSame(['home_seo_title_bg' => 'Т', 'home_seo_title_en' => ''], home_seo_values());
        } finally {
            home_seo_save($before);
        }
    }

    public function test_the_front_page_and_the_admin_use_it(): void
    {
        $index = self::src('index.php');
        $this->assertStringContainsString("includes/home_seo.php", $index, 'loads its helper itself (a site may keep an older config.php)');
        $this->assertStringContainsString('home_seo_title($lang', $index);
        $this->assertStringContainsString('org_site_description($lang)', $index, 'the description is the one from Организация');
        $en = self::src('en/index.php');
        $this->assertStringContainsString("includes/home_seo.php", $en, 'the English front page too');
        $this->assertStringContainsString("home_seo_title('en'", $en);
        $this->assertStringContainsString("org_site_description('en')", $en);

        $admin = self::src('admin/home-sections.php');
        $this->assertStringContainsString("includes/home_seo.php", $admin);
        $this->assertStringContainsString("\$action === 'home_seo' && \$cpage === null", $admin, 'only the front page has it; created pages have their own title');
        $this->assertStringContainsString('home_seo_clean($_POST)', $admin);
        $this->assertStringContainsString('Заглавие за търсачките', $admin);
        $this->assertStringNotContainsString('Описание за търсачките', $admin, 'no second description field');
        $this->assertStringContainsString('/admin/organisation.php#f-site_description_bg', $admin, 'points to where the description is set');
        $this->assertStringContainsString("data-translate-from=\"' . str_replace('_en', '_bg', \$name)", $admin, 'EN fields can be translated from BG');
        $this->assertLessThan(strpos($admin, "\$action === 'home_seo'"), strpos($admin, 'csrf_verify()'), 'CSRF checked before saving');
    }
}
