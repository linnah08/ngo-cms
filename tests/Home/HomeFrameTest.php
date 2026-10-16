<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_render.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

/** Photo framing on the hero, mission and text+image blocks (field image_frame). */
final class HomeFrameTest extends TestCase
{
    private string $file;
    private const FRAME = ['w' => 60, 'r' => 0.75, 'x' => 30.0, 'y' => 20.0, 'z' => 1.5];

    protected function setUp(): void
    {
        start_session();
        unset($_SESSION[ADMIN_SESSION_NAME]);
        $GLOBALS['_show_admin_bar'] = false;
        $this->file = sys_get_temp_dir() . '/home-frame-' . bin2hex(random_bytes(4)) . '.json';
        $GLOBALS['_om_home_file'] = $this->file;
        $this->seed(['title' => ['bg' => 'Здравей', 'en' => 'Hello'], 'image' => '/assets/images/a.jpg', 'image_frame' => self::FRAME]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file']);
        $GLOBALS['_show_admin_bar'] = false;
        foreach (glob($this->file . '*') as $f) unlink($f);
    }

    private function seed(array $heroFields): void
    {
        file_put_contents($this->file, json_encode(['version' => 2, 'rev' => 1, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => $heroFields],
        ]]));
    }

    private function hero(): array
    {
        $doc = home_load()['doc'];
        return $doc['sections'][home_find($doc, 's_hero')]['fields'];
    }

    private function render(array $s): string
    {
        ob_start();
        home_render(['version' => 2, 'rev' => 1, 'sections' => [$s]], 'bg');
        return (string) ob_get_clean();
    }

    // ── The value ──────────────────────────────────────────────────────────────

    public function test_photo_blocks_have_a_frame_field(): void
    {
        foreach (['hero', 'mission', 'text_image'] as $type) {
            $this->assertSame('frame', home_types()[$type]['fields']['image_frame']['kind'] ?? null, $type);
        }
    }

    public function test_values_are_pulled_into_their_limits(): void
    {
        $this->assertSame(['w' => 65, 'r' => 0.5, 'x' => 0.0, 'y' => 100.0, 'z' => 3.0],
            home_frame(['w' => 90, 'r' => 0.1, 'x' => -5, 'y' => 250, 'z' => 9]));
        $this->assertSame(['w' => 35, 'r' => 2.0, 'x' => 12.3, 'y' => 50.0, 'z' => 1.0],
            home_frame(['w' => '10', 'r' => '7', 'x' => '12.34', 'y' => 50, 'z' => 0.2]));
    }

    public static function notFrames(): iterable
    {
        yield 'not an array'  => ['50'];
        yield 'missing value' => [['w' => 50, 'r' => 1, 'x' => 50, 'y' => 50]];
        yield 'not a number'  => [['w' => 'wide', 'r' => 1, 'x' => 50, 'y' => 50, 'z' => 1]];
        yield 'nested array'  => [['w' => [50], 'r' => 1, 'x' => 50, 'y' => 50, 'z' => 1]];
        yield 'infinite'      => [['w' => 50, 'r' => INF, 'x' => 50, 'y' => 50, 'z' => 1]];
    }

    
    #[DataProvider('notFrames')]
    public function test_anything_else_is_not_a_frame(mixed $raw): void
    {
        $this->assertNull(home_frame($raw));
    }

    public function test_an_empty_frame_is_the_normal_look_and_a_broken_one_an_error(): void
    {
        $def = home_types()['hero']['fields']['image_frame'];
        $this->assertSame([[], []], home_clean_field($def, null, 'image_frame'));
        $this->assertSame([[], []], home_clean_field($def, [], 'image_frame'));
        [, $err] = home_clean_field($def, ['w' => 'x'], 'image_frame');
        $this->assertArrayHasKey('image_frame', $err);
    }

    // ── Saving on the page ────────────────────────────────────────────────────

    public function test_frame_saves_from_the_page(): void
    {
        $r = home_inline_save('s_hero', ['image_frame' => ['bg' => ['w' => 55, 'r' => 1.2, 'x' => 40, 'y' => 60, 'z' => 2], 'en' => '']]);
        $this->assertTrue($r['ok']);
        $this->assertEquals(['w' => 55, 'r' => 1.2, 'x' => 40.0, 'y' => 60.0, 'z' => 2.0], $this->hero()['image_frame']);
    }

    public function test_an_empty_frame_from_the_page_resets_it(): void
    {
        $this->assertTrue(home_inline_save('s_hero', ['image_frame' => ['bg' => [], 'en' => []]])['ok']);
        $this->assertEquals([], $this->hero()['image_frame']);
    }

    public function test_a_broken_frame_is_refused_in_plain_words(): void
    {
        $r = home_inline_save('s_hero', ['image_frame' => ['bg' => ['w' => '<script>'], 'en' => '']]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Нагласянето на снимката', $r['error']);
        $this->assertEquals(self::FRAME, $this->hero()['image_frame']);
    }

    public function test_a_new_photo_keeps_the_frame_size_but_starts_centred(): void
    {
        $this->assertTrue(home_inline_save('s_hero', ['image' => ['bg' => '/assets/images/b.jpg', 'en' => '/assets/images/b.jpg']])['ok']);
        $this->assertEquals(['x' => 50.0, 'y' => 50.0, 'z' => 1.0, 'w' => 60, 'r' => 0.75], $this->hero()['image_frame']);
    }

    public function test_a_new_photo_framed_in_the_same_save_keeps_that_framing(): void
    {
        $frame = ['w' => 40, 'r' => 1.5, 'x' => 10, 'y' => 90, 'z' => 1.25];
        $this->assertTrue(home_inline_save('s_hero', [
            'image'       => ['bg' => '/assets/images/b.jpg', 'en' => '/assets/images/b.jpg'],
            'image_frame' => ['bg' => $frame, 'en' => $frame],
        ])['ok']);
        $this->assertEquals(['w' => 40, 'r' => 1.5, 'x' => 10.0, 'y' => 90.0, 'z' => 1.25], $this->hero()['image_frame']);
    }

    public function test_the_same_photo_again_keeps_the_framing(): void
    {
        $this->assertTrue(home_inline_save('s_hero', ['image' => ['bg' => '/assets/images/a.jpg', 'en' => '/assets/images/a.jpg']])['ok']);
        $this->assertEquals(self::FRAME, $this->hero()['image_frame']);
    }

    // ── Saving the admin form ─────────────────────────────────────────────────

    private function adminSave(array $f): array
    {
        $doc = home_load()['doc'];
        $in  = $f + ['title' => ['bg' => 'Здравей', 'en' => 'Hello'], 'image' => '/assets/images/a.jpg'];
        return home_admin_save($doc, ['id' => 's_hero', 'rev' => $doc['rev'], 'f' => $in], [], fn() => '');
    }

    public function test_the_admin_form_keeps_the_framing_it_does_not_show(): void
    {
        $this->assertSame('saved', $this->adminSave([])['status']);
        $this->assertEquals(self::FRAME, $this->hero()['image_frame']);
    }

    public function test_the_admin_form_cannot_post_a_frame_of_its_own(): void
    {
        $this->assertSame('saved', $this->adminSave(['image_frame' => ['w' => 35, 'r' => 2, 'x' => 0, 'y' => 0, 'z' => 3]])['status']);
        $this->assertEquals(self::FRAME, $this->hero()['image_frame']);
    }

    public function test_the_admin_form_can_reset_the_framing(): void
    {
        $this->assertSame('saved', $this->adminSave(['image_frame' => 'reset'])['status']);
        $this->assertEquals([], $this->hero()['image_frame']);
    }

    public function test_a_new_photo_from_the_admin_form_starts_centred(): void
    {
        $this->assertSame('saved', $this->adminSave(['image' => '/assets/images/b.jpg'])['status']);
        $this->assertEquals(['x' => 50.0, 'y' => 50.0, 'z' => 1.0, 'w' => 60, 'r' => 0.75], $this->hero()['image_frame']);
    }

    public function test_the_form_offers_a_reset_only_when_there_is_framing(): void
    {
        $def = home_types()['hero']['fields']['image_frame'];
        $with = hs_field('image_frame', $def, self::FRAME, []);
        $this->assertStringContainsString('value="reset"', $with);
        $this->assertStringContainsString('<label', $with);
        $this->assertStringContainsString('Нагласи снимката', $with);
        $this->assertStringNotContainsString('value="reset"', hs_field('image_frame', $def, [], []));
    }

    // ── Drawing ───────────────────────────────────────────────────────────────

    public function test_without_framing_the_blocks_look_as_before(): void
    {
        $html = $this->render(['id' => 's_hero', 'type' => 'hero', 'visible' => true,
            'fields' => ['title' => ['bg' => 'Х', 'en' => ''], 'image' => '/assets/images/a.jpg']]);
        $this->assertStringNotContainsString('data-om-frame-css', $html);
        $this->assertStringNotContainsString('object-position', $html);
        $this->assertStringNotContainsString('data-om-frame=', $html, 'visitors get no editor attributes');

        $ti = $this->render(['id' => 's_ti', 'type' => 'text_image', 'visible' => true, 'fields' => ['image' => '/assets/images/a.jpg']]);
        $this->assertStringContainsString('grid-template-columns:1fr 1fr', $ti);
        $this->assertStringContainsString('height:auto', $ti);
    }

    public function test_hero_draws_its_frame_inline(): void
    {
        $html = $this->render(['id' => 's_hero', 'type' => 'hero', 'visible' => true,
            'fields' => ['title' => ['bg' => 'Х', 'en' => ''], 'image' => '/assets/images/a.jpg', 'image_frame' => self::FRAME]]);
        // Photo column on the right takes 60% of the row, only above the stacking width.
        $this->assertStringContainsString('@media(min-width:901px){html body [data-om-fgrid="s_hero"][data-om-fgrid]{grid-template-columns:minmax(0,40fr) minmax(0,60fr)}}', $html);
        $this->assertStringContainsString('html body [data-om-fbox="s_hero"][data-om-fbox]{aspect-ratio:0.75;max-height:90vh}', $html);
        // On a phone a tall shape is held to 4:5.
        $this->assertStringContainsString('@media(max-width:900px){html body [data-om-fbox="s_hero"][data-om-fbox]{aspect-ratio:0.8}}', $html);
        $this->assertStringContainsString('object-position:30% 20%;transform:scale(1.5);transform-origin:30% 20%;', $html);
    }

    public function test_photo_first_blocks_put_its_share_first(): void
    {
        $html = $this->render(['id' => 's_mis', 'type' => 'mission', 'visible' => true,
            'fields' => ['title' => ['bg' => 'Х', 'en' => ''], 'image' => '/assets/images/a.jpg', 'image_frame' => self::FRAME]]);
        $this->assertStringContainsString('grid-template-columns:minmax(0,60fr) minmax(0,40fr)', $html);

        $ti = $this->render(['id' => 's_ti', 'type' => 'text_image', 'visible' => true,
            'fields' => ['image' => '/assets/images/a.jpg', 'image_side' => 'right', 'image_frame' => self::FRAME]]);
        // The template sets its columns inline; the shared phone rule still stacks it.
        $this->assertStringContainsString('grid-template-columns:minmax(0,40fr) minmax(0,60fr);', $ti);
    }

    public function test_the_editor_gets_the_frame_and_which_side_to_resize_from(): void
    {
        $GLOBALS['_show_admin_bar'] = true;
        $html = $this->render(['id' => 's_hero', 'type' => 'hero', 'visible' => true,
            'fields' => ['title' => ['bg' => 'Х', 'en' => ''], 'image' => '/assets/images/a.jpg', 'image_frame' => self::FRAME]]);
        $this->assertStringContainsString('data-om-frame="' . h((string) json_encode(self::FRAME)) . '"', $html);
        $this->assertStringContainsString('data-om-frame-handle="left"', $html);
    }

    public function test_a_stored_frame_out_of_range_is_drawn_within_the_limits(): void
    {
        $html = $this->render(['id' => 's_hero', 'type' => 'hero', 'visible' => true,
            'fields' => ['title' => ['bg' => 'Х', 'en' => ''], 'image' => '/assets/images/a.jpg',
                         'image_frame' => ['w' => 99, 'r' => 0.01, 'x' => '50;}body{display:none', 'y' => 1, 'z' => 1]]]);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringNotContainsString('object-position', $html, 'a frame that does not clean is ignored');
    }
}
