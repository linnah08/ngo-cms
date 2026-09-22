<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('order-email-history')]
final class OrderEmailHistoryTest extends TestCase
{
    private const NUM = 'TEST-OEH-PHPUNIT';

    public function test_kind_label_maps_known_keys_and_never_shows_raw_codes(): void
    {
        $this->assertSame('Поръчката е изпратена', order_email_kind_label('order-shipped-customer'));
        $this->assertSame('Ръчно съобщение', order_email_kind_label(null));
        $this->assertSame('Ръчно съобщение', order_email_kind_label(''));
        $this->assertSame('Имейл', order_email_kind_label('some-unknown-key'));
    }

    public function test_log_ignores_missing_order_id(): void
    {
        order_email_log(0, 'a@b.bg', 'Тема', '<p>x</p>', null, true);
        $this->addToAssertionCount(1); // must not throw
    }

    public function test_log_stores_sent_and_failed_rows(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No test DB available.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pledge_documents.php';
        $pdo = get_pdo();
        $pdo->prepare("DELETE FROM orders WHERE order_number = ? AND type = 'pledge'")->execute([self::NUM]);

        $oid = pledge_ensure_order_row($pdo, [
            'pledge_number' => self::NUM, 'name' => 'Тест', 'email' => 't@example.com',
            'amount_eur' => 10, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            order_email_log($oid, 't@example.com', 'Потвърждение', '<p>ok</p>', 'order-confirmation-customer', true);
            order_email_log($oid, 't@example.com', 'Изпратена', '<p>ship</p>', 'order-shipped-customer', false, 'Мария', 5);

            $rows = $pdo->prepare('SELECT * FROM order_emails WHERE order_id = ? ORDER BY id');
            $rows->execute([$oid]);
            $rows = $rows->fetchAll();

            $this->assertCount(2, $rows);
            $this->assertSame('sent', $rows[0]['status']);
            $this->assertNull($rows[0]['sent_by_name']); // automatic
            $this->assertSame('failed', $rows[1]['status']);
            $this->assertSame('Мария', $rows[1]['sent_by_name']);
            $this->assertSame('order-shipped-customer', $rows[1]['template_key']);
        } finally {
            $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$oid]); // cascades order_emails
        }
    }
}
