<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stock kept by another site — includes/shared_stock.php.
 *
 * The "other site" here is the test database itself: a local item is pointed at
 * a second row in the same tables, which is exactly the cross-database query the
 * live sites run, with the database name being this one.
 */
#[Group('shop')]
final class SharedStockTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $order_ids   = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/shared_stock.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_stock.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sumup.php';
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        unset($GLOBALS['_shared_stock_config']);
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

    protected function tearDown(): void
    {
        unset($GLOBALS['_shared_stock_config']);
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function product(int $stock = 0): int
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,?)')
            ->execute(['test-shared-' . uniqid(), 'Лафетки', 'Lafetki', 12.00, $stock, 1]);
        return self::$product_ids[] = (int)self::$pdo->lastInsertId();
    }

    private function variant(int $product_id, int $stock): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$product_id, 'Ледоразбивачи', 'Icebreakers', '{}', $stock, 1, 0]);
        return (int)self::$pdo->lastInsertId();
    }

    private function stock(string $table, int $id): int
    {
        $s = self::$pdo->prepare("SELECT stock FROM `$table` WHERE id = ?");
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }

    /** A local variant pointed at a "home" variant; returns [local product, local variant, home variant]. */
    private function sharedVariant(int $local_stock, int $home_stock, string $db = DB_NAME): array
    {
        $home_product = $this->product();
        $home_variant = $this->variant($home_product, $home_stock);
        $local_product = $this->product();
        $local_variant = $this->variant($local_product, $local_stock);
        $GLOBALS['_shared_stock_config'] = ['db' => $db, 'name' => 'oddminds.org', 'variants' => [$local_variant => $home_variant]];
        return [$local_product, $local_variant, $home_variant];
    }

    public function testConfigNeedsAPlainDatabaseName(): void
    {
        $GLOBALS['_shared_stock_config'] = ['db' => 'x`; DROP TABLE orders; --', 'variants' => [1 => 2]];
        $this->assertNull(shared_stock_config());
        $GLOBALS['_shared_stock_config'] = ['db' => 'site_db', 'variants' => ['3' => '7', 0 => 5, 4 => -1]];
        $this->assertSame([3 => ['table' => 'product_variants', 'id' => 7]], shared_stock_config()['variants']);
    }

    public function testAProductHereCanShareAVariantsStockThere(): void
    {
        $this->requireDb();
        $home_product = $this->product();
        $home_variant = $this->variant($home_product, 9);
        $local = $this->product(0);
        $GLOBALS['_shared_stock_config'] = ['db' => DB_NAME, 'products' => [$local => ['variant' => $home_variant]]];

        shared_stock_sync(self::$pdo);
        $this->assertSame(9, $this->stock('products', $local));
        $this->assertTrue(stock_move(self::$pdo, $local, null, -4, 'guard'));
        $this->assertSame(5, $this->stock('product_variants', $home_variant));
        $this->assertSame(5, $this->stock('products', $local));
        $this->assertFalse(stock_move(self::$pdo, $local, null, -6, 'guard'));
    }

    public function testASaleComesOffTheOtherSitesCountAndIsCopiedHere(): void
    {
        $this->requireDb();
        [$product, $local, $home] = $this->sharedVariant(local_stock: 99, home_stock: 5);

        $this->assertTrue(stock_move(self::$pdo, $product, $local, -3, 'guard'));
        $this->assertSame(2, $this->stock('product_variants', $home));
        $this->assertSame(2, $this->stock('product_variants', $local), 'the local row is a copy of the real count');
    }

    public function testASaleTheOtherSiteCanNoLongerCoverIsRefusedEvenWhenTheLocalCopyIsStale(): void
    {
        $this->requireDb();
        [$product, $local, $home] = $this->sharedVariant(local_stock: 10, home_stock: 1);

        $this->assertFalse(stock_move(self::$pdo, $product, $local, -2, 'guard'));
        $this->assertSame(1, $this->stock('product_variants', $home));
        $this->assertSame(1, $this->stock('product_variants', $local), 'the stale copy is corrected');
    }

    public function testSyncCopiesTheRealCountHere(): void
    {
        $this->requireDb();
        [, $local, ] = $this->sharedVariant(local_stock: 0, home_stock: 7);

        shared_stock_sync(self::$pdo);
        $this->assertSame(7, $this->stock('product_variants', $local));
    }

    public function testWhenTheOtherSiteCantBeReachedSharedItemsAreSoldOutNotOversold(): void
    {
        $this->requireDb();
        [$product, $local, ] = $this->sharedVariant(local_stock: 8, home_stock: 8, db: 'no_such_db_' . bin2hex(random_bytes(3)));

        shared_stock_sync(self::$pdo);
        $this->assertSame(0, $this->stock('product_variants', $local));
        $this->assertFalse(stock_move(self::$pdo, $product, $local, -1, 'guard'));
    }

    public function testAnItemOfThisSitesOwnIsUntouchedByTheSetting(): void
    {
        $this->requireDb();
        $this->sharedVariant(local_stock: 0, home_stock: 0);
        $own_product = $this->product();
        $own = $this->variant($own_product, 4);

        $this->assertTrue(stock_move(self::$pdo, $own_product, $own, -4, 'guard'));
        $this->assertFalse(stock_move(self::$pdo, $own_product, $own, -1, 'guard'));
        $this->assertSame(0, $this->stock('product_variants', $own));
    }

    public function testASimpleProductCanBeSharedToo(): void
    {
        $this->requireDb();
        $home = $this->product(6);
        $local = $this->product(0);
        $GLOBALS['_shared_stock_config'] = ['db' => DB_NAME, 'products' => [$local => $home]];

        $this->assertTrue(stock_move(self::$pdo, $local, null, -2, 'guard'));
        $this->assertSame(4, $this->stock('products', $home));
        $this->assertSame(4, $this->stock('products', $local));
    }

    public function testACancelledOrderPutsItsItemsBackOnTheOtherSite(): void
    {
        $this->requireDb();
        [$product, $local, $home] = $this->sharedVariant(local_stock: 3, home_stock: 3);
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute(['T' . substr(uniqid(), -13), 'physical', 'cancelled', 'Тест', 't@example.com',
            json_encode([['product_id' => $product, 'variant_id' => $local, 'quantity' => 2, 'name_bg' => 'Лафетки']]), 24, 0, 24, 'card', 'pending']);
        $id = self::$order_ids[] = (int)self::$pdo->lastInsertId();
        $order = self::$pdo->query("SELECT * FROM orders WHERE id = $id")->fetch();

        $this->assertTrue(order_return_stock(self::$pdo, $order));
        $this->assertSame(5, $this->stock('product_variants', $home));
        $this->assertSame(5, $this->stock('product_variants', $local));

        $order = self::$pdo->query("SELECT * FROM orders WHERE id = $id")->fetch();
        $this->assertSame([], order_take_stock_again(self::$pdo, $order));
        $this->assertSame(3, $this->stock('product_variants', $home));
    }

    public function testAStallSaleStopsAtZeroOnTheOtherSite(): void
    {
        $this->requireDb();
        [$product, $local, $home] = $this->sharedVariant(local_stock: 1, home_stock: 1);

        sumup_stock_move(self::$pdo, $product, $local, -3);
        $this->assertSame(0, $this->stock('product_variants', $home));
    }

    public function testTheProductEditorPassesOnItsChangeNotItsNumber(): void
    {
        $this->requireDb();
        [$product, $local, $home] = $this->sharedVariant(local_stock: 10, home_stock: 10);

        $before = shared_stock_snapshot(self::$pdo, $product);
        $this->assertSame([$local => 10], $before);

        // While the admin edits, the other site sells 2; the admin adds 5 to the 10 they see.
        self::$pdo->prepare('UPDATE product_variants SET stock = stock - 2 WHERE id = ?')->execute([$home]);
        self::$pdo->prepare('UPDATE product_variants SET stock = 15 WHERE id = ?')->execute([$local]);

        $this->assertTrue(shared_stock_after_edit(self::$pdo, $product, $before));
        $this->assertSame(13, $this->stock('product_variants', $home), 'the sale made meanwhile is kept');
        $this->assertSame(13, $this->stock('product_variants', $local));
    }
}
