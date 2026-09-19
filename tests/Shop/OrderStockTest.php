<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stock follows an order's status — includes/order_stock.php.
 * Cancel puts items back, reopen takes them out again, each at most once.
 */
#[Group('shop')]
final class OrderStockTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids   = [];
    private static array $product_ids = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_stock.php';
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (self::$order_ids) {
            $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
            self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
        }
        if (self::$product_ids) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute(self::$product_ids);
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute(self::$product_ids);
        }
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function insertProduct(int $stock): int
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,?)')
            ->execute(['test-stock-' . uniqid(), 'Тениска', 'T-shirt', 10.00, $stock, 1]);
        return self::$product_ids[] = (int)self::$pdo->lastInsertId();
    }

    private function insertVariant(int $product_id, int $stock): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$product_id, 'M', 'M', '{}', $stock, 1, 0]);
        return (int)self::$pdo->lastInsertId();
    }

    private function insertOrder(string $status, array $items, string $type = 'physical'): array
    {
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([generate_order_number(), $type, $status, 'Тест', 't@example.com', json_encode($items), 10, 0, 10, 'card', 'paid']);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $this->reload($id);
    }

    private function reload(int $id): array
    {
        $s = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    }

    /** Applies a status change the way the admin pages do. */
    private function changeStatus(array $order, string $status): array
    {
        $r = order_stock_on_status_change(self::$pdo, $order, $status);
        if ($r['ok']) {
            self::$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $order['id']]);
        }
        return $r;
    }

    private function stock(string $table, int $id): int
    {
        $s = self::$pdo->prepare("SELECT stock FROM $table WHERE id = ?");
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }

    // ── pure ──────────────────────────────────────────────────────────────────

    public function testOnlyProductLinesOfShopOrdersHoldStock(): void
    {
        $items = json_encode([
            ['product_id' => 5, 'quantity' => 2, 'name_bg' => 'Чаша'],
            ['product_id' => 6, 'variant_id' => 9, 'variant_label' => 'L', 'quantity' => 1, 'name_bg' => 'Тениска'],
            ['type' => 'donation', 'amount_eur' => 5],
            ['name' => 'Ръчен ред от фактура', 'quantity' => 3],   // manual invoice line: no product_id
        ]);
        $lines = order_stock_lines(['type' => 'physical', 'items' => $items]);

        $this->assertCount(2, $lines);
        $this->assertSame('Тениска (L)', $lines[1]['name']);
        $this->assertSame([], order_stock_lines(['type' => 'donation', 'items' => $items]));
    }

    // ── manual cancel / reopen ────────────────────────────────────────────────

    public function testManualCancelPutsItemsBackOnce(): void
    {
        $this->requireDb();
        $pid = $this->insertProduct(3);
        $vp  = $this->insertProduct(0);
        $vid = $this->insertVariant($vp, 1);
        $order = $this->insertOrder('confirmed', [
            ['product_id' => $pid, 'quantity' => 2],
            ['product_id' => $vp, 'variant_id' => $vid, 'quantity' => 1],
        ]);

        $r = $this->changeStatus($order, 'cancelled');
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('върнати в наличност', $r['message']);

        // Cancelling again (e.g. from the bulk action) must not add stock twice.
        $this->changeStatus($this->reload((int)$order['id']), 'cancelled');
        $this->assertFalse(order_return_stock(self::$pdo, $this->reload((int)$order['id'])));

        $this->assertSame(5, $this->stock('products', $pid));
        $this->assertSame(2, $this->stock('product_variants', $vid));
    }

    public function testReopenTakesItemsOutAgain(): void
    {
        $this->requireDb();
        $pid   = $this->insertProduct(4);
        $order = $this->insertOrder('new', [['product_id' => $pid, 'quantity' => 3]]);

        $this->changeStatus($order, 'cancelled');
        $this->assertSame(7, $this->stock('products', $pid));

        $r = $this->changeStatus($this->reload((int)$order['id']), 'confirmed');
        $this->assertTrue($r['ok']);
        $this->assertSame(4, $this->stock('products', $pid));
        $this->assertNull($this->reload((int)$order['id'])['stock_returned_at']);

        // …and cancelling once more returns them again.
        $this->changeStatus($this->reload((int)$order['id']), 'cancelled');
        $this->assertSame(7, $this->stock('products', $pid));
    }

    public function testReopenIsBlockedWhenItemsSoldOut(): void
    {
        $this->requireDb();
        $pid   = $this->insertProduct(0);
        $other = $this->insertProduct(10);
        $order = $this->insertOrder('new', [
            ['product_id' => $other, 'quantity' => 1],
            ['product_id' => $pid, 'quantity' => 2, 'name_bg' => 'Изчерпана чаша'],
        ]);
        $this->changeStatus($order, 'cancelled');
        // Someone else bought the returned items in the meantime.
        self::$pdo->prepare('UPDATE products SET stock = 1 WHERE id = ?')->execute([$pid]);

        $r = $this->changeStatus($this->reload((int)$order['id']), 'new');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Изчерпана чаша', $r['message']);
        $this->assertSame('cancelled', $this->reload((int)$order['id'])['status']);
        $this->assertSame(1, $this->stock('products', $pid));
        $this->assertSame(11, $this->stock('products', $other), 'nothing is taken when any line is short');
        $this->assertNotNull($this->reload((int)$order['id'])['stock_returned_at']);
    }

    public function testCancellingShippedOrderLeavesStockAlone(): void
    {
        $this->requireDb();
        $pid   = $this->insertProduct(2);
        $order = $this->insertOrder('shipped', [['product_id' => $pid, 'quantity' => 1]]);

        $r = $this->changeStatus($order, 'cancelled');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('вече е изпратена', $r['message']);
        $this->assertSame(2, $this->stock('products', $pid));
    }

    public function testReopeningOldCancelledOrderDoesNotTouchStock(): void
    {
        $this->requireDb();
        // Cancelled before this feature: its stock was never returned.
        $pid   = $this->insertProduct(2);
        $order = $this->insertOrder('cancelled', [['product_id' => $pid, 'quantity' => 1]]);

        $this->assertTrue($this->changeStatus($order, 'new')['ok']);
        $this->assertSame(2, $this->stock('products', $pid));
    }

    public function testDonationStatusChangesNeverTouchStock(): void
    {
        $this->requireDb();
        $order = $this->insertOrder('new', [['type' => 'donation', 'amount_eur' => 10]], 'donation');

        $r = $this->changeStatus($order, 'cancelled');

        $this->assertSame(['ok' => true, 'message' => ''], $r);
        $this->assertNull($this->reload((int)$order['id'])['stock_returned_at']);
    }
}
