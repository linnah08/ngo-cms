<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * includes/couriers/order_shipment.php — no labels for unpaid orders, and a
 * cancelled order's courier shipment is cancelled too.
 * Pure-logic tests always run; DB tests self-skip without a database.
 * No courier API is called: every test passes a capturing canceller.
 */
final class OrderShipmentTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    /** @var list<array{0:string,1:string}> */
    private array $cancelled = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/couriers/order_shipment.php';
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo || !self::$order_ids) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function canceller(bool $ok = true): callable
    {
        return function (string $courier, string $id) use ($ok): void {
            $this->cancelled[] = [$courier, $id];
            if (!$ok) throw new RuntimeException('Shipment already picked up');
        };
    }

    private function insertOrder(array $o): array
    {
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,total_eur,
                                 payment_method,payment_status,courier,speedy_shipment_id,boxnow_parcel_id,tracking_number)
             VALUES (?, 'physical', 'cancelled', 'Тест', 'ship@example.com', '[]', 10, 10, 'card', 'pending', ?, ?, ?, ?)"
        )->execute([
            'T' . substr(uniqid(), -13),
            $o['courier'],
            $o['speedy_shipment_id'] ?? null,
            $o['boxnow_parcel_id'] ?? null,
            $o['speedy_shipment_id'] ?? $o['boxnow_parcel_id'] ?? null,
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $this->reload($id);
    }

    private function reload(int $id): array
    {
        return self::$pdo->query("SELECT * FROM orders WHERE id = $id")->fetch();
    }

    // ── order_label_block_reason ──────────────────────────────────────────────

    public function testPaidOrderMayGetALabel(): void
    {
        $this->assertNull(order_label_block_reason(['status' => 'confirmed', 'payment_method' => 'card', 'payment_status' => 'paid']));
    }

    public function testCashOnDeliveryMayGetALabelBeforePayment(): void
    {
        $this->assertNull(order_label_block_reason(['status' => 'new', 'payment_method' => 'cod', 'payment_status' => 'pending']));
    }

    public function testUnpaidOnlineOrdersGetNoLabel(): void
    {
        foreach (['card', 'iris', 'bank_transfer'] as $method) {
            $reason = order_label_block_reason(['status' => 'new', 'payment_method' => $method, 'payment_status' => 'pending']);
            $this->assertStringContainsString('не е платена', (string)$reason, $method);
        }
    }

    public function testCancelledOrderGetsNoLabelEvenIfPaid(): void
    {
        $reason = order_label_block_reason(['status' => 'cancelled', 'payment_method' => 'cod', 'payment_status' => 'paid']);
        $this->assertStringContainsString('отменена', (string)$reason);
    }

    // ── order_cancel_shipment ─────────────────────────────────────────────────

    public function testOrderWithoutShipmentCallsNoCourier(): void
    {
        $pdo = $this->createStub(PDO::class);
        $this->assertNull(order_cancel_shipment($pdo, ['id' => 1, 'courier' => 'speedy', 'speedy_shipment_id' => null], $this->canceller()));
        $this->assertNull(order_cancel_shipment($pdo, ['id' => 1, 'courier' => 'econt'], $this->canceller()));
        $this->assertSame([], $this->cancelled);
    }

    public function testSpeedyShipmentIsCancelledAndCleared(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(['courier' => 'speedy', 'speedy_shipment_id' => '63748913025']);

        $this->assertNull(order_cancel_shipment(self::$pdo, $order, $this->canceller()));

        $this->assertSame([['speedy', '63748913025']], $this->cancelled);
        $fresh = $this->reload((int)$order['id']);
        $this->assertNull($fresh['speedy_shipment_id']);
        $this->assertNull($fresh['tracking_number']);
    }

    public function testBoxNowParcelIsCancelledAndCleared(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(['courier' => 'boxnow', 'boxnow_parcel_id' => '9100123']);

        $this->assertNull(order_cancel_shipment(self::$pdo, $order, $this->canceller()));

        $this->assertSame([['boxnow', '9100123']], $this->cancelled);
        $this->assertNull($this->reload((int)$order['id'])['boxnow_parcel_id']);
    }

    public function testFailedCancelKeepsTheShipmentAndSaysSo(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(['courier' => 'speedy', 'speedy_shipment_id' => '63748915218']);

        $error = order_cancel_shipment(self::$pdo, $order, $this->canceller(false));

        $this->assertStringContainsString('63748915218', (string)$error);
        $this->assertStringContainsString('ръчно', (string)$error);
        $this->assertStringNotContainsString('picked up', (string)$error);   // no raw API text for the admin
        $this->assertSame('63748915218', $this->reload((int)$order['id'])['speedy_shipment_id']);
    }
}
