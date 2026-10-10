<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * migrations/043_events.sql — the carry-over of tickets sold through the
 * campaign into an event.
 *
 * Runs the migration's own statements (split exactly like migrate.php) on a
 * private connection where `events` and `campaign_pledges` are TEMPORARY
 * tables shadowing the real ones, so the shared dev database is never touched.
 */
#[Group('events')]
final class EventsMigrationTest extends TestCase
{
    private ?PDO $pdo = null;

    private static function statements(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/043_events.sql');
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
        // The pre-043 shape of the columns the migration reads.
        $this->pdo->exec("CREATE TEMPORARY TABLE campaign_pledges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pledge_number VARCHAR(30) NOT NULL,
            pledge_type ENUM('donation','ticket') NOT NULL DEFAULT 'donation',
            payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
            ticket_qty TINYINT UNSIGNED NOT NULL DEFAULT 1
        )");
    }

    protected function tearDown(): void
    {
        $this->pdo = null;   // temporary tables go with the connection
    }

    private function migrate(): void
    {
        foreach (self::statements() as $i => $st) {
            if ($i === 0) {
                $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS events', $st);
                $st = preg_replace('/^CREATE TABLE IF NOT EXISTS/', 'CREATE TEMPORARY TABLE', $st);
            }
            $this->pdo->exec($st);
        }
    }

    public function test_sold_tickets_get_one_event_and_are_linked_to_it(): void
    {
        $this->pdo->exec("INSERT INTO campaign_pledges (pledge_number, pledge_type, payment_status, ticket_qty) VALUES
            ('CP-1', 'ticket', 'paid', 2), ('CP-2', 'ticket', 'pending', 1), ('CP-3', 'donation', 'paid', 1)");
        $this->migrate();

        $events = $this->pdo->query('SELECT * FROM events')->fetchAll();
        $this->assertCount(1, $events);
        $this->assertSame(1, (int) $events[0]['legacy_settings'], 'filled in from the old settings later');
        $this->assertSame(0, (int) $events[0]['published'], 'not shown until its details are known');
        $this->assertSame('bileti', $events[0]['slug']);

        $links = $this->pdo->query('SELECT pledge_number, event_id FROM campaign_pledges ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame((int) $events[0]['id'], (int) $links['CP-1']);
        $this->assertSame((int) $events[0]['id'], (int) $links['CP-2']);
        $this->assertNull($links['CP-3'], 'campaign donations do not belong to an event');
    }

    public function test_a_site_without_ticket_sales_gets_no_event(): void
    {
        $this->pdo->exec("INSERT INTO campaign_pledges (pledge_number, pledge_type) VALUES ('CP-9', 'donation')");
        $this->migrate();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
        $this->assertNull($this->pdo->query('SELECT event_id FROM campaign_pledges')->fetchColumn() ?: null);
    }

    public function test_sql_is_portable_between_mysql_and_mariadb(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/043_events.sql');
        $code = preg_replace('/^\s*--.*$/m', '', $sql);
        $this->assertDoesNotMatchRegularExpression('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS|DROP\s+COLUMN\s+IF\s+EXISTS/i', $code);
        $this->assertStringNotContainsString('FOREIGN KEY', $code, 'an FK would block deleting an event row the bulk delete allows');
    }
}
