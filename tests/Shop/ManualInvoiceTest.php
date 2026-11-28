<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Shop products on a manual invoice — includes/manual_invoice.php.
 * Picker list, filling a line from a product, taking stock (below zero allowed),
 * and stock coming back when the invoice's order is cancelled or deleted.
 */
#[Group('shop')]
final class ManualInvoiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    private static array $order_ids   = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/manual_invoice.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_stock.php';
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

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function product(int $stock, int $active = 1, string $type = 'standard', string $name_en = 'Soap'): int
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`) VALUES (?,?,?,?,?,?,?)')
            ->execute(['test-mi-' . uniqid(), 'Сапун', $name_en, 12.50, $stock, $active, $type]);
        return self::$product_ids[] = (int)self::$pdo->lastInsertId();
    }

    private function variant(int $product_id, int $stock, int $active = 1): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$product_id, 'Лавандула', 'Lavender', '{}', $stock, $active, 0]);
        return (int)self::$pdo->lastInsertId();
    }

    private function stock(int $product_id, int $variant_id = 0): int
    {
        $s = $variant_id
            ? self::$pdo->prepare('SELECT stock FROM product_variants WHERE id = ?')
            : self::$pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $s->execute([$variant_id ?: $product_id]);
        return (int)$s->fetchColumn();
    }

    /** Saves an invoice's order the way admin/manual-invoice.php does. */
    private function saveInvoice(array $items): array
    {
        self::$pdo->beginTransaction();
        self::$pdo->prepare(
            "INSERT INTO orders (order_number,type,status,customer_name,customer_email,items,subtotal_eur,shipping_eur,total_eur,payment_method,payment_status)
             VALUES (?, 'physical', 'confirmed', 'Фирма', '', ?, 10, 0, 10, 'bank_transfer', 'paid')"
        )->execute(['T' . substr(uniqid(), -13), json_encode($items)]);
        $id = self::$order_ids[] = (int)self::$pdo->lastInsertId();
        $short = manual_invoice_take_stock(self::$pdo, $items);
        self::$pdo->commit();

        $s = self::$pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $s->execute([$id]);
        return ['order' => $s->fetch(), 'short' => $short];
    }

    private static function values(array $entries): array
    {
        return array_column($entries, 'value');
    }

    // ── Picker list ──────────────────────────────────────────────────────────

    public function testPickerListsInactiveProductsInTheirOwnGroup(): void
    {
        $active   = $this->product(5);
        $inactive = $this->product(5, 0);

        $opts = manual_invoice_product_options(self::$pdo);
        $this->assertContains((string)$active, self::values($opts['active']));
        $this->assertContains((string)$inactive, self::values($opts['inactive']));
        $this->assertNotContains((string)$inactive, self::values($opts['active']));
    }

    public function testVariantProductIsOfferedThroughItsVariants(): void
    {
        $pid = $this->product(0, 1, 'variant');
        $on  = $this->variant($pid, 3);
        $off = $this->variant($pid, 3, 0);

        $opts = manual_invoice_product_options(self::$pdo);
        $all  = array_merge(self::values($opts['active']), self::values($opts['inactive']));
        $this->assertNotContains((string)$pid, $all, 'a variant product is chosen through a variant');
        $this->assertContains("$pid:$on", self::values($opts['active']));
        $this->assertContains("$pid:$off", self::values($opts['inactive']), 'a switched-off variant is inactive');
    }

    public function testOptionLabelShowsPriceAndStock(): void
    {
        $label = manual_invoice_option_label(['name_bg' => 'Сапун', 'price_eur' => 12.5, 'stock' => -2]);
        $this->assertSame('Сапун — 12.50 € · наличност -2', $label);
    }

    // ── Resolving a posted product ───────────────────────────────────────────

    public function testResolveRejectsWhatDoesNotBelongTogether(): void
    {
        $plain   = $this->product(5);
        $vprod   = $this->product(0, 1, 'variant');
        $vid     = $this->variant($vprod, 3);
        $other   = $this->product(0, 1, 'variant');

        $this->assertNull(manual_invoice_resolve(self::$pdo, '999999999'), 'unknown product');
        $this->assertNull(manual_invoice_resolve(self::$pdo, "$other:$vid"), 'variant of another product');
        $this->assertNull(manual_invoice_resolve(self::$pdo, (string)$vprod), 'variant product without its variant');
        $this->assertNull(manual_invoice_resolve(self::$pdo, "$plain:$vid"), 'plain product with a variant');
        $this->assertNull(manual_invoice_resolve(self::$pdo, 'abc'), 'not an id');
        $this->assertNull(manual_invoice_resolve(self::$pdo, "1 OR 1=1"), 'not an id');
    }

    public function testResolveVariantBuildsBothNames(): void
    {
        $pid = $this->product(0, 0, 'variant');   // inactive products are still valid
        $vid = $this->variant($pid, 4);

        $e = manual_invoice_resolve(self::$pdo, "$pid:$vid");
        $this->assertSame('Сапун (Лавандула)', $e['name_bg']);
        $this->assertSame('Soap (Lavender)', $e['name_en']);
        $this->assertSame(12.5, $e['price_eur']);
        $this->assertSame($vid, $e['variant_id']);
    }

    public function testEnglishNameFallsBackToBulgarian(): void
    {
        $pid = $this->product(5, 1, 'standard', '');
        $this->assertSame('Сапун', manual_invoice_resolve(self::$pdo, (string)$pid)['name_en']);
    }

    // ── Building the lines ───────────────────────────────────────────────────

    public function testLinesFillFromTheProductAndKeepWhatWasTyped(): void
    {
        $pid  = $this->product(5);
        $items = manual_invoice_items(self::$pdo, [
            'item_product' => [(string)$pid, (string)$pid, '',              '999999999',  ''],
            'item_name'    => ['',           'Сапун подарък', 'Консултация', 'Стар артикул', ''],
            'item_qty'     => ['2',          '1',          '1',             '3',           '1'],
            'item_price'   => ['',           '10',         '50',            '4',           ''],
        ]);

        $this->assertCount(4, $items, 'the empty last row is skipped');

        // Filled from the product
        $this->assertSame('Сапун', $items[0]['name_bg']);
        $this->assertSame('Soap', $items[0]['name_en']);
        $this->assertSame(12.5, $items[0]['price_eur']);
        $this->assertSame(25.0, $items[0]['subtotal_eur']);
        $this->assertSame($pid, $items[0]['product_id']);
        $this->assertArrayNotHasKey('variant_id', $items[0]);

        // Typed name and price win; EN name still comes from the product
        $this->assertSame('Сапун подарък', $items[1]['name_bg']);
        $this->assertSame('Soap', $items[1]['name_en']);
        $this->assertSame(10.0, $items[1]['price_eur']);
        $this->assertSame($pid, $items[1]['product_id']);

        // Free text, as before
        $this->assertSame('Консултация', $items[2]['name_bg']);
        $this->assertArrayNotHasKey('product_id', $items[2]);

        // Unknown product: kept as typed, without a product
        $this->assertSame('Стар артикул', $items[3]['name_bg']);
        $this->assertArrayNotHasKey('product_id', $items[3]);
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    public function testSavingTakesStockAndWarnsBelowZero(): void
    {
        $plain = $this->product(5);
        $vprod = $this->product(0, 1, 'variant');
        $vid   = $this->variant($vprod, 1);

        $items = manual_invoice_items(self::$pdo, [
            'item_product' => [(string)$plain, (string)$plain, "$vprod:$vid", ''],
            'item_name'    => ['', '', '', 'Консултация'],
            'item_qty'     => ['2', '1', '3', '1'],
            'item_price'   => ['', '', '', '50'],
        ]);
        $saved = $this->saveInvoice($items);

        $this->assertSame(2, $this->stock($plain), 'both lines of the same product are taken');
        $this->assertSame(-2, $this->stock($vprod, $vid), 'stock may go below zero');
        $this->assertSame(['Сапун (Лавандула) (−2)'], $saved['short'], 'only the item below zero is named');
    }

    public function testCancellingTheInvoiceOrderGivesStockBack(): void
    {
        $pid   = $this->product(1);
        $saved = $this->saveInvoice(manual_invoice_items(self::$pdo, [
            'item_product' => [(string)$pid], 'item_name' => [''], 'item_qty' => ['3'], 'item_price' => [''],
        ]));
        $this->assertSame(-2, $this->stock($pid));

        $r = order_stock_on_status_change(self::$pdo, $saved['order'], 'cancelled');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->stock($pid));
    }

    public function testDeletingTheInvoiceOrderGivesStockBack(): void
    {
        $pid   = $this->product(4);
        $vprod = $this->product(0, 1, 'variant');
        $vid   = $this->variant($vprod, 2);
        $saved = $this->saveInvoice(manual_invoice_items(self::$pdo, [
            'item_product' => [(string)$pid, "$vprod:$vid"], 'item_name' => ['', ''], 'item_qty' => ['1', '2'], 'item_price' => ['', ''],
        ]));
        $this->assertSame(3, $this->stock($pid));
        $this->assertSame(0, $this->stock($vprod, $vid));

        $this->assertTrue(order_stock_on_delete(self::$pdo, $saved['order']));
        $this->assertSame(4, $this->stock($pid));
        $this->assertSame(2, $this->stock($vprod, $vid));
    }

    public function testFreeTextInvoiceNeverTouchesStock(): void
    {
        $pid   = $this->product(5);
        $saved = $this->saveInvoice(manual_invoice_items(self::$pdo, [
            'item_product' => [''], 'item_name' => ['Сапун'], 'item_qty' => ['2'], 'item_price' => ['5'],
        ]));
        $this->assertSame([], $saved['short']);
        $this->assertSame(5, $this->stock($pid));
        $this->assertSame([], order_stock_lines($saved['order']));
    }

    // ── The form ─────────────────────────────────────────────────────────────

    public function testFormPostsTheProductWithEveryRow(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/manual-invoice.php');
        $this->assertStringContainsString('name="item_product[]"', $src);
        $this->assertStringContainsString('<template id="itemRowTpl">', $src, 'added rows use the same markup');
        $this->assertStringContainsString('manual_invoice_items($pdo, $_POST)', $src);
        $this->assertStringContainsString('manual_invoice_take_stock($pdo, $items)', $src);
        $this->assertStringContainsString("csrf_verify()", $src);
        $this->assertStringContainsString("admin_require_shop()", $src);
    }
}
