<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unpaid online orders — includes/payment/unpaid_orders.php.
 * Pure-logic tests always run; DB tests self-skip without a database.
 * No emails are sent: every test passes a capturing mailer.
 */
#[Group('payment')]
final class UnpaidOrdersTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $order_ids   = [];
    private static array $product_ids = [];

    /** @var list<array{to:string,subject:string,html:string}> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/payment/unpaid_orders.php';
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        self::$pdo = get_pdo();
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (self::$order_ids) {
            $in = implode(',', array_fill(0, count(self::$order_ids), '?'));
            self::$pdo->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(self::$order_ids);
        }
        if (self::$product_ids) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute(self::$product_ids);
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute(self::$product_ids);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function mailer(bool $ok = true): callable
    {
        return function (string $to, string $subject, string $html) use ($ok): bool {
            $this->sent[] = compact('to', 'subject', 'html');
            return $ok;
        };
    }

    private function insertProduct(int $stock): int
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active) VALUES (?,?,?,?,?,?)')
            ->execute(['test-unpaid-' . uniqid(), 'Тест', 'Test', 10.00, $stock, 1]);
        return self::$product_ids[] = (int)self::$pdo->lastInsertId();
    }

    private function insertVariant(int $product_id, int $stock): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$product_id, 'M', 'M', '{}', $stock, 1, 0]);
        return (int)self::$pdo->lastInsertId();
    }

    /** Inserts an order created $age_minutes ago and returns the fresh row. */
    private function insertOrder(int $age_minutes, array $overrides = []): array
    {
        $d = array_merge([
            'type'           => 'physical',
            'status'         => 'new',
            'lang'           => 'bg',
            'customer_email' => 'shopper@example.com',
            'items'          => json_encode([]),
            'payment_method' => 'card',
            'payment_status' => 'pending',
        ], $overrides);

        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,lang,customer_name,customer_email,items,
                                 subtotal_eur,shipping_eur,total_eur,payment_method,payment_status,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW() - INTERVAL ? MINUTE)"
        )->execute([
            generate_order_number(), $d['type'], $d['status'], $d['lang'], 'Тест Клиент', $d['customer_email'],
            $d['items'], 20.00, 0, 20.00, $d['payment_method'], $d['payment_status'], $age_minutes,
        ]);
        $id = (int)self::$pdo->lastInsertId();
        self::$order_ids[] = $id;
        return $this->reload($id);
    }

    private function reload(int $id): array
    {
        $s = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    }

    private function stock(string $table, int $id): int
    {
        $s = self::$pdo->prepare("SELECT stock FROM $table WHERE id = ?");
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }

    private function dueNumbers(string $bucket): array
    {
        return array_column(unpaid_orders_due(self::$pdo)[$bucket], 'order_number');
    }

    // ── order_is_unpaid (pure) ────────────────────────────────────────────────

    public function testCardOrderBecomesUnpaidAfterOneHour(): void
    {
        $o = ['type' => 'physical', 'status' => 'new', 'payment_method' => 'card', 'payment_status' => 'pending'];
        $this->assertFalse(order_is_unpaid($o, 59));
        $this->assertTrue(order_is_unpaid($o, 60));
    }

    public function testIrisOrderBecomesUnpaidAfterTwoHours(): void
    {
        $o = ['type' => 'physical', 'status' => 'new', 'payment_method' => 'iris', 'payment_status' => 'pending'];
        $this->assertFalse(order_is_unpaid($o, 119));
        $this->assertTrue(order_is_unpaid($o, 120));
    }

    public function testDeclinedOrderIsUnpaidImmediately(): void
    {
        $o = ['type' => 'donation', 'status' => 'cancelled', 'payment_method' => 'card', 'payment_status' => 'pending'];
        $this->assertTrue(order_is_unpaid($o, 1));
    }

    public function testPaidOrOfflineOrTicketOrdersAreNeverUnpaid(): void
    {
        $base = ['type' => 'physical', 'status' => 'new', 'payment_method' => 'card', 'payment_status' => 'pending'];
        $this->assertFalse(order_is_unpaid(['payment_status' => 'paid'] + $base, 9999));
        $this->assertFalse(order_is_unpaid(['payment_method' => 'cod'] + $base, 9999));
        $this->assertFalse(order_is_unpaid(['type' => 'ticket'] + $base, 9999));
    }

    public function testRetryUrlPointsToTheRightBank(): void
    {
        $this->assertSame(SITE_URL . '/api/payment-return.php?retry=1&order=OM-20260919-B60E',
            payment_retry_url(['payment_method' => 'card', 'order_number' => 'OM-20260919-B60E']));
        $this->assertSame(SITE_URL . '/api/iris-payment-return.php?retry=1&order=OM-20260919-B60E',
            payment_retry_url(['payment_method' => 'iris', 'order_number' => 'OM-20260919-B60E']));
    }

    public function testPaymentMethodLabelsAreReadable(): void
    {
        $this->assertSame('Карта (DSK Банк)', payment_method_label('card'));
        $this->assertSame('Банков превод (IRIS)', payment_method_label('iris'));
        $this->assertSame('—', payment_method_label(null));
    }

    // ── email ─────────────────────────────────────────────────────────────────

    public function testEmailIsSentOnceWithTryAgainButton(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(5);

        $this->assertTrue(send_payment_failed_email(self::$pdo, $order, $this->mailer()));
        $this->assertFalse(send_payment_failed_email(self::$pdo, $this->reload((int)$order['id']), $this->mailer()));

        $this->assertCount(1, $this->sent);
        $this->assertSame('shopper@example.com', $this->sent[0]['to']);
        $this->assertStringContainsString($order['order_number'], $this->sent[0]['subject']);
        $this->assertStringContainsString('Опитай отново', $this->sent[0]['html']);
        $this->assertStringContainsString('/api/payment-return.php?retry=1&amp;order=' . $order['order_number'], $this->sent[0]['html']);
        $this->assertNotNull($this->reload((int)$order['id'])['payment_failed_email_at']);
    }

    public function testEnglishIrisEmail(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(5, ['lang' => 'en', 'payment_method' => 'iris']);

        send_payment_failed_email(self::$pdo, $order, $this->mailer());

        $this->assertStringContainsString('was not completed', $this->sent[0]['subject']);
        $this->assertStringContainsString('Try again', $this->sent[0]['html']);
        $this->assertStringContainsString('/api/iris-payment-return.php?retry=1', $this->sent[0]['html']);
    }

    public function testDonationEmailTalksAboutTheDonation(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(5, ['type' => 'donation']);

        send_payment_failed_email(self::$pdo, $order, $this->mailer());

        $this->assertStringContainsString('Дарението', $this->sent[0]['subject']);
        $this->assertStringContainsString('20.00', $this->sent[0]['html']);
    }

    public function testFailedSendIsRetriedLater(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(90);

        $this->assertFalse(send_payment_failed_email(self::$pdo, $order, $this->mailer(false)));
        $this->assertNull($this->reload((int)$order['id'])['payment_failed_email_at']);
        $this->assertTrue(send_payment_failed_email(self::$pdo, $order, $this->mailer()));
    }

    public function testPaidOrderIsNeverEmailed(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(90, ['payment_status' => 'paid', 'status' => 'confirmed']);

        $this->assertFalse(send_payment_failed_email(self::$pdo, $order, $this->mailer()));
        $this->assertCount(0, $this->sent);
    }

    // ── which orders the cron picks ──────────────────────────────────────────

    public function testCronEmailsCardAfterOneHourAndIrisAfterTwo(): void
    {
        $this->requireDb();
        $card_young = $this->insertOrder(30);
        $card_due   = $this->insertOrder(61);
        $iris_young = $this->insertOrder(90, ['payment_method' => 'iris']);
        $iris_due   = $this->insertOrder(121, ['payment_method' => 'iris']);
        $cod        = $this->insertOrder(300, ['payment_method' => 'cod']);

        $due = $this->dueNumbers('to_email');

        $this->assertContains($card_due['order_number'], $due);
        $this->assertContains($iris_due['order_number'], $due);
        $this->assertNotContains($card_young['order_number'], $due);
        $this->assertNotContains($iris_young['order_number'], $due);
        $this->assertNotContains($cod['order_number'], $due);
    }

    public function testCronLeavesOldHistoricOrdersAlone(): void
    {
        $this->requireDb();
        $old = $this->insertOrder(10 * 24 * 60);

        $this->assertNotContains($old['order_number'], $this->dueNumbers('to_email'));
        $this->assertNotContains($old['order_number'], $this->dueNumbers('to_cancel'));
    }

    public function testRunJobEmailsOnlyOnce(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(61, ['customer_email' => 'once-' . uniqid() . '@example.com']);

        run_unpaid_orders_job(self::$pdo, $this->mailer());
        run_unpaid_orders_job(self::$pdo, $this->mailer());

        $this->assertCount(1, array_filter($this->sent, fn($m) => $m['to'] === $order['customer_email']));
    }

    public function testRunJobSkipsOrdersTheBankSaysArePaid(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(61, ['customer_email' => 'paid-' . uniqid() . '@example.com']);

        $refresh = function (PDO $pdo, array $o) use ($order): void {
            if ((int)$o['id'] === (int)$order['id']) {
                $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$o['id']]);
            }
        };
        run_unpaid_orders_job(self::$pdo, $this->mailer(), $refresh);

        $this->assertCount(0, array_filter($this->sent, fn($m) => $m['to'] === $order['customer_email']));
    }

    // ── 24h cancel + restock ─────────────────────────────────────────────────

    public function testCancelAfter24hPutsItemsBackInStockOnce(): void
    {
        $this->requireDb();
        $simple  = $this->insertProduct(5);
        $variant_product = $this->insertProduct(0);
        $variant = $this->insertVariant($variant_product, 1);
        $items = json_encode([
            ['product_id' => $simple, 'quantity' => 2, 'subtotal_eur' => 20.0],
            ['product_id' => $variant_product, 'variant_id' => $variant, 'quantity' => 3, 'subtotal_eur' => 30.0],
            ['type' => 'donation', 'amount_eur' => 5.0, 'subtotal_eur' => 5.0],
        ]);
        $order = $this->insertOrder(25 * 60, ['items' => $items]);

        $this->assertContains($order['order_number'], $this->dueNumbers('to_cancel'));
        $this->assertTrue(cancel_unpaid_order(self::$pdo, $order));
        $this->assertFalse(cancel_unpaid_order(self::$pdo, $this->reload((int)$order['id'])));

        $this->assertSame(7, $this->stock('products', $simple));
        $this->assertSame(4, $this->stock('product_variants', $variant));
        $fresh = $this->reload((int)$order['id']);
        $this->assertSame('cancelled', $fresh['status']);
        $this->assertNotNull($fresh['unpaid_cancelled_at']);
    }

    public function testDeclinedOrderIsStillRestockedAfter24h(): void
    {
        $this->requireDb();
        $pid   = $this->insertProduct(0);
        $order = $this->insertOrder(25 * 60, [
            'status' => 'cancelled',
            'items'  => json_encode([['product_id' => $pid, 'quantity' => 1]]),
        ]);

        $this->assertTrue(cancel_unpaid_order(self::$pdo, $order));
        $this->assertSame(1, $this->stock('products', $pid));
    }

    public function testShippedOrderIsNeverAutoCancelled(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(25 * 60, ['status' => 'shipped']);

        $this->assertFalse(cancel_unpaid_order(self::$pdo, $order));
        $this->assertNotContains($order['order_number'], $this->dueNumbers('to_cancel'));
    }

    public function testNoEmailAfterAutoCancel(): void
    {
        $this->requireDb();
        $order = $this->insertOrder(25 * 60);
        cancel_unpaid_order(self::$pdo, $order);

        $this->assertFalse(send_payment_failed_email(self::$pdo, $this->reload((int)$order['id']), $this->mailer()));
    }

    public function testLatePaymentTakesStockBackOut(): void
    {
        $this->requireDb();
        $pid   = $this->insertProduct(0);
        $order = $this->insertOrder(25 * 60, ['items' => json_encode([['product_id' => $pid, 'quantity' => 2]])]);
        cancel_unpaid_order(self::$pdo, $order);
        $this->assertSame(2, $this->stock('products', $pid));

        $order = $this->reload((int)$order['id']);
        unpaid_order_reinstate_for_late_payment(self::$pdo, $order);
        unpaid_order_reinstate_for_late_payment(self::$pdo, $order); // idempotent

        $this->assertSame(0, $this->stock('products', $pid));
        $this->assertNull($this->reload((int)$order['id'])['unpaid_cancelled_at']);
    }

    // ── admin filter matches the badge ───────────────────────────────────────

    public function testAdminFilterMatchesBadge(): void
    {
        $this->requireDb();
        $unpaid = $this->insertOrder(61);
        $fresh  = $this->insertOrder(10);

        $ids = self::$pdo->query('SELECT id FROM orders WHERE ' . unpaid_orders_sql_condition())->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains($unpaid['id'], $ids);
        $this->assertNotContains($fresh['id'], $ids);
        $this->assertTrue(order_is_unpaid($unpaid, 61));
        $this->assertFalse(order_is_unpaid($fresh, 10));
    }
}
