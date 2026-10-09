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

    public function test_seed_shows_every_section_unless_the_site_says_otherwise(): void
    {
        // An explicit empty list: the site's own theme may start some hidden, and a
        // fork's test run uses that theme (lafetki hides three).
        $doc = home_seed([], [], [], []);
        $this->assertSame([true], array_values(array_unique(array_column($doc['sections'], 'visible'))));
    }

    public function test_a_site_can_start_some_built_ins_hidden(): void
    {
        // What a theme's 'home_start_hidden' key does: the sections stay, switched off.
        $doc = home_seed([], [], [], ['impact', 'partners', 'no_such_type', 42]);
        $vis = array_column($doc['sections'], 'visible', 'type');
        $this->assertFalse($vis['impact']);
        $this->assertFalse($vis['partners']);
        $this->assertSame(7, count(array_filter($vis)));
        $this->assertCount(9, $doc['sections']);
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

    /**
     * The seed reads pages.json and both strings files on every call, so it is injected
     * here as a counter: with every built-in present it must not be built at all.
     */
    public function test_ensure_builtins_does_not_build_the_seed_when_nothing_is_missing(): void
    {
        $calls = 0;
        $seed  = function () use (&$calls) { $calls++; return $this->seed(); };
        $full  = $this->seed();
        $this->assertSame($full, home_ensure_builtins($full, $seed));
        $this->assertSame(0, $calls);

        $partial = $full;
        $partial['sections'] = array_values(array_filter($full['sections'], fn($s) => $s['type'] !== 'news'));
        $out = home_ensure_builtins($partial, $seed);
        $this->assertSame(1, $calls);
        $this->assertSame('news', end($out['sections'])['type']);
        $this->assertFalse(end($out['sections'])['visible']);
        $this->assertCount(count($full['sections']), $out['sections']);
    }

    private function field(array $doc, string $type, string $key): mixed
    {
        $s = array_values(array_filter($doc['sections'], fn($s) => $s['type'] === $type))[0];
        return $s['fields'][$key];
    }

    /** pages.json text was saved by the old inline editor as HTML: "&nbsp;" showed on the page. */
    public function test_seed_turns_saved_page_text_into_plain_text(): void
    {
        $doc = $this->seed(['cta_body' => 'Сензорна терапия.&nbsp;', 'cta_body_en' => 'Fish &amp; <b>chips</b>',
                            'hero_title' => 'Добре&nbsp;дошли']);
        $this->assertSame(['bg' => 'Сензорна терапия.', 'en' => 'Fish & chips'], $this->field($doc, 'cta', 'text'));
        $this->assertSame('Добре дошли', $this->field($doc, 'hero', 'title')['bg']);
    }

    public function test_load_repairs_text_saved_before_it_was_decoded(): void
    {
        file_put_contents($GLOBALS['_om_home_file'], json_encode(['version' => 1, 'rev' => 5, 'sections' => [
            ['id' => 's_cta', 'type' => 'cta', 'visible' => true, 'fields' => [
                'heading' => ['bg' => 'Всяко дете &quot;заслужава&quot;', 'en' => ''],
                'text'    => ['bg' => 'Сензорна терапия.&nbsp;', 'en' => 'Fish &amp; chips'],
            ]],
            ['id' => 's_mission', 'type' => 'mission', 'visible' => true, 'fields' => [
                'text' => ['bg' => '<p>А&nbsp;Б</p>', 'en' => ''],
            ]],
        ]]));
        $doc = home_load()['doc'];
        $this->assertSame(['bg' => 'Сензорна терапия.', 'en' => 'Fish & chips'], $this->field($doc, 'cta', 'text'));
        $this->assertSame('Всяко дете "заслужава"', $this->field($doc, 'cta', 'heading')['bg']);
        $this->assertSame('<p>А&nbsp;Б</p>', $this->field($doc, 'mission', 'text')['bg'], 'formatted text is HTML and stays as it is');

        // Saved once, the file is repaired for good and the next load leaves it alone.
        $this->assertTrue(home_save($doc, 5)['ok']);
        $this->assertSame('Сензорна терапия.', $this->field(json_decode(file_get_contents($GLOBALS['_om_home_file']), true), 'cta', 'text')['bg']);
    }

    /** After the repair, text is stored exactly as typed — "&amp;" typed on purpose stays. */
    public function test_load_leaves_text_alone_once_repaired(): void
    {
        $this->assertTrue(home_save($this->seed(), 0)['ok']);
        $doc = home_load()['doc'];
        $i = array_search('cta', array_column($doc['sections'], 'type'), true);
        $doc['sections'][$i]['fields']['text']['bg'] = 'Пишем &amp; така';
        $this->assertTrue(home_save($doc, 1)['ok']);
        $this->assertSame('Пишем &amp; така', $this->field(home_load()['doc'], 'cta', 'text')['bg']);
    }

    public function test_plain_fallback_keeps_the_words_and_escapes_everything(): void
    {
        $out = home_clean_html_plain('<p>Здравей &amp; <b>добре</b> дошли</p><p><script>alert(1)</script>Втори</p>');
        $this->assertSame('<p>Здравей &amp; добре дошли<br>' . "\n" . 'alert(1)Втори</p>', $out);
        $this->assertSame('', home_clean_html_plain('<p> </p>'));
        $this->assertStringNotContainsString('<script', home_clean_html_plain('&lt;script&gt;x'));
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
