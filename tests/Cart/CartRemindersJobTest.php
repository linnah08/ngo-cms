<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../Support/TestServer.php';

/**
 * Reminder emails for saved carts and pledges — saved_carts_due(), send_cart_reminder(),
 * run_cart_reminders_job() and cart/no-reminder.php. Every test passes a capturing
 * mailer: nothing is ever sent. DB tests self-skip without a database.
 */
#[Group('shop')]
#[Group('cart')]
final class CartRemindersJobTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static string $base = '';
    private static array $saved_settings = [];
    private static array $product_ids = [];
    private static array $pledge_ids  = [];
    private static array $reward_ids  = [];
    private static array $order_ids   = [];
    private static array $row_ids     = [];   // rows whose email a test may have cleared
    private static string $mark = '';

    /** @var list<array{to:string,subject:string,html:string}> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/mailer.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
        self::$pdo  = get_pdo();
        self::$mark = 'rem-' . bin2hex(random_bytes(4)) . '@example.com';   // every test email contains this
        foreach (['cart_reminder_email', 'cart_reminder_notice', 'campaign_active', 'campaign_title', 'campaign_title_en'] as $k) {
            self::$saved_settings[$k] = setting_get($k, '');
        }
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        if (!self::$pdo) return;
        $pdo = self::$pdo;
        $pdo->prepare('DELETE FROM saved_carts WHERE email LIKE ? OR name = ?')->execute(['%' . self::$mark, 'CRTEST']);
        foreach (self::$row_ids as $id) $pdo->prepare('DELETE FROM saved_carts WHERE id = ?')->execute([$id]);
        foreach (self::$order_ids as $id) $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        foreach (self::$pledge_ids as $id) $pdo->prepare('DELETE FROM campaign_pledges WHERE id = ?')->execute([$id]);
        foreach (self::$reward_ids as $id) $pdo->prepare('DELETE FROM campaign_rewards WHERE id = ?')->execute([$id]);
        foreach (self::$product_ids as $id) {
            $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        foreach (self::$saved_settings as $k => $v) setting_set($k, $v);
    }

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
        setting_set('cart_reminder_email', '1');
        setting_set('campaign_active', '1');
        $this->sent = [];
        // Rows from earlier tests must not leak into this test's counts.
        self::$pdo->prepare('DELETE FROM saved_carts WHERE email LIKE ?')->execute(['%' . self::$mark]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function mailer(bool $ok = true): callable
    {
        return function (string $to, string $subject, string $html) use ($ok): bool {
            $this->sent[] = compact('to', 'subject', 'html');
            return $ok;
        };
    }

    private function email(string $local = 'a'): string
    {
        return $local . '.' . self::$mark;
    }

    private function product(array $o = []): int
    {
        $d = array_merge(['name_bg' => 'Тениска ' . uniqid(), 'name_en' => 'Shirt ' . uniqid(), 'stock' => 5, 'active' => 1, 'price' => 12.50], $o);
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,preorder_enabled) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['test-cr-' . uniqid(), $d['name_bg'], $d['name_en'], $d['price'], $d['stock'], $d['active'], 'standard', 0]);
        return self::$product_ids[] = (int)self::$pdo->lastInsertId();
    }

    /** Inserts a shop row updated $age_hours ago; returns the fresh row. */
    private function row(int $age_hours, array $o = []): array
    {
        $d = array_merge([
            'token' => bin2hex(random_bytes(32)), 'browser_token' => bin2hex(random_bytes(32)),
            'pledge_id' => null, 'items' => json_encode([['product_id' => $this->product(), 'quantity' => 2]]),
            'lang' => 'bg', 'email' => $this->email(), 'name' => 'CRTEST', 'remind_opt_in' => 1,
            'reminded_at' => null, 'opted_out_at' => null, 'ordered_at' => null,
        ], $o);
        self::$pdo->prepare(
            'INSERT INTO saved_carts (token,browser_token,pledge_id,items,lang,email,name,remind_opt_in,reminded_at,opted_out_at,ordered_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW() - INTERVAL ? HOUR, NOW() - INTERVAL ? HOUR)'
        )->execute([$d['token'], $d['browser_token'], $d['pledge_id'], $d['items'], $d['lang'], $d['email'], $d['name'],
            $d['remind_opt_in'], $d['reminded_at'], $d['opted_out_at'], $d['ordered_at'], $age_hours, $age_hours]);
        $id = (int)self::$pdo->lastInsertId();
        if (!self::$pdo->inTransaction()) self::$row_ids[] = $id;
        return $this->reload($id);
    }

    private function reload(int $id): array
    {
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    private function dueIds(): array
    {
        return array_map('intval', array_column(saved_carts_due(self::$pdo), 'id'));
    }

    private function pledgeRow(array $o = [], int $age_hours = 30): array
    {
        self::$pdo->prepare('INSERT INTO campaign_rewards (title,description,title_en,description_en,amount_eur,active) VALUES (?,?,?,?,?,1)')
            ->execute(['Малка награда', 'Тест', 'Small reward', 'Test', 25]);
        $reward = (int)self::$pdo->lastInsertId();
        self::$reward_ids[] = $reward;
        self::$pdo->prepare('INSERT INTO campaign_pledges (pledge_number,name,email,phone,amount_eur,reward_id,payment_status) VALUES (?,?,?,?,?,?,?)')
            ->execute(['CP-TEST-' . strtoupper(bin2hex(random_bytes(3))), 'Мария', $this->email('p'), '0888', 25.00, $reward, $o['status'] ?? 'pending']);
        $pid = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[] = $pid;
        return $this->row($age_hours, ['pledge_id' => $pid, 'items' => '[]', 'email' => $this->email('p'), 'lang' => $o['lang'] ?? 'en']);
    }

    // ── saved_carts_due ───────────────────────────────────────────────────────

    public function test_due_picks_opted_in_row_older_than_24h_only(): void
    {
        $old   = $this->row(30);
        $fresh = $this->row(2, ['email' => $this->email('b')]);
        $ids = $this->dueIds();
        $this->assertContains((int)$old['id'], $ids);
        $this->assertNotContains((int)$fresh['id'], $ids);
    }

    public function test_due_skips_rows_older_than_7_days(): void
    {
        $r = $this->row(24 * 8);
        $this->assertNotContains((int)$r['id'], $this->dueIds());
    }

    public function test_due_skips_opted_out(): void
    {
        $r = $this->row(30, ['opted_out_at' => date('Y-m-d H:i:s')]);
        $this->assertNotContains((int)$r['id'], $this->dueIds());
    }

    public function test_due_skips_ordered(): void
    {
        $r = $this->row(30, ['ordered_at' => date('Y-m-d H:i:s')]);
        $this->assertNotContains((int)$r['id'], $this->dueIds());
    }

    public function test_due_skips_not_opted_in(): void
    {
        $r = $this->row(30, ['remind_opt_in' => 0]);
        $this->assertNotContains((int)$r['id'], $this->dueIds());
    }

    public function test_due_skips_shop_rows_with_empty_items_but_keeps_pledge_rows(): void
    {
        $empty  = $this->row(30, ['items' => '[]']);
        $pledge = $this->pledgeRow();
        $ids = $this->dueIds();
        $this->assertNotContains((int)$empty['id'], $ids);
        $this->assertContains((int)$pledge['id'], $ids);
    }

    public function test_due_skips_when_same_email_ordered_after_update(): void
    {
        $r = $this->row(30, ['email' => strtoupper($this->email('o'))]);
        $this->assertContains((int)$r['id'], $this->dueIds());
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,lang,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW() - INTERVAL 3 HOUR)"
        )->execute(['T' . substr(uniqid(), -13), 'physical', 'new', 'bg', 'Тест', strtolower($this->email('o')), '[]', 10, 0, 10, 'cod', 'pending']);
        self::$order_ids[] = (int)self::$pdo->lastInsertId();
        $this->assertNotContains((int)$r['id'], $this->dueIds(), 'bought something after the cart was last touched');
    }

    public function test_reminded_then_edited_cart_is_never_emailed_again(): void
    {
        $r = $this->row(30);
        $this->assertTrue(send_cart_reminder(self::$pdo, $r, $this->mailer()));
        // The shopper edits the cart a day later: updated_at moves, reminded_at must stay.
        self::$pdo->prepare('UPDATE saved_carts SET updated_at = NOW() - INTERVAL 30 HOUR WHERE id = ?')->execute([$r['id']]);
        $this->assertNotContains((int)$r['id'], $this->dueIds());
        $this->assertSame(0, run_cart_reminders_job(self::$pdo, $this->mailer())['emailed']);
        $this->assertCount(1, $this->sent);
    }

    // ── send_cart_reminder ────────────────────────────────────────────────────

    public function test_send_claims_before_mailing_and_never_twice(): void
    {
        $r = $this->row(30);
        $claimed_when_mailing = null;
        $mailer = function (string $to, string $s, string $h) use ($r, &$claimed_when_mailing): bool {
            $claimed_when_mailing = $this->reload((int)$r['id'])['reminded_at'];
            return true;
        };
        $this->assertTrue(send_cart_reminder(self::$pdo, $r, $mailer));
        $this->assertNotNull($claimed_when_mailing, 'the claim is made before the email goes out');
        $this->assertFalse(send_cart_reminder(self::$pdo, $r, $this->mailer()), 'a second attempt on the same row sends nothing');
        $this->assertSame([], $this->sent);
    }

    public function test_mailer_failure_keeps_the_claim(): void
    {
        $r = $this->row(30);
        $this->assertFalse(send_cart_reminder(self::$pdo, $r, $this->mailer(false)));
        $this->assertNotNull($this->reload((int)$r['id'])['reminded_at'], 'at most one email beats delivery');
        $this->assertFalse(send_cart_reminder(self::$pdo, $r, $this->mailer()));
    }

    public function test_send_skips_cart_with_no_available_items(): void
    {
        $gone = $this->product(['active' => 0]);
        $r = $this->row(30, ['items' => json_encode([['product_id' => $gone, 'quantity' => 1]])]);
        $this->assertFalse(send_cart_reminder(self::$pdo, $r, $this->mailer()));
        $this->assertSame([], $this->sent);
        $after = $this->reload((int)$r['id']);
        $this->assertNotNull($after['reminded_at']);
        $this->assertNull($after['email'], 'a row that can never be emailed forgets its address');
        $this->assertNull($after['name']);
    }

    public function test_email_contains_restore_link_and_no_reminder_link_in_shopper_language(): void
    {
        $en = $this->row(30, ['lang' => 'en', 'email' => $this->email('en')]);
        $bg = $this->row(30, ['lang' => 'bg', 'email' => $this->email('bg')]);
        $this->assertTrue(send_cart_reminder(self::$pdo, $en, $this->mailer()));
        $this->assertTrue(send_cart_reminder(self::$pdo, $bg, $this->mailer()));
        [$e, $b] = $this->sent;

        $this->assertSame($en['email'], $e['to']);
        $this->assertSame('You left something in your cart', $e['subject']);
        $this->assertStringContainsString(SITE_URL . '/en/cart/restore.php?t=' . $en['token'], $e['html']);
        $this->assertStringContainsString(SITE_URL . '/en/cart/no-reminder.php?t=' . $en['token'], $e['html']);
        $this->assertStringContainsString("You're getting this because you asked us to remind you.", html_entity_decode($e['html'], ENT_QUOTES));
        $this->assertStringContainsString('25.00 €', $e['html']);   // 2 x 12.50: the line total, as in order emails
        $this->assertStringContainsString('>Total<', $e['html']);
        $this->assertStringNotContainsString('>Price<', $e['html']);

        $this->assertSame('Оставихте нещо в количката си', $b['subject']);
        $this->assertStringContainsString(SITE_URL . '/cart/restore.php?t=' . $bg['token'], $b['html']);
        $this->assertStringContainsString(SITE_URL . '/cart/no-reminder.php?t=' . $bg['token'], $b['html']);
        $this->assertStringContainsString('Получавате това писмо, защото поискахте да ви напомним.', $b['html']);
        $this->assertStringContainsString('>Сума<', $b['html']);
    }

    public function test_pledge_reminder_shows_reward_and_amount(): void
    {
        setting_set('campaign_title_en', 'Spring appeal');
        $r = $this->pledgeRow();
        $this->assertTrue(send_cart_reminder(self::$pdo, $r, $this->mailer()));
        $m = $this->sent[0];
        $this->assertSame("Your support isn't finished yet", $m['subject']);
        $this->assertStringContainsString('Small reward', $m['html']);
        $this->assertStringContainsString('25.00 €', $m['html']);
        $this->assertStringContainsString('Spring appeal', $m['html']);
        $this->assertStringContainsString('/cart/restore.php?t=' . $r['token'], $m['html']);
    }

    public function test_pledge_reminder_not_sent_when_pledge_no_longer_pending_or_campaign_closed(): void
    {
        $paid = $this->pledgeRow(['status' => 'paid']);
        $this->assertFalse(send_cart_reminder(self::$pdo, $paid, $this->mailer()));
        setting_set('campaign_active', '0');
        $open = $this->pledgeRow();
        $this->assertFalse(send_cart_reminder(self::$pdo, $open, $this->mailer()));
        $this->assertSame([], $this->sent);
    }

    // ── run_cart_reminders_job ────────────────────────────────────────────────
    // The job works on the whole table, so these tests run inside a transaction that is rolled
    // back: other local rows are never changed, and only our own fixture rows are asserted on.

    /** Emails our fixtures were sent (the job may also see other rows in a shared local database). */
    private function sentToMine(): array
    {
        return array_values(array_filter($this->sent, fn($m) => str_ends_with(strtolower($m['to']), self::$mark)));
    }

    private function begin(): void
    {
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo && self::$pdo->inTransaction()) self::$pdo->rollBack();
    }

    public function test_job_emails_due_rows_and_skips_ones_with_nothing_left(): void
    {
        $this->begin();
        $ok   = $this->row(30);
        $gone = $this->product(['active' => 0]);
        $none = $this->row(30, ['email' => $this->email('x'), 'items' => json_encode([['product_id' => $gone, 'quantity' => 1]])]);
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $mine = $this->sentToMine();
        $this->assertCount(1, $mine);
        $this->assertSame($ok['email'], $mine[0]['to']);
        $this->assertNotNull($this->reload((int)$none['id'])['reminded_at']);
    }

    public function test_job_sends_one_email_per_address_per_run(): void
    {
        $this->begin();
        $a = $this->row(30);
        $b = $this->row(31, ['email' => strtoupper($a['email'])]);   // a second browser, same person
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $this->assertCount(1, $this->sentToMine());
        $this->assertNotNull($this->reload((int)$a['id'])['reminded_at']);
        $this->assertNotNull($this->reload((int)$b['id'])['reminded_at'], 'the extra row is marked so it is never emailed later');
    }

    public function test_second_row_for_same_address_due_in_a_later_run_is_not_emailed(): void
    {
        $this->begin();
        $a = $this->row(30);
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $this->assertCount(1, $this->sentToMine());
        // A second browser's row for the same person (other letter case) only becomes due now.
        $b = $this->row(26, ['email' => strtoupper($a['email'])]);
        $this->assertNotContains((int)$b['id'], $this->dueIds());
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $this->assertCount(1, $this->sentToMine(), 'still just the first email');
        $this->assertNull($this->reload((int)$b['id'])['reminded_at']);
    }

    public function test_a_row_with_nothing_to_send_does_not_block_the_address(): void
    {
        $this->begin();
        setting_set('campaign_active', '0');
        $pledge = $this->pledgeRow([], 32);                       // the campaign closed: nothing to send
        $shop   = $this->row(30, ['email' => $pledge['email']]);   // same person, a cart still worth a reminder
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $mine = $this->sentToMine();
        $this->assertCount(1, $mine);
        $this->assertSame($shop['email'], $mine[0]['to']);
        $this->assertSame('Оставихте нещо в количката си', $mine[0]['subject']);
        $p = $this->reload((int)$pledge['id']);
        $this->assertNotNull($p['reminded_at']);
        $this->assertNull($p['email']);
    }

    public function test_job_sends_nothing_with_the_shop_module_off_but_still_forgets_old_rows(): void
    {
        $this->begin();
        $r   = $this->row(30);
        $old = $this->row(24 * 31, ['email' => $this->email('old2')]);
        $off = getenv('NGO_TEST_MODULES_OFF');
        putenv('NGO_TEST_MODULES_OFF=shop');
        try {
            run_cart_reminders_job(self::$pdo, $this->mailer());
        } finally {
            putenv($off === false ? 'NGO_TEST_MODULES_OFF' : 'NGO_TEST_MODULES_OFF=' . $off);
        }
        $this->assertSame([], $this->sentToMine());
        $this->assertNull($this->reload((int)$r['id'])['reminded_at']);
        $this->assertFalse(self::$pdo->query('SELECT 1 FROM saved_carts WHERE id = ' . (int)$old['id'])->fetchColumn());
    }

    public function test_job_does_nothing_when_switch_is_off(): void
    {
        $this->begin();
        setting_set('cart_reminder_email', '0');
        $r = $this->row(30);
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $this->assertSame([], $this->sentToMine());
        $this->assertNull($this->reload((int)$r['id'])['reminded_at']);
    }

    public function test_job_deletes_rows_older_than_30_days(): void
    {
        $this->begin();
        $old  = $this->row(24 * 31, ['email' => $this->email('old')]);
        $keep = $this->row(24 * 20, ['email' => $this->email('keep')]);
        run_cart_reminders_job(self::$pdo, $this->mailer());
        $this->assertFalse(self::$pdo->query('SELECT 1 FROM saved_carts WHERE id = ' . (int)$old['id'])->fetchColumn());
        $this->assertNotFalse(self::$pdo->query('SELECT 1 FROM saved_carts WHERE id = ' . (int)$keep['id'])->fetchColumn());
    }

    public function test_send_does_not_mail_an_opted_out_or_ordered_row(): void
    {
        $out = $this->row(30, ['opted_out_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse(send_cart_reminder(self::$pdo, $out, $this->mailer()));
        $ord = $this->row(30, ['email' => $this->email('o2'), 'ordered_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse(send_cart_reminder(self::$pdo, $ord, $this->mailer()));
        $off = $this->row(30, ['email' => $this->email('o3'), 'remind_opt_in' => 0]);
        $this->assertFalse(send_cart_reminder(self::$pdo, $off, $this->mailer()));
        $this->assertSame([], $this->sent);
        $this->assertNull($this->reload((int)$out['id'])['reminded_at']);
    }

    // ── cart/no-reminder.php ──────────────────────────────────────────────────

    private function http(string $path, string $jar, array $post = []): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        if ($post) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }

    public function test_no_reminder_get_changes_nothing_post_opts_out(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') $this->markTestSkipped('Could not start a local test server.');
        $r   = $this->row(30, ['lang' => 'en']);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $get = $this->http('/cart/no-reminder.php?t=' . $r['token'], $jar);
            $this->assertSame(200, $get['code']);
            $this->assertNull($this->reload((int)$r['id'])['opted_out_at'], 'a GET (a mail scanner following the link) changes nothing');
            $this->assertStringContainsString('name="csrf_token"', $get['body']);
            $enget = $this->http('/en/cart/no-reminder.php?t=' . $r['token'], $jar);
            $this->assertSame(200, $enget['code']);
            $this->assertStringContainsString('<html lang="en"', $enget['body']);
            $this->assertStringContainsString('action="/en/cart/no-reminder.php?t=', $enget['body']);
            $this->assertStringContainsString('method="post"', strtolower($get['body']));
            preg_match('/name="csrf_token" value="([^"]+)"/', $get['body'], $m);

            $bad = $this->http('/cart/no-reminder.php?t=' . $r['token'], $jar, ['csrf_token' => 'wrong']);
            $this->assertNull($this->reload((int)$r['id'])['opted_out_at'], 'no CSRF token, no change');
            $this->assertStringNotContainsString('Done', $bad['body']);

            $post = $this->http('/cart/no-reminder.php?t=' . $r['token'], $jar, ['csrf_token' => $m[1]]);
            $this->assertSame(200, $post['code']);
            $after = $this->reload((int)$r['id']);
            $this->assertNotNull($after['opted_out_at']);
            $this->assertNull($after['email'], 'opting out forgets the address');
            $this->assertNull($after['name']);
            $this->assertStringContainsString('Done', $post['body']);
            $this->assertNotContains((int)$r['id'], $this->dueIds());
        } finally {
            @unlink($jar);
        }
    }

    public function test_no_reminder_unknown_token_is_friendly_and_leaks_nothing(): void
    {
        self::$base = TestServer::start();
        if (self::$base === '') $this->markTestSkipped('Could not start a local test server.');
        $r   = $this->row(30);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            foreach ([bin2hex(random_bytes(32)), 'not-a-token', ''] as $t) {
                $g = $this->http('/cart/no-reminder.php?t=' . $t, $jar);
                $this->assertSame(404, $g['code']);
                $this->assertStringNotContainsString('<form method="post" action="/cart/no-reminder.php', $g['body']);
                $this->assertStringNotContainsString($r['email'], $g['body']);
            }
            $this->assertNull($this->reload((int)$r['id'])['opted_out_at']);
        } finally {
            @unlink($jar);
        }
    }
}
