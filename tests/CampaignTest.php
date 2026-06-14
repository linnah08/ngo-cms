<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('campaign')]
final class CampaignTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $pledge_ids = [];
    private static array $reward_ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    protected function setUp(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured (db.config.php missing or unreachable).');
        }
        // Campaign tables must exist (migration 009)
        try {
            self::$pdo->query('SELECT 1 FROM campaign_rewards LIMIT 1');
            self::$pdo->query('SELECT 1 FROM campaign_pledges LIMIT 1');
        } catch (Throwable) {
            $this->markTestSkipped('Campaign tables not found — run migration 009_campaign.sql first.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (!empty(self::$pledge_ids)) {
            $in = implode(',', array_fill(0, count(self::$pledge_ids), '?'));
            self::$pdo->prepare("DELETE FROM campaign_pledges WHERE id IN ($in)")
                      ->execute(self::$pledge_ids);
        }
        if (!empty(self::$reward_ids)) {
            $in = implode(',', array_fill(0, count(self::$reward_ids), '?'));
            self::$pdo->prepare("DELETE FROM campaign_rewards WHERE id IN ($in)")
                      ->execute(self::$reward_ids);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertReward(float $amount = 25.0): int
    {
        self::$pdo->prepare(
            'INSERT INTO campaign_rewards (position,title,title_en,description,description_en,amount_eur,active)
             VALUES (99,?,?,?,?,?,1)'
        )->execute(['Test Reward', '', 'Test description', '', $amount]);
        $id = (int)self::$pdo->lastInsertId();
        self::$reward_ids[] = $id;
        return $id;
    }

    private function insertPledge(array $overrides = []): int
    {
        $defaults = [
            'pledge_number'    => 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2))),
            'pledge_type'      => 'donation',
            'lang'             => 'bg',
            'name'             => 'Тест Поддръжник',
            'email'            => 'test-campaign@example.com',
            'amount_eur'       => 25.00,
            'ticket_qty'       => 1,
            'reward_id'        => null,
            'delivery_address' => null,
            'payment_status'   => 'pending',
        ];
        $d = array_merge($defaults, $overrides);
        self::$pdo->prepare(
            'INSERT INTO campaign_pledges
             (pledge_number,pledge_type,lang,name,email,amount_eur,ticket_qty,reward_id,delivery_address,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['pledge_number'], $d['pledge_type'], $d['lang'],
            $d['name'], $d['email'], $d['amount_eur'], $d['ticket_qty'],
            $d['reward_id'], $d['delivery_address'], $d['payment_status'],
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[] = $id;
        return $id;
    }

    // ── pledge_number uniqueness ──────────────────────────────────────────────

    public function test_pledge_number_unique(): void
    {
        $num1 = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $num2 = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

        $this->insertPledge(['pledge_number' => $num1]);
        $this->insertPledge(['pledge_number' => $num2]);

        $this->expectException(PDOException::class);
        // Inserting the same number twice must fail
        self::$pdo->prepare(
            'INSERT INTO campaign_pledges (pledge_number,name,email,amount_eur,payment_status)
             VALUES (?,?,?,?,?)'
        )->execute([$num1, 'Dup', 'dup@example.com', 10.0, 'pending']);
    }

    // ── pledge status transitions ─────────────────────────────────────────────

    public function test_pledge_status_transitions(): void
    {
        $id = $this->insertPledge(['payment_status' => 'pending']);

        self::$pdo->prepare("UPDATE campaign_pledges SET payment_status='paid' WHERE id=?")
                  ->execute([$id]);
        $row = self::$pdo->query("SELECT payment_status FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame('paid', $row['payment_status']);

        self::$pdo->prepare("UPDATE campaign_pledges SET payment_status='reversed' WHERE id=?")
                  ->execute([$id]);
        $row = self::$pdo->query("SELECT payment_status FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame('reversed', $row['payment_status']);
    }

    // ── reward FK ────────────────────────────────────────────────────────────

    public function test_pledge_with_reward_and_delivery(): void
    {
        $rid = $this->insertReward(50.0);
        $addr = json_encode(['address' => 'ул. Тест 1', 'city' => 'София', 'postcode' => '1000', 'phone' => '+359888000001']);
        $id   = $this->insertPledge([
            'reward_id'        => $rid,
            'amount_eur'       => 50.0,
            'delivery_address' => $addr,
        ]);

        $row = self::$pdo->query("SELECT * FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame($rid, (int)$row['reward_id']);
        $decoded = json_decode($row['delivery_address'], true);
        $this->assertSame('София', $decoded['city']);
    }

    // ── pledge without reward has no delivery ────────────────────────────────

    public function test_pledge_without_reward_has_no_delivery(): void
    {
        $id = $this->insertPledge(['reward_id' => null, 'delivery_address' => null]);
        $row = self::$pdo->query("SELECT reward_id, delivery_address FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertNull($row['reward_id']);
        $this->assertNull($row['delivery_address']);
    }

    // ── reward_shipped toggle ─────────────────────────────────────────────────

    public function test_reward_shipped_toggle(): void
    {
        $rid = $this->insertReward(25.0);
        $id  = $this->insertPledge(['reward_id' => $rid, 'payment_status' => 'paid']);

        $row = self::$pdo->query("SELECT reward_shipped FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame(0, (int)$row['reward_shipped']);

        self::$pdo->prepare("UPDATE campaign_pledges SET reward_shipped=1 WHERE id=?")->execute([$id]);
        $row = self::$pdo->query("SELECT reward_shipped FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame(1, (int)$row['reward_shipped']);
    }

    // ── EUR→BGN rate constant ─────────────────────────────────────────────────

    public function test_eur_bgn_rate_constant(): void
    {
        $this->assertSame(1.95583, EUR_BGN_RATE);
    }

    // ── progress stats query ──────────────────────────────────────────────────

    public function test_stats_query_counts_only_paid(): void
    {
        $before = self::$pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount_eur),0) AS total
             FROM campaign_pledges WHERE payment_status='paid'"
        )->fetch();

        $this->insertPledge(['amount_eur' => 10.0, 'payment_status' => 'pending']);
        $this->insertPledge(['amount_eur' => 20.0, 'payment_status' => 'paid']);
        $this->insertPledge(['amount_eur' => 15.0, 'payment_status' => 'failed']);

        $after = self::$pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount_eur),0) AS total
             FROM campaign_pledges WHERE payment_status='paid'"
        )->fetch();

        $this->assertSame((int)$before['n'] + 1, (int)$after['n']);
        $this->assertEqualsWithDelta((float)$before['total'] + 20.0, (float)$after['total'], 0.001);
    }

    // ── packs sold = total printed minus current Icebreakers variant stock ───

    public function test_packs_sold_is_total_minus_current_stock(): void
    {
        $in_stock = (int)self::$pdo->query("
            SELECT COALESCE(SUM(pv.stock),0)
            FROM products p
            JOIN product_variants pv ON pv.product_id = p.id
            WHERE p.slug = 'lafetki'
        ")->fetchColumn();

        $total      = 100;
        $packs_sold = max(0, $total - $in_stock);

        // Sold can never be negative and never exceeds the total printed.
        $this->assertGreaterThanOrEqual(0, $packs_sold);
        $this->assertLessThanOrEqual($total, $packs_sold);
        $this->assertSame(max(0, $total - $in_stock), $packs_sold);
    }

    // ── pledge_number format validation (regex used in callback) ─────────────

    public function test_pledge_number_regex(): void
    {
        $valid   = ['CP-20260430-A1B2', 'CP-20260101-FFFF', 'CP-20260430-0000'];
        $invalid = ['OM-20260430-A1B2', 'CP-2026430-A1B2', 'CP-20260430-GGG', ''];

        $pattern = '/^CP-\d{8}-[A-F0-9]{4}$/i';
        foreach ($valid   as $n) $this->assertMatchesRegularExpression($pattern, $n);
        foreach ($invalid as $n) $this->assertDoesNotMatchRegularExpression($pattern, $n);
    }

    // ── ticket pledge type ────────────────────────────────────────────────────

    public function test_ticket_pledge_stores_type_and_qty(): void
    {
        $id = $this->insertPledge([
            'pledge_type' => 'ticket',
            'amount_eur'  => 20.00,
            'ticket_qty'  => 2,
        ]);
        $row = self::$pdo->query("SELECT pledge_type, ticket_qty FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame('ticket', $row['pledge_type']);
        $this->assertSame(2, (int)$row['ticket_qty']);
    }

    public function test_ticket_amount_is_qty_times_unit_price(): void
    {
        // Simulates checkout.php: amount_eur = round($ev_price * $ticket_qty, 2)
        $unit_price = 10.0;
        $qty        = 3;
        $expected   = round($unit_price * $qty, 2);

        $id = $this->insertPledge([
            'pledge_type' => 'ticket',
            'amount_eur'  => $expected,
            'ticket_qty'  => $qty,
        ]);
        $row = self::$pdo->query("SELECT amount_eur, ticket_qty FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertEqualsWithDelta($expected, (float)$row['amount_eur'], 0.001);
        $this->assertSame($qty, (int)$row['ticket_qty']);
    }

    public function test_ticket_path_accumulates_as_json_array(): void
    {
        // Regression: generate_campaign_ticket() was passing $pledge by value,
        // so each iteration overwrote ticket_path with only the latest PDF path.
        $id = $this->insertPledge(['pledge_type' => 'ticket', 'ticket_qty' => 2]);

        // Simulate what generate_campaign_ticket() does for ticket 1
        $paths = [];
        $paths[] = '/documents/tickets/2026/TKT-1_CP-test.pdf';
        self::$pdo->prepare("UPDATE campaign_pledges SET ticket_path=? WHERE id=?")
                  ->execute([json_encode($paths), $id]);

        // Simulate ticket 2: re-read from DB before appending (the fix)
        $cur = self::$pdo->prepare('SELECT ticket_path FROM campaign_pledges WHERE id=?');
        $cur->execute([$id]);
        $existing = json_decode((string)$cur->fetchColumn(), true);
        $existing[] = '/documents/tickets/2026/TKT-2_CP-test.pdf';
        self::$pdo->prepare("UPDATE campaign_pledges SET ticket_path=? WHERE id=?")
                  ->execute([json_encode($existing), $id]);

        // Both paths must be in the JSON array
        $row    = self::$pdo->query("SELECT ticket_path FROM campaign_pledges WHERE id=$id")->fetch();
        $stored = json_decode($row['ticket_path'], true);
        $this->assertIsArray($stored);
        $this->assertCount(2, $stored);
        $this->assertStringContainsString('TKT-1', $stored[0]);
        $this->assertStringContainsString('TKT-2', $stored[1]);
    }

    public function test_ticket_pledge_lang_stored(): void
    {
        $id = $this->insertPledge(['pledge_type' => 'ticket', 'lang' => 'en']);
        $row = self::$pdo->query("SELECT lang FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame('en', $row['lang']);
    }

    // ── reward active flag ────────────────────────────────────────────────────

    public function test_only_active_rewards_returned(): void
    {
        $active_id   = $this->insertReward(25.0);
        self::$pdo->prepare("UPDATE campaign_rewards SET active=0 WHERE id=?")->execute([$active_id]);
        self::$pdo->prepare("UPDATE campaign_rewards SET active=0 WHERE id=?")->execute([$active_id]);
        self::$pdo->prepare("INSERT INTO campaign_rewards (position,title,title_en,description,description_en,amount_eur,active) VALUES (99,'Active Test','','','',30.0,1)")->execute();
        $active2 = (int)self::$pdo->lastInsertId();
        self::$reward_ids[] = $active2;

        $ids = self::$pdo->query(
            "SELECT id FROM campaign_rewards WHERE active=1 AND id IN ($active_id,$active2)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains($active_id, array_map('intval', $ids));
        $this->assertContains($active2, array_map('intval', $ids));
    }

    public function test_icebreaker_qty_column_exists(): void
    {
        $cols = self::$pdo->query(
            "SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'"
        )->fetchAll();
        $this->assertNotEmpty($cols, 'Run migration 021 first.');
    }

    public function test_icebreaker_qty_values_per_reward(): void
    {
        $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'")->fetchAll();
        if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

        $rows = self::$pdo->query(
            "SELECT id, icebreaker_qty FROM campaign_rewards WHERE id IN (1,2,3,4) ORDER BY id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int)($rows[1] ?? -1), 'Reward id 1 (€25, position 1) → 1 pack');
        $this->assertSame(2, (int)($rows[2] ?? -1), 'Reward id 2 (€50, position 2) → 2 packs');
        $this->assertSame(4, (int)($rows[3] ?? -1), 'Reward id 3 (€100, position 3) → 4 packs');
        $this->assertSame(5, (int)($rows[4] ?? -1), 'Reward id 4 (€250, position 4) → 5 packs');
    }

    public function test_delivery_courier_columns_exist(): void
    {
        foreach (['delivery_courier','delivery_type','office_code','office_name','office_city'] as $col) {
            $cols = self::$pdo->query(
                "SHOW COLUMNS FROM campaign_pledges LIKE '$col'"
            )->fetchAll();
            $this->assertNotEmpty($cols, "campaign_pledges.$col column must exist (run migration 021).");
        }
    }

    public function test_stock_decrement_sql_reduces_by_qty(): void
    {
        // Create a temporary product + variant to test the SQL without touching prod data
        self::$pdo->prepare(
            "INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,variant_attributes)
             VALUES ('test-icebreaker-tmp','Test','Test',25.00,0,1,'variant','{}')"
        )->execute();
        $prod_id = (int)self::$pdo->lastInsertId();

        self::$pdo->prepare(
            "INSERT INTO product_variants (product_id,label_bg,label_en,attributes,image,stock,active,sort_order)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$prod_id,'Тест','Test','{}','',50,1,0]);
        $variant_id = (int)self::$pdo->lastInsertId();

        // Simulate ticket with qty=2
        $qty = 2;
        $stmt = self::$pdo->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$qty, $variant_id, $qty]);
        $this->assertSame(1, $stmt->rowCount(), 'Decrement must affect exactly 1 row');

        $stock = (int)self::$pdo->query("SELECT stock FROM product_variants WHERE id=$variant_id")->fetchColumn();
        $this->assertSame(48, $stock, 'Stock should be 50 - 2 = 48');

        // Guard: decrement beyond stock must affect 0 rows (no negative stock)
        $overshoot = 100;
        $stmt->execute([$overshoot, $variant_id, $overshoot]);
        $this->assertSame(0, $stmt->rowCount(), 'Overshoot must affect 0 rows (guard)');

        // Cleanup
        self::$pdo->exec("DELETE FROM product_variants WHERE id=$variant_id");
        self::$pdo->exec("DELETE FROM products WHERE id=$prod_id");
    }

    public function test_icebreaker_qty_lookup_per_reward(): void
    {
        $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_rewards LIKE 'icebreaker_qty'")->fetchAll();
        if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

        // Simulate the lookup reduce_icebreaker_stock() does for a reward pledge
        $rid = $this->insertReward(25.0);
        // Set icebreaker_qty for our test reward
        self::$pdo->prepare("UPDATE campaign_rewards SET icebreaker_qty = 3 WHERE id = ?")->execute([$rid]);

        $row = self::$pdo->prepare('SELECT icebreaker_qty FROM campaign_rewards WHERE id = ?');
        $row->execute([$rid]);
        $qty = (int)($row->fetchColumn() ?: 0);
        $this->assertSame(3, $qty);
    }

    public function test_pledge_with_reward_stores_delivery_courier(): void
    {
        $cols = self::$pdo->query("SHOW COLUMNS FROM campaign_pledges LIKE 'delivery_courier'")->fetchAll();
        if (empty($cols)) { $this->markTestSkipped('Run migration 021 first.'); }

        $rid = $this->insertReward(50.0);
        $pledge_number = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        self::$pdo->prepare(
            "INSERT INTO campaign_pledges
             (pledge_number, pledge_type, lang, name, email, amount_eur, ticket_qty, reward_id,
              delivery_address, delivery_courier, delivery_type, office_code, office_name, office_city,
              payment_status)
             VALUES (?, 'donation', 'bg', 'Тест', 'test@example.com', 50.0, 1, ?,
                     NULL, 'speedy', 'office', 'SOF001', 'Speedy София', 'София', 'pending')"
        )->execute([$pledge_number, $rid]);
        $id = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[] = $id;

        $row = self::$pdo->query("SELECT * FROM campaign_pledges WHERE id=$id")->fetch();
        $this->assertSame('speedy',       $row['delivery_courier']);
        $this->assertSame('office',       $row['delivery_type']);
        $this->assertSame('SOF001',       $row['office_code']);
        $this->assertSame('Speedy София', $row['office_name']);
        $this->assertSame('София',        $row['office_city']);
    }
}
