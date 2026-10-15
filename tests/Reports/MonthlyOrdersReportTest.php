<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('reports')]
#[Group('db')]
final class MonthlyOrdersReportTest extends TestCase
{
    private const COL_TYPE   = 2; // Вид
    private const COL_STATUS = 3; // Статус на плащане
    private const COL_AMOUNT = 4; // Сума (EUR)

    private static ?PDO $pdo = null;
    private static array $order_ids = [];

    /** All data rows (parsed) whose order-number column equals $order_number. */
    private function findRows(string $csv, string $order_number): array
    {
        $rows_csv = ltrim($csv, "\xEF\xBB\xBF");
        $all_rows = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", trim($rows_csv))));
        array_shift($all_rows); // header
        return array_values(array_filter($all_rows, fn($r) => isset($r[1]) && $r[1] === $order_number));
    }

    private function findRow(string $csv, string $order_number): ?array
    {
        return $this->findRows($csv, $order_number)[0] ?? null;
    }

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
        // One donation recipient (no site extensions file): donations read "Дарение".
        $GLOBALS['_om_site_extensions_file'] = sys_get_temp_dir() . '/no-site-ext-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
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
        $this->assertStringContainsString('Номер на поръчка', $lines[0]);
        $this->assertStringContainsString('Сума (EUR)', $lines[0]);
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
        $this->assertSame('Върната', $this->findRow($csv, 'TEST-REFUND-001')[self::COL_STATUS]);
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

        $found_row = $this->findRow($csv, 'TEST-PHYS-001');
        $this->assertNotNull($found_row, 'Row for TEST-PHYS-001 not found in CSV');
        $this->assertSame('Покупка', $found_row[self::COL_TYPE]);
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

        $found_row = $this->findRow($csv, 'TEST-DON-001');
        $this->assertNotNull($found_row, 'Row for TEST-DON-001 not found in CSV');
        $this->assertSame('Дарение', $found_row[self::COL_TYPE]);
    }

    public function testMixedOrderSplitsPurchaseAndDonationRows(): void
    {
        // Products 40 + shipping 5 + donation add-on 10 = 55 total.
        $this->insertOrder([
            'order_number' => 'TEST-MIX-001',
            'type' => 'physical',
            'subtotal_eur' => 40.00,
            'shipping_eur' => 5.00,
            'total_eur' => 55.00,
            'items' => json_encode([
                ['product_id' => 1, 'name_bg' => 'Тениска', 'price_eur' => 40.00, 'quantity' => 1, 'subtotal_eur' => 40.00],
                ['type' => 'donation', 'amount_eur' => 10.00, 'subtotal_eur' => 10.00],
            ], JSON_UNESCAPED_UNICODE),
            'payment_status' => 'paid',
            'created_at' => '2026-04-22 10:00:00',
        ]);
        $rows = $this->findRows(generate_monthly_orders_csv(2026, 4), 'TEST-MIX-001');
        $this->assertCount(2, $rows, 'Mixed order should split into two rows');
        $by_type = array_column($rows, self::COL_AMOUNT, self::COL_TYPE);
        $this->assertSame('45.00', $by_type['Покупка'] ?? null, 'Purchase row = products + shipping');
        $this->assertSame('10.00', $by_type['Дарение'] ?? null, 'Donation row = donation amount');
    }

    public function testIssuedButUnpaidInvoiceMarked(): void
    {
        $this->insertOrder([
            'order_number' => 'TEST-PROFORMA-001',
            'status' => 'new',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'paid',
            'created_at' => '2026-04-23 10:00:00',
        ]);
        $row = $this->findRow(generate_monthly_orders_csv(2026, 4), 'TEST-PROFORMA-001');
        $this->assertNotNull($row);
        $this->assertSame('Неплатена (издадена фактура)', $row[self::COL_STATUS]);
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
