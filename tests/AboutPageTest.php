<?php
// tests/AboutPageTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The built-in About page (/za-nas/, /en/about/): the team is optional, the text uses
 * the full page width, and title, intro and bios are editable in Edit mode through
 * admin/inline-save.php (section "about"). Each test writes its own pages.json and
 * puts back whatever was there before, byte for byte.
 */
#[Group('admin')]
final class AboutPageTest extends TestCase
{
    private ?string $backup = null;

    private function pagesPath(): string { return CONTENT_PATH . '/pages.json'; }

    protected function setUp(): void
    {
        $this->backup = is_file($this->pagesPath()) ? file_get_contents($this->pagesPath()) : null;
    }

    protected function tearDown(): void
    {
        if ($this->backup !== null) file_put_contents($this->pagesPath(), $this->backup);
        elseif (is_file($this->pagesPath())) unlink($this->pagesPath());
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

    private function withTeam(): array
    {
        return $this->storyOnly() + ['team' => [
            ['name' => 'Мария', 'name_en' => 'Maria', 'role' => 'Основател', 'role_en' => 'Founder',
             'bio' => '<p>Био БГ</p>', 'bio_en' => '<p>Bio EN</p>', 'photo' => '', 'order' => 0],
        ]];
    }

    private function render(string $rel, ?string $role = null): string
    {
        $r = run_admin_page($rel, [], ['method' => 'GET', 'role' => $role]);
        $this->assertSame(200, $r['status'], $r['body']);
        return $r['body'];
    }

    private function save(array $fields): array
    {
        $r = run_admin_page('admin/inline-save.php', [], ['json' => ['section' => 'about', 'fields' => $fields]]);
        return json_decode(trim($r['body']), true) ?? [];
    }

    private function about(): array { return load_json($this->pagesPath())['about'] ?? []; }

    // ── The team is optional ─────────────────────────────────────────────────

    public function test_story_only_page_has_no_team_section_in_either_language(): void
    {
        $this->putAbout($this->storyOnly());
        $bg = $this->render('za-nas/index.php');
        $en = $this->render('en/about/index.php');

        $this->assertStringContainsString('Историята на продукта.', $bg);
        $this->assertStringNotContainsString('Защо го правим?', $bg);
        $this->assertStringNotContainsString('нашия екип', $bg);
        $this->assertStringContainsString('The story of the product.', $en);
        $this->assertStringNotContainsString('Why we do it', $en);
        $this->assertStringNotContainsString('our team', $en);
    }

    public function test_page_with_a_team_shows_it_in_both_languages(): void
    {
        $this->putAbout($this->withTeam());
        $this->assertStringContainsString('Защо го правим?', $this->render('za-nas/index.php'));
        $en = $this->render('en/about/index.php');
        $this->assertStringContainsString('Why we do it', $en);
        $this->assertStringContainsString('Bio EN', $en);
    }

    public function test_text_is_not_squeezed_into_the_narrow_column(): void
    {
        $this->putAbout($this->storyOnly());
        $this->assertStringNotContainsString('container--narrow', $this->render('za-nas/index.php'));
        $this->assertStringNotContainsString('container--narrow', $this->render('en/about/index.php'));
    }

    public function test_english_page_falls_back_to_bulgarian_when_english_is_empty(): void
    {
        $this->putAbout(['title' => 'Заглавие', 'title_en' => '', 'intro' => '<p>Само БГ</p>', 'intro_en' => '']);
        $en = $this->render('en/about/index.php');
        $this->assertStringContainsString('Само БГ', $en);
        $this->assertStringContainsString('Who are we?', $en);
    }

    // ── Edit mode hooks ──────────────────────────────────────────────────────

    public function test_admin_sees_edit_hooks_on_title_intro_and_bios(): void
    {
        $this->putAbout($this->withTeam());
        foreach (['za-nas/index.php', 'en/about/index.php'] as $rel) {
            $html = $this->render($rel, 'admin');
            $this->assertMatchesRegularExpression('/<h1 data-cms-section="about" data-cms-field="title"[^>]*data-cms-bg="Нашата история" data-cms-en="Our story"/u', $html, $rel);
            $this->assertStringContainsString('data-cms-field="intro" data-cms-type="richtext"', $html, $rel);
            $this->assertStringContainsString('data-cms-field="bio_0" data-cms-type="richtext"', $html, $rel);
        }
    }

    public function test_visitor_does_not_get_the_stored_values_in_attributes(): void
    {
        $this->putAbout($this->withTeam());
        $html = $this->render('za-nas/index.php');
        $this->assertStringContainsString('data-cms-section="about"', $html);
        $this->assertDoesNotMatchRegularExpression('/data-cms-section="about"[^>]*data-cms-bg=/', $html);
    }

    // ── Saving from Edit mode ────────────────────────────────────────────────

    public function test_saves_title_intro_and_bio(): void
    {
        $this->putAbout($this->withTeam());
        $reply = $this->save([
            'title' => ['bg' => 'Ново &amp; заглавие', 'en' => 'New title'],
            'intro' => ['bg' => '<p>Нов <strong>текст</strong></p><script>alert(1)</script>', 'en' => '<p>New text</p>'],
            'bio_0' => ['bg' => '<p>Ново био</p>', 'en' => '<p>New bio</p>'],
        ]);
        $this->assertSame(['ok' => true], $reply);

        $a = $this->about();
        $this->assertSame('Ново & заглавие', $a['title']);
        $this->assertSame('New title', $a['title_en']);
        $this->assertSame('<p>Нов <strong>текст</strong></p>', $a['intro']);
        $this->assertSame('<p>New text</p>', $a['intro_en']);
        $this->assertSame('<p>Ново био</p>', $a['team'][0]['bio']);
        $this->assertSame('<p>New bio</p>', $a['team'][0]['bio_en']);
        $this->assertSame('Мария', $a['team'][0]['name'], 'the rest of the member is kept');
    }

    public function test_bio_of_a_member_that_no_longer_exists_adds_nobody(): void
    {
        $this->putAbout($this->storyOnly());
        $this->assertSame(['ok' => true], $this->save(['bio_3' => ['bg' => '<p>x</p>', 'en' => '<p>x</p>']]));
        $this->assertArrayNotHasKey('team', $this->about());
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $this->putAbout($this->storyOnly());
        $this->save(['team' => ['bg' => 'x', 'en' => 'x'], 'photo' => ['bg' => 'x', 'en' => 'x']]);
        $this->assertSame($this->storyOnly(), $this->about());
    }

    public function test_logged_out_visitor_cannot_save(): void
    {
        $this->putAbout($this->storyOnly());
        $r = run_admin_page('admin/inline-save.php', [], ['role' => null,
            'json' => ['section' => 'about', 'fields' => ['title' => ['bg' => 'X', 'en' => 'X']]]]);
        $this->assertSame('unauthorized', json_decode(trim($r['body']), true)['error'] ?? null);
        $this->assertSame('Нашата история', $this->about()['title']);
    }
}
