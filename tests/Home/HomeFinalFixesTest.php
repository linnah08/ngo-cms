<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

/** Fixes from the final whole-branch review. */
final class HomeFinalFixesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/home-final-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $GLOBALS['_om_home_file'] = $this->dir . '/home.json';
        $GLOBALS['_om_home_types_file'] = __DIR__ . '/fixtures/site-cards-type.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_home_file'], $GLOBALS['_om_home_types_file']);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
        rmdir($this->dir);
    }

    // 1. a site type that still says kind 'cards'
    public function test_old_site_cards_field_becomes_the_list_field_with_its_own_label(): void
    {
        $def = home_types()['tst_steps']['fields']['cards'];
        $this->assertSame('list', $def['kind']);
        $this->assertSame('Стъпки', $def['label']);
        $this->assertSame(home_cards_list_def('x')['fields'], $def['fields']);
        $this->assertSame(home_types()['cards']['fields']['cards'], home_cards_list_def());
    }

    public function test_old_site_cards_field_cleans_two_to_four_cards_with_ids(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => '']];
        [$f, $e] = home_validate_section('tst_steps', ['heading' => ['bg' => 'Как', 'en' => ''], 'cards' => [$card('А'), $card('Б')]]);
        $this->assertSame([], $e);
        $this->assertCount(2, $f['cards']);
        $this->assertMatchesRegularExpression(HOME_LIST_ID_RE, $f['cards'][0]['id']);
        [, $e] = home_validate_section('tst_steps', ['cards' => [$card('А')]]);
        $this->assertSame('Добавете между 2 и 4 карти.', $e['cards']);
    }

    public function test_old_site_cards_field_renders_the_list_form(): void
    {
        $def = home_types()['tst_steps']['fields']['cards'];
        $html = hs_field('cards', $def, [['id' => 'i_abc123'], ['id' => 'i_def456']], []);
        $this->assertStringContainsString('data-hs-list="cards"', $html);
        $this->assertStringContainsString('name="up_list[cards][1][image]"', $html);
    }

    public function test_old_site_cards_field_routes_row_uploads(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($txt, 'just text');
        $in = ['cards' => [0 => ['title' => ['bg' => 'A']]]];
        $files = ['up_list' => [
            'tmp_name' => ['cards' => [0 => ['image' => $txt]]],
            'error'    => ['cards' => [0 => ['image' => UPLOAD_ERR_OK]]],
            'name'     => ['cards' => [0 => ['image' => 'a.txt']]],
        ]];
        $errors = home_apply_uploads($in, 'tst_steps', $files, 's_ab12');
        @unlink($txt);
        $this->assertSame('Снимката трябва да е JPEG, PNG или WebP.', $errors['cards.0.image'] ?? null);
    }

    // 3. an item removed in another tab
    public function test_inline_save_of_a_removed_list_item_says_so(): void
    {
        file_put_contents($this->dir . '/home.json', json_encode(['version' => 1, 'rev' => 1, 'sections' => [
            ['id' => 's_pe01', 'type' => 'people', 'visible' => true, 'fields' => ['people' => [['id' => 'i_pppp01', 'name' => ['bg' => 'М', 'en' => '']]]]],
        ]]));
        $r = home_inline_save('s_pe01', ['people.i_gone01.image' => ['bg' => '/assets/images/a.jpg']]);
        $this->assertFalse($r['ok']);
        $this->assertSame('Тази снимка вече е премахната. Презаредете страницата.', $r['error']);
    }

    // 5. a changed photo never keeps the old crop
    private function crop(): array
    {
        return ['x' => 100, 'y' => 0, 'w' => 300, 'h' => 400, 'iw' => 1200, 'ih' => 800];
    }

    private function savePerson(string $image, ?array $crop = null, string $id = 'i_pppp01'): array
    {
        $old = ['id' => 'i_pppp01', 'image' => '/assets/images/a.jpg', 'focus' => $this->crop(), 'image_alt' => ['bg' => '', 'en' => ''],
                'name' => ['bg' => 'М', 'en' => ''], 'role' => ['bg' => '', 'en' => ''], 'bio' => ['bg' => '', 'en' => '']];
        $doc = ['version' => 1, 'rev' => 1, 'sections' => [['id' => 's_pe01', 'type' => 'people', 'visible' => true, 'fields' => ['people' => [$old]]]]];
        file_put_contents($this->dir . '/home.json', json_encode($doc));
        $post = ['id' => 's_pe01', 'rev' => 1, 'f' => ['people' => [[
            'id' => $id, 'image' => $image, 'focus' => json_encode($crop ?? $this->crop()),
            'name' => ['bg' => 'М', 'en' => ''],
        ]]]];
        $r = home_admin_save(home_load()['doc'], $post, []);
        $this->assertSame('saved', $r['status'], json_encode($r['errors']));
        return $r['doc']['sections'][0]['fields']['people'][0];
    }

    public function test_changed_photo_drops_the_old_crop(): void
    {
        $this->assertNull($this->savePerson('/assets/images/b.jpg')['focus']);
    }

    public function test_new_person_with_photo_and_crop_keeps_the_crop(): void
    {
        $this->assertSame($this->crop(), $this->savePerson('/assets/images/b.jpg', null, 'i_pppp09')['focus']);
    }

    public function test_changed_photo_with_a_new_crop_keeps_the_new_crop(): void
    {
        $new = ['x' => 0, 'y' => 0, 'w' => 150, 'h' => 200, 'iw' => 1200, 'ih' => 800];
        $this->assertSame($new, $this->savePerson('/assets/images/b.jpg', $new)['focus']);
    }

    public function test_same_photo_keeps_its_crop(): void
    {
        $this->assertSame($this->crop(), $this->savePerson('/assets/images/a.jpg')['focus']);
    }

    // 7. singular
    public function test_preview_uses_the_singular_for_one_item(): void
    {
        $this->assertSame('1 човек', home_section_preview(['type' => 'people', 'fields' => ['people' => [[]]]]));
        $this->assertSame('1 карта', home_section_preview(['type' => 'cards', 'fields' => ['cards' => [[]]]]));
        $this->assertSame('2 души', home_section_preview(['type' => 'people', 'fields' => ['people' => [[], []]]]));
    }
}
