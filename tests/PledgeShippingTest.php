<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__) . '/includes/pledge_shipping.php';

#[Group('campaign')]
final class PledgeShippingTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $pledge_ids   = [];
    private static array $order_numbers = [];
    private static array $reward_ids   = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (!empty(self::$order_numbers)) {
            $in = implode(',', array_fill(0, count(self::$order_numbers), '?'));
            self::$pdo->prepare("DELETE FROM orders WHERE order_number IN ($in) AND type='pledge'")
                      ->execute(self::$order_numbers);
        }
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

    private function requireDb(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No DB configured (db.config.php missing or unreachable).');
        }
        try {
            self::$pdo->query('SELECT 1 FROM campaign_pledges LIMIT 1');
            self::$pdo->query("SHOW COLUMNS FROM campaign_pledges LIKE 'delivery_courier'");
        } catch (Throwable) {
            $this->markTestSkipped('Campaign delivery columns not found — run migration 021.');
        }
    }

    private function insertDeliveryPledge(array $o): array
    {
        $num = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        self::$pdo->prepare(
            "INSERT INTO campaign_pledges
             (pledge_number, pledge_type, lang, name, email, phone, amount_eur, ticket_qty, reward_id,
              delivery_address, delivery_courier, delivery_type, office_code, office_name, office_city,
              reward_shipped, payment_status)
             VALUES (?, 'donation', 'bg', 'Тест', 'ship-test@example.com', ?, 25.0, 1, NULL,
                     ?, ?, ?, ?, ?, ?, 0, 'paid')"
        )->execute([
            $num,
            $o['phone'] ?? null,
            $o['delivery_address'] ?? null,
            $o['delivery_courier'] ?? null,
            $o['delivery_type'] ?? null,
            $o['office_code'] ?? null,
            $o['office_name'] ?? null,
            $o['office_city'] ?? null,
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[]    = $id;
        self::$order_numbers[] = $num;
        return self::$pdo->query("SELECT * FROM campaign_pledges WHERE id=$id")->fetch();
    }

    private function makeReward(): int
    {
        self::$pdo->prepare(
            "INSERT INTO campaign_rewards (position,title,title_en,description,description_en,amount_eur,active)
             VALUES (98,'Ship Test Reward','','','',25.0,1)"
        )->execute();
        $rid = (int)self::$pdo->lastInsertId();
        self::$reward_ids[] = $rid;
        return $rid;
    }

    /** Insert a pledge with explicit columns; returns [id, pledge_number]. */
    private function insertPledge(array $o): array
    {
        $num = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        self::$pdo->prepare(
            "INSERT INTO campaign_pledges
             (pledge_number, pledge_type, lang, name, email, amount_eur, ticket_qty, reward_id,
              delivery_courier, reward_shipped, payment_status)
             VALUES (?, ?, 'bg', 'Тест', 'ship-test@example.com', 25.0, 1, ?, ?, ?, ?)"
        )->execute([
            $num,
            $o['pledge_type']     ?? 'donation',
            $o['reward_id']       ?? null,
            $o['delivery_courier']?? null,
            $o['reward_shipped']  ?? 0,
            $o['payment_status']  ?? 'paid',
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[]    = $id;
        self::$order_numbers[] = $num;
        return [$id, $num];
    }

    /** Insert the synthetic type='pledge' order row that mirrors a pledge, with a given status. */
    private function insertPledgeOrder(string $order_number, string $status): void
    {
        self::$pdo->prepare(
            "INSERT INTO orders
             (order_number, type, status, customer_name, customer_email,
              items, subtotal_eur, total_eur, payment_status)
             VALUES (?, 'pledge', ?, 'Тест', 'ship-test@example.com', '[]', 25.0, 25.0, 'paid')"
        )->execute([$order_number, $status]);
    }

    // ── pledges_awaiting_shipping (dashboard "За изпращане" card) ────────────────

    public function test_awaiting_shipping_keys_off_order_status_not_reward_shipped(): void
    {
        $this->requireDb();
        $rid = $this->makeReward();

        // Due to ship: reward pledge with NO courier recorded and no order row yet.
        [$rewardNoCourier] = $this->insertPledge(['reward_id' => $rid, 'delivery_courier' => null]);
        // Due to ship: courier-only donation, no reward.
        [$courierNoReward] = $this->insertPledge(['reward_id' => null, 'delivery_courier' => 'speedy']);
        // Due to ship: label already printed (reward_shipped=1) but order still 'confirmed'.
        // This is the regression — a printed label must NOT hide the pack.
        [$labeledConfirmed, $labeledConfirmedNum] =
            $this->insertPledge(['reward_id' => $rid, 'delivery_courier' => 'speedy', 'reward_shipped' => 1]);
        $this->insertPledgeOrder($labeledConfirmedNum, 'confirmed');

        // Not due: order marked shipped — the only real "done" signal.
        [$orderShipped, $orderShippedNum] =
            $this->insertPledge(['reward_id' => $rid, 'delivery_courier' => 'speedy']);
        $this->insertPledgeOrder($orderShippedNum, 'shipped');
        // Not due: order cancelled.
        [$orderCancelled, $orderCancelledNum] =
            $this->insertPledge(['reward_id' => $rid, 'delivery_courier' => 'speedy']);
        $this->insertPledgeOrder($orderCancelledNum, 'cancelled');

        // Not due: plain donation (no reward, no courier).
        [$plainDonation] = $this->insertPledge(['reward_id' => null, 'delivery_courier' => null]);
        // Not due: ticket pledge.
        [$ticket] = $this->insertPledge(['pledge_type' => 'ticket', 'reward_id' => null, 'delivery_courier' => null]);
        // Not due: unpaid reward pledge.
        [$unpaid] = $this->insertPledge(['reward_id' => $rid, 'delivery_courier' => 'speedy', 'payment_status' => 'pending']);

        $ids = array_map(fn($r) => (int)$r['id'], pledges_awaiting_shipping(self::$pdo));

        $this->assertContains($rewardNoCourier, $ids, 'reward pledge without courier must appear');
        $this->assertContains($courierNoReward, $ids, 'courier-only donation must appear');
        $this->assertContains($labeledConfirmed, $ids, 'printed label with confirmed order must still appear');
        $this->assertNotContains($orderShipped, $ids, 'order marked shipped must be hidden');
        $this->assertNotContains($orderCancelled, $ids, 'cancelled order must be hidden');
        $this->assertNotContains($plainDonation, $ids);
        $this->assertNotContains($ticket, $ids);
        $this->assertNotContains($unpaid, $ids);
    }

    // ── Pure helpers (no DB) ────────────────────────────────────────────────────

    public function test_delivery_addr_decodes_json(): void
    {
        $p = ['delivery_address' => '{"address":"ул. Тест 1","city":"София","phone":"+359888"}'];
        $this->assertSame('ул. Тест 1', pledge_delivery_addr($p)['address']);
        $this->assertSame('София',       pledge_delivery_addr($p)['city']);
    }

    public function test_delivery_addr_empty_for_null_or_garbage(): void
    {
        $this->assertSame([], pledge_delivery_addr(['delivery_address' => null]));
        $this->assertSame([], pledge_delivery_addr(['delivery_address' => 'not json']));
        $this->assertSame([], pledge_delivery_addr([]));
    }

    public function test_phone_prefers_column_then_address_json(): void
    {
        $this->assertSame('0888111', pledge_phone(['phone' => '0888111']));
        $this->assertSame('0888222', pledge_phone([
            'phone' => '', 'delivery_address' => '{"phone":"0888222"}',
        ]));
        $this->assertSame('', pledge_phone(['phone' => null]));
    }

    // ── pledge_sync_order_delivery (DB) ─────────────────────────────────────────

    public function test_sync_backfills_speedy_office_onto_order(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'speedy', 'delivery_type' => 'office',
            'office_code' => 'SOF42', 'office_name' => 'Speedy НДК', 'office_city' => 'София',
        ]);

        $order_id = pledge_sync_order_delivery(self::$pdo, $pledge);
        $row = self::$pdo->query("SELECT * FROM orders WHERE id=$order_id")->fetch();

        $this->assertSame('speedy', $row['courier']);
        $this->assertSame('office', $row['delivery_type']);
        $this->assertSame('SOF42',  $row['courier_office_code']);
        $this->assertSame('Speedy НДК', $row['courier_office_name']);
        $this->assertSame('София',   $row['delivery_city']);
        $this->assertNull($row['delivery_address']); // office → no street address
    }

    public function test_sync_backfills_address_delivery_onto_order(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'speedy', 'delivery_type' => 'address',
            'delivery_address' => '{"address":"ул. Роза 5","city":"Пловдив","phone":"+359877"}',
        ]);

        $order_id = pledge_sync_order_delivery(self::$pdo, $pledge);
        $row = self::$pdo->query("SELECT * FROM orders WHERE id=$order_id")->fetch();

        $this->assertSame('ул. Роза 5', $row['delivery_address']);
        $this->assertSame('Пловдив',     $row['delivery_city']);
        $this->assertSame('+359877',     $row['customer_phone']);
    }

    // ── Validation throws before any courier API call (DB) ──────────────────────

    public function test_speedy_label_rejects_wrong_courier(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'boxnow', 'delivery_type' => 'locker', 'office_code' => '8910',
        ]);
        $this->expectException(RuntimeException::class);
        pledge_create_speedy_label(self::$pdo, $pledge, 1.0, 1);
    }

    public function test_speedy_office_without_code_throws(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'speedy', 'delivery_type' => 'office',
            'office_name' => 'Speedy НДК', 'office_city' => 'София', // office_code intentionally null
        ]);
        $this->expectExceptionMessageMatches('/office ID/u');
        pledge_create_speedy_label(self::$pdo, $pledge, 1.0, 1);
    }

    public function test_boxnow_label_without_locker_throws(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'boxnow', 'delivery_type' => 'locker', // office_code null
        ]);
        $this->expectException(RuntimeException::class);
        pledge_create_boxnow_label(self::$pdo, $pledge, 1);
    }

    public function test_boxnow_label_without_phone_throws(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'boxnow', 'delivery_type' => 'locker',
            'office_code' => '8910', // locker present, phone intentionally null
        ]);
        $this->expectExceptionMessageMatches('/телефон/u');
        pledge_create_boxnow_label(self::$pdo, $pledge, 1);
    }

    public function test_speedy_label_without_phone_throws(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'speedy', 'delivery_type' => 'office',
            'office_code' => 'SOF42', 'office_name' => 'Speedy НДК', 'office_city' => 'София',
            // phone intentionally null
        ]);
        $this->expectExceptionMessageMatches('/телефон/u');
        pledge_create_speedy_label(self::$pdo, $pledge, 1.0, 1);
    }

    public function test_shipment_description_falls_back_without_reward(): void
    {
        $this->requireDb();
        $pledge = $this->insertDeliveryPledge([
            'delivery_courier' => 'boxnow', 'delivery_type' => 'locker', 'office_code' => '1',
        ]);
        $desc = pledge_shipment_description(self::$pdo, $pledge);
        $this->assertStringContainsString($pledge['pledge_number'], $desc);
    }
}
