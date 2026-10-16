<?php
// tests/AboutPageTest.php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/CreatedPages/CreatedPagesTestCase.php';
require_once dirname(__DIR__) . '/includes/about.php';

/**
 * The built-in About page (/za-nas/, /en/about/) is a page built from blocks,
 * stored as content/pages/p_000about.json. The first time it is asked for, it
 * is seeded once from pages.json → about (title, intro, team) and never again.
 *
 * Page files go to the test's own temp folder (CreatedPagesTestCase); the
 * seed source, pages.json, is written by each test and put back byte for byte.
 */
#[Group('admin')]
final class AboutPageTest extends CreatedPagesTestCase
{
    private ?string $backup = null;

    private function pagesPath(): string { return CONTENT_PATH . '/pages.json'; }
    private function aboutFile(): string { return $this->dir . '/' . CPAGE_ABOUT_ID . '.json'; }

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = is_file($this->pagesPath()) ? file_get_contents($this->pagesPath()) : null;
    }

    protected function tearDown(): void
    {
        if ($this->backup !== null) file_put_contents($this->pagesPath(), $this->backup);
        elseif (is_file($this->pagesPath())) unlink($this->pagesPath());
        parent::tearDown();
    }

    private function putAbout(array $about): void
    {
        save_json($this->pagesPath(), ['about' => $about]);
    }

    private function storyOnly(): array
    {
        return ['title' => 'Нашата история', 'title_en' => 'Our story',
                'intro' => '<p>Историята на продукта.</p>', 'intro_en' => '<p>The story of the product.</p>'];
    }

    /** Mixed BG/EN, a bad photo path, a member with only an English name, one with no photo. */
    private function threeMembers(): array
    {
        return $this->storyOnly() + ['team' => [
            ['name' => 'Мария', 'name_en' => 'Maria', 'role' => 'Основател', 'role_en' => 'Founder',
             'bio' => '<p>Био БГ</p><script>alert(1)</script>', 'bio_en' => '<p>Bio EN</p>',
             'photo' => '/assets/images/team/maria.jpg', 'order' => 0],
            ['name' => '', 'name_en' => 'Ivan', 'role' => 'Доброволец', 'role_en' => '',
             'bio' => '<p>За Иван</p>', 'photo' => 'javascript:x'],
            ['name' => 'Петя', 'role' => 'Касиер', 'bio' => '', 'photo' => ''],
        ]];
    }

    private function people(array $page): array
    {
        foreach ($page['sections'] as $s) if ($s['type'] === 'people') return $s;
        $this->fail('no people section');
    }

    // ── The seed ─────────────────────────────────────────────────────────────

    public function test_seed_maps_intro_and_every_team_member(): void
    {
        $page = about_seed_page($this->threeMembers());

        $this->assertSame([CPAGE_ABOUT_ID, 'published', 'za-nas', 'about', 'Нашата история', 'Our story'],
            [$page['id'], $page['status'], $page['slug_bg'], $page['slug_en'], $page['title_bg'], $page['title_en']]);
        $this->assertNotNull(cpage_normalize($page, CPAGE_ABOUT_ID), 'stored like any page');
        $this->assertSame(['text_image', 'people'], array_column($page['sections'], 'type'));

        foreach ($page['sections'] as $s) {
            $this->assertMatchesRegularExpression('/^s_[a-z0-9]{8}$/', $s['id']);
            $this->assertTrue($s['visible']);
            [, $errors] = home_validate_section($s['type'], $s['fields']);
            $this->assertSame([], $errors, $s['type']);
        }

        $intro = $page['sections'][0]['fields'];
        $this->assertSame(['bg' => '<p>Историята на продукта.</p>', 'en' => '<p>The story of the product.</p>'], $intro['body']);
        $this->assertSame([], $intro['photos']);
        $this->assertSame('white', $intro['background']);

        $team = $this->people($page)['fields'];
        $this->assertSame(['bg' => 'Защо го правим?', 'en' => 'Why we do it'], $team['heading']);
        $this->assertSame('grey', $team['background']);
        $this->assertCount(3, $team['people'], 'nobody is dropped');
        [$maria, $ivan, $petya] = $team['people'];
        foreach ($team['people'] as $p) $this->assertMatchesRegularExpression(HOME_LIST_ID_RE, $p['id']);

        $this->assertSame(['bg' => 'Мария', 'en' => 'Maria'], $maria['name']);
        $this->assertSame(['bg' => 'Основател', 'en' => 'Founder'], $maria['role']);
        $this->assertSame(['bg' => '<p>Био БГ</p>', 'en' => '<p>Bio EN</p>'], $maria['bio'], 'cleaned');
        $this->assertSame('/assets/images/team/maria.jpg', $maria['image']);
        $this->assertNull($maria['focus']);

        $this->assertSame(['bg' => 'Ivan', 'en' => 'Ivan'], $ivan['name'], 'no BG name: the EN one');
        $this->assertSame('', $ivan['image'], 'a bad photo path is dropped, not the person');
        $this->assertSame(['bg' => 'Доброволец', 'en' => ''], $ivan['role']);

        $this->assertSame(['bg' => 'Петя', 'en' => ''], $petya['name']);
        $this->assertSame('', $petya['image']);
    }

    public function test_member_without_any_name_is_kept(): void
    {
        $page = about_seed_page(['team' => [['name' => ' ', 'role' => 'Х'], 'not a member', ['name' => 'А']]]);
        $team = $this->people($page)['fields']['people'];
        $this->assertSame(['—', 'А'], array_column(array_column($team, 'name'), 'bg'));
        [, $errors] = home_validate_section('people', $this->people($page)['fields']);
        $this->assertSame([], $errors);
    }

    public function test_seed_without_team_has_no_people_section(): void
    {
        $page = about_seed_page($this->storyOnly());
        $this->assertSame(['text_image'], array_column($page['sections'], 'type'));

        $empty = about_seed_page([]);
        $this->assertSame(['Кои сме ние?', 'Who are we?'], [$empty['title_bg'], $empty['title_en']]);
        $this->assertSame(['text_image'], array_column($empty['sections'], 'type'));
    }

    public function test_seed_is_written_once_and_never_overwrites(): void
    {
        $this->putAbout($this->threeMembers());
        $this->assertFileDoesNotExist($this->aboutFile());
        $first = about_page();
        $this->assertFileExists($this->aboutFile());
        $this->assertSame('Нашата история', $first['title_bg']);

        $this->assertTrue(cpage_update(CPAGE_ABOUT_ID, fn(array $p) => ['title_bg' => 'Променено'] + $p)['ok']);
        $this->putAbout(['title' => 'Друго от pages.json']);
        cpage_cache_reset();

        $again = about_page();
        $this->assertSame('Променено', $again['title_bg'], 'an admin edit is never replaced by the seed');
        $this->assertSame(array_column($first['sections'], 'id'), array_column($again['sections'], 'id'));
    }

    public function test_seed_race_keeps_existing_file(): void
    {
        // Another request (or an admin) got there first: its file is read, never written over.
        $this->putAbout($this->threeMembers());
        $this->putPage(['id' => CPAGE_ABOUT_ID, 'status' => 'published', 'title_bg' => 'Вече има', 'title_en' => 'Already',
                        'slug_bg' => 'za-nas', 'slug_en' => 'about']);
        $before = file_get_contents($this->aboutFile());

        $page = about_page();
        $this->assertSame('Вече има', $page['title_bg']);
        $this->assertSame([], $page['sections']);
        $this->assertSame($before, file_get_contents($this->aboutFile()));
    }

    public function test_two_requests_at_once_make_one_file(): void
    {
        $this->putAbout($this->threeMembers());
        $root = dirname(__DIR__);
        $code = sprintf('$_SERVER["DOCUMENT_ROOT"] = %1$s; $GLOBALS["_om_pages_dir"] = %2$s; require %1$s . "/config.php";'
            . 'require_once %1$s . "/includes/about.php"; echo json_encode(array_column(about_page()["sections"], "id"));',
            var_export($root, true), var_export($this->dir, true));
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
        $procs = [];
        for ($i = 0; $i < 4; $i++) $procs[] = popen($cmd, 'r');
        $out = [];
        foreach ($procs as $p) { $out[] = stream_get_contents($p); pclose($p); }

        $ids = array_map(fn($o) => json_decode(trim($o), true), $out);
        $this->assertCount(1, array_unique(array_map('json_encode', $ids)), implode("\n", $out));
        $this->assertSame($ids[0], array_column(json_decode(file_get_contents($this->aboutFile()), true)['sections'], 'id'));
        $this->assertSame([CPAGE_ABOUT_ID . '.json'], array_values(array_filter(scandir($this->dir), fn($f) => str_ends_with($f, '.json'))));
    }

    public function test_seed_is_written_through_a_temp_file_and_never_over_a_file_made_meanwhile(): void
    {
        $this->putAbout($this->threeMembers());
        // Someone else's file appears while the seed is being built (the seed callable stands in for it).
        $page = cpage_seed_once(CPAGE_ABOUT_ID, function (): array {
            file_put_contents($this->aboutFile(), 'THEIRS');
            return about_seed_page(['title' => 'Seed']);
        });
        $this->assertSame('THEIRS', file_get_contents($this->aboutFile()), 'a file that appeared meanwhile is not replaced');
        $this->assertSame('Seed', $page['title_bg'], 'the page is still shown from the seed');
        $this->assertSame([], array_values(array_filter(scandir($this->dir), fn($f) => str_contains($f, '.tmp-'))), 'no temp file is left behind');
    }

    public function test_page_is_shown_with_its_sections_when_the_file_cannot_be_written(): void
    {
        $this->putAbout($this->threeMembers());
        chmod($this->dir, 0555);
        try {
            if (@file_put_contents($this->dir . '/probe', 'x') !== false) { @unlink($this->dir . '/probe'); $this->markTestSkipped('the directory stays writable (running as root)'); }
            [$bg, $code] = $this->request('za-nas/index.php', '/za-nas/');
        } finally {
            chmod($this->dir, 0755);
        }
        $this->assertSame(200, $code, $bg);
        $this->assertFileDoesNotExist($this->aboutFile());
        foreach (['Нашата история', 'Историята на продукта.', 'Мария', 'Петя'] as $want) {
            $this->assertStringContainsString($want, $bg);
        }
    }

    // ── The public pages ─────────────────────────────────────────────────────

    public function test_bg_and_en_pages_render_blocks(): void
    {
        $this->putAbout($this->threeMembers());
        [$bg, $code] = $this->request('za-nas/index.php', '/za-nas/');
        $this->assertSame(200, $code, $bg);
        foreach (['<span class="section-label">За нас</span>', 'Нашата история', 'Историята на продукта.',
                  'Защо го правим?', 'Мария', 'Ivan', 'Петя', 'Основател', 'Био БГ', 'нашия екип'] as $want) {
            $this->assertStringContainsString($want, $bg);
        }
        $this->assertStringNotContainsString('👤', $bg);
        $this->assertStringNotContainsString('alert(1)', $bg);
        $this->assertStringNotContainsString('javascript:x', $bg);
        $this->assertSame(1, substr_count($bg, '<h1'), 'one page title');

        [$en, $code] = $this->request('en/about/index.php', '/en/about/');
        $this->assertSame(200, $code, $en);
        foreach (['<span class="section-label">About us</span>', 'Our story', 'The story of the product.',
                  'Why we do it', 'Maria', 'Ivan', 'Петя', 'Founder', 'Bio EN', 'За Иван', 'our team'] as $want) {
            $this->assertStringContainsString($want, $en);
        }
        $this->assertStringNotContainsString('👤', $en);
    }

    public function test_story_only_page_has_no_team_section_in_either_language(): void
    {
        $this->putAbout($this->storyOnly());
        [$bg] = $this->request('za-nas/index.php', '/za-nas/');
        [$en] = $this->request('en/about/index.php', '/en/about/');
        $this->assertStringContainsString('Историята на продукта.', $bg);
        $this->assertStringNotContainsString('Защо го правим?', $bg);
        $this->assertStringNotContainsString('нашия екип', $bg);
        $this->assertStringContainsString('The story of the product.', $en);
        $this->assertStringNotContainsString('Why we do it', $en);
        $this->assertStringNotContainsString('our team', $en);
    }

    public function test_english_page_falls_back_to_bulgarian_when_english_is_empty(): void
    {
        $this->putAbout(['title' => 'Заглавие', 'title_en' => '', 'intro' => '<p>Само БГ</p>', 'intro_en' => '']);
        [$en] = $this->request('en/about/index.php', '/en/about/');
        $this->assertStringContainsString('Само БГ', $en);
        $this->assertStringContainsString('Who are we?', $en);
    }

    public function test_title_is_editable_inline(): void
    {
        $this->putAbout($this->threeMembers());
        foreach (['za-nas/index.php' => '/za-nas/', 'en/about/index.php' => '/en/about/'] as $script => $uri) {
            [$html] = $this->request($script, $uri, 'admin');
            $this->assertMatchesRegularExpression('/<h1 data-cms-section="pagetitle:p_000about" data-cms-field="title" data-cms-type="text" data-cms-bg="Нашата история" data-cms-en="Our story"/u', $html, $script);
            $this->assertMatchesRegularExpression('/data-cms-section="page:p_000about:s_[a-z0-9]{8}" data-cms-field="body" data-cms-type="richtext"/', $html, $script);
        }
        [$visitor] = $this->request('za-nas/index.php', '/za-nas/');
        $this->assertStringContainsString('data-cms-section="pagetitle:p_000about"', $visitor);
        $this->assertDoesNotMatchRegularExpression('/data-cms-section="pagetitle:[^"]*"[^>]*data-cms-bg=/', $visitor, 'stored values only for an admin');
    }
}
