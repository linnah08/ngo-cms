<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for cart session mutations.
 *
 * These tests replicate exactly what cart/add.php, cart/update.php, and
 * cart/remove.php do to $_SESSION['cart'] — no HTTP requests needed.
 *
 * Cart structure (from cart/add.php):
 *   $_SESSION['cart'] = [
 *     ['product_id' => int, 'quantity' => int, ...optional variant fields],
 *     ...
 *   ]
 *
 * Print items additionally carry: colour, size, design_file, design_position.
 */
#[Group('shop')]
#[Group('cart')]
final class CartMutationTest extends TestCase
{
    protected function setUp(): void
    {
        start_session();
        $_SESSION['cart'] = [];
    }

    protected function tearDown(): void
    {
        unset($_SESSION['cart']);
    }

    // ── 1. testCartStartsEmpty ────────────────────────────────────────────────

    public function testCartStartsEmpty(): void
    {
        $this->assertIsArray($_SESSION['cart']);
        $this->assertEmpty($_SESSION['cart']);
        $this->assertSame(0, cart_count());
    }

    // ── 2. testAddingProductToCart ────────────────────────────────────────────

    public function testAddingProductToCart(): void
    {
        // Replicate what cart/add.php does when a standard product is added
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 2];

        $this->assertCount(1, $_SESSION['cart']);
        $this->assertSame(2, cart_count());
    }

    // ── 3. testPrintProductStoresVariantFields ────────────────────────────────

    public function testPrintProductStoresVariantFields(): void
    {
        // Replicate cart/add.php entry construction for a 'print' type product
        $entry = [
            'product_id'      => 7,
            'quantity'        => 1,
            'colour'          => 'black',
            'size'            => 'L',
            'design_file'     => 'uploads/print-designs/abc123.png',
            'design_position' => ['x' => 0.5, 'y' => 0.4, 'scale' => 0.3, 'rotation' => 0],
        ];
        $_SESSION['cart'][] = $entry;

        $stored = $_SESSION['cart'][0];
        $this->assertSame('black', $stored['colour']);
        $this->assertSame('L', $stored['size']);
        $this->assertSame('uploads/print-designs/abc123.png', $stored['design_file']);
        $this->assertIsArray($stored['design_position']);
        $this->assertSame(0.5, $stored['design_position']['x']);
    }

    // ── 4. testCartCountSumsQuantities ────────────────────────────────────────

    public function testCartCountSumsQuantities(): void
    {
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 1];
        $_SESSION['cart'][] = ['product_id' => 2, 'quantity' => 3];

        $this->assertSame(4, cart_count());
    }

    // ── 5. testRemovingItemByIndexRemovesFromCart ─────────────────────────────

    public function testRemovingItemByIndexRemovesFromCart(): void
    {
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 2];
        $_SESSION['cart'][] = ['product_id' => 2, 'quantity' => 5];

        $this->assertCount(2, $_SESSION['cart']);

        // Replicate cart/remove.php: array_splice + array_values
        $idx  = 0;
        $cart = $_SESSION['cart'];
        if ($idx >= 0 && isset($cart[$idx])) {
            array_splice($cart, $idx, 1);
        }
        $_SESSION['cart'] = array_values($cart);

        $this->assertCount(1, $_SESSION['cart']);
        // Only the second item (product_id=2, qty=5) should remain
        $this->assertSame(2, $_SESSION['cart'][0]['product_id']);
        $this->assertSame(5, cart_count());
    }

    // ── 6. testRemoveOutOfBoundsIsNoop ────────────────────────────────────────

    public function testRemoveOutOfBoundsIsNoop(): void
    {
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 1];

        $cart = $_SESSION['cart'];
        $idx  = 99;
        if ($idx >= 0 && isset($cart[$idx])) {
            array_splice($cart, $idx, 1);
        }
        $_SESSION['cart'] = array_values($cart);

        $this->assertCount(1, $_SESSION['cart']);
    }

    // ── 7. testRemoveNegativeIndexIsNoop ─────────────────────────────────────

    public function testRemoveNegativeIndexIsNoop(): void
    {
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 1];

        $cart = $_SESSION['cart'];
        $idx  = -1;
        if ($idx >= 0 && isset($cart[$idx])) {
            array_splice($cart, $idx, 1);
        }
        $_SESSION['cart'] = array_values($cart);

        $this->assertCount(1, $_SESSION['cart']);
    }

    // ── 8. testRemoveLastItemLeavesEmptyCart ──────────────────────────────────

    public function testRemoveLastItemLeavesEmptyCart(): void
    {
        $_SESSION['cart'][] = ['product_id' => 5, 'quantity' => 1];

        $cart = $_SESSION['cart'];
        array_splice($cart, 0, 1);
        $_SESSION['cart'] = array_values($cart);

        $this->assertEmpty($_SESSION['cart']);
        $this->assertSame(0, cart_count());
    }

    // ── 9. testCartReindexesAfterRemoval ─────────────────────────────────────

    public function testCartReindexesAfterRemoval(): void
    {
        // Verify array_values() re-creates a 0-based index
        $_SESSION['cart'][] = ['product_id' => 1, 'quantity' => 1];
        $_SESSION['cart'][] = ['product_id' => 2, 'quantity' => 1];
        $_SESSION['cart'][] = ['product_id' => 3, 'quantity' => 1];

        $cart = $_SESSION['cart'];
        array_splice($cart, 0, 1);          // remove first
        $_SESSION['cart'] = array_values($cart);

        $this->assertArrayHasKey(0, $_SESSION['cart']);
        $this->assertArrayHasKey(1, $_SESSION['cart']);
        $this->assertArrayNotHasKey(2, $_SESSION['cart']);
    }

    // ── 10. testUpdateCapsQuantityToStock ────────────────────────────────────

    public function testUpdateCapsQuantityToStock(): void
    {
        // Replicates the capping logic inside cart/update.php
        $cart = [['product_id' => 1, 'quantity' => 1]];
        $stock_by_product = [1 => 3];

        $new_cart = [];
        $capped   = false;
        foreach ($cart as $i => $item) {
            $qty   = 10;  // user requested 10
            $limit = $stock_by_product[$item['product_id']] ?? 0;
            if ($qty > $limit) { $qty = $limit; $capped = true; }
            if ($qty <= 0) continue;
            $item['quantity'] = $qty;
            $new_cart[] = $item;
        }

        $this->assertTrue($capped);
        $this->assertSame(3, $new_cart[0]['quantity']);
    }

    // ── 11. testUpdateDropsItemWhenStockZero ──────────────────────────────────

    public function testUpdateDropsItemWhenStockZero(): void
    {
        $cart = [['product_id' => 1, 'quantity' => 2]];
        $stock_by_product = [1 => 0];

        $new_cart = [];
        foreach ($cart as $item) {
            $qty   = (int)$item['quantity'];
            $limit = $stock_by_product[$item['product_id']] ?? 0;
            if ($qty > $limit) $qty = $limit;
            if ($qty <= 0) continue;
            $item['quantity'] = $qty;
            $new_cart[] = $item;
        }

        $this->assertEmpty($new_cart);
    }

    // ── 12. testUpdateZeroQuantityDropsItem ───────────────────────────────────

    public function testUpdateZeroQuantityDropsItem(): void
    {
        // When user submits qty=0 for an item, update.php drops it
        $cart = [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
        ];
        $quantities = [0 => 0, 1 => 1];   // first item set to 0
        $stock_by_product = [1 => 5, 2 => 5];

        $new_cart = [];
        foreach ($cart as $i => $item) {
            $qty   = (int)($quantities[$i] ?? 0);
            $limit = $stock_by_product[$item['product_id']] ?? 0;
            if ($qty > $limit) $qty = $limit;
            if ($qty <= 0) continue;
            $item['quantity'] = $qty;
            $new_cart[] = $item;
        }

        $this->assertCount(1, $new_cart);
        $this->assertSame(2, $new_cart[0]['product_id']);
    }
}
