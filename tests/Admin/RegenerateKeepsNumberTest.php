<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regenerating an issued document keeps its number (oddminds 475bcad): a new
 * number would leave a gap in the legal numbering of invoices and receipts.
 */
final class RegenerateKeepsNumberTest extends TestCase
{
    public function test_regenerating_a_receipt_keeps_its_number(): void
    {
        if (!test_db_available()) $this->markTestSkipped('DB not available.');
        $pdo = get_pdo();
        $num = 'PHPUNIT-REGEN-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO orders (order_number,type,status,customer_name,customer_email,customer_phone,
                         delivery_type,courier,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
                       VALUES (?,'physical','confirmed','Тест Тестов','regen@example.test','+359888123456',
                         'office','speedy',?,10,0,10,'card','paid')")
            ->execute([$num, json_encode([['type' => 'physical', 'name_bg' => 'Тест', 'qty' => 1, 'price_eur' => 10, 'subtotal_eur' => 10]])]);
        $order_id = (int) $pdo->lastInsertId();
        $files  = [];
        $before = (int) $pdo->query("SELECT last_number FROM document_sequences WHERE type = 'receipt'")->fetchColumn();
        try {
            run_admin_page('admin/generate-document.php', ['order_id' => $order_id, 'doc_type' => 'receipt']);
            $first = $pdo->query("SELECT number, formatted_number, file_path FROM documents WHERE order_id = $order_id AND type = 'receipt'")->fetch();
            $this->assertNotFalse($first, 'the receipt was issued');
            $files[] = $first['file_path'];

            run_admin_page('admin/generate-document.php', ['order_id' => $order_id, 'doc_type' => 'receipt']);
            $second = $pdo->query("SELECT number, formatted_number, file_path FROM documents WHERE order_id = $order_id AND type = 'receipt'")->fetch();
            $files[] = $second['file_path'];

            $this->assertSame($first['number'], $second['number'], 'regenerating keeps the number');
            $this->assertSame($first['formatted_number'], $second['formatted_number']);
            $this->assertSame($before + 1, (int) $pdo->query("SELECT last_number FROM document_sequences WHERE type = 'receipt'")->fetchColumn(),
                'the counter moved once, not twice');
        } finally {
            // Leave the counter where it was, so other tests' numbering is undisturbed.
            $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'receipt'")->execute([$before]);
            $pdo->prepare('DELETE FROM documents WHERE order_id = ?')->execute([$order_id]);
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$order_id]);
            foreach (array_unique(array_filter($files)) as $f) @unlink(dirname(__DIR__, 2) . '/' . $f);
        }
    }
}
