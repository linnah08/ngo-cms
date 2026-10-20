<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * migrations/046_declined_orders_stay_open.sql — reopens the orders an older
 * release cancelled on a declined payment, while they can still be paid.
 *
 * Runs the migration's own statements (split exactly like migrate.php) on a
 * private connection where `orders` is a TEMPORARY table shadowing the real
 * one, so the shared dev database is never touched.
 */
final class DeclinedOrdersMigrationTest extends TestCase
{
    private ?PDO $pdo = null;

    private static function statements(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/046_declined_orders_stay_open.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No DB configured.');
        $this->pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->pdo->exec("CREATE TEMPORARY TABLE orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(30) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'physical',
            status VARCHAR(20) NOT NULL DEFAULT 'cancelled',
            payment_method VARCHAR(20) NOT NULL DEFAULT 'card',
            payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_failed_email_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            unpaid_cancelled_at DATETIME NULL,
            stock_returned_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }

    protected function tearDown(): void
    {
        $this->pdo = null;   // temporary tables go with the connection
    }

    private function insert(string $number, array $cols = []): void
    {
        $cols = ['order_number' => $number] + $cols;
        $this->pdo->prepare('INSERT INTO orders (' . implode(',', array_keys($cols)) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    }

    private function statuses(): array
    {
        return $this->pdo->query('SELECT order_number, status FROM orders')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function test_only_recent_declines_are_reopened(): void
    {
        $this->insert('declined-card');
        $this->insert('declined-iris-donation', ['type' => 'donation', 'payment_method' => 'iris']);
        $this->insert('too-old', ['created_at' => date('Y-m-d H:i:s', time() - 4 * 86400)]);
        $this->insert('auto-cancelled', ['unpaid_cancelled_at' => date('Y-m-d H:i:s')]);
        $this->insert('admin-cancelled-shop', ['stock_returned_at' => date('Y-m-d H:i:s')]);
        $this->insert('never-emailed', ['payment_failed_email_at' => null]);
        $this->insert('paid-then-cancelled', ['payment_status' => 'paid']);
        $this->insert('cash-on-delivery', ['payment_method' => 'cod']);
        $this->insert('ticket', ['type' => 'ticket']);

        foreach (self::statements() as $st) $this->pdo->exec($st);
        foreach (self::statements() as $st) $this->pdo->exec($st);   // a re-run changes nothing

        $this->assertSame([
            'declined-card'          => 'new',
            'declined-iris-donation' => 'new',
            'too-old'                => 'cancelled',
            'auto-cancelled'         => 'cancelled',
            'admin-cancelled-shop'   => 'cancelled',
            'never-emailed'          => 'cancelled',
            'paid-then-cancelled'    => 'cancelled',
            'cash-on-delivery'       => 'cancelled',
            'ticket'                 => 'cancelled',
        ], $this->statuses());
    }
}
