<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the ordered-list logic extracted out of admin/pages.php into
 * includes/pages_admin.php. The same normalise / upsert / delete maths was
 * copy-pasted across eight POST branches (impact, team, projects, ways — each
 * save + delete); these tests cover it once.
 */
#[Group('admin')]
final class PagesAdminLogicTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/pages_admin.php';
    }

    // ── pages_normalize_order ────────────────────────────────────────────────

    public function testNormalizeSortsByOrderAndFillsMissing(): void
    {
        $out = pages_normalize_order([
            ['id' => 'x', 'order' => 2],
            ['id' => 'y', 'order' => 0],
            ['id' => 'z', 'order' => 1],
        ]);
        $this->assertSame(['y', 'z', 'x'], array_column($out, 'id'));
    }

    public function testNormalizeAssignsIndexAsDefaultOrder(): void
    {
        $out = pages_normalize_order([['id' => 'a'], ['id' => 'b']]);
        $this->assertSame(0, $out[0]['order']);
        $this->assertSame(1, $out[1]['order']);
    }

    // ── pages_list_upsert (new) ──────────────────────────────────────────────

    public function testUpsertNewAppendsWithNextOrder(): void
    {
        $list = [['id' => 'a', 'order' => 0], ['id' => 'b', 'order' => 1]];
        $out  = pages_list_upsert($list, 'new', ['id' => 'c']);

        $this->assertCount(3, $out);
        $this->assertSame('c', $out[2]['id']);
        $this->assertSame(2, $out[2]['order']); // max(0,1)+1
    }

    public function testUpsertNewIntoEmptyListGetsOrderZero(): void
    {
        $out = pages_list_upsert([], 'new', ['id' => 'first']);
        $this->assertSame(0, $out[0]['order']);
    }

    // ── pages_list_upsert (update) ───────────────────────────────────────────

    public function testUpsertReplacesAtIndexPreservingItsOrder(): void
    {
        $list = [['id' => 'a', 'order' => 0], ['id' => 'b', 'order' => 1]];
        $out  = pages_list_upsert($list, 0, ['id' => 'A2']);

        $this->assertCount(2, $out);
        $this->assertSame('A2', $out[0]['id']);
        $this->assertSame(0, $out[0]['order']);   // order preserved → stays first
        $this->assertSame('b', $out[1]['id']);
    }

    public function testUpsertOutOfRangeIndexIsANoOp(): void
    {
        $list = [['id' => 'a', 'order' => 0]];
        $out  = pages_list_upsert($list, 5, ['id' => 'ghost']);

        $this->assertCount(1, $out);                  // entry dropped, not added
        $this->assertSame('a', $out[0]['id']);
    }

    // ── pages_list_delete ────────────────────────────────────────────────────

    public function testDeleteRemovesAndResequencesOrder(): void
    {
        $list = [
            ['id' => 'a', 'order' => 0],
            ['id' => 'b', 'order' => 1],
            ['id' => 'c', 'order' => 2],
        ];
        $out = pages_list_delete($list, 1); // remove 'b'

        $this->assertSame(['a', 'c'], array_column($out, 'id'));
        $this->assertSame([0, 1], array_column($out, 'order')); // re-sequenced
    }

    public function testDeleteOutOfRangeReturnsNull(): void
    {
        $this->assertNull(pages_list_delete([['id' => 'a', 'order' => 0]], 9));
        $this->assertNull(pages_list_delete([['id' => 'a', 'order' => 0]], -1));
        $this->assertNull(pages_list_delete([], 0));
    }

    public function testDeleteOperatesOnNormalisedPositions(): void
    {
        // idx refers to the normalised (order-sorted) position, not input order.
        $list = [['id' => 'x', 'order' => 2], ['id' => 'y', 'order' => 0]];
        $out  = pages_list_delete($list, 0); // normalised first is 'y'
        $this->assertSame(['x'], array_column($out, 'id'));
    }
}
