<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the reorder/assignOrderDefaults helpers shared across
 * impact, projects, team, and ways sections of pages.php.
 * The helpers are reproduced here because they are inline closures in the
 * admin page; if they are ever extracted to a shared function, point the
 * tests at that function instead.
 */
final class ReorderLogicTest extends TestCase
{
    // ── helpers under test ────────────────────────────────────────────────────

    private function assignOrderDefaults(array $items): array
    {
        foreach ($items as $i => &$p) {
            if (!isset($p['order'])) {
                $p['order'] = $i;
            }
        }
        return $items;
    }

    private function reorderByKey(array $items, array $order, string $key): array
    {
        $indexed = [];
        foreach ($items as $p) {
            $indexed[$p[$key]] = $p;
        }
        $sorted = [];
        foreach ($order as $k) {
            if (isset($indexed[$k])) {
                $sorted[] = $indexed[$k];
                unset($indexed[$k]);
            }
        }
        foreach ($indexed as $p) {
            $sorted[] = $p;
        }
        foreach ($sorted as $i => &$p) {
            $p['order'] = $i;
        }
        return $sorted;
    }

    // ── reorderByKey ──────────────────────────────────────────────────────────

    public function test_reorder_reverses_order(): void
    {
        $items  = [
            ['id' => 'a', 'order' => 0],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $result = $this->reorderByKey($items, ['c', 'b', 'a'], 'id');

        $this->assertSame('c', $result[0]['id']);
        $this->assertSame('b', $result[1]['id']);
        $this->assertSame('a', $result[2]['id']);
    }

    public function test_reorder_reassigns_sequential_order_values(): void
    {
        $items  = [
            ['id' => 'a', 'order' => 0],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $result = $this->reorderByKey($items, ['c', 'a', 'b'], 'id');

        foreach ($result as $i => $row) {
            $this->assertSame($i, (int)$row['order']);
        }
    }

    public function test_reorder_appends_items_not_in_order_list(): void
    {
        $items  = [
            ['id' => 'a', 'order' => 0],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $result = $this->reorderByKey($items, ['b', 'a'], 'id');

        $this->assertCount(3, $result);
        $this->assertSame('b', $result[0]['id']);
        $this->assertSame('a', $result[1]['id']);
        $ids = array_column($result, 'id');
        $this->assertContains('c', $ids);
    }

    public function test_reorder_with_empty_order_list_preserves_all_items(): void
    {
        $items  = [
            ['id' => 'a', 'order' => 0],
            ['id' => 'b', 'order' => 1],
        ];
        $result = $this->reorderByKey($items, [], 'id');

        $this->assertCount(2, $result);
    }

    public function test_reorder_ignores_unknown_keys_in_order_list(): void
    {
        $items  = [['id' => 'a', 'order' => 0]];
        $result = $this->reorderByKey($items, ['z', 'a'], 'id');

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['id']);
    }

    // ── assignOrderDefaults ───────────────────────────────────────────────────

    public function test_assign_order_defaults_fills_missing(): void
    {
        $items  = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
        $result = $this->assignOrderDefaults($items);

        $this->assertSame(0, (int)$result[0]['order']);
        $this->assertSame(1, (int)$result[1]['order']);
        $this->assertSame(2, (int)$result[2]['order']);
    }

    public function test_assign_order_defaults_preserves_existing_values(): void
    {
        $items  = [
            ['id' => 'a', 'order' => 5],
            ['id' => 'b', 'order' => 2],
        ];
        $result = $this->assignOrderDefaults($items);

        $this->assertSame(5, (int)$result[0]['order']);
        $this->assertSame(2, (int)$result[1]['order']);
    }

    public function test_assign_order_defaults_on_empty_array(): void
    {
        $this->assertSame([], $this->assignOrderDefaults([]));
    }

    // ── delete by splice ──────────────────────────────────────────────────────

    public function test_delete_removes_correct_item(): void
    {
        $items = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
        array_splice($items, 1, 1);

        $this->assertCount(2, $items);
        $this->assertSame('a', $items[0]['id']);
        $this->assertSame('c', $items[1]['id']);
    }

    public function test_delete_out_of_bounds_is_noop(): void
    {
        $items = [['id' => 'a'], ['id' => 'b']];
        if (isset($items[99])) {
            array_splice($items, 99, 1);
        }
        $this->assertCount(2, $items);
    }
}
