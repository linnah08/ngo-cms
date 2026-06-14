<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class DonationCertEmailedAtTest extends TestCase
{
    private PDO $pdo;
    private int $order_id = 0;

    protected function setUp(): void
    {
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
        $this->pdo->prepare("DELETE FROM orders WHERE order_number = 'TEST-CERT-EMAILEDAT'")
            ->execute();
    }

    private function insertTestOrder(): int
    {
        $this->pdo->prepare(
            "INSERT INTO orders
             (order_number, type, status, customer_name, customer_email,
              items, subtotal_eur, total_eur, payment_method, payment_status)
             VALUES ('TEST-CERT-EMAILEDAT', 'donation', 'confirmed',
                     'Test Donor', 'donor@example.com',
                     '[]', 25.00, 25.00, 'card', 'paid')"
        )->execute();
        return (int) $this->pdo->lastInsertId();
    }

    private function insertTestDocument(int $order_id): int
    {
        $this->pdo->prepare(
            "INSERT INTO documents
             (order_id, type, number, formatted_number, file_path)
             VALUES (?, 'donation_cert', 99999, '99999', 'documents/donation_certs/2026/test.pdf')"
        )->execute([$order_id]);
        return (int) $this->pdo->lastInsertId();
    }

    public function test_emailed_at_column_exists(): void
    {
        $col = $this->pdo->query("SHOW COLUMNS FROM documents LIKE 'emailed_at'")->fetch();
        $this->assertNotFalse($col, 'documents.emailed_at column must exist after migration 023');
    }

    public function test_emailed_at_is_null_by_default(): void
    {
        $order_id = $this->insertTestOrder();
        $doc_id   = $this->insertTestDocument($order_id);

        $row = $this->pdo->prepare('SELECT emailed_at FROM documents WHERE id = ?');
        $row->execute([$doc_id]);
        $val = $row->fetchColumn();

        $this->assertNull($val, 'emailed_at must be NULL before an email is sent');
    }

    public function test_emailed_at_can_be_set(): void
    {
        $order_id = $this->insertTestOrder();
        $doc_id   = $this->insertTestDocument($order_id);

        $this->pdo->prepare('UPDATE documents SET emailed_at = NOW() WHERE id = ?')
            ->execute([$doc_id]);

        $row = $this->pdo->prepare('SELECT emailed_at FROM documents WHERE id = ?');
        $row->execute([$doc_id]);
        $val = $row->fetchColumn();

        $this->assertNotNull($val, 'emailed_at must be set after UPDATE');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $val, 'emailed_at must be a datetime string');
    }
}
