<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

/** A site adds its own section types through its theme's 'home_types' file. */
final class HomeSiteTypesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $GLOBALS['_om_home_types_file'] = __DIR__ . '/fixtures/site-types.php';
        $this->dir = sys_get_temp_dir() . '/home-site-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_types_file'], $GLOBALS['_om_home_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    public function test_site_types_join_the_registry_as_addable_blocks(): void
    {
        $types = home_types();
        $this->assertArrayHasKey('tst_words', $types);
        $this->assertFalse($types['tst_words']['builtin']);
        $this->assertSame('Думи', $types['tst_words']['label']);
        $this->assertSame('▫️', $types['tst_clip']['icon'], 'a missing icon gets a default');
    }

    public function test_a_site_type_cannot_replace_a_cms_type_and_bad_entries_are_skipped(): void
    {
        $types = home_types();
        $this->assertSame('Начален банер', $types['hero']['label']);
        $this->assertTrue($types['hero']['builtin']);
        $this->assertArrayNotHasKey('Bad-Key', $types);
        $this->assertArrayNotHasKey('tst_nofields', $types);
    }

    public function test_without_a_site_file_only_the_cms_types_exist(): void
    {
        $GLOBALS['_om_home_types_file'] = '';
        $this->assertArrayNotHasKey('tst_words', home_types());
        $GLOBALS['_om_home_types_file'] = $this->dir . '/missing.php';
        $this->assertArrayNotHasKey('tst_words', @home_types());
    }

    public function test_a_site_field_is_cleaned_by_its_own_callable(): void
    {
        [$f, $e] = home_validate_section('tst_words', ['heading' => ['bg' => 'Здравей', 'en' => ''], 'words' => ' едно, , две ']);
        $this->assertSame(['едно', 'две'], $f['words']);
        $this->assertSame([], $e);

        [, $e] = home_validate_section('tst_words', ['words' => '']);
        $this->assertSame('Добавете поне една дума.', $e['words']);
    }

    public function test_a_site_field_without_a_clean_callable_is_an_error(): void
    {
        [, $e] = home_clean_field(['kind' => 'site', 'label' => 'X'], 'a', 'x');
        $this->assertArrayHasKey('x', $e);
    }

    public function test_a_site_field_draws_its_own_admin_markup(): void
    {
        $def = home_types()['tst_words']['fields']['words'];
        $this->assertSame('<input id="f_words" value="а, б">', hs_field('words', $def, ['а', 'б'], []));
        $this->assertSame('', hs_field('x', ['kind' => 'site', 'label' => 'X'], null, []));
    }

    public function test_a_site_field_can_store_its_own_uploads(): void
    {
        $in = ['words' => 'едно'];
        $this->assertSame([], home_apply_uploads($in, 'tst_words', ['up_words' => []], 's_ab12'));
        $this->assertSame('качена', $in['words']);
        $this->assertSame(['words' => 'Лош файл.'], home_apply_uploads($in, 'tst_words', ['bad' => []], 's_ab12'));
    }

    public function test_a_site_type_with_a_video_gets_a_local_thumbnail(): void
    {
        $doc   = ['version' => 1, 'rev' => 0, 'sections' => []];
        $asked = null;
        $r = home_admin_save($doc, ['id' => '', 'type' => 'tst_clip', 'rev' => 0,
                                    'f' => ['video' => 'https://youtu.be/ov5CsGPKIRc']], [],
            function (array $v, string $sid) use (&$asked): string { $asked = $v['id']; return '/assets/images/pages/home/x.jpg'; });
        $this->assertSame('saved', $r['status']);
        $this->assertSame('ov5CsGPKIRc', $asked);
        $this->assertSame('/assets/images/pages/home/x.jpg', $r['doc']['sections'][0]['fields']['thumb']);
    }
}
