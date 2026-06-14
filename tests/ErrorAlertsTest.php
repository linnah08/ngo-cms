<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ErrorAlertsTest extends TestCase
{
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) {
            self::markTestSkipped('DB not available — skipping error-alerts tests.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/error-alerts.php';
        self::$pdo = get_pdo();
        error_alert_ensure_table();
        self::$pdo->exec("DELETE FROM error_alerts WHERE error_class = 'TestCapture'");
    }

    public function testEnsureTableCreatesTable(): void
    {
        error_alert_ensure_table();
        $result = self::$pdo->query("SHOW TABLES LIKE 'error_alerts'")->fetchColumn();
        $this->assertSame('error_alerts', $result);
    }

    public function testCaptureInsertsRowWhenEnabled(): void
    {
        setting_set('error_alert_enabled',   '1');
        setting_set('error_alert_email',     'test@example.com');
        setting_set('error_alert_frequency', 'daily');

        error_alert_capture('TestCapture', 'Test message', '/some/file.php', 42);

        $row = self::$pdo->query(
            "SELECT * FROM error_alerts WHERE error_class = 'TestCapture' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('Test message', $row['message']);
        $this->assertSame('/some/file.php', $row['file']);
        $this->assertSame(42, (int)$row['line']);
        $this->assertNull($row['sent_at']);
    }

    public function testCaptureDoesNothingWhenDisabled(): void
    {
        setting_set('error_alert_enabled', '0');

        $before = (int) self::$pdo->query(
            "SELECT COUNT(*) FROM error_alerts WHERE error_class = 'TestCapture'"
        )->fetchColumn();

        error_alert_capture('TestCapture', 'Should not insert', '/file.php', 1);

        $after = (int) self::$pdo->query(
            "SELECT COUNT(*) FROM error_alerts WHERE error_class = 'TestCapture'"
        )->fetchColumn();

        $this->assertSame($before, $after);
    }

    public function testMessageTruncatedAt2000Chars(): void
    {
        setting_set('error_alert_enabled',   '1');
        setting_set('error_alert_email',     'test@example.com');
        setting_set('error_alert_frequency', 'daily');

        error_alert_capture('TestCapture', str_repeat('x', 3000), '/file.php', 1);

        $row = self::$pdo->query(
            "SELECT message FROM error_alerts WHERE error_class = 'TestCapture' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertLessThanOrEqual(2000, mb_strlen($row['message']));
    }

    public function testFloodProtectionSkipsSendForSameHashWithin5Min(): void
    {
        error_alert_ensure_table();
        self::$pdo->prepare(
            "INSERT INTO error_alerts (error_class, message, file, line) VALUES ('TestCapture', 'Flood test', '/f.php', 7)"
        )->execute();
        $id  = (int) self::$pdo->lastInsertId();
        $sel = self::$pdo->prepare('SELECT * FROM error_alerts WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);

        // Prime the flood guard so it looks like this error was just sent
        $hash = md5('TestCapture' . 'Flood test' . '/f.php' . '7');
        setting_set('error_alert_last_hash',    $hash);
        setting_set('error_alert_last_hash_at', date('Y-m-d H:i:s'));

        error_alert_send_immediate($row, 'test@example.com');

        // sent_at must still be NULL — send was suppressed
        $check = self::$pdo->prepare('SELECT sent_at FROM error_alerts WHERE id = ?');
        $check->execute([$id]);
        $sentAt = $check->fetchColumn();
        $this->assertNull($sentAt);

        // Cleanup flood guard
        setting_set('error_alert_last_hash',    '');
        setting_set('error_alert_last_hash_at', '');
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pdo)) return;
        self::$pdo->exec("DELETE FROM error_alerts WHERE error_class = 'TestCapture'");
        setting_set('error_alert_enabled',   '0');
        setting_set('error_alert_email',     '');
        setting_set('error_alert_frequency', 'immediate');
    }
}
