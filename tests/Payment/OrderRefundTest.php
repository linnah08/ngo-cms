<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * order_return_card_payment() / order_card_payment_owed() — giving a shop
 * order's card payment back, on cancel or from the "Върни сумата" button.
 */
#[Group('payment')]
#[Group('db')]
final class OrderRefundTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/process_payment.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/order_refund.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/tests/Support/ScriptedDSK.php';
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
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
    }

    private function cancelledPaidOrder(): array
    {
        self::$pdo->prepare("
            INSERT INTO orders
              (order_number, type, status, lang, customer_name, customer_email, items,
               subtotal_eur, shipping_eur, total_eur, payment_method, payment_status, dsk_order_id)
            VALUES (?, 'physical', 'cancelled', 'bg', 'Тест Клиент', 'customer@example.com', '[]',
                    20.00, 4.00, 24.00, 'card', 'paid', 'dsk-uuid-refund')
        ")->execute([generate_order_number()]);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $this->fresh($id);
    }

    private function fresh(int $id): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testMoneyAlreadyBackMarksTheOrderReturned(): void
    {
        // OM-20261009-131C: cancelled, DSK reversed the payment, the site still said "paid".
        $order = $this->cancelledPaidOrder();
        $this->assertTrue(order_card_payment_owed($order));

        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_REVERSED]);
        $this->assertNull(order_return_card_payment(self::$pdo, $order, $dsk));

        $after = $this->fresh((int)$order['id']);
        $this->assertSame('refunded', $after['payment_status']);
        $this->assertFalse(order_card_payment_owed($after), 'the button goes away');
        $this->assertSame([], $dsk->actions(), 'no second refund for money that is already back');
    }

    public function testMoneyStillWithUsIsReturnedThenMarked(): void
    {
        $order = $this->cancelledPaidOrder();
        $dsk = new ScriptedDSK([DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_REFUNDED]);

        $this->assertNull(order_return_card_payment(self::$pdo, $order, $dsk));
        $this->assertSame('refunded', $this->fresh((int)$order['id'])['payment_status']);
        $this->assertSame(['refund.do'], $dsk->actions());
    }

    public function testBankRefusingLeavesTheOrderPaidAndSaysWhy(): void
    {
        $order = $this->cancelledPaidOrder();
        $no = ['errorCode' => '7', 'errorMessage' => 'Refund is impossible for current transaction state'];
        $dsk = new ScriptedDSK(
            [DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_DEPOSITED, DSKBankPayment::STATUS_DEPOSITED],
            ['refund.do' => $no, 'reverse.do' => $no]
        );

        $message = order_return_card_payment(self::$pdo, $order, $dsk);
        $this->assertNotNull($message);
        $this->assertStringContainsString('платено', $message);
        $this->assertSame('paid', $this->fresh((int)$order['id'])['payment_status']);
    }

    public function testButtonOnlyForCancelledCardOrdersStillMarkedPaid(): void
    {
        $owed = ['status' => 'cancelled', 'payment_status' => 'paid', 'payment_method' => 'card', 'dsk_order_id' => 'x'];
        $this->assertTrue(order_card_payment_owed($owed));
        $this->assertFalse(order_card_payment_owed(['status' => 'confirmed'] + $owed));
        $this->assertFalse(order_card_payment_owed(['payment_status' => 'refunded'] + $owed));
        $this->assertFalse(order_card_payment_owed(['payment_method' => 'iris'] + $owed));
        $this->assertFalse(order_card_payment_owed(['dsk_order_id' => null] + $owed));
    }
}
