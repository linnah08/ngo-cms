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
    private array $pledge_ids = [];
    private string|false $modules_off = false;

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
        $this->modules_off = getenv('NGO_TEST_MODULES_OFF');
        $this->pledge_ids  = [];
        foreach (['cart_reminder_email', 'cart_reminder_notice', 'campaign_active'] as $k) {
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
        putenv($this->modules_off === false ? 'NGO_TEST_MODULES_OFF' : 'NGO_TEST_MODULES_OFF=' . $this->modules_off);
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);
        foreach ($this->pledge_ids as $id) {
            self::$pdo->prepare('DELETE FROM saved_carts WHERE pledge_id = ?')->execute([$id]);
            self::$pdo->prepare('DELETE FROM campaign_pledges WHERE id = ?')->execute([$id]);
        }
        foreach ($this->saved_settings as $k => $v) setting_set($k, $v);
    }

    /** A campaign pledge (no reward) with the given payment status; returns its id. */
    private function pledge(string $status = 'pending'): int
    {
        self::$pdo->prepare('INSERT INTO campaign_pledges (pledge_number,name,email,phone,amount_eur,reward_id,payment_status) VALUES (?,?,?,?,?,?,?)')
            ->execute(['CP-TEST-' . strtoupper(bin2hex(random_bytes(3))), 'Мария', 'm@example.com', '0888123456', 25.00, null, $status]);
        return $this->pledge_ids[] = (int)self::$pdo->lastInsertId();
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
        skip_unless_modules_on('campaign');
        setting_set('campaign_active', '1');
        $pid = $this->pledge();
        $id = $this->insertRow(['pledge_id' => $pid, 'items' => '[]']);
        $got = saved_cart_for_browser(self::$pdo);
        $this->assertSame($id, (int)$got['pledge']['id']);
        $this->assertNull($got['cart']);
        saved_cart_mark_ordered(self::$pdo, $pid);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge']);
    }

    public function test_for_browser_hides_a_pledge_that_can_no_longer_be_finished(): void
    {
        $this->requireDb();
        skip_unless_modules_on('campaign');
        setting_set('campaign_active', '1');
        $this->insertRow(['pledge_id' => $this->pledge('paid'), 'items' => '[]']);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge'], 'a paid pledge');
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $this->insertRow(['pledge_id' => 999999999, 'items' => '[]']);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge'], 'a pledge that no longer exists');
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);

        $id = $this->insertRow(['pledge_id' => $this->pledge(), 'items' => '[]']);
        $this->assertSame($id, (int)saved_cart_for_browser(self::$pdo)['pledge']['id']);
        setting_set('campaign_active', '0');
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge'], 'the campaign is closed');
        setting_set('campaign_active', '1');
        putenv('NGO_TEST_MODULES_OFF=campaign');
        $this->assertNull(saved_cart_for_browser(self::$pdo)['pledge'], 'the campaign module is off');
    }

    public function test_pledge_upsert_saves_the_pledge_for_everyone_and_leaves_the_shop_row(): void
    {
        $this->requireDb();
        setting_set('cart_reminder_email', '0');
        setting_set('cart_reminder_notice', '1');
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        $shop = $this->shopRow();
        $pid  = $this->pledge();
        saved_cart_pledge_upsert(self::$pdo, $pid, 'en');
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE pledge_id = ?');
        $s->execute([$pid]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame($this->browser, $rows[0]['browser_token']);
        $this->assertSame('[]', $rows[0]['items']);
        $this->assertSame('en', $rows[0]['lang']);
        $this->assertNull($rows[0]['email'], 'no address without the checkbox');
        $this->assertTrue(saved_cart_token_valid($rows[0]['token']));
        $this->assertSame($shop, $this->shopRow());

        // Again (say after closing the notice): the same row, shown again.
        self::$pdo->prepare('UPDATE saved_carts SET dismissed_at = NOW() WHERE id = ?')->execute([$rows[0]['id']]);
        saved_cart_pledge_upsert(self::$pdo, $pid, 'bg');
        $s->execute([$pid]);
        $again = $s->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $again);
        $this->assertSame($rows[0]['id'], $again[0]['id']);
        $this->assertNull($again[0]['dismissed_at']);
        $this->assertSame($shop, $this->shopRow());

        // Both switches off: nothing is saved.
        setting_set('cart_reminder_notice', '0');
        $other = $this->pledge();
        saved_cart_pledge_upsert(self::$pdo, $other, 'bg');
        $s->execute([$other]);
        $this->assertSame([], $s->fetchAll());
    }

    public function test_mark_ordered_forgets_email_and_name_but_keeps_the_consent(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        saved_cart_record_consent(self::$pdo, true, 'a@example.com', 'Анна Иванова', 'bg', 'Съгласен');
        saved_cart_mark_ordered(self::$pdo);
        $row = $this->shopRow();
        $this->assertNotNull($row['ordered_at']);
        $this->assertNull($row['email']);
        $this->assertNull($row['name']);
        $this->assertNotNull($row['remind_consent_at']);
        $this->assertSame('Съгласен', $row['remind_consent_text']);

        $pid = $this->pledge();
        saved_cart_record_consent(self::$pdo, true, 'p@example.com', 'Петър Петров', 'bg', 'Съгласен', $pid);
        saved_cart_mark_ordered(self::$pdo, $pid);
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE pledge_id = ?');
        $s->execute([$pid]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($p['ordered_at']);
        $this->assertNull($p['email']);
        $this->assertNull($p['name']);
        $this->assertSame('Съгласен', $p['remind_consent_text']);
    }

    public function test_opt_out_forgets_email_and_name_but_keeps_the_consent(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        saved_cart_record_consent(self::$pdo, true, 'a@example.com', 'Анна Иванова', 'bg', 'Съгласен');
        $this->assertTrue(saved_cart_opt_out(self::$pdo, $this->shopRow()['token']));
        $row = $this->shopRow();
        $this->assertNotNull($row['opted_out_at']);
        $this->assertNull($row['email']);
        $this->assertNull($row['name']);
        $this->assertNotNull($row['remind_consent_at']);
        $this->assertSame('Съгласен', $row['remind_consent_text']);
        $this->assertTrue(saved_cart_opt_out(self::$pdo, $row['token']), 'a second click is still fine');
        $this->assertFalse(saved_cart_opt_out(self::$pdo, bin2hex(random_bytes(32))));
    }

    public function test_mark_ordered_marks_shop_row(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        saved_cart_mark_ordered(self::$pdo);
        $this->assertNotNull($this->shopRow()['ordered_at']);
        $this->assertNull(saved_cart_for_browser(self::$pdo)['cart']);
    }

    public function test_sync_after_order_starts_a_new_row(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['id' => 1, 'qty' => 1]], 'bg');
        $old = $this->shopRow();
        saved_cart_mark_ordered(self::$pdo);
        saved_cart_sync(self::$pdo, [['id' => 2, 'qty' => 1]], 'bg');
        $this->assertSame(2, $this->rowCount());
        $got = saved_cart_for_browser(self::$pdo)['cart'];
        $this->assertNotNull($got);
        $this->assertNotSame((int)$old['id'], (int)$got['id']);
        $this->assertNotSame($old['token'], $got['token']);
        $this->assertSame([['id' => 2, 'qty' => 1]], $got['items']);
        $s = self::$pdo->prepare('SELECT ordered_at FROM saved_carts WHERE id = ?');
        $s->execute([$old['id']]);
        $this->assertNotNull($s->fetchColumn());
    }

    public function test_token_valid_rejects_short_uppercase_and_non_hex(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
        $this->assertTrue(saved_cart_token_valid(str_repeat('a1', 32)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('a', 63)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('A', 64)));
        $this->assertFalse(saved_cart_token_valid(str_repeat('g', 64)));
        $this->assertFalse(saved_cart_token_valid(''));
        $this->assertFalse(saved_cart_token_valid(str_repeat('a', 64) . "\n"), 'a trailing newline is not part of a token');
    }
}
