<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeInlineSaveTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/home-inline-' . bin2hex(random_bytes(4)) . '.json';
        $GLOBALS['_om_home_file'] = $this->file;
        file_put_contents($this->file, json_encode(['version' => 1, 'rev' => 2, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => ['title' => ['bg' => 'Стар', 'en' => 'Old']]],
            ['id' => 's_ab12', 'type' => 'richtext', 'visible' => true, 'fields' => []],
        ]]));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->file . '*') as $f) unlink($f);
    }

    private function section(string $id): array
    {
        $doc = home_load()['doc'];
        return $doc['sections'][home_find($doc, $id)];
    }

    public function test_saves_text_both_languages(): void
    {
        $r = home_inline_save('s_hero', ['title' => ['bg' => 'Нов <b>дом</b>', 'en' => 'New home']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['bg' => 'Нов дом', 'en' => 'New home'], $this->section('s_hero')['fields']['title']);
        $this->assertSame(3, home_load()['doc']['rev']);
    }

    public function test_a_language_left_out_of_the_payload_keeps_its_stored_value(): void
    {
        $r = home_inline_save('s_hero', ['title' => ['en' => 'Only English']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['bg' => 'Стар', 'en' => 'Only English'], $this->section('s_hero')['fields']['title']);
    }

    /**
     * Contract: a language that IS sent replaces the stored one, even when empty —
     * that is how an editor deliberately clears a field. The on-page editor
     * (inline-cms.js) therefore seeds both languages from data-cms-bg/data-cms-en
     * before the first edit, so it never sends an accidental ''.
     */
    public function test_an_empty_language_that_is_sent_clears_it_on_purpose(): void
    {
        $r = home_inline_save('s_hero', ['title' => ['bg' => '', 'en' => 'New']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['bg' => '', 'en' => 'New'], $this->section('s_hero')['fields']['title']);
    }

    public function test_on_page_editor_seeds_both_languages_before_the_first_edit(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/inline-cms.js');
        $this->assertStringContainsString('function _dirtyEntry(', $js);
        $this->assertStringContainsString("bg: (el && el.dataset.cmsBg) || ''", $js);
        $this->assertStringContainsString("en: (el && el.dataset.cmsEn) || ''", $js);
        $this->assertDoesNotMatchRegularExpression("/bg:\\s*'',\\s*en:\\s*''/", $js, 'no dirty entry may start with both languages blank');
    }

    public function test_rich_text_is_cleaned(): void
    {
        home_inline_save('s_ab12', ['body' => ['bg' => '<p onclick="x">Hi</p>', 'en' => '']]);
        $this->assertSame('<p>Hi</p>', $this->section('s_ab12')['fields']['body']['bg']);
    }

    public function test_image_path_is_validated(): void
    {
        $r = home_inline_save('s_hero', ['image' => ['bg' => '/etc/passwd', 'en' => '/etc/passwd']]);
        $this->assertFalse($r['ok']);
        $r = home_inline_save('s_hero', ['image' => ['bg' => '/assets/images/pages/x.jpg', 'en' => '/assets/images/pages/x.jpg']]);
        $this->assertTrue($r['ok']);
        $this->assertSame('/assets/images/pages/x.jpg', $this->section('s_hero')['fields']['image']);
    }

    public function test_links_and_unknown_fields_cannot_be_set_inline(): void
    {
        home_inline_save('s_hero', ['btn1_url' => ['bg' => 'javascript:x', 'en' => ''], '__proto__' => ['bg' => 'x', 'en' => 'x']]);
        $f = $this->section('s_hero')['fields'];
        $this->assertArrayNotHasKey('__proto__', $f);
        $this->assertNotSame('javascript:x', $f['btn1_url']['bg'] ?? '');
    }

    public function test_unknown_section_is_refused(): void
    {
        $this->assertFalse(home_inline_save('s_nope', ['title' => ['bg' => 'x', 'en' => '']])['ok']);
    }

    public function test_inline_save_endpoint_routes_home_sections(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/inline-save.php');
        $route = strpos($src, "str_starts_with(\$section, 'home:')");
        $this->assertNotFalse($route, 'inline-save.php must route home:<id> sections');
        $this->assertLessThan(strpos($src, '// Allowed field map'), $route);
        $this->assertLessThan($route, strpos($src, "hash_equals(csrf_token()"), 'auth + CSRF checks must run first');
    }
}
