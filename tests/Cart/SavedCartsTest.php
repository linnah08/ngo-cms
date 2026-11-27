<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Saved carts — includes/saved_carts.php. DB tests self-skip without a database. */
final class SavedCartsTest extends TestCase
{
    private static ?PDO $pdo = null;
    private string $browser;
    private array $saved_settings = [];
    private array $saved_cookie = [];
    private array $saved_session = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        $this->saved_cookie  = $_COOKIE;
        $this->saved_session = $_SESSION ?? [];
        if (!self::$pdo) return;
        foreach (['cart_reminder_email', 'cart_reminder_notice'] as $k) {
            $this->saved_settings[$k] = setting_get($k, '0');
        }
        $this->browser = bin2hex(random_bytes(32));
        $_COOKIE['om_cart'] = $this->browser;
        $_SESSION = [];
        setting_set('cart_reminder_email', '1');
        setting_set('cart_reminder_notice', '0');
    }

    protected function tearDown(): void
    {
        $_COOKIE  = $this->saved_cookie;
        $_SESSION = $this->saved_session;
        if (!self::$pdo) return;
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);
        foreach ($this->saved_settings as $k => $v) setting_set($k, $v);
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function rowCount(): int
    {
        $s = self::$pdo->prepare('SELECT COUNT(*) FROM saved_carts WHERE browser_token = ?');
        $s->execute([$this->browser]);
        return (int)$s->fetchColumn();
    }

    private function shopRow(): ?array
    {
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
        $s->execute([$this->browser]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function insertRow(array $o = []): int
    {
        $d = array_merge([
            'token' => bin2hex(random_bytes(32)), 'browser_token' => $this->browser,
            'pledge_id' => null, 'items' => '[{"id":1,"qty":1}]', 'ordered_at' => null,
            'dismissed_at' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ], $o);
        self::$pdo->prepare('INSERT INTO saved_carts (token,browser_token,pledge_id,items,ordered_at,dismissed_at,updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$d['token'], $d['browser_token'], $d['pledge_id'], $d['items'], $d['ordered_at'], $d['dismissed_at'], $d['updated_at']]);
        return (int)self::$pdo->lastInsertId();
    }

    public function test_sync_is_noop_when_both_switches_off(): void
    {
        $this->requireDb();
        setting_set('cart_reminder_email', '0');
        setting_set('cart_reminder_notice', '0');
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        $this->assertSame(0, $this->rowCount());
    }

    public function test_sync_creates_then_updates_one_row_per_browser(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        $second = [['id' => 2, 'qty' => 3]];
        saved_cart_sync(self::$pdo, $second, 'en');
        $this->assertSame(1, $this->rowCount());
        $row = $this->shopRow();
        $this->assertSame($second, json_decode($row['items'], true));
        $this->assertSame('en', $row['lang']);
        $this->assertTrue(saved_cart_token_valid($row['token']));
    }

    public function test_sync_never_clears_reminded_at(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        self::$pdo->prepare('UPDATE saved_carts SET reminded_at = NOW() WHERE browser_token = ?')->execute([$this->browser]);
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 2]], 'bg');
        $this->assertNotNull($this->shopRow()['reminded_at']);
    }

    public function test_sync_clears_dismissed_at(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        self::$pdo->prepare('UPDATE saved_carts SET dismissed_at = NOW() WHERE browser_token = ?')->execute([$this->browser]);
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 2]], 'bg');
        $this->assertNull($this->shopRow()['dismissed_at']);
    }

    public function test_sync_skipped_in_pledge_mode(): void
    {
        $this->requireDb();
        $_SESSION['checkout_data'] = ['_pledge_number' => 'P-1'];
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        $this->assertSame(0, $this->rowCount());
    }

    public function test_for_browser_ignores_ordered_dismissed_empty_and_old_rows(): void
    {
        $this->requireDb();
        $this->insertRow(['ordered_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $this->insertRow(['dismissed_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $this->insertRow(['items' => '[]']);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $this->insertRow(['updated_at' => date('Y-m-d H:i:s', time() - 31 * 86400)]);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $id = $this->insertRow();
        $got = saved_cart_for_browser(self::$pdo);
        $this->assertSame($id, (int)$got['cart']['id']);
        $this->assertSame([['id' => 1, 'qty' => 1]], $got['cart']['items']);
        $this->assertNull($got['pledge']);
    }

    public function test_for_browser_returns_pledge_row_with_empty_items(): void
    {
        $this->requireDb();
        $pid = random_int(900000000, 999999999);
        $id = $this->insertRow(['pledge_id' => $pid, 'items' => '[]']);
        $got = saved_cart_for_browser(self::$pdo);
        $this->assertSame($id, (int)$got['pledge']['id']);
        $this->assertNull($got['cart']);
        saved_cart_mark_ordered(self::$pdo, $pid);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge']);
    }

    public function test_mark_ordered_marks_shop_row(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        saved_cart_mark_ordered(self::$pdo);
        $this->assertNotNull($this->shopRow()['ordered_at']);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
    }

    public function test_token_valid_rejects_short_uppercase_and_non_hex(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
        $this->assertTrue(saved_cart_token_valid(str_repeat('a1', 32)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('a', 63)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('A', 64)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('g', 64)));
        $this->assertFalse(saved_cart_token_valid(''));
    }
}
