<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
final class ProductReviewsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/product_reviews.php';
    }

    public function testAggregateEmpty(): void
    {
        $agg = product_reviews_aggregate([]);
        $this->assertSame(0, $agg['count']);
        $this->assertSame(0.0, $agg['avg']);
    }

    public function testAggregateAveragesAndRoundsToOneDecimal(): void
    {
        $rows = [['rating' => 5], ['rating' => 4], ['rating' => 4]]; // 13/3 = 4.333
        $agg  = product_reviews_aggregate($rows);
        $this->assertSame(3, $agg['count']);
        $this->assertSame(4.3, $agg['avg']);
    }

    public function testSchemaEmptyReturnsEmptyArray(): void
    {
        $this->assertSame([], product_reviews_schema([]));
    }

    public function testSchemaBuildsAggregateAndReviewNodes(): void
    {
        $rows = [
            ['author_name' => 'Ива', 'rating' => 5, 'content' => 'Чудесно', 'created_at' => '2026-06-01 10:00:00'],
            ['author_name' => 'Bob', 'rating' => 4, 'content' => 'Good',    'created_at' => '2026-06-02 11:00:00'],
        ];
        $s = product_reviews_schema($rows);

        $this->assertSame('AggregateRating', $s['aggregateRating']['@type']);
        $this->assertSame(4.5, $s['aggregateRating']['ratingValue']);
        $this->assertSame(2,   $s['aggregateRating']['reviewCount']);

        $this->assertCount(2, $s['review']);
        $this->assertSame('Review', $s['review'][0]['@type']);
        $this->assertSame('Ива',    $s['review'][0]['author']['name']);
        $this->assertSame(5,        $s['review'][0]['reviewRating']['ratingValue']);
        $this->assertSame(5,        $s['review'][0]['reviewRating']['bestRating']);
        $this->assertSame('Чудесно',$s['review'][0]['reviewBody']);
        $this->assertSame('2026-06-01', $s['review'][0]['datePublished']);
    }

    public function testSchemaLimitsReviewNodes(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['author_name' => "U$i", 'rating' => 5, 'content' => "c$i", 'created_at' => '2026-06-01 00:00:00'];
        }
        $s = product_reviews_schema($rows, 5);
        $this->assertCount(5, $s['review']);
        $this->assertSame(8, $s['aggregateRating']['reviewCount']); // count is over ALL rows
    }

    // ── DB tests ──────────────────────────────────────────────────────────────

    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $review_ids  = [];
    private static array $order_ids   = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo === null) return;
        if (self::$order_ids) {
            $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
            self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
        }
        if (self::$review_ids) {
            $in = implode(',', array_fill(0, count(self::$review_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_reviews WHERE id IN ($in)")->execute(self::$review_ids);
        }
        if (self::$product_ids) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute(self::$product_ids);
        }
    }

    private function ensurePdo(): void
    {
        if (self::$pdo === null) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
            self::$pdo = get_pdo();
        }
    }

    private function insertTestProduct(): int
    {
        $slug = 'test-review-prod-' . uniqid();
        self::$pdo->prepare(
            "INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,1)"
        )->execute([$slug, 'Тест', 'Test', 10.00, 5]);
        $pid = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $pid;
        return $pid;
    }

    private function insertOrder(string $email, int $product_id, string $status = 'new'): int
    {
        $items = json_encode([['product_id' => $product_id, 'name_bg' => 'Тест', 'quantity' => 1, 'price_eur' => 10]]);
        self::$pdo->prepare(
            "INSERT INTO orders
               (order_number,type,status,customer_name,customer_email,customer_phone,items,subtotal_eur,total_eur)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute(['T' . uniqid(), 'physical', $status, 'Buyer', $email, '+359888000000', $items, 10.00, 10.00]);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $id;
    }

    public function testFetchApprovedReturnsOnlyApproved(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();

        $pid = $this->insertTestProduct();

        foreach ([['approved', 5], ['approved', 3], ['pending', 1]] as [$st, $rt]) {
            self::$pdo->prepare(
                "INSERT INTO product_reviews (product_id,lang,author_name,rating,content,status) VALUES (?,?,?,?,?,?)"
            )->execute([$pid, 'bg', 'Tester', $rt, 'body', $st]);
            self::$review_ids[] = (int)self::$pdo->lastInsertId();
        }

        $rows = product_reviews_fetch_approved(self::$pdo, $pid);
        $this->assertCount(2, $rows);
        $agg = product_reviews_aggregate($rows);
        $this->assertSame(4.0, $agg['avg']);
    }

    public function testFetchApprovedIncludesVerifiedPurchaseColumn(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();

        $pid = $this->insertTestProduct();

        self::$pdo->prepare(
            "INSERT INTO product_reviews (product_id,lang,author_name,author_email,rating,content,status,verified_purchase) VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$pid, 'bg', 'Tester', 't@example.com', 5, 'body', 'approved', 1]);
        self::$review_ids[] = (int)self::$pdo->lastInsertId();

        $rows = product_reviews_fetch_approved(self::$pdo, $pid);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['verified_purchase']);
    }

    // ── product_review_is_verified_purchase (DB-gated) ────────────────────────

    public function testVerifiedPurchaseTrueForMatchingNonCancelledOrder(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();

        $pid   = $this->insertTestProduct();
        $email = 'buyer-' . uniqid() . '@example.com';
        $this->insertOrder($email, $pid, 'new');

        $this->assertTrue(product_review_is_verified_purchase(self::$pdo, $email, $pid));
    }

    public function testVerifiedPurchaseFalseForCancelledOrder(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();

        $pid   = $this->insertTestProduct();
        $email = 'cancelled-buyer-' . uniqid() . '@example.com';
        $this->insertOrder($email, $pid, 'cancelled');

        $this->assertFalse(product_review_is_verified_purchase(self::$pdo, $email, $pid));
    }

    public function testVerifiedPurchaseFalseForNoMatchingOrder(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();
        $this->assertFalse(product_review_is_verified_purchase(self::$pdo, 'nobody-' . uniqid() . '@example.com', 999999));
    }

    public function testVerifiedPurchaseFalseForEmptyEmailOrProductId(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured.');
        }
        $this->ensurePdo();
        $this->assertFalse(product_review_is_verified_purchase(self::$pdo, '', 1));
        $this->assertFalse(product_review_is_verified_purchase(self::$pdo, 'someone@example.com', 0));
    }
}
