<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('db')]
final class ProductVariantsDbTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $variant_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (!empty(self::$variant_ids)) {
            $in = implode(',', array_fill(0, count(self::$variant_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE id IN ($in)")
                      ->execute(self::$variant_ids);
        }
        if (!empty(self::$product_ids)) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                      ->execute(self::$product_ids);
        }
    }

    private function insertVariantProduct(array $attrs = ['Вид', 'Аромат']): int
    {
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,variant_attributes)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            'test-variant-' . uniqid(), 'Тест Сапун', 'Test Soap',
            8.00, 0, 1, 'variant', json_encode($attrs),
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $id;
        return $id;
    }

    private function insertVariant(int $product_id, array $overrides = []): int
    {
        $d = array_merge([
            'label_bg'   => 'Лавандула',
            'label_en'   => 'Lavender',
            'attributes' => ['Вид' => 'лилав', 'Аромат' => 'лавандула'],
            'image'      => 'upload-abc123.jpg',
            'stock'      => 10,
            'active'     => 1,
            'sort_order' => 0,
        ], $overrides);
        $images = $d['images'] ?? ($d['image'] !== '' ? [$d['image']] : []);
        self::$pdo->prepare(
            'INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,images,stock,active,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $product_id, $d['label_bg'], $d['label_en'],
            json_encode($d['attributes'], JSON_UNESCAPED_UNICODE),
            $d['image'], json_encode($images, JSON_UNESCAPED_UNICODE),
            $d['stock'], $d['active'], $d['sort_order'],
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$variant_ids[] = $id;
        return $id;
    }

    public function testInsertAndRetrieveVariant(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid);

        $stmt = self::$pdo->prepare('SELECT * FROM product_variants WHERE id = ?');
        $stmt->execute([$vid]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($pid, (int)$row['product_id']);
        $this->assertSame('Лавандула', $row['label_bg']);
        $this->assertSame(10, (int)$row['stock']);

        $attrs = json_decode($row['attributes'], true);
        $this->assertSame('лилав', $attrs['Вид']);
        $this->assertSame('лавандула', $attrs['Аромат']);
    }

    public function testVariantBelongsToProduct(): void
    {
        $pid = $this->insertVariantProduct();
        $this->insertVariant($pid, ['label_bg' => 'Портокал', 'stock' => 5]);
        $this->insertVariant($pid, ['label_bg' => 'Роза',     'stock' => 0]);

        $stmt = self::$pdo->prepare(
            'SELECT label_bg, stock FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order'
        );
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();

        $this->assertCount(2, $rows);
        $this->assertSame('Портокал', $rows[0]['label_bg']);
    }

    public function testStockDecrementOnVariant(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, ['stock' => 8]);

        self::$pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        )->execute([3, $vid, 3]);

        $check = self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?');
        $check->execute([$vid]);
        $this->assertSame(5, (int)$check->fetchColumn());
    }

    public function testStockDecrementFailsWhenInsufficient(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, ['stock' => 2]);

        $stmt = self::$pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([5, $vid, 5]);
        $this->assertSame(0, (int)$stmt->rowCount());

        $check = self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?');
        $check->execute([$vid]);
        $this->assertSame(2, (int)$check->fetchColumn());
    }

    public function testVariantTypeProductHasVariantAttributes(): void
    {
        $pid = $this->insertVariantProduct(['Вид', 'Аромат']);

        $stmt = self::$pdo->prepare('SELECT `type`, variant_attributes FROM products WHERE id = ?');
        $stmt->execute([$pid]);
        $row = $stmt->fetch();

        $this->assertSame('variant', $row['type']);
        $attrs = json_decode($row['variant_attributes'], true);
        $this->assertSame(['Вид', 'Аромат'], $attrs);
    }

    public function testInsertAndRetrieveMultipleImages(): void
    {
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, [
            'image'  => 'b.jpg',                 // chosen primary (not first)
            'images' => ['a.jpg', 'b.jpg', 'c.jpg'],
        ]);

        $stmt = self::$pdo->prepare('SELECT image, images FROM product_variants WHERE id = ?');
        $stmt->execute([$vid]);
        $row = $stmt->fetch();

        $this->assertSame('b.jpg', $row['image']); // primary persisted
        $gallery = variant_gallery($row);
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], $gallery);   // order preserved
        $this->assertContains($row['image'], $gallery);            // primary is a member
    }

    public function testSingleImageBackfillsGallery(): void
    {
        // A variant saved with only a primary image still yields a one-photo gallery.
        $pid = $this->insertVariantProduct();
        $vid = $this->insertVariant($pid, ['image' => 'solo.jpg', 'images' => ['solo.jpg']]);

        $stmt = self::$pdo->prepare('SELECT image, images FROM product_variants WHERE id = ?');
        $stmt->execute([$vid]);
        $row = $stmt->fetch();

        $this->assertSame(['solo.jpg'], variant_gallery($row));
    }

    public function testFirstActiveVariantImageQuery(): void
    {
        $pid = $this->insertVariantProduct();
        $this->insertVariant($pid, ['image' => 'first.jpg', 'sort_order' => 0]);
        $this->insertVariant($pid, ['image' => 'second.jpg', 'sort_order' => 1]);

        $stmt = self::$pdo->prepare(
            'SELECT image FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order ASC LIMIT 1'
        );
        $stmt->execute([$pid]);
        $this->assertSame('first.jpg', $stmt->fetchColumn());
    }
}
