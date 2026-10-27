<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * In-person SumUp sales take their products out of stock — includes/sumup.php.
 * SumUp itself is replaced by a fake getter fed with the shapes its API returns.
 */
#[Group('shop')]
final class SumUpStockTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $tx_ids      = [];
    private array $saved_settings     = [];

    private const SETTINGS = ['sumup_api_key', 'sumup_merchant_code', 'sumup_merchant_name', 'sumup_connected_at', 'sumup_synced_until', 'sumup_last_sync'];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sumup.php';
        if (!test_db_available()) return;
        self::$pdo = get_pdo();
        try {
            self::$pdo->query('SELECT 1 FROM sumup_sales LIMIT 1');
        } catch (Throwable) {
            self::$pdo = null; // migration 047 not applied
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$pdo) return;
        if (self::$tx_ids) {
            $in = implode(',', array_fill(0, count(self::$tx_ids), '?'));
            self::$pdo->prepare("DELETE FROM sumup_sales WHERE transaction_id IN ($in)")->execute(self::$tx_ids);
        }
        self::$pdo->exec("DELETE FROM sumup_name_map WHERE name_key LIKE 'phpunit %'");
        if (self::$product_ids) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute(self::$product_ids);
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute(self::$product_ids);
        }
    }

    protected function setUp(): void
    {
        if (!self::$pdo) return;
        foreach (self::SETTINGS as $k) $this->saved_settings[$k] = setting_get($k);
        setting_set('sumup_merchant_code', 'MTEST');
        setting_set('sumup_connected_at', (string) strtotime('2026-10-01 00:00:00 UTC'));
        setting_delete('sumup_synced_until');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_settings as $k => $v) {
            $v === '' ? setting_delete($k) : setting_set($k, $v);
        }
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB (or migration 047 not applied).');
    }

    private function product(string $name, int $stock, array $variants = []): array
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,type) VALUES (?,?,?,?,?,?,?)')
            ->execute(['phpunit-sumup-' . uniqid(), $name, $name . ' EN', 10.00, $stock, 1, $variants ? 'variant' : 'standard']);
        $pid = self::$product_ids[] = (int) self::$pdo->lastInsertId();
        $vids = [];
        foreach ($variants as $label => $vstock) {
            self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
                ->execute([$pid, $label, $label, '{}', $vstock, 1, 0]);
            $vids[$label] = (int) self::$pdo->lastInsertId();
        }
        return ['id' => $pid, 'variants' => $vids];
    }

    private function stock(int $pid, ?int $vid = null): int
    {
        $stmt = $vid ? self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?') : self::$pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$vid ?: $pid]);
        return (int) $stmt->fetchColumn();
    }

    private function tx(string $status, array $products, string $time = '2026-10-26T10:00:00.000+00:00'): array
    {
        $id = self::$tx_ids[] = 'phpunit-' . bin2hex(random_bytes(6));
        return ['id' => $id, 'transaction_code' => 'T' . substr($id, -6), 'status' => $status, 'timestamp' => $time,
                'amount' => 20.0, 'currency' => 'EUR', 'payment_type' => 'POS', 'products' => $products];
    }

    /** A fake SumUp: history lists $txs (two per page), details by ?id=. Records every path asked for. */
    private function fake(array $txs, array &$asked = []): callable
    {
        $byId = [];
        foreach ($txs as $t) $byId[$t['id']] = $t;
        return function (string $path) use ($txs, $byId, &$asked): array {
            $asked[] = $path;
            if (str_contains($path, '/history?')) {
                $page  = preg_match('/oldest_ref=(\d+)/', $path, $m) ? (int) $m[1] : 0;
                $items = array_map(fn ($t) => array_diff_key($t, ['products' => 1]), array_slice($txs, $page * 2, 2));
                $links = ($page + 1) * 2 < count($txs) ? [['rel' => 'next', 'href' => 'oldest_ref=' . ($page + 1) . '&order=ascending']] : [];
                return ['items' => $items, 'links' => $links];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
            return $byId[$q['id']] ?? throw new SumUpError('unknown tx');
        };
    }

    // ── Pure matching ─────────────────────────────────────────────────────────

    public function testNormalisedNamesIgnoreCaseAndPunctuation(): void
    {
        $this->assertSame('тениска m', sumup_norm('  Тениска - M '));
        $this->assertSame('тениска m', sumup_norm('Тениска (M)'));
        $this->assertSame('тениска m', sumup_norm('тениска / m'));
    }

    public function testMatchUsesNameAndVariantLabelAndRefusesAmbiguousNames(): void
    {
        $catalog = [
            ['product_id' => 1, 'variant_id' => null, 'label' => 'Чаша', 'names' => ['Чаша', 'Mug'], 'stock' => 3],
            ['product_id' => 2, 'variant_id' => 7, 'label' => 'Тениска — M', 'names' => ['Тениска M'], 'stock' => 3],
            ['product_id' => 3, 'variant_id' => null, 'label' => 'Торба', 'names' => ['Торба'], 'stock' => 1],
            ['product_id' => 4, 'variant_id' => null, 'label' => 'Торба', 'names' => ['Торба'], 'stock' => 1],
        ];
        $index = sumup_catalog_index($catalog);
        $this->assertSame([1, null], sumup_match('mug', $index, []));
        $this->assertSame([2, 7], sumup_match('Тениска - M', $index, []));
        $this->assertNull(sumup_match('Торба', $index, []), 'two products share the name — ask, never guess');
        $this->assertNull(sumup_match('Нещо друго', $index, []));
        $this->assertSame([3, null], sumup_match('Торба', $index, ['торба' => '3:0']), 'the admin\'s remembered choice wins');
        $this->assertNull(sumup_match('Нещо', $index, ['нещо' => '99:0']), 'a remembered product that is gone no longer matches');
    }

    // ── The nightly check ─────────────────────────────────────────────────────

    public function testSaleTakesMatchedItemsOutOfStockOnce(): void
    {
        $this->requireDb();
        $mug   = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $shirt = $this->product('PHPUnit Тениска ' . uniqid(), 0, ['M' => 4, 'L' => 2]);
        $mugName = self::$pdo->query('SELECT name_bg FROM products WHERE id = ' . $mug['id'])->fetchColumn();
        $shirtName = self::$pdo->query('SELECT name_bg FROM products WHERE id = ' . $shirt['id'])->fetchColumn();

        $sales = [
            $this->tx('SUCCESSFUL', [['name' => $mugName, 'quantity' => 2], ['name' => $shirtName . ' - M', 'quantity' => 1]]),
            $this->tx('SUCCESSFUL', [['name' => $shirtName . ' (L)', 'quantity' => 1]]),
            $this->tx('SUCCESSFUL', [['name' => $mugName, 'quantity' => 1]]),
        ];
        $sum = sumup_sync(self::$pdo, $this->fake($sales), strtotime('2026-10-27 02:30:00 UTC'));

        $this->assertSame(3, $sum['new_sales'], 'all pages of the history are read');
        $this->assertSame(5, $sum['items_out']);
        $this->assertSame(0, $sum['attention']);
        $this->assertSame(2, $this->stock($mug['id']));
        $this->assertSame(3, $this->stock($shirt['id'], $shirt['variants']['M']));
        $this->assertSame(1, $this->stock($shirt['id'], $shirt['variants']['L']));

        sumup_sync(self::$pdo, $this->fake($sales));
        $this->assertSame(2, $this->stock($mug['id']), 'a second check never takes the same sale again');
    }

    public function testRefundPutsItemsBackOnce(): void
    {
        $this->requireDb();
        $mug  = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $name = self::$pdo->query('SELECT name_bg FROM products WHERE id = ' . $mug['id'])->fetchColumn();
        $sale = $this->tx('SUCCESSFUL', [['name' => $name, 'quantity' => 2]]);

        sumup_sync(self::$pdo, $this->fake([$sale]));
        $this->assertSame(3, $this->stock($mug['id']));

        $sale['status'] = 'REFUNDED';
        $sum = sumup_sync(self::$pdo, $this->fake([$sale]));
        $this->assertSame(1, $sum['refunds']);
        $this->assertSame(5, $this->stock($mug['id']));

        sumup_sync(self::$pdo, $this->fake([$sale]));
        $this->assertSame(5, $this->stock($mug['id']), 'a refund puts items back only once');
    }

    public function testSalesBeforeConnectingAndAlreadyRefundedSalesLeaveStockAlone(): void
    {
        $this->requireDb();
        $mug  = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $name = self::$pdo->query('SELECT name_bg FROM products WHERE id = ' . $mug['id'])->fetchColumn();

        sumup_sync(self::$pdo, $this->fake([
            $this->tx('SUCCESSFUL', [['name' => $name, 'quantity' => 1]], '2026-09-30T23:00:00.000+00:00'),
            $this->tx('REFUNDED', [['name' => $name, 'quantity' => 1]]),
        ]));
        $this->assertSame(5, $this->stock($mug['id']));
    }

    public function testOnlyCardReaderPaymentsChangedSinceTheLastCheckAreAskedFor(): void
    {
        $this->requireDb();
        setting_set('sumup_synced_until', (string) strtotime('2026-10-26 02:30:00 UTC'));
        $asked = [];
        sumup_sync(self::$pdo, $this->fake([], $asked));
        $this->assertStringContainsString('/v2.1/merchants/MTEST/transactions/history?', $asked[0]);
        $this->assertStringContainsString('changes_since=2026-10-26T01%3A30%3A00Z', $asked[0], 'an hour of overlap');
        $this->assertStringContainsString('payment_types[]=POS', $asked[0]);
        $this->assertStringContainsString('types[]=PAYMENT', $asked[0]);
    }

    // ── What the admin decides ────────────────────────────────────────────────

    public function testUnknownNameWaitsAndTheAdminsChoiceIsRemembered(): void
    {
        $this->requireDb();
        $mug  = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $odd  = 'phpunit странно име ' . uniqid();
        $sale = $this->tx('SUCCESSFUL', [['name' => $odd, 'quantity' => 2]]);

        $sum = sumup_sync(self::$pdo, $this->fake([$sale]));
        $this->assertSame(1, $sum['attention']);
        $this->assertSame(5, $this->stock($mug['id']));

        $id = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($sale['id']))->fetchColumn();
        $this->assertSame('Изберете продукт от списъка.', sumup_resolve_line(self::$pdo, $id, 0, 'nonsense'));
        $this->assertSame('', sumup_resolve_line(self::$pdo, $id, 0, $mug['id'] . ':0'));
        $this->assertSame(3, $this->stock($mug['id']));
        $this->assertNotSame('', sumup_resolve_line(self::$pdo, $id, 0, $mug['id'] . ':0'), 'answering twice does nothing');
        $this->assertSame(3, $this->stock($mug['id']));

        sumup_sync(self::$pdo, $this->fake([$this->tx('SUCCESSFUL', [['name' => strtoupper($odd), 'quantity' => 1]])]));
        $this->assertSame(2, $this->stock($mug['id']), 'the next sale with that name matches by itself');
    }

    public function testRefundAfterTheAdminsChoicePutsThatProductBack(): void
    {
        $this->requireDb();
        $mug  = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $sale = $this->tx('SUCCESSFUL', [['name' => 'phpunit няма такова ' . uniqid(), 'quantity' => 1]]);
        sumup_sync(self::$pdo, $this->fake([$sale]));
        $id = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($sale['id']))->fetchColumn();
        sumup_resolve_line(self::$pdo, $id, 0, $mug['id'] . ':0');
        $this->assertSame(4, $this->stock($mug['id']));

        $sale['status'] = 'REFUNDED';
        sumup_sync(self::$pdo, $this->fake([$sale]));
        $this->assertSame(5, $this->stock($mug['id']));
    }

    public function testTypedInAmountWaitsUntilTheAdminSaysWhatWasSold(): void
    {
        $this->requireDb();
        $mug   = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $shirt = $this->product('PHPUnit Тениска ' . uniqid(), 0, ['M' => 4]);
        $a = $this->tx('SUCCESSFUL', []);
        $b = $this->tx('SUCCESSFUL', []);
        $sum = sumup_sync(self::$pdo, $this->fake([$a, $b]));
        $this->assertSame(2, $sum['attention']);

        $ida = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($a['id']))->fetchColumn();
        $idb = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($b['id']))->fetchColumn();

        $this->assertNotSame('', sumup_resolve_sale_products(self::$pdo, $ida, [['', 1]]), 'nothing picked');
        $this->assertSame('', sumup_resolve_sale_products(self::$pdo, $ida, [
            [$mug['id'] . ':0', 2], [$shirt['id'] . ':' . $shirt['variants']['M'], 1], ['', 1],
        ]));
        $this->assertSame(3, $this->stock($mug['id']));
        $this->assertSame(3, $this->stock($shirt['id'], $shirt['variants']['M']));

        sumup_dismiss_sale(self::$pdo, $idb);
        $left = self::$pdo->prepare('SELECT COUNT(*) FROM sumup_sales WHERE id IN (?, ?) AND needs_attention = 1');
        $left->execute([$ida, $idb]);
        $this->assertSame(0, (int) $left->fetchColumn());
    }

    public function testABareNameOfAProductWithSizesIsNotRemembered(): void
    {
        $this->requireDb();
        $shirt = $this->product('PHPUnit Тениска ' . uniqid(), 0, ['M' => 4, 'L' => 4]);
        $name  = self::$pdo->query('SELECT name_bg FROM products WHERE id = ' . $shirt['id'])->fetchColumn();
        $sale  = $this->tx('SUCCESSFUL', [['name' => $name, 'quantity' => 1]]);
        sumup_sync(self::$pdo, $this->fake([$sale]));
        $id = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($sale['id']))->fetchColumn();

        $this->assertSame('', sumup_resolve_line(self::$pdo, $id, 0, $shirt['id'] . ':' . $shirt['variants']['M']));
        $this->assertSame(3, $this->stock($shirt['id'], $shirt['variants']['M']));
        $known = self::$pdo->prepare('SELECT COUNT(*) FROM sumup_name_map WHERE name_key = ?');
        $known->execute([sumup_norm($name)]);
        $this->assertSame(0, (int) $known->fetchColumn(), 'one sale\'s size says nothing about the next');
    }

    // ── The admin page ────────────────────────────────────────────────────────

    public function testThePageIsForShopAdminsAndOnlyAdminsConnect(): void
    {
        $this->requireDb();
        $out = run_admin_page('admin/sumup.php', [], ['role' => null, 'method' => 'GET']);
        $this->assertStringNotContainsString('Чакат да изберете продукт', $out['body'], 'a visitor sees nothing');

        $r = run_admin_page('admin/sumup.php', ['action' => 'connect', 'api_key' => 'sup_sk_x'], ['role' => 'shop_admin']);
        $this->assertSame('Само администратор може да свързва или прекъсва SumUp.', $r['flash'][0]['message'] ?? null);

        $r = run_admin_page('admin/sumup.php', ['action' => 'sync'], ['csrf' => false]);
        $this->assertSame(400, $r['status'], 'no CSRF token, no change');
    }

    public function testTheAdminAnswersAWaitingSaleFromThePage(): void
    {
        $this->requireDb();
        $mug  = $this->product('PHPUnit Чаша ' . uniqid(), 5);
        $sale = $this->tx('SUCCESSFUL', [['name' => 'phpunit от страницата ' . uniqid(), 'quantity' => 2]]);
        sumup_sync(self::$pdo, $this->fake([$sale]));
        $id = (int) self::$pdo->query("SELECT id FROM sumup_sales WHERE transaction_id = " . self::$pdo->quote($sale['id']))->fetchColumn();

        $page = run_admin_page('admin/sumup.php', [], ['method' => 'GET']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('phpunit от страницата', $page['body']);
        $this->assertStringContainsString('value="' . $mug['id'] . ':0"', $page['body']);

        $r = run_admin_page('admin/sumup.php', ['action' => 'resolve_line', 'sale_id' => $id, 'line' => 0, 'target' => $mug['id'] . ':0'], ['role' => 'shop_admin']);
        $this->assertSame('success', $r['flash'][0]['type'] ?? null, json_encode($r['flash']));
        $this->assertSame(3, $this->stock($mug['id']));
    }

    public function testNeverConnectedSaysSoInPlainWords(): void
    {
        $this->requireDb();
        setting_delete('sumup_merchant_code');
        $this->expectException(SumUpError::class);
        sumup_sync(self::$pdo, $this->fake([]));
    }

    public function testConnectFindsTheMerchantAndKeepsTheFirstStartTime(): void
    {
        $this->requireDb();
        $first = setting_get('sumup_connected_at');
        $get = fn (string $path) => ['items' => [['resource_id' => 'MXYZ', 'resource' => ['id' => 'MXYZ', 'name' => 'Сдружение']]], 'total_count' => 1];
        $m = sumup_connect(' sup_sk_test ', $get, time());
        $this->assertSame(['code' => 'MXYZ', 'name' => 'Сдружение'], $m);
        $this->assertSame('sup_sk_test', setting_get('sumup_api_key'));
        $this->assertSame($first, setting_get('sumup_connected_at'), 'reconnecting never restarts the clock');

        $this->expectException(SumUpError::class);
        sumup_connect('sup_sk_x', fn () => ['items' => [], 'total_count' => 0]);
    }
}
