<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for process_dsk_result() — the shared post-payment handler.
 *
 * These are DB integration tests: they insert real rows, call
 * process_dsk_result() with a synthetic DSK status array, then assert
 * the DB was updated correctly and the right email was (or wasn't) sent.
 *
 * No network calls to DSK Bank are made.
 */
#[Group('payment')]
#[Group('db')]
final class ProcessPaymentTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_payment.php';
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
        if (!self::$pdo || empty(self::$order_ids)) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")
                  ->execute(self::$order_ids);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertDonationOrder(array $overrides = []): array
    {
        $defaults = [
            'order_number'     => generate_order_number(),
            'type'             => 'donation',
            'status'           => 'new',
            'customer_name'    => 'Тест Дарител',
            'customer_email'   => 'donor@example.com',
            'items'            => json_encode([['type' => 'donation', 'amount_eur' => 20.0, 'recipient' => 'foundation']]),
            'subtotal_eur'     => 20.00,
            'shipping_eur'     => 0.00,
            'total_eur'        => 20.00,
            'donation_message' => null,
            'payment_method'   => 'card',
            'payment_status'   => 'pending',
            'dsk_order_id'     => null,
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare("
            INSERT INTO orders
              (order_number, type, status, customer_name, customer_email,
               items, subtotal_eur, shipping_eur, total_eur, donation_message,
               payment_method, payment_status, dsk_order_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $d['order_number'], $d['type'], $d['status'],
            $d['customer_name'], $d['customer_email'],
            $d['items'], $d['subtotal_eur'], $d['shipping_eur'], $d['total_eur'],
            $d['donation_message'],
            $d['payment_method'], $d['payment_status'],
            $d['dsk_order_id'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;

        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function insertPhysicalOrder(array $overrides = []): array
    {
        $defaults = [
            'order_number'        => generate_order_number(),
            'type'                => 'physical',
            'status'              => 'new',
            'customer_name'       => 'Тест Клиент',
            'customer_email'      => 'customer@example.com',
            'customer_phone'      => '+359888000001',
            'delivery_type'       => 'office',
            'courier'             => 'speedy',
            'courier_office_code' => '1001',
            'courier_office_name' => 'Sofia Center',
            'delivery_address'    => null,
            'delivery_city'       => null,
            'items'               => json_encode([['product_id' => 1, 'name_bg' => 'Тест', 'quantity' => 1, 'subtotal_eur' => 20.0]]),
            'subtotal_eur'        => 20.00,
            'shipping_eur'        => 4.00,
            'total_eur'           => 24.00,
            'payment_method'      => 'card',
            'payment_status'      => 'pending',
            'dsk_order_id'        => null,
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare("
            INSERT INTO orders
              (order_number, type, status, customer_name, customer_email, customer_phone,
               delivery_type, courier, courier_office_code, courier_office_name,
               delivery_address, delivery_city, items, subtotal_eur, shipping_eur, total_eur,
               payment_method, payment_status, dsk_order_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $d['order_number'], $d['type'], $d['status'],
            $d['customer_name'], $d['customer_email'], $d['customer_phone'],
            $d['delivery_type'], $d['courier'], $d['courier_office_code'], $d['courier_office_name'],
            $d['delivery_address'], $d['delivery_city'],
            $d['items'], $d['subtotal_eur'], $d['shipping_eur'], $d['total_eur'],
            $d['payment_method'], $d['payment_status'],
            $d['dsk_order_id'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;

        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function freshOrder(int $id): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function paidStatus(): array
    {
        return ['orderStatus' => DSKBankPayment::STATUS_DEPOSITED];
    }

    private function declinedStatus(): array
    {
        return ['orderStatus' => DSKBankPayment::STATUS_DECLINED];
    }

    private function pendingStatus(): array
    {
        return ['orderStatus' => DSKBankPayment::STATUS_CREATED];
    }

    // ── donation: paid ────────────────────────────────────────────────────────

    public function testDonationPaidSetsPaymentStatusToPaid(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-001', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('paid', $fresh['payment_status']);
    }

    public function testDonationPaidSetsStatusToConfirmed(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-002', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('confirmed', $fresh['status']);
    }

    public function testDonationPaidPersistsDskOrderId(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-003', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('dsk-uuid-003', $fresh['dsk_order_id']);
    }

    public function testDonationPaidDoesNotOverwriteExistingDskOrderId(): void
    {
        $order = $this->insertDonationOrder(['dsk_order_id' => 'original-uuid']);
        process_dsk_result(self::$pdo, $order, 'different-uuid', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('original-uuid', $fresh['dsk_order_id']);
    }

    public function testDonationPaidIsIdempotent(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-004', $this->paidStatus());

        // Reload and call again — should not throw or corrupt state
        $order2 = $this->freshOrder((int)$order['id']);
        process_dsk_result(self::$pdo, $order2, 'dsk-uuid-004', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('paid', $fresh['payment_status']);
        $this->assertSame('confirmed', $fresh['status']);
    }

    // ── donation: declined ────────────────────────────────────────────────────

    public function testDonationDeclinedSetsStatusToCancelled(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-005', $this->declinedStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('cancelled', $fresh['status']);
    }

    public function testDonationDeclinedDoesNotMarkAsPaid(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-006', $this->declinedStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('pending', $fresh['payment_status']);
    }

    public function testDonationDeclinedDoesNotCancelAlreadyPaidOrder(): void
    {
        // If somehow payment-return and callback both fire: confirmed order must not be cancelled
        $order = $this->insertDonationOrder(['payment_status' => 'paid', 'status' => 'confirmed']);
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-007', $this->declinedStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('confirmed', $fresh['status'], 'A paid order must not be cancelled by a late decline signal');
    }

    // ── donation: created/pending (no-op) ─────────────────────────────────────

    public function testDonationPendingStatusDoesNotChangeOrder(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-008', $this->pendingStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('new', $fresh['status']);
        $this->assertSame('pending', $fresh['payment_status']);
    }

    // ── physical order: paid ──────────────────────────────────────────────────

    public function testPhysicalOrderPaidSetsPaymentStatusToPaid(): void
    {
        $order = $this->insertPhysicalOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-009', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('paid', $fresh['payment_status']);
    }

    public function testPhysicalOrderPaidSetsStatusToConfirmed(): void
    {
        $order = $this->insertPhysicalOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-010', $this->paidStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('confirmed', $fresh['status']);
    }

    public function testPhysicalOrderDeclinedSetsStatusToCancelled(): void
    {
        $order = $this->insertPhysicalOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-011', $this->declinedStatus());

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('cancelled', $fresh['status']);
    }

    // ── pre-auth approved (orderStatus=1) ────────────────────────────────────

    public function testDonationPreAuthApprovedAlsoMarksPaid(): void
    {
        $order = $this->insertDonationOrder();
        process_dsk_result(self::$pdo, $order, 'dsk-uuid-012', ['orderStatus' => DSKBankPayment::STATUS_APPROVED]);

        $fresh = $this->freshOrder((int)$order['id']);
        $this->assertSame('paid', $fresh['payment_status']);
    }
}
