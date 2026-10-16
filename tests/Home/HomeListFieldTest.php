<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';
require_once dirname(__DIR__, 2) . '/includes/home_admin.php';

final class HomeListFieldTest extends TestCase
{
    private function cardsDef(): array
    {
        return home_types()['cards']['fields']['cards'];
    }

    public function test_items_get_stable_ids(): void
    {
        [$out] = home_clean_field($this->cardsDef(), [
            ['title' => ['bg' => 'A', 'en' => '']],
            ['id' => 'i_abc123', 'title' => ['bg' => 'B', 'en' => '']],
        ], 'cards');
        $this->assertMatchesRegularExpression('/^i_[a-z0-9]{6}$/', $out[0]['id']);
        $this->assertSame('i_abc123', $out[1]['id']);
    }

    public function test_bad_ids_are_replaced(): void
    {
        [$out] = home_clean_field($this->cardsDef(), [
            ['id' => '../x', 'title' => ['bg' => 'A', 'en' => '']],
            ['id' => 'i_abc123', 'title' => ['bg' => 'B', 'en' => '']],
            ['id' => 'i_abc123', 'title' => ['bg' => 'C', 'en' => '']],   // a repeated id would make two items one
        ], 'cards');
        $this->assertMatchesRegularExpression('/^i_[a-z0-9]{6}$/', $out[0]['id']);
        $this->assertNotSame('../x', $out[0]['id']);
        $this->assertSame('i_abc123', $out[1]['id']);
        $this->assertNotSame('i_abc123', $out[2]['id']);
    }

    public function test_item_ids_and_find(): void
    {
        $this->assertMatchesRegularExpression('/^i_[a-z0-9]{6}$/', home_list_item_id());
        $items = [['id' => 'i_aaaaaa'], ['id' => 'i_bbbbbb']];
        $this->assertSame(1, home_list_find($items, 'i_bbbbbb'));
        $this->assertNull(home_list_find($items, 'i_cccccc'));
    }

    public function test_min_max_errors_use_the_def(): void
    {
        $def = ['kind' => 'list', 'label' => 'Хора', 'item' => 'Човек', 'add' => '+', 'remove' => '-',
                'min' => 1, 'max' => 2, 'plural' => 'души', 'fields' => ['name' => ['kind' => 'text', 'label' => 'Име']]];
        [, $e] = home_clean_field($def, [['name' => ['bg' => 'a']], ['name' => ['bg' => 'b']], ['name' => ['bg' => 'c']]], 'k');
        $this->assertSame(['k' => 'Добавете между 1 и 2 души.'], $e);
        [, $e] = home_clean_field($def, [], 'k');
        $this->assertSame(['k' => 'Добавете между 1 и 2 души.'], $e);
    }

    public function test_item_field_errors_are_keyed_by_position(): void
    {
        $card = fn(string $t) => ['title' => ['bg' => $t, 'en' => '']];
        [, $e] = home_clean_field($this->cardsDef(), [$card('A'), $card('')], 'cards');
        $this->assertArrayHasKey('cards.1.title.bg', $e);
        $this->assertArrayNotHasKey('cards', $e);
    }

    public function test_upload_goes_into_the_right_row(): void
    {
        // move_uploaded_file() refuses a file that PHP did not receive in a real request, so a
        // JPEG that passes the type check ends at "could not be saved" — keyed to the row it was
        // posted in. A text file in row 0 is a type error at position 0, not at the other row.
        $jpeg = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($jpeg, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $txt = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($txt, 'just text');
        $in = ['cards' => [0 => ['title' => ['bg' => 'A']], 1 => ['title' => ['bg' => 'B']]]];
        $files = ['up_list' => [
            'tmp_name' => ['cards' => [0 => ['image' => $txt], 1 => ['image' => $jpeg]]],
            'error'    => ['cards' => [0 => ['image' => UPLOAD_ERR_OK], 1 => ['image' => UPLOAD_ERR_OK]]],
            'name'     => ['cards' => [0 => ['image' => 'a.txt'], 1 => ['image' => 'b.jpg']]],
        ]];
        $errors = home_apply_uploads($in, 'cards', $files, 's_ab12');
        @unlink($txt);
        @unlink($jpeg);
        $this->assertSame('Снимката трябва да е JPEG, PNG или WebP.', $errors['cards.0.image'] ?? null);
        $this->assertSame('Снимката не можа да се запази. Опитайте отново.', $errors['cards.1.image'] ?? null);
        $this->assertArrayNotHasKey('image', $in['cards'][0]);
        $this->assertArrayNotHasKey('image', $in['cards'][1]);
    }

    public function test_admin_form_has_one_fieldset_per_item_with_hidden_id(): void
    {
        $html = hs_field('cards', $this->cardsDef(), [['id' => 'i_abc123'], ['id' => 'i_def456']], []);
        $this->assertStringContainsString('name="f[cards][0][id]" value="i_abc123"', $html);
        $this->assertStringContainsString('name="f[cards][1][id]" value="i_def456"', $html);
        $this->assertStringContainsString('<template id="hsListTpl_cards">', $html);
        $this->assertStringContainsString('data-hs-list="cards"', $html);
        $this->assertStringContainsString('name="up_list[cards][1][image]"', $html);
        // two rows + the template
        $this->assertSame(3, substr_count($html, '<fieldset style="border:0;padding:0;margin:0;min-width:0;"><legend data-hs-item-legend'));
    }

    public function test_error_labels_and_preview_follow_the_def(): void
    {
        $this->assertSame('Карта 2 — Заглавие (BG)', hs_error_label('cards', 'cards.1.title.bg'));
        $this->assertSame('Карти', hs_error_label('cards', 'cards'));
        $s = ['type' => 'cards', 'fields' => ['cards' => [[], [], []]]];
        $this->assertSame('3 карти', home_section_preview($s));
    }
}
