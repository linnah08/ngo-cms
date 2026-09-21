<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/home.php';

final class HomeActionsTest extends TestCase
{
    private function doc(): array
    {
        return ['version' => 1, 'rev' => 4, 'sections' => [
            ['id' => 's_hero', 'type' => 'hero', 'visible' => true, 'fields' => ['title' => ['bg' => 'Здравейте', 'en' => '']]],
            ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['heading' => ['bg' => 'Помогнете', 'en' => '']]],
            ['id' => 's_cd34', 'type' => 'video', 'visible' => false, 'fields' => []],
        ]];
    }

    private function ids(array $doc): array { return array_column($doc['sections'], 'id'); }

    public function test_move_up_and_down(): void
    {
        $r = home_apply_action($this->doc(), 'move_up', 's_ab12');
        $this->assertTrue($r['ok']);
        $this->assertSame(['s_ab12', 's_hero', 's_cd34'], $this->ids($r['doc']));
        $this->assertSame("\u{201E}Помогнете\u{201C} е преместена нагоре.", $r['message']);
        $r = home_apply_action($this->doc(), 'move_down', 's_ab12');
        $this->assertSame(['s_hero', 's_cd34', 's_ab12'], $this->ids($r['doc']));
    }

    public function test_cannot_move_past_the_ends(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'move_up', 's_hero')['ok']);
        $this->assertFalse(home_apply_action($this->doc(), 'move_down', 's_cd34')['ok']);
    }

    public function test_toggle_flips_visibility_with_a_clear_message(): void
    {
        $r = home_apply_action($this->doc(), 'toggle', 's_cd34');
        $this->assertTrue($r['doc']['sections'][2]['visible']);
        $this->assertStringContainsString('вече се показва', $r['message']);
        $r = home_apply_action($this->doc(), 'toggle', 's_hero');
        $this->assertFalse($r['doc']['sections'][0]['visible']);
        $this->assertStringContainsString('скрита', $r['message']);
    }

    public function test_duplicate_inserts_a_copy_below_with_a_new_id(): void
    {
        $r = home_apply_action($this->doc(), 'duplicate', 's_ab12');
        $ids = $this->ids($r['doc']);
        $this->assertCount(4, $ids);
        $this->assertSame('s_ab12', $ids[1]);
        $this->assertMatchesRegularExpression('/^s_[a-z0-9_]{1,24}$/', $ids[2]);
        $this->assertNotSame('s_ab12', $ids[2]);
        $this->assertSame($r['doc']['sections'][1]['fields'], $r['doc']['sections'][2]['fields']);
        $this->assertSame($ids[2], $r['focus']);
    }

    public function test_delete_removes_blocks_only(): void
    {
        $r = home_apply_action($this->doc(), 'delete', 's_ab12');
        $this->assertSame(['s_hero', 's_cd34'], $this->ids($r['doc']));
        $this->assertStringContainsString('изтрита', $r['message']);
        $r = home_apply_action($this->doc(), 'delete', 's_hero');
        $this->assertFalse($r['ok']);
        $this->assertCount(3, $r['doc']['sections']);
    }

    public function test_builtins_cannot_be_duplicated(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'duplicate', 's_hero')['ok']);
    }

    public function test_unknown_id_or_action_is_refused(): void
    {
        $this->assertFalse(home_apply_action($this->doc(), 'toggle', 's_nope')['ok']);
        $this->assertFalse(home_apply_action($this->doc(), 'explode', 's_hero')['ok']);
    }

    public function test_upsert_replaces_or_appends(): void
    {
        $doc = home_upsert($this->doc(), ['id' => 's_ab12', 'type' => 'cta', 'visible' => true, 'fields' => ['x' => 1]]);
        $this->assertSame(['x' => 1], $doc['sections'][1]['fields']);
        $doc = home_upsert($doc, ['id' => 's_new1', 'type' => 'richtext', 'visible' => true, 'fields' => []]);
        $this->assertSame('s_new1', end($doc['sections'])['id']);
    }

    public function test_name_and_preview_fall_back_to_type_label(): void
    {
        $this->assertSame('Видео', home_section_name(['type' => 'video', 'fields' => []]));
        $this->assertSame('Помогнете', home_section_name($this->doc()['sections'][1]));
        $this->assertSame('YouTube видео', home_section_preview(['type' => 'video', 'fields' => ['video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ']]]));
    }
}
