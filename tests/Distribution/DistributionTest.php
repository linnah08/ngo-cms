<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Distribution module — includes/distribution.php against the real schema.
 * Each test makes its own throwaway products so the ledgers start at zero;
 * everything it creates is removed in tearDownAfterClass.
 */
#[Group('distribution')]
#[Group('db')]
final class DistributionTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids     = [];
    private static array $distributor_ids = [];
    private static array $order_ids       = [];
    private static ?int  $sequence_before = null;
    private static string $tmp_root       = '';

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/distribution.php';
        if (!test_db_available()) return;
        self::$pdo = get_pdo();
        try {
            self::$pdo->query('SELECT 1 FROM distribution_batches LIMIT 1');
        } catch (PDOException $e) {
            self::$pdo = null; // migration 041 not applied on this DB
            return;
        }
        $seq = self::$pdo->query("SELECT last_number FROM document_sequences WHERE type = 'distribution_protocol'")->fetchColumn();
        self::$sequence_before = $seq === false ? null : (int) $seq;
        self::$tmp_root = sys_get_temp_dir() . '/dist_test_' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        $pdo = self::$pdo;
        $in  = fn(array $ids) => implode(',', array_fill(0, count($ids), '?'));
        if (self::$order_ids) {
            $pdo->prepare('DELETE FROM orders WHERE id IN (' . $in(self::$order_ids) . ')')->execute(self::$order_ids);
        }
        if (self::$product_ids) {
            $p = self::$product_ids;
            foreach (['distribution_sales', 'distribution_allocations', 'distribution_batches', 'product_variants'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE product_id IN (" . $in($p) . ')')->execute($p);
            }
            $pdo->prepare('DELETE FROM products WHERE id IN (' . $in($p) . ')')->execute($p);
        }
        if (self::$distributor_ids) {
            $d = self::$distributor_ids;
            $pdo->prepare('DELETE FROM distribution_distributors WHERE id IN (' . $in($d) . ')')->execute($d);
        }
        if (self::$sequence_before !== null) {
            $pdo->prepare("UPDATE document_sequences SET last_number = ? WHERE type = 'distribution_protocol'")
                ->execute([self::$sequence_before]);
        }
        if (self::$tmp_root && is_dir(self::$tmp_root)) {
            exec('rm -rf ' . escapeshellarg(self::$tmp_root));
        }
    }

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with the distribution tables (migration 041).');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function product(int $stock = 0, string $type = 'standard', float $price = 10.0): int
    {
        self::$pdo->prepare('INSERT INTO products (slug, name_bg, name_en, price_eur, stock, active, `type`) VALUES (?, ?, ?, ?, ?, 1, ?)')
            ->execute(['test-dist-' . bin2hex(random_bytes(5)), 'Тестова книга', 'Test book', $price, $stock, $type]);
        return self::$product_ids[] = (int) self::$pdo->lastInsertId();
    }

    private function variant(int $product_id, string $label, int $stock = 0): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id, label_bg, label_en, attributes, stock, active, sort_order) VALUES (?, ?, ?, ?, ?, 1, 0)')
            ->execute([$product_id, $label, $label, '{}', $stock]);
        return (int) self::$pdo->lastInsertId();
    }

    private function distributor(string $name = 'Книжарница Тест'): int
    {
        return self::$distributor_ids[] = distribution_create_distributor(self::$pdo, $name, '123456789', 'Иван', 'ул. Тест 1', 'shop@example.test');
    }

    private function batch(int $pid, int $qty, ?int $vid = null): int
    {
        $r = distribution_create_batch(self::$pdo, $pid, $vid, $qty, '2026-01-10', 'тест');
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        return $r['id'];
    }

    private function alloc(int $pid, string $dest, int $qty, array $o = []): array
    {
        return distribution_create_allocation(
            self::$pdo, $pid, $o['variant'] ?? null, $dest, $o['distributor'] ?? null, $qty,
            $o['price'] ?? 10.0, $o['date'] ?? '2026-02-01', null, $o['shop'] ?? false, $o['recipient'] ?? null
        );
    }

    // ── pure helpers ───────────────────────────────────────────────────────────

    public function testDateValidation(): void
    {
        $this->assertSame('2026-02-28', distribution_date('2026-02-28'));
        $this->assertNull(distribution_date('2026-02-30'));
        $this->assertNull(distribution_date('28.02.2026'));
        $this->assertNull(distribution_date(''));
    }

    public function testDistributorValidation(): void
    {
        $this->assertNull(distribution_distributor_error('Книжарница', '123', null));
        $this->assertStringContainsString('име', distribution_distributor_error(' ', '123', null));
        $this->assertStringContainsString('ЕИК', distribution_distributor_error('Книжарница', '', null));
        $this->assertStringContainsString('Имейл', distribution_distributor_error('Книжарница', '123', 'not-an-email'));
        $this->assertNotNull(distribution_distributor_error('Книжарница', str_repeat('1', 21), null));
    }

    // ── items ──────────────────────────────────────────────────────────────────

    public function testItemRules(): void
    {
        $plain = $this->product();
        $var   = $this->product(0, 'variant');
        $v1    = $this->variant($var, 'Синя');
        $other = $this->variant($this->product(0, 'variant'), 'Чужда');

        $this->assertNull(distribution_item_error(self::$pdo, $plain, null));
        $this->assertNotNull(distribution_item_error(self::$pdo, $plain, $v1), 'a plain product takes no variant');
        $this->assertNotNull(distribution_item_error(self::$pdo, $var, null), 'a variant product needs its variant');
        $this->assertNotNull(distribution_item_error(self::$pdo, $var, $other), 'another product\'s variant is refused');
        $this->assertNull(distribution_item_error(self::$pdo, $var, $v1));
        $this->assertNotNull(distribution_item_error(self::$pdo, 999999999, null));

        $this->assertSame('Тестова книга (Синя)', distribution_item_label(self::$pdo, $var, $v1));
        $items = distribution_product_items(self::$pdo, distribution_product(self::$pdo, $var));
        $this->assertSame([$v1], array_column($items, 'variant_id'));
        $this->assertSame([null], array_column(distribution_product_items(self::$pdo, distribution_product(self::$pdo, $plain)), 'variant_id'));
    }

    // ── batches + pool ─────────────────────────────────────────────────────────

    public function testBatchesFeedThePoolAndCannotShrinkBelowAllocated(): void
    {
        $pid = $this->product();
        $b   = $this->batch($pid, 100);
        $this->batch($pid, 50);
        $this->assertSame(150, distribution_available(self::$pdo, $pid, null));

        $this->assertFalse(distribution_create_batch(self::$pdo, $pid, null, 0, '2026-01-01')['ok']);
        $this->assertFalse(distribution_create_batch(self::$pdo, $pid, null, 5, 'вчера')['ok']);

        $this->assertTrue($this->alloc($pid, 'personal', 120)['ok']);
        $r = distribution_update_batch(self::$pdo, $b, 60, '2026-01-10');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('70', $r['error'], 'tells the smallest allowed quantity');
        $this->assertTrue(distribution_update_batch(self::$pdo, $b, 70, '2026-01-11', 'поправено')['ok']);
        $this->assertSame(0, distribution_available(self::$pdo, $pid, null));
    }

    public function testVariantsKeepSeparateLedgers(): void
    {
        $pid = $this->product(0, 'variant');
        $a   = $this->variant($pid, 'A');
        $b   = $this->variant($pid, 'B');
        $this->batch($pid, 10, $a);
        $this->batch($pid, 3, $b);

        $r = $this->alloc($pid, 'personal', 5, ['variant' => $b]);
        $this->assertFalse($r['ok'], 'B only has 3 even though A has 10');
        $this->assertTrue($this->alloc($pid, 'personal', 3, ['variant' => $b])['ok']);
        $this->assertSame(10, distribution_available(self::$pdo, $pid, $a));
        $this->assertSame(0, distribution_available(self::$pdo, $pid, $b));

        $sum = distribution_product_summary(self::$pdo, distribution_product(self::$pdo, $pid));
        $this->assertCount(2, $sum['items']);
        $this->assertSame(13, $sum['total']['produced']);
        $this->assertSame(10, $sum['total']['available']);
    }

    // ── allocations ────────────────────────────────────────────────────────────

    public function testDistributorAllocationChecksPoolAndDistributor(): void
    {
        $pid = $this->product();
        $d   = $this->distributor();
        $this->batch($pid, 20);

        $this->assertFalse($this->alloc($pid, 'distributor', 5)['ok'], 'needs a distributor');
        $this->assertFalse($this->alloc($pid, 'distributor', 21, ['distributor' => $d])['ok'], 'more than produced');
        $this->assertFalse($this->alloc($pid, 'distributor', 5, ['distributor' => $d, 'price' => 0])['ok'], 'needs a price');
        $this->assertFalse($this->alloc($pid, 'teleport', 5)['ok']);

        $r = $this->alloc($pid, 'distributor', 12, ['distributor' => $d]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['allocated' => 12, 'sold' => 0, 'unsold' => 12], distribution_distributor_stock(self::$pdo, $d, $pid, null));
        $this->assertSame(8, distribution_available(self::$pdo, $pid, null));

        distribution_set_distributors_active(self::$pdo, [$d], false);
        $r = $this->alloc($pid, 'distributor', 1, ['distributor' => $d]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('архивиран', $r['error']);
        $this->assertSame(1, distribution_set_distributors_active(self::$pdo, [$d, 0, -4], true));
    }

    public function testOnlineAllocationAddsToShopStockOnlyWhenAsked(): void
    {
        $pid = $this->product(2);
        $this->batch($pid, 30);

        $this->assertTrue($this->alloc($pid, 'online', 10)['ok']);
        $this->assertSame(2, distribution_online_stock(self::$pdo, $pid, null), 'records only — shop stock untouched');

        $this->assertTrue($this->alloc($pid, 'online', 5, ['shop' => true])['ok']);
        $this->assertSame(7, distribution_online_stock(self::$pdo, $pid, null));
        $this->assertSame(15, distribution_total_allocated(self::$pdo, $pid, null, 'online'));
        $this->assertSame(15, distribution_available(self::$pdo, $pid, null));
    }

    public function testPersonalSaleFromShopMovesStockOutOfTheShop(): void
    {
        $pid = $this->product();
        $this->batch($pid, 20);
        $this->assertTrue($this->alloc($pid, 'online', 6, ['shop' => true])['ok']);
        $this->assertTrue($this->alloc($pid, 'online', 4, ['shop' => true])['ok']);
        $this->assertSame(10, distribution_online_stock(self::$pdo, $pid, null));

        $r = $this->alloc($pid, 'personal', 11, ['shop' => true]);
        $this->assertFalse($r['ok'], 'only 10 were sent to the shop');

        $this->assertTrue($this->alloc($pid, 'personal', 5, ['shop' => true])['ok']);
        $this->assertSame(5, distribution_online_stock(self::$pdo, $pid, null));
        $this->assertSame(5, distribution_total_allocated(self::$pdo, $pid, null, 'online'), 'newest online rows shrink first');
        $this->assertSame(10, distribution_available(self::$pdo, $pid, null), 'pool untouched');

        // Live stock lower than the records (sold online meanwhile) blocks it.
        distribution_set_online_stock(self::$pdo, $pid, null, 1);
        $r = $this->alloc($pid, 'personal', 3, ['shop' => true]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('само 1', $r['error']);
    }

    public function testSampleUsesPoolFirstThenShop(): void
    {
        $pid = $this->product();
        $this->batch($pid, 10);
        $this->assertTrue($this->alloc($pid, 'online', 8, ['shop' => true])['ok']); // pool 2, shop 8

        $this->assertFalse($this->alloc($pid, 'sample', 2)['ok'], 'needs a recipient');
        $this->assertFalse($this->alloc($pid, 'sample', 11, ['recipient' => 'Училище'])['ok'], 'pool 2 + shop 8 < 11');

        $r = $this->alloc($pid, 'sample', 5, ['recipient' => 'Училище', 'price' => 99]);
        $this->assertTrue($r['ok']);
        $this->assertSame(0, distribution_available(self::$pdo, $pid, null));
        $this->assertSame(5, distribution_online_stock(self::$pdo, $pid, null));
        $row = self::$pdo->query('SELECT unit_price_eur, sample_recipient FROM distribution_allocations WHERE id = ' . (int) $r['id'])->fetch();
        $this->assertSame(0.0, (float) $row['unit_price_eur'], 'samples are free');
        $this->assertSame('Училище', $row['sample_recipient']);
    }

    public function testUpdateAllocationLimits(): void
    {
        $pid = $this->product();
        $d   = $this->distributor();
        $this->batch($pid, 10);
        $id = $this->alloc($pid, 'distributor', 6, ['distributor' => $d])['id'];
        $this->assertTrue(distribution_record_sale(self::$pdo, $d, $pid, null, 4, '2026-03-01', false)['ok']);

        $this->assertFalse(distribution_update_allocation(self::$pdo, $id, 11, 10.0, '2026-02-01')['ok'], 'more than produced');
        $r = distribution_update_allocation(self::$pdo, $id, 3, 10.0, '2026-02-01');
        $this->assertFalse($r['ok'], 'below what was already sold');
        $this->assertStringContainsString('4', $r['error']);
        $this->assertTrue(distribution_update_allocation(self::$pdo, $id, 10, 12.5, '2026-02-02', 'ok')['ok']);
        $this->assertSame(0, distribution_available(self::$pdo, $pid, null));
        $this->assertFalse(distribution_update_allocation(self::$pdo, 999999999, 1, 1.0, '2026-02-02')['ok']);
    }

    // ── sales ──────────────────────────────────────────────────────────────────

    public function testSalesAndPayments(): void
    {
        $pid = $this->product();
        $d   = $this->distributor();
        $this->batch($pid, 10);
        $this->alloc($pid, 'distributor', 8, ['distributor' => $d]);

        $this->assertFalse(distribution_record_sale(self::$pdo, $d, $pid, null, 9, '2026-03-01', false)['ok']);
        $this->assertFalse(distribution_record_sale(self::$pdo, $d, $pid, null, 1, '2026-03-01', true, 'скоро')['ok'], 'paid needs a date');
        $s1 = distribution_record_sale(self::$pdo, $d, $pid, null, 3, '2026-03-01', false)['id'];
        $s2 = distribution_record_sale(self::$pdo, $d, $pid, null, 2, '2026-03-02', true, '2026-03-05')['id'];

        $this->assertSame(['paid_qty' => 2, 'unpaid_qty' => 3], distribution_distributor_payment_summary(self::$pdo, $d, $pid));
        $this->assertSame(3, distribution_distributor_stock(self::$pdo, $d, $pid, null)['unsold']);

        $this->assertFalse(distribution_update_sale(self::$pdo, $s1, 7, '2026-03-01', false)['ok'], '3 + 3 left = 6 max');
        $this->assertTrue(distribution_update_sale(self::$pdo, $s1, 6, '2026-03-01', false)['ok']);

        $this->assertSame(1, distribution_set_sales_paid(self::$pdo, [$s1, $s2], true, '2026-04-01'), 'already-paid sale keeps its date');
        $paid_on = self::$pdo->query("SELECT payment_received_on FROM distribution_sales WHERE id = $s2")->fetchColumn();
        $this->assertSame('2026-03-05', $paid_on);
        $this->assertSame(2, distribution_set_sales_paid(self::$pdo, [$s1, $s2], false, '2026-04-01'));
        $this->assertSame(['paid_qty' => 0, 'unpaid_qty' => 8], distribution_distributor_payment_summary(self::$pdo, $d, $pid));
        $this->assertSame(0, distribution_set_sales_paid(self::$pdo, [], true, '2026-04-01'));
    }

    // ── shop + protocol ────────────────────────────────────────────────────────

    public function testShopOrderedQtyCountsNonCancelledOrders(): void
    {
        $pid = $this->product(0, 'variant');
        $va  = $this->variant($pid, 'A');
        $vb  = $this->variant($pid, 'B');
        foreach ([['new', $va, 2], ['delivered', $vb, 3], ['cancelled', $va, 7]] as [$status, $vid, $qty]) {
            self::$pdo->prepare(
                "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
                 VALUES (?, 'physical', ?, 'Тест', 't@example.test', ?, 10, 0, 10, 'cod', 'pending')"
            )->execute(['D' . substr(bin2hex(random_bytes(7)), 0, 13), $status,
                json_encode([['product_id' => $pid, 'variant_id' => $vid, 'quantity' => $qty]])]);
            self::$order_ids[] = (int) self::$pdo->lastInsertId();
        }
        $this->assertSame(2, distribution_shop_ordered_qty(self::$pdo, $pid, $va));
        $this->assertSame(3, distribution_shop_ordered_qty(self::$pdo, $pid, $vb));
        $this->assertSame(5, distribution_shop_ordered_qty(self::$pdo, $pid, null, true));
    }

    public function testProtocolIsNumberedWrittenAndRewrittenUnderTheSameNumber(): void
    {
        $pid = $this->product(0, 'standard', 7.5);
        $d   = $this->distributor('Партньор ООД');
        $this->batch($pid, 10);
        $id = $this->alloc($pid, 'distributor', 4, ['distributor' => $d, 'price' => 7.5])['id'];

        $before = (int) self::$pdo->query("SELECT last_number FROM document_sequences WHERE type = 'distribution_protocol'")->fetchColumn();
        $r = distribution_issue_protocol(self::$pdo, $id, self::$tmp_root);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(str_pad((string) ($before + 1), 5, '0', STR_PAD_LEFT), $r['number']);

        $row = self::$pdo->query("SELECT * FROM distribution_allocations WHERE id = $id")->fetch();
        $this->assertSame('documents/distribution_protocols/2026/' . $r['number'] . '.pdf', $row['protocol_file_path']);
        $file = self::$tmp_root . '/' . $row['protocol_file_path'];
        $this->assertFileExists($file);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($file, false, null, 0, 4));

        distribution_update_allocation(self::$pdo, $id, 5, 7.5, '2026-02-01');
        $again = distribution_issue_protocol(self::$pdo, $id, self::$tmp_root);
        $this->assertSame($r['number'], $again['number'], 're-issued under the same number');
        $this->assertSame($before + 1, (int) self::$pdo->query("SELECT last_number FROM document_sequences WHERE type = 'distribution_protocol'")->fetchColumn());

        $online = $this->alloc($pid, 'online', 1)['id'];
        $this->assertFalse(distribution_issue_protocol(self::$pdo, $online, self::$tmp_root)['ok'], 'no protocol for the shop');
    }

    public function testProtocolFieldsAndHtml(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/documents/DistributionProtocolGenerator.php';
        $pid = $this->product(0, 'variant', 4.0);
        $vid = $this->variant($pid, 'Червена');
        $d   = $this->distributor('Магазин <Б&Б>');
        $this->batch($pid, 5, $vid);
        $id  = $this->alloc($pid, 'distributor', 3, ['distributor' => $d, 'variant' => $vid, 'price' => 4.0])['id'];
        $row = self::$pdo->query("SELECT * FROM distribution_allocations WHERE id = $id")->fetch();

        $fields = distribution_protocol_fields(self::$pdo, $row);
        $this->assertSame('Тестова книга (Червена)', $fields['item_name']);
        $this->assertSame('123456789', $fields['distributor_eik']);

        $gen = new class extends DistributionProtocolGenerator {
            public function html(array $o): string { return $this->buildHtml($o, [], ['formatted_number' => '00042']); }
        };
        $html = $gen->html($fields);
        $this->assertStringContainsString('ПРИЕМО-ПРЕДАВАТЕЛЕН ПРОТОКОЛ', $html);
        $this->assertStringContainsString('00042', $html);
        $this->assertStringContainsString('Тестова книга (Червена)', $html);
        $this->assertStringContainsString('Магазин &lt;Б&amp;Б&gt;', $html, 'distributor name is escaped');
        $this->assertStringContainsString('12.00 €', $html, 'line total = 3 × 4.00');
        $this->assertStringNotContainsString('Лафетки', $html);
    }

    public function testRecordsProtectProductsAndVariantsFromDeletion(): void
    {
        $with    = $this->product(0, 'variant');
        $vid     = $this->variant($with, 'X');
        $without = $this->product();
        $this->batch($with, 1, $vid);

        $this->assertSame([$with], distribution_products_with_records(self::$pdo, [$with, $without]));
        $this->assertTrue(distribution_variant_has_records(self::$pdo, $vid));
        $this->assertFalse(distribution_variant_has_records(self::$pdo, $this->variant($without, 'Y')));
        $this->assertSame([], distribution_products_with_records(self::$pdo, []));
    }
}
