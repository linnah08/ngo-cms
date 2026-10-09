<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pre-orders — the helpers in includes/products.php, the product-edit variant
 * parser, and over real HTTP: product page, cart add / update and the cart page
 * for a sold-out product that takes pre-orders (BG and EN).
 */
#[Group('shop')]
final class ProductPreorderTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static array $product_ids = [];
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static array $logs = [];

    public static function setUpBeforeClass(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
        if (!test_db_available()) return;
        self::$pdo = get_pdo();
        try {
            self::$pdo->query('SELECT preorder_enabled FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::$pdo = null; // migration 042 not applied
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo && self::$product_ids) {
            $in = implode(',', array_fill(0, count(self::$product_ids), '?'));
            self::$pdo->prepare("DELETE FROM product_variants WHERE product_id IN ($in)")->execute(self::$product_ids);
            self::$pdo->prepare("DELETE FROM products WHERE id IN ($in)")->execute(self::$product_ids);
        }
        if (is_resource(self::$server)) {
            $st = proc_get_status(self::$server);
            proc_terminate(self::$server, 9);
            if (!empty($st['pid'])) @exec('kill -9 ' . (int) $st['pid'] . ' 2>/dev/null');
        }
        foreach (self::$logs as $l) @unlink($l);
    }

    // ── pure helpers ───────────────────────────────────────────────────────────

    public function testIsPreorder(): void
    {
        $on  = ['preorder_enabled' => 1];
        $off = ['preorder_enabled' => 0];
        $this->assertTrue(product_is_preorder($on, 0));
        $this->assertTrue(product_is_preorder($on, -3), 'already oversold stays a pre-order');
        $this->assertFalse(product_is_preorder($on, 1), 'back in stock');
        $this->assertFalse(product_is_preorder($off, 0));
        $this->assertFalse(product_is_preorder([], 0));
    }

    public function testNoteAndText(): void
    {
        $p = ['preorder_note_bg' => '  Очаквана доставка: март  ', 'preorder_note_en' => 'Expected: March'];
        $this->assertSame('Очаквана доставка: март', product_preorder_note($p, 'bg'));
        $this->assertSame('Expected: March', product_preorder_note($p, 'en'));
        $this->assertSame('Очаквана доставка: март', product_preorder_note(['preorder_note_bg' => 'Очаквана доставка: март', 'preorder_note_en' => ''], 'en'), 'EN falls back to the BG note');
        $this->assertSame('', product_preorder_note(['preorder_note_bg' => null, 'preorder_note_en' => null], 'bg'));

        $this->assertStringContainsString('Expected: March', product_preorder_text($p, 'en'));
        $this->assertStringContainsString('по-късно', product_preorder_text([], 'bg'), 'a default sentence when there is no note');
        $this->assertSame('Pre-order', product_preorder_label('en'));
    }

    public function testDefaultVariantCanBuyAndLimit(): void
    {
        $sold = [['id' => 1, 'stock' => 0], ['id' => 2, 'stock' => -2]];
        $this->assertNull(product_default_variant($sold));
        $this->assertSame(1, product_default_variant($sold, true)['id']);
        $this->assertSame(2, product_default_variant([['id' => 1, 'stock' => 0], ['id' => 2, 'stock' => 4]], true)['id'], 'in-stock still wins');
        $this->assertNull(product_default_variant([], true));

        $this->assertTrue(product_can_buy(['type' => 'standard', 'stock' => 0, 'preorder_enabled' => 1]));
        $this->assertFalse(product_can_buy(['type' => 'standard', 'stock' => 0, 'preorder_enabled' => 0]));
        $this->assertTrue(product_can_buy(['type' => 'variant', 'preorder_enabled' => 1], $sold));
        $this->assertFalse(product_can_buy(['type' => 'variant', 'preorder_enabled' => 1], []), 'no variants, nothing to order');

        $this->assertSame(PHP_INT_MAX, product_line_limit(['preorder_enabled' => 1], 0));
        $this->assertSame(3, product_line_limit(['preorder_enabled' => 1], 3));
        $this->assertSame(0, product_line_limit(['preorder_enabled' => 0], -2), 'never a negative cap');
    }

    public function testVariantRowsKeepNegativeStockOnlyForPreorders(): void
    {
        $post = ['pv_label_bg' => ['M'], 'pv_stock' => ['-4']];
        $ok   = fn() => true;
        $this->assertSame(0, product_parse_variant_rows($post, $ok)[0]['stock']);
        $this->assertSame(-4, product_parse_variant_rows($post, $ok, true)[0]['stock']);
    }

    public function testProductEditFieldsAndTranslateHook(): void
    {
        $src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/product-edit.php');
        $this->assertStringContainsString('name="preorder_enabled"', $src);
        $this->assertMatchesRegularExpression('/name="preorder_note_en"[^>]*data-translate-from="preorder_note_bg"/s', $src);
    }

    // ── DB ─────────────────────────────────────────────────────────────────────

    private function product(int $stock, bool $preorder, string $type = 'standard'): int
    {
        self::$pdo->prepare(
            'INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,preorder_enabled,preorder_note_bg,preorder_note_en)
             VALUES (?,?,?,?,?,1,?,?,?,?)'
        )->execute(['test-preorder-' . bin2hex(random_bytes(5)), 'Тест предварителна', 'Preorder test', 5.0, $stock, $type,
                    $preorder ? 1 : 0, 'Очаквана доставка: март', 'Expected delivery: March']);
        return self::$product_ids[] = (int) self::$pdo->lastInsertId();
    }

    public function testLineStock(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with migration 042.');
        $std = $this->product(7, false);
        $row = self::$pdo->query("SELECT * FROM products WHERE id = $std")->fetch();
        $this->assertSame(7, product_line_stock(self::$pdo, $row, null));

        $var = $this->product(0, true, 'variant');
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,1,0)')
            ->execute([$var, 'L', 'L', '{}', -2]);
        $vid = (int) self::$pdo->lastInsertId();
        $row = self::$pdo->query("SELECT * FROM products WHERE id = $var")->fetch();
        $this->assertSame(-2, product_line_stock(self::$pdo, $row, $vid));
        $this->assertTrue(product_is_preorder($row, -2));
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────

    private function server(): void
    {
        if (self::$base) return;
        $port = 8000 + random_int(100, 900);
        $out  = tempnam(sys_get_temp_dir(), 'om_po_out_');
        $err  = tempnam(sys_get_temp_dir(), 'om_po_err_');
        self::$logs = [$out, $err];
        $root = $_SERVER['DOCUMENT_ROOT'];
        self::$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $root);
        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init("http://127.0.0.1:$port/");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_NOBODY => true]);
            curl_exec($ch);
            $e = curl_errno($ch);
            curl_close($ch);
            if ($e === 0) { self::$base = "http://127.0.0.1:$port"; return; }
            usleep(100_000);
        }
        $this->markTestSkipped('Could not start the local test server.');
    }

    private function get(string $path, string $jar, ?array $post = null): string
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return $body;
    }

    public function testSoldOutPreorderProductCanBeOrderedAndSaysSo(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with migration 042.');
        $this->server();
        $pid  = $this->product(0, true);
        $slug = self::$pdo->query("SELECT slug FROM products WHERE id = $pid")->fetchColumn();

        foreach (['bg' => ['/magazin/', 'Изпращаме по-късно: Очаквана доставка: март', '/cart/'],
                  'en' => ['/en/shop/', 'Ships later: Expected delivery: March', '/en/cart/']] as $lang => [$shop, $text, $cart]) {
            $jar  = (string) tempnam(sys_get_temp_dir(), 'om_po_jar_');
            $page = $this->get($shop . $slug . '/', $jar);
            $this->assertStringContainsString('id="preorderNotice"', $page, "$lang product page shows the notice");
            $this->assertStringContainsString($text, $page);
            $this->assertStringContainsString('schema.org/PreOrder', $page);
            $this->assertStringContainsString('id="addToCartForm"', $page, 'buy button stays');

            preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m);
            $body = $this->get('/cart/add.php', $jar, ['csrf_token' => $m[1], 'product_id' => $pid, 'quantity' => 3, '_lang' => $lang, 'redirect' => 'cart']);
            $this->assertStringContainsString($text, $body, "$lang cart line says it ships later");
            $this->assertStringContainsString('class="preorder-notice"', $body);
            $this->assertMatchesRegularExpression('/name="quantity\[0\]"\s+value="3"/', $body, 'not capped at stock 0');

            preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m);
            $body = $this->get('/cart/update.php', $jar, ['csrf_token' => $m[1], 'quantity' => [0 => 5], '_lang' => $lang]);
            $this->assertMatchesRegularExpression('/name="quantity\[0\]"\s+value="5"/', $body, 'update is not capped either');
            @unlink($jar);
        }
    }

    public function testSoldOutProductWithoutPreorderStillRefused(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB with migration 042.');
        $this->server();
        $pid  = $this->product(0, false);
        $slug = self::$pdo->query("SELECT slug FROM products WHERE id = $pid")->fetchColumn();
        $jar  = (string) tempnam(sys_get_temp_dir(), 'om_po_jar_');
        $page = $this->get('/magazin/' . $slug . '/', $jar);
        $this->assertStringNotContainsString('id="preorderNotice"', $page);
        $this->assertStringNotContainsString('id="addToCartForm"', $page);
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m);
        $this->get('/cart/add.php', $jar, ['csrf_token' => $m[1], 'product_id' => $pid, 'quantity' => 1, 'redirect' => 'cart']);
        $cart = $this->get('/cart/', $jar);
        // An empty cart redirects to the shop listing, which has no quantity fields.
        $this->assertStringNotContainsString('name="quantity[0]"', $cart, 'nothing was added');
        @unlink($jar);
    }
}
