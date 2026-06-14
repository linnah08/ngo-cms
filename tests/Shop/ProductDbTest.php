<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('db')]
final class ProductDbTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $created_ids = [];
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured (db.config.php missing or unreachable).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (!empty(self::$order_ids)) {
            $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
            self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")
                      ->execute(self::$order_ids);
        }
        if (!empty(self::$created_ids)) {
            $in = implode(',', array_fill(0, count(self::$created_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")
                      ->execute(self::$created_ids);
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                      ->execute(self::$created_ids);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $defaults = [
            'slug'           => 'test-product-' . uniqid(),
            'name_bg'        => 'Тест Продукт',
            'name_en'        => 'Test Product',
            'description_bg' => 'Описание BG',
            'description_en' => 'Description EN',
            'price_eur'      => 15.00,
            'stock'          => 10,
            'active'         => 1,
            'image'          => null,
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,description_bg,description_en,price_eur,stock,active,image)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['slug'], $d['name_bg'], $d['name_en'],
            $d['description_bg'], $d['description_en'],
            $d['price_eur'], $d['stock'], $d['active'], $d['image'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$created_ids[] = $id;
        return $id;
    }

    private function insertOrder(int $product_id): int
    {
        $items = json_encode([[
            'product_id' => $product_id,
            'name'       => 'Test Product',
            'quantity'   => 1,
            'price_eur'  => 5.00,
        ]]);
        self::$pdo->prepare(
            "INSERT INTO orders
                 (order_number, type, status, customer_name, customer_email,
                  items, subtotal_eur, shipping_eur, total_eur)
             VALUES (?, 'physical', 'new', 'Test', 'test@test.com', ?, 5.00, 0.00, 5.00)"
        )->execute(['T' . uniqid(), $items]);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $id;
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testInsertAndRetrieveProduct(): void
    {
        $id   = $this->insertProduct(['name_bg' => 'Тест Вмъкване', 'price_eur' => 29.99]);
        $stmt = self::$pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('Тест Вмъкване', $row['name_bg']);
        $this->assertSame('29.99', $row['price_eur']);
        $this->assertSame(1, (int)$row['active']);
    }

    public function testRetrieveProductBySlug(): void
    {
        $slug = 'test-slug-' . uniqid();
        $this->insertProduct(['slug' => $slug, 'name_bg' => 'Намери по Slug']);

        $stmt = self::$pdo->prepare('SELECT id, name_bg FROM products WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('Намери по Slug', $row['name_bg']);
    }

    public function testSlugIsUnique(): void
    {
        $slug = 'test-unique-' . uniqid();
        $this->insertProduct(['slug' => $slug]);

        $this->expectException(PDOException::class);
        // Second insert with same slug should throw UNIQUE constraint violation
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,?)'
        )->execute([$slug, 'Another', '', 5.00, 1, 1]);
    }

    public function testToggleActiveStatus(): void
    {
        $id = $this->insertProduct(['active' => 1]);

        self::$pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([$id]);
        $stmt = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn());

        // Toggle back
        self::$pdo->prepare('UPDATE products SET active = 1 - active WHERE id = ?')->execute([$id]);
        $stmt->execute([$id]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testSoftDelete(): void
    {
        $id = $this->insertProduct(['active' => 1]);
        self::$pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);

        // Should not appear in active product query
        $stmt = self::$pdo->prepare('SELECT id FROM products WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $this->assertFalse($stmt->fetch(), 'Soft-deleted product should not appear in active=1 query');
    }

    public function testStockDecrementOnOrder(): void
    {
        $id = $this->insertProduct(['stock' => 5]);

        // Simulate the checkout stock decrement
        $stmt = self::$pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([2, $id, 2]);
        $this->assertSame(1, (int)$stmt->rowCount(), 'UPDATE should affect 1 row');

        $check = self::$pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $check->execute([$id]);
        $this->assertSame(3, (int)$check->fetchColumn());
    }

    public function testStockDecrementFailsWhenInsufficientStock(): void
    {
        $id = $this->insertProduct(['stock' => 1]);

        // Try to decrement by more than available
        $stmt = self::$pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([5, $id, 5]);
        $this->assertSame(0, (int)$stmt->rowCount(), 'UPDATE should affect 0 rows — insufficient stock');

        // Stock should be unchanged
        $check = self::$pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $check->execute([$id]);
        $this->assertSame(1, (int)$check->fetchColumn());
    }

    public function testActiveProductsQuery(): void
    {
        $active_id   = $this->insertProduct(['active' => 1, 'slug' => 'test-active-' . uniqid()]);
        $inactive_id = $this->insertProduct(['active' => 0, 'slug' => 'test-inactive-' . uniqid()]);

        $stmt = self::$pdo->query('SELECT id FROM products WHERE active = 1');
        $ids  = array_column($stmt->fetchAll(), 'id');
        $ids  = array_map('intval', $ids);

        $this->assertContains($active_id, $ids);
        $this->assertNotContains($inactive_id, $ids);
    }

    public function testBulkToggleAllActiveDeactivatesAll(): void
    {
        $id1 = $this->insertProduct(['active' => 1]);
        $id2 = $this->insertProduct(['active' => 1]);
        $ids = [$id1, $id2];

        // All active → detect → set active = 0
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in) AND active = 1");
        $stmt->execute($ids);
        $activeCount = (int)$stmt->fetchColumn();
        $newActive = ($activeCount === count($ids)) ? 0 : 1;

        self::$pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")
                  ->execute(array_merge([$newActive], $ids));

        foreach ($ids as $id) {
            $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
            $check->execute([$id]);
            $this->assertSame(0, (int)$check->fetchColumn(), "Product $id should be inactive");
        }
    }

    public function testBulkToggleMixedActivatesAll(): void
    {
        $id1 = $this->insertProduct(['active' => 1]);
        $id2 = $this->insertProduct(['active' => 0]);
        $ids = [$id1, $id2];

        // Mixed → detect → set active = 1
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in) AND active = 1");
        $stmt->execute($ids);
        $activeCount = (int)$stmt->fetchColumn();
        $newActive = ($activeCount === count($ids)) ? 0 : 1;

        self::$pdo->prepare("UPDATE products SET active = ? WHERE id IN ($in)")
                  ->execute(array_merge([$newActive], $ids));

        foreach ($ids as $id) {
            $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
            $check->execute([$id]);
            $this->assertSame(1, (int)$check->fetchColumn(), "Product $id should be active");
        }
    }

    public function testBulkDeleteSoftDeletesAll(): void
    {
        $id1 = $this->insertProduct(['active' => 1]);
        $id2 = $this->insertProduct(['active' => 1]);
        $ids = [$id1, $id2];

        $in = implode(',', array_fill(0, count($ids), '?'));
        self::$pdo->prepare("UPDATE products SET active = 0 WHERE id IN ($in)")
                  ->execute($ids);

        foreach ($ids as $id) {
            $check = self::$pdo->prepare('SELECT active FROM products WHERE id = ?');
            $check->execute([$id]);
            $this->assertSame(0, (int)$check->fetchColumn(), "Product $id should be soft-deleted");
        }
    }

    public function testBulkHardDeleteRemovesProduct(): void
    {
        $id = $this->insertProduct(['active' => 0]);

        self::$pdo->prepare('DELETE FROM product_variants WHERE product_id IN (?)')->execute([$id]);
        self::$pdo->prepare('DELETE FROM products WHERE id IN (?)')->execute([$id]);

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), "Product $id should be hard-deleted");

        self::$created_ids = array_values(array_filter(self::$created_ids, fn($i) => $i !== $id));
    }

    public function testBulkHardDeleteAlsoRemovesVariants(): void
    {
        $id = $this->insertProduct(['active' => 0]);
        self::$pdo->prepare(
            "INSERT INTO product_variants (product_id, label_bg, label_en, attributes, stock, active, sort_order)
             VALUES (?, 'Тест', 'Test', '[]', 5, 1, 0)"
        )->execute([$id]);

        self::$pdo->prepare('DELETE FROM product_variants WHERE product_id IN (?)')->execute([$id]);
        self::$pdo->prepare('DELETE FROM products WHERE id IN (?)')->execute([$id]);

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), "Product $id should be hard-deleted");

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), "Variants for product $id should also be deleted");

        self::$created_ids = array_values(array_filter(self::$created_ids, fn($i) => $i !== $id));
    }

    public function testBulkHardDeleteDetectsOrderReference(): void
    {
        $id = $this->insertProduct(['active' => 0]);
        $this->insertOrder($id);

        // PHP-side detection: fetch all order items and scan every element
        $stmt = self::$pdo->query('SELECT items FROM orders');
        $found = false;
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $items = json_decode($row[0], true) ?: [];
            foreach ($items as $item) {
                if ((int)($item['product_id'] ?? 0) === $id) {
                    $found = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($found, "Product $id should be detected as having orders via PHP-side item scan");
    }
}
