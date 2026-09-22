<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/home-store-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    private function seed(array $home = []): array
    {
        return home_seed($home, ['home.hero.title' => 'Добре дошли', 'home.shop.title' => 'Продукти'],
                                ['home.hero.title' => 'Welcome', 'home.shop.title' => 'Products']);
    }

    public function test_seed_keeps_todays_order(): void
    {
        $types = array_column($this->seed()['sections'], 'type');
        $this->assertSame(['hero', 'products', 'impact', 'campaign', 'centres', 'mission', 'news', 'partners', 'cta'], $types);
    }

    public function test_seed_prefers_saved_page_values_over_defaults(): void
    {
        $doc  = $this->seed(['hero_title' => 'Нашият дом', 'hero_title_en' => '', 'section_mission' => '', 'mission_title' => 'Мисия X',
                             'mission_text' => '<p onclick="x">Текст</p>', 'mission_image' => '/assets/images/pages/mission.jpg']);
        $hero = $doc['sections'][0]['fields'];
        $this->assertSame('Нашият дом', $hero['title']['bg']);
        $this->assertSame('Welcome', $hero['title']['en']);
        $this->assertSame('/assets/images/hero.webp', $hero['image']);
        $this->assertSame(['bg' => '/kak-da-pomogna/', 'en' => '/en/how-to-help/'], $hero['btn1_url']);
        $mission = $doc['sections'][5]['fields'];
        $this->assertSame('Мисия X', $mission['title']['bg']);
        $this->assertSame('<p>Текст</p>', $mission['text']['bg']);
        $this->assertSame('/assets/images/pages/mission.jpg', $mission['image']);
    }

    public function test_seed_ignores_unsafe_saved_images(): void
    {
        $doc = $this->seed(['hero_image' => 'javascript:x', 'mission_image' => '/etc/passwd']);
        $this->assertSame('/assets/images/hero.webp', $doc['sections'][0]['fields']['image']);
        $this->assertSame('', $doc['sections'][5]['fields']['image']);
    }

    public function test_every_seeded_section_validates_cleanly(): void
    {
        foreach ($this->seed()['sections'] as $s) {
            [, $errors] = home_validate_section($s['type'], $s['fields']);
            $this->assertSame([], $errors, $s['type']);
        }
    }

    public function test_missing_file_loads_the_seed_without_writing(): void
    {
        $r = home_load();
        $this->assertFalse($r['corrupt']);
        $this->assertFalse($r['exists']);
        $this->assertSame(0, $r['doc']['rev']);
        $this->assertFileDoesNotExist($GLOBALS['_om_home_file']);
    }

    public function test_corrupt_file_falls_back_and_says_so(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{not json');
        $r = home_load();
        $this->assertTrue($r['corrupt']);
        $this->assertSame('hero', $r['doc']['sections'][0]['type']);
    }

    public function test_missing_builtin_is_restored_hidden(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => 1, 'rev' => 3, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => []],
        ]]));
        $doc = home_load()['doc'];
        $partners = array_values(array_filter($doc['sections'], fn($s) => $s['type'] === 'partners'))[0];
        $this->assertFalse($partners['visible']);
        $this->assertSame('s_partners', $partners['id']);
        $this->assertSame(3, $doc['rev']);
    }

    public function test_unknown_type_is_preserved_on_save(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => 1, 'rev' => 1, 'sections' => [
            ['id' => 's_future', 'type' => 'from_a_newer_version', 'visible' => true, 'fields' => ['x' => 1]],
        ]]));
        $doc = home_load()['doc'];
        $this->assertTrue(home_save($doc, 1)['ok']);
        $this->assertStringContainsString('from_a_newer_version', file_get_contents($GLOBALS['_om_home_file']));
    }

    public function test_save_bumps_revision_and_refuses_stale_revision(): void
    {
        $doc = home_load()['doc'];
        $r1  = home_save($doc, 0);
        $this->assertTrue($r1['ok']);
        $this->assertSame(1, $r1['doc']['rev']);
        $r2 = home_save($doc, 0);          // someone else saved in between
        $this->assertFalse($r2['ok']);
        $this->assertSame('conflict', $r2['error']);
        $this->assertSame(1, home_load()['doc']['rev']);
    }

    public function test_save_leaves_no_temp_files(): void
    {
        home_save(home_load()['doc'], 0);
        $this->assertSame([], glob($this->dir . '/home.json.tmp-*'));
        $this->assertIsArray(json_decode(file_get_contents($GLOBALS['_om_home_file']), true));
    }

    public function test_corrupt_file_can_be_overwritten_from_revision_zero(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{not json');
        $this->assertTrue(home_save(home_load()['doc'], 0)['ok']);
        $this->assertFalse(home_load()['corrupt']);
    }

    public function test_a_damaged_file_is_backed_up_before_it_is_overwritten(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{not json');
        $this->assertTrue(home_save(home_load()['doc'], 0)['ok']);
        $copies = glob($GLOBALS['_om_home_file'] . '.corrupt-*');
        $this->assertCount(1, $copies);
        $this->assertMatchesRegularExpression('/\.corrupt-\d{14}$/', $copies[0]);
        $this->assertSame('{not json', file_get_contents($copies[0]));
    }

    public function test_a_file_without_sections_counts_as_damaged_and_is_backed_up(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], '{"rev":0,"sections":"oops"}');
        $this->assertTrue(home_load()['corrupt']);
        $this->assertTrue(home_save(home_load()['doc'], 0)['ok']);
        $this->assertCount(1, glob($GLOBALS['_om_home_file'] . '.corrupt-*'));
    }

    public function test_a_healthy_file_is_not_backed_up(): void
    {
        home_save(home_load()['doc'], 0);
        home_save(home_load()['doc'], 1);
        $this->assertSame([], glob($GLOBALS['_om_home_file'] . '.corrupt-*'));
    }

    public function test_backups_are_ignored_by_git(): void
    {
        $this->assertStringContainsString('/content/home.json.corrupt-*', (string) file_get_contents(dirname(__DIR__, 2) . '/.gitignore'));
    }
}
