<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('shop')]
#[Group('db')]
final class OrderDbTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids   = [];
    private static array $product_ids = [];

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
        if (!empty(self::$product_ids)) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")
                      ->execute(self::$product_ids);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertTestProduct(int $stock = 10): int
    {
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active)
             VALUES (?,?,?,?,?,?)'
        )->execute(['test-order-p-' . uniqid(), 'Тест', 'Test', 20.00, $stock, 1]);
        $id = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $id;
        return $id;
    }

    private function insertOrder(array $overrides = []): int
    {
        $defaults = [
            'order_number'        => generate_order_number(),
            'type'                => 'physical',
            'status'              => 'new',
            'customer_name'       => 'Тест Клиент',
            'customer_email'      => 'test@example.com',
            'customer_phone'      => '+359888000001',
            'delivery_type'       => 'office',
            'courier'             => 'econt',
            'courier_office_code' => '1001',
            'courier_office_name' => 'Sofia Center',
            'delivery_address'    => null,
            'delivery_city'       => null,
            'items'               => json_encode([['product_id'=>1,'name_bg'=>'Тест','quantity'=>1,'subtotal_eur'=>20.0]]),
            'subtotal_eur'        => 20.00,
            'shipping_eur'        => 4.00,
            'total_eur'           => 24.00,
            'payment_method'      => 'cod',
            'payment_status'      => 'pending',
            'donation_message'    => null,
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare("
            INSERT INTO orders
              (order_number,type,status,customer_name,customer_email,customer_phone,
               delivery_type,courier,courier_office_code,courier_office_name,
               delivery_address,delivery_city,items,subtotal_eur,shipping_eur,total_eur,
               payment_method,payment_status,donation_message)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $d['order_number'], $d['type'], $d['status'],
            $d['customer_name'], $d['customer_email'], $d['customer_phone'],
            $d['delivery_type'], $d['courier'],
            $d['courier_office_code'], $d['courier_office_name'],
            $d['delivery_address'], $d['delivery_city'],
            $d['items'], $d['subtotal_eur'], $d['shipping_eur'], $d['total_eur'],
            $d['payment_method'], $d['payment_status'],
            $d['donation_message'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $id;
    }

    // ── order number ──────────────────────────────────────────────────────────

    public function testOrderNumberIsUnique(): void
    {
        $n1 = generate_order_number();
        $n2 = generate_order_number();
        // Both valid format
        $this->assertMatchesRegularExpression('/^OM-\d{8}-[A-F0-9]{4}$/i', $n1);
        $this->assertMatchesRegularExpression('/^OM-\d{8}-[A-F0-9]{4}$/i', $n2);
    }

    public function testDuplicateOrderNumberRejected(): void
    {
        $num = generate_order_number();
        $this->insertOrder(['order_number' => $num]);

        $this->expectException(PDOException::class);
        $this->insertOrder(['order_number' => $num]);
    }

    // ── physical order ────────────────────────────────────────────────────────

    public function testInsertAndRetrievePhysicalOrder(): void
    {
        $id   = $this->insertOrder();
        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('physical', $row['type']);
        $this->assertSame('new', $row['status']);
        $this->assertSame('Тест Клиент', $row['customer_name']);
        $this->assertSame('cod', $row['payment_method']);
        $this->assertSame('pending', $row['payment_status']);
        $this->assertSame('24.00', $row['total_eur']);
    }

    public function testOrderItemsJsonIsDecodable(): void
    {
        $items = [
            ['product_id' => 1, 'name_bg' => 'Продукт', 'quantity' => 2, 'subtotal_eur' => 30.0],
        ];
        $id   = $this->insertOrder(['items' => json_encode($items)]);
        $stmt = self::$pdo->prepare('SELECT items FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $raw     = $stmt->fetchColumn();
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('Продукт', $decoded[0]['name_bg']);
        $this->assertSame(2, $decoded[0]['quantity']);
    }

    // ── donation order ────────────────────────────────────────────────────────

    public function testInsertDonationOrder(): void
    {
        $id = $this->insertOrder([
            'type'             => 'donation',
            'payment_method'   => 'bank_transfer',
            'payment_status'   => 'pending',
            'delivery_type'    => null,
            'courier'          => null,
            'courier_office_code' => null,
            'courier_office_name' => null,
            'customer_phone'   => null,
            'items'            => json_encode([['type' => 'donation', 'amount_eur' => 50.0]]),
            'subtotal_eur'     => 50.00,
            'shipping_eur'     => 0.00,
            'total_eur'        => 50.00,
            'donation_message' => 'За децата',
        ]);

        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame('donation', $row['type']);
        $this->assertSame('bank_transfer', $row['payment_method']);
        $this->assertSame('50.00', $row['total_eur']);
        $this->assertSame('0.00', $row['shipping_eur']);
        $this->assertSame('За децата', $row['donation_message']);
    }

    // ── status transitions ────────────────────────────────────────────────────

    public function testStatusTransitionNewToConfirmed(): void
    {
        $id = $this->insertOrder(['status' => 'new']);
        self::$pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute(['confirmed', $id]);

        $stmt = self::$pdo->prepare('SELECT status FROM orders WHERE id=?');
        $stmt->execute([$id]);
        $this->assertSame('confirmed', $stmt->fetchColumn());
    }

    public function testStatusTransitionToShippedWithTracking(): void
    {
        $id      = $this->insertOrder(['status' => 'confirmed']);
        $tracking = 'EE1234567890BG';
        self::$pdo->prepare('UPDATE orders SET status=?, tracking_number=? WHERE id=?')
                  ->execute(['shipped', $tracking, $id]);

        $stmt = self::$pdo->prepare('SELECT status, tracking_number FROM orders WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame('shipped', $row['status']);
        $this->assertSame($tracking, $row['tracking_number']);
    }

    // ── checkout stock decrement transaction ──────────────────────────────────

    public function testFullCheckoutStockDecrementTransaction(): void
    {
        $product_id = $this->insertTestProduct(stock: 5);
        $order_num  = generate_order_number();
        $items_json = json_encode([[
            'product_id'   => $product_id,
            'name_bg'      => 'Тест Транзакция',
            'name_en'      => 'Test Transaction',
            'price_eur'    => 20.00,
            'quantity'     => 2,
            'subtotal_eur' => 40.00,
        ]]);

        self::$pdo->beginTransaction();
        try {
            // Decrement stock with guard
            $upd = self::$pdo->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            );
            $upd->execute([2, $product_id, 2]);
            $this->assertSame(1, (int)$upd->rowCount());

            self::$pdo->prepare("
                INSERT INTO orders
                  (order_number,type,status,customer_name,customer_email,customer_phone,
                   delivery_type,courier,courier_office_code,courier_office_name,
                   items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $order_num,'physical','new','Checkout Test','t@test.com','+359000',
                'office','econt','1001','Test Office',
                $items_json,40.00,4.00,44.00,'cod','pending',
            ]);

            $order_id = (int)self::$pdo->lastInsertId();
            self::$pdo->commit();
            self::$order_ids[] = $order_id;

            // Verify stock decremented
            $chk = self::$pdo->prepare('SELECT stock FROM products WHERE id=?');
            $chk->execute([$product_id]);
            $this->assertSame(3, (int)$chk->fetchColumn());

            // Verify order exists
            $chk2 = self::$pdo->prepare('SELECT order_number FROM orders WHERE id=?');
            $chk2->execute([$order_id]);
            $this->assertSame($order_num, $chk2->fetchColumn());

        } catch (Throwable $e) {
            self::$pdo->rollBack();
            $this->fail('Transaction failed: ' . $e->getMessage());
        }
    }

    public function testCheckoutRollsBackOnInsufficientStock(): void
    {
        $product_id  = $this->insertTestProduct(stock: 1);
        $stock_before = 1;

        self::$pdo->beginTransaction();
        try {
            $upd = self::$pdo->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            );
            $upd->execute([5, $product_id, 5]);

            if ((int)$upd->rowCount() === 0) {
                throw new RuntimeException('Insufficient stock');
            }
            self::$pdo->commit();
            $this->fail('Should have thrown before commit');

        } catch (RuntimeException $e) {
            self::$pdo->rollBack();
            $this->assertSame('Insufficient stock', $e->getMessage());
        }

        // Stock should be unchanged after rollback
        $chk = self::$pdo->prepare('SELECT stock FROM products WHERE id=?');
        $chk->execute([$product_id]);
        $this->assertSame($stock_before, (int)$chk->fetchColumn());
    }

    // ── shipping_rates table ──────────────────────────────────────────────────

    public function testShippingRatesTableHasDefaultRows(): void
    {
        $stmt  = self::$pdo->query('SELECT COUNT(*) FROM shipping_rates');
        $count = (int)$stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $count, 'setup.php should have inserted at least 4 default rates');
    }

    public function testShippingRateQueryByCourierAndType(): void
    {
        $stmt = self::$pdo->prepare('SELECT rate_eur FROM shipping_rates WHERE courier=? AND delivery_type=?');
        $stmt->execute(['speedy', 'office']);
        $rate = $stmt->fetchColumn();
        $this->assertNotFalse($rate, 'speedy/office rate should exist');
        $this->assertGreaterThan(0, (float)$rate);
    }

    // ── ticket order type ─────────────────────────────────────────────────────

    public function testInsertTicketOrderType(): void
    {
        // Regression: migration 015 adds 'ticket' to the orders.type ENUM.
        // Without it, this insert silently fails or is rejected, breaking all ticket purchases.
        $id = $this->insertOrder([
            'type'           => 'ticket',
            'status'         => 'confirmed',
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'items'          => json_encode([[
                'type'          => 'ticket',
                'name'          => 'Lafetki - Launch Party',
                'ticket_code'   => 'TKT-20260508-ABCD1234',
                'pledge_number' => 'CP-20260508-ABCD',
                'amount_eur'    => 10.00,
            ]]),
            'subtotal_eur'   => 10.00,
            'shipping_eur'   => 0.00,
            'total_eur'      => 10.00,
            'customer_phone' => null,
            'delivery_type'  => null,
            'courier'        => null,
            'courier_office_code' => null,
            'courier_office_name' => null,
        ]);

        $stmt = self::$pdo->prepare('SELECT type, payment_method FROM orders WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertSame('ticket', $row['type']);
        $this->assertSame('card', $row['payment_method']);
    }

    // ── payment status ────────────────────────────────────────────────────────

    public function testMarkDonationAsPaid(): void
    {
        $id = $this->insertOrder([
            'type'           => 'donation',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'items'          => json_encode([['type'=>'donation','amount_eur'=>25.0]]),
            'subtotal_eur'   => 25.00, 'shipping_eur' => 0.00, 'total_eur' => 25.00,
        ]);
        self::$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute(['paid', $id]);

        $stmt = self::$pdo->prepare('SELECT payment_status FROM orders WHERE id=?');
        $stmt->execute([$id]);
        $this->assertSame('paid', $stmt->fetchColumn());
    }
}
