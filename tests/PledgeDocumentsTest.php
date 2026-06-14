<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pledge-documents')]
final class PledgeDocumentsTest extends TestCase
{
    private PDO $pdo;
    private string $pledge_number = 'TEST-PLG-PHPUNIT';

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No test DB available.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';
        $this->pdo = get_pdo();
        try { $this->pdo->exec("ALTER TABLE orders MODIFY COLUMN type ENUM('physical','donation','ticket','pledge') NOT NULL"); } catch (Throwable) {}
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
        // Cascade: orders FK deletes documents rows too
        $this->pdo->prepare("DELETE FROM orders WHERE order_number = ? AND type = 'pledge'")
            ->execute([$this->pledge_number]);
        $this->pdo->prepare("DELETE FROM campaign_pledges WHERE pledge_number = ?")
            ->execute([$this->pledge_number]);
    }

    private function insertTestPledge(): array
    {
        $this->pdo->prepare(
            "INSERT INTO campaign_pledges (pledge_number, name, email, amount_eur, payment_status)
             VALUES (?, 'Test Donor', 'test@example.com', 50.00, 'paid')"
        )->execute([$this->pledge_number]);

        return [
            'id'             => (int)$this->pdo->lastInsertId(),
            'pledge_number'  => $this->pledge_number,
            'name'           => 'Test Donor',
            'email'          => 'test@example.com',
            'amount_eur'     => '50.00',
            'created_at'     => date('Y-m-d H:i:s'),
        ];
    }

    // ── pledge_ensure_order_row ────────────────────────────────────────────────

    public function test_ensure_order_row_creates_new_row(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        $this->assertGreaterThan(0, $order_id);

        $row = $this->pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $row->execute([$order_id]);
        $order = $row->fetch();

        $this->assertNotFalse($order);
        $this->assertSame('pledge',    $order['type']);
        $this->assertSame($this->pledge_number, $order['order_number']);
        $this->assertSame('Test Donor', $order['customer_name']);
        $this->assertSame('test@example.com', $order['customer_email']);
        $this->assertSame('50.00', $order['total_eur']);
        $this->assertSame('paid',      $order['payment_status']);
        $this->assertSame('confirmed', $order['status']);
    }

    public function test_ensure_order_row_is_idempotent(): void
    {
        $pledge = $this->insertTestPledge();
        $id1    = pledge_ensure_order_row($this->pdo, $pledge);
        $id2    = pledge_ensure_order_row($this->pdo, $pledge);

        $this->assertSame($id1, $id2);

        $stmt2 = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND type = 'pledge'");
        $stmt2->execute([$this->pledge_number]);
        $count = (int)$stmt2->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ── pledge_insert_cert_document ───────────────────────────────────────────

    public function test_insert_cert_document_creates_row(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');

        $row = $this->pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $row->execute([$order_id]);
        $doc = $row->fetch();

        $this->assertNotFalse($doc);
        $this->assertSame(42,       (int)$doc['number']);
        $this->assertSame('00042',  $doc['formatted_number']);
        $this->assertSame('documents/donation_certs/2026/00042_test.pdf', $doc['file_path']);
    }

    public function test_insert_cert_document_is_idempotent(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');
        pledge_insert_cert_document($this->pdo, $order_id, 42, '00042', 'documents/donation_certs/2026/00042_test.pdf');

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM documents WHERE order_id = ? AND type = 'donation_cert'");
        $stmt->execute([$order_id]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    // ── pledge_update_refunded_statuses ───────────────────────────────────────

    public function test_update_refunded_statuses_sets_both(): void
    {
        $pledge   = $this->insertTestPledge();
        $order_id = pledge_ensure_order_row($this->pdo, $pledge);

        pledge_update_refunded_statuses($this->pdo, $pledge['id'], $this->pledge_number);

        $p = $this->pdo->prepare('SELECT payment_status FROM campaign_pledges WHERE id = ?');
        $p->execute([$pledge['id']]);
        $this->assertSame('reversed', $p->fetchColumn());

        $o = $this->pdo->prepare("SELECT payment_status FROM orders WHERE order_number = ? AND type = 'pledge'");
        $o->execute([$this->pledge_number]);
        $this->assertSame('refunded', $o->fetchColumn());
    }
}
