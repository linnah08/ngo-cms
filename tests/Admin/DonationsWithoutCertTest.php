<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The dashboard "Дарения без сертификат" list and the Поръчки "Дарения" filter
 * must include product orders that carry a donation add-on line, not only
 * dedicated donation orders — otherwise those donors never get a certificate.
 */
#[Group('admin')]
final class DonationsWithoutCertTest extends TestCase
{
    private const PREFIX = 'TDNC-';

    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/order_view.php';
        if (!test_db_available()) {
            $this->markTestSkipped('No test DB available.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $this->pdo = get_pdo();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->prepare(
            "DELETE d FROM documents d JOIN orders o ON o.id = d.order_id WHERE o.order_number LIKE ?"
        )->execute([self::PREFIX . '%']);
        $this->pdo->prepare("DELETE FROM orders WHERE order_number LIKE ?")
            ->execute([self::PREFIX . '%']);
    }

    private function insertOrder(string $suffix, string $type, array $items, float $total, string $payment_status = 'paid'): int
    {
        $this->pdo->prepare(
            "INSERT INTO orders
             (order_number, type, status, customer_name, customer_email,
              items, subtotal_eur, total_eur, payment_method, payment_status)
             VALUES (?, ?, 'confirmed', 'Test Donor', 'donor@example.com', ?, ?, ?, 'card', ?)"
        )->execute([self::PREFIX . $suffix, $type, json_encode($items), $total, $total, $payment_status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function addCert(int $order_id): void
    {
        $this->pdo->prepare(
            "INSERT INTO documents (order_id, type, number, formatted_number, file_path)
             VALUES (?, 'donation_cert', 99998, '99998', 'documents/donation_certs/2026/test.pdf')"
        )->execute([$order_id]);
    }

    /** @return array<string, array> order_number suffix => row, test rows only */
    private function uncerted(): array
    {
        $out = [];
        foreach (orders_paid_donations_without_cert($this->pdo) as $row) {
            if (str_starts_with($row['order_number'], self::PREFIX)) {
                $out[substr($row['order_number'], strlen(self::PREFIX))] = $row;
            }
        }
        return $out;
    }

    public function test_lists_standalone_and_add_on_donations_without_cert(): void
    {
        $this->insertOrder('STANDALONE', 'donation', [], 25.0);
        $this->insertOrder('MIXED', 'physical', [
            ['type' => 'physical', 'subtotal_eur' => 30.0],
            ['type' => 'donation', 'amount_eur' => 10.0],
        ], 40.0);
        $this->insertOrder('PRODUCT', 'physical', [['type' => 'physical', 'subtotal_eur' => 30.0]], 30.0);
        $this->insertOrder('UNPAID', 'physical', [['type' => 'donation', 'amount_eur' => 5.0]], 5.0, 'pending');
        $certed = $this->insertOrder('CERTED', 'physical', [['type' => 'donation', 'amount_eur' => 7.0]], 7.0);
        $this->addCert($certed);

        $rows = $this->uncerted();

        $this->assertSame(['STANDALONE', 'MIXED'], array_keys($rows));
        $this->assertSame(25.0, $rows['STANDALONE']['donation_eur']);
        // Only the donation portion of a mixed order, not the 40 € order total.
        $this->assertSame(10.0, $rows['MIXED']['donation_eur']);
    }

    public function test_donation_filter_condition_matches_add_on_orders(): void
    {
        $this->insertOrder('STANDALONE', 'donation', [], 25.0);
        $this->insertOrder('MIXED', 'physical', [['type' => 'donation', 'amount_eur' => 10.0]], 10.0);
        $this->insertOrder('PRODUCT', 'physical', [['type' => 'physical', 'subtotal_eur' => 30.0]], 30.0);

        $stmt = $this->pdo->prepare(
            'SELECT order_number FROM orders WHERE ' . order_has_donation_sql() . ' AND order_number LIKE ? ORDER BY order_number'
        );
        $stmt->execute([self::PREFIX . '%']);

        $this->assertSame(
            [self::PREFIX . 'MIXED', self::PREFIX . 'STANDALONE'],
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}
