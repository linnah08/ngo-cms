<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('reports')]
#[Group('db')]
final class MonthlyOrdersReportTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/reports/monthly-orders.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured — set db.config.php to run DB tests.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo || empty(self::$order_ids)) return;
        $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
        self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")
                  ->execute(self::$order_ids);
    }

    private function insertOrder(array $overrides = []): int
    {
        $defaults = [
            'order_number'  => 'TEST-' . uniqid(),
            'type'          => 'physical',
            'status'        => 'confirmed',
            'customer_name' => 'Test Customer',
            'customer_email'=> 'test@example.com',
            'customer_phone'=> '',
            'delivery_type' => 'address',
            'items'         => '[]',
            'subtotal_eur'  => 10.00,
            'shipping_eur'  => 5.00,
            'total_eur'     => 15.00,
            'payment_method'=> 'card',
            'payment_status'=> 'paid',
            'lang'          => 'bg',
            'created_at'    => '2026-04-15 10:00:00',
        ];
        $d = array_merge($defaults, $overrides);

        self::$pdo->prepare(
            'INSERT INTO orders
             (order_number, type, status, customer_name, customer_email, customer_phone,
              delivery_type, items, subtotal_eur, shipping_eur, total_eur,
              payment_method, payment_status, lang, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['order_number'], $d['type'], $d['status'], $d['customer_name'],
            $d['customer_email'], $d['customer_phone'], $d['delivery_type'],
            $d['items'], $d['subtotal_eur'], $d['shipping_eur'], $d['total_eur'],
            $d['payment_method'], $d['payment_status'], $d['lang'], $d['created_at'],
        ]);

        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $id;
    }

    public function testReturnsHeaderRow(): void
    {
        $csv = generate_monthly_orders_csv(2026, 4);
        $lines = explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")));
        $this->assertStringContainsString('Order Number', $lines[0]);
        $this->assertStringContainsString('Amount (EUR)', $lines[0]);
    }

    public function testIncludesPaidOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PAID-001',
            'payment_status' => 'paid',
            'total_eur' => 20.00,
            'created_at' => '2026-04-10 09:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-PAID-001', $csv);
    }

    public function testIncludesRefundedOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-REFUND-001',
            'payment_status' => 'refunded',
            'total_eur' => 12.50,
            'created_at' => '2026-04-20 11:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-REFUND-001', $csv);
        $this->assertStringContainsString('refunded', $csv);
    }

    public function testExcludesPendingOrder(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PENDING-001',
            'payment_status' => 'pending',
            'created_at' => '2026-04-05 08:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringNotContainsString('TEST-PENDING-001', $csv);
    }

    public function testExcludesOrdersFromOtherMonth(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-MAY-001',
            'payment_status' => 'paid',
            'created_at' => '2026-05-01 00:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringNotContainsString('TEST-MAY-001', $csv);
    }

    public function testPhysicalTypeLabelledPurchase(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PHYS-001',
            'type' => 'physical',
            'payment_status' => 'paid',
            'created_at' => '2026-04-12 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-PHYS-001', $csv);

        $rows_csv = ltrim($csv, "\xEF\xBB\xBF");
        $all_rows = array_map('str_getcsv', array_filter(explode("\n", trim($rows_csv))));
        $header = array_shift($all_rows);
        $col_type = array_search('Type', $header);
        $found_row = null;
        foreach ($all_rows as $r) {
            if (isset($r[1]) && $r[1] === 'TEST-PHYS-001') { $found_row = $r; break; }
        }
        $this->assertNotNull($found_row, 'Row for TEST-PHYS-001 not found in CSV');
        $this->assertSame('purchase', $found_row[$col_type]);
    }

    public function testDonationTypeIncluded(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-DON-001',
            'type' => 'donation',
            'payment_status' => 'paid',
            'created_at' => '2026-04-14 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('TEST-DON-001', $csv);

        $rows_csv = ltrim($csv, "\xEF\xBB\xBF");
        $all_rows = array_map('str_getcsv', array_filter(explode("\n", trim($rows_csv))));
        $header = array_shift($all_rows);
        $col_type = array_search('Type', $header);
        $found_row = null;
        foreach ($all_rows as $r) {
            if (isset($r[1]) && $r[1] === 'TEST-DON-001') { $found_row = $r; break; }
        }
        $this->assertNotNull($found_row, 'Row for TEST-DON-001 not found in CSV');
        $this->assertSame('donation', $found_row[$col_type]);
    }

    public function testAmountFormattedToTwoDecimals(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-AMT-001',
            'payment_status' => 'paid',
            'total_eur' => 99.90,
            'created_at' => '2026-04-16 10:00:00',
        ]);
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringContainsString('99.90', $csv);
    }

    public function testStartsWithUtf8Bom(): void
    {
        $csv = generate_monthly_orders_csv(2026, 4);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testEmptyMonthReturnsOnlyHeader(): void
    {
        $csv = generate_monthly_orders_csv(2099, 1);
        $lines = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertCount(1, $lines);
    }
}
