<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../Support/TestServer.php';

/**
 * Restoring saved carts and pledges — saved_cart_validate_items(), saved_cart_merge(),
 * saved_cart_restore_pledge() and cart/restore.php. DB tests self-skip without a database.
 */
#[Group('shop')]
#[Group('cart')]
final class SavedCartRestoreTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static string $base = '';
    private static array $product_ids = [];
    private static array $pledge_ids  = [];
    private static array $reward_ids  = [];
    private static array $tokens      = [];
    private static array $saved_settings = [];
    private array $saved_session = [];
    private array $saved_cookie  = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/products.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order_session.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/saved_carts.php';
        self::$pdo = get_pdo();
        foreach (['cart_reminder_email', 'cart_reminder_notice', 'campaign_active'] as $k) {
            self::$saved_settings[$k] = setting_get($k, '0');
        }
        setting_set('cart_reminder_email', '1');
        setting_set('cart_reminder_notice', '1');
        setting_set('campaign_active', '1');
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        if (!self::$pdo) return;
        $pdo = self::$pdo;
        foreach (self::$tokens as $t) {
            $pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ? OR token = ?')->execute([$t, $t]);
        }
        foreach (self::$pledge_ids as $id) {
            $pdo->prepare('DELETE FROM campaign_pledges WHERE id = ?')->execute([$id]);
        }
        foreach (self::$reward_ids as $id) {
            $pdo->prepare('DELETE FROM campaign_rewards WHERE id = ?')->execute([$id]);
        }
        foreach (self::$product_ids as $id) {
            $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        foreach (self::$saved_settings as $k => $v) setting_set($k, $v);
    }

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
        start_session();
        $this->saved_session = $_SESSION ?? [];
        $this->saved_cookie  = $_COOKIE;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->saved_session;
        $_COOKIE  = $this->saved_cookie;
    }

    // ── seeding ───────────────────────────────────────────────────────────────

    private function product(array $o = []): int
    {
        $d = array_merge([
            'name_bg' => 'Тениска ' . uniqid(), 'name_en' => 'Shirt ' . uniqid(),
            'stock' => 5, 'active' => 1, 'type' => 'standard', 'preorder_enabled' => 0,
        ], $o);
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`,preorder_enabled) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['test-restore-' . uniqid(), $d['name_bg'], $d['name_en'], 10.00, $d['stock'], $d['active'], $d['type'], $d['preorder_enabled']]);
        $id = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $id;
        return $id;
    }

    private function variant(int $product_id, int $stock, int $active = 1): int
    {
        self::$pdo->prepare('INSERT INTO product_variants (product_id,label_bg,label_en,attributes,stock,active,sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$product_id, 'М', 'M', '{}', $stock, $active, 0]);
        return (int)self::$pdo->lastInsertId();
    }

    private function pname(int $product_id, string $col = 'name_en'): string
    {
        $s = self::$pdo->prepare("SELECT $col FROM products WHERE id = ?");
        $s->execute([$product_id]);
        return (string)$s->fetchColumn();
    }

    private function pledge(array $o = []): array
    {
        $reward_id = $o['reward_id'] ?? null;
        if ($reward_id === 'new') {
            self::$pdo->prepare('INSERT INTO campaign_rewards (title,description,title_en,description_en,amount_eur,active) VALUES (?,?,?,?,?,?)')
                ->execute(['Награда', 'Тест', 'Reward', 'Test', 10, $o['reward_active'] ?? 1]);
            $reward_id = (int)self::$pdo->lastInsertId();
            self::$reward_ids[] = $reward_id;
        }
        $number = 'CP-TEST-' . strtoupper(bin2hex(random_bytes(3)));
        self::$pdo->prepare("INSERT INTO campaign_pledges (pledge_number,name,email,phone,amount_eur,reward_id,payment_status) VALUES (?,?,?,?,?,?,?)")
            ->execute([$number, 'Мария', 'maria@example.com', '0888123456', 25.00, $reward_id, $o['status'] ?? 'pending']);
        $id = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[] = $id;
        return ['pledge_id' => $id, 'number' => $number];
    }

    private function savedRow(string $browser, array $items, ?int $pledge_id = null, ?string $ordered = null): string
    {
        $token = bin2hex(random_bytes(32));
        self::$tokens[] = $browser;
        self::$tokens[] = $token;
        self::$pdo->prepare('INSERT INTO saved_carts (token,browser_token,pledge_id,items,lang,ordered_at,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$token, $browser, $pledge_id, json_encode($items), 'bg', $ordered]);
        return $token;
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function test_validate_drops_inactive_and_deleted_variant_with_named_notes(): void
    {
        $inactive = $this->product(['active' => 0]);
        $vp   = $this->product(['type' => 'variant']);
        $gone = $this->variant($vp, 5, 0);
        $ok   = $this->product();
        $items = [
            ['product_id' => $inactive, 'quantity' => 1],
            ['product_id' => $vp, 'quantity' => 1, 'variant_id' => $gone, 'variant_label' => 'М'],
            ['product_id' => $vp, 'quantity' => 1, 'variant_id' => 999999999, 'variant_label' => 'Х'],
            ['product_id' => $ok, 'quantity' => 2],
        ];
        $r = saved_cart_validate_items(self::$pdo, $items, 'en');
        $this->assertCount(1, $r['items']);
        $this->assertSame($ok, (int)$r['items'][0]['product_id']);
        $this->assertCount(3, $r['notes']);
        $this->assertStringContainsString($this->pname($inactive), $r['notes'][0]);
        $this->assertStringContainsString($this->pname($vp), $r['notes'][1]);
        $this->assertStringContainsString($this->pname($vp), $r['notes'][2]);

        $bg = saved_cart_validate_items(self::$pdo, [['product_id' => $inactive, 'quantity' => 1]], 'bg');
        $this->assertStringContainsString($this->pname($inactive, 'name_bg'), $bg['notes'][0]);
    }

    public function test_validate_caps_quantity_to_stock_unless_preorder(): void
    {
        $limited  = $this->product(['stock' => 3]);
        $preorder = $this->product(['stock' => 0, 'preorder_enabled' => 1]);
        $soldout  = $this->product(['stock' => 0]);
        $vp = $this->product(['type' => 'variant']);
        $vid = $this->variant($vp, 2);
        $r = saved_cart_validate_items(self::$pdo, [
            ['product_id' => $limited, 'quantity' => 9],
            ['product_id' => $preorder, 'quantity' => 9],
            ['product_id' => $soldout, 'quantity' => 1],
            ['product_id' => $vp, 'quantity' => 7, 'variant_id' => $vid, 'variant_label' => 'М'],
        ], 'en');
        $this->assertCount(3, $r['items']);
        $this->assertSame(3, $r['items'][0]['quantity']);
        $this->assertSame(9, $r['items'][1]['quantity']);
        $this->assertSame(2, $r['items'][2]['quantity']);
        $this->assertCount(3, $r['notes']);   // capped, sold out (dropped), capped
        $this->assertStringContainsString($this->pname($limited), $r['notes'][0]);
        $this->assertStringContainsString($this->pname($soldout), $r['notes'][1]);
    }

    private function printOptions(int $product_id, array $colours, array $sizes): void
    {
        $opts = ['colours' => array_map(fn($c) => ['name' => $c, 'hex' => '#000000'], $colours), 'sizes' => $sizes];
        self::$pdo->prepare('UPDATE products SET variants = ? WHERE id = ?')->execute([json_encode($opts), $product_id]);
    }

    public function test_validate_drops_print_item_whose_colour_or_size_is_no_longer_offered(): void
    {
        $p = $this->product(['type' => 'print']);
        $this->printOptions($p, ['black', 'white'], ['M', 'L']);
        $r = saved_cart_validate_items(self::$pdo, [
            ['product_id' => $p, 'quantity' => 1, 'colour' => 'black', 'size' => 'M'],
            ['product_id' => $p, 'quantity' => 1, 'colour' => 'purple', 'size' => 'M'],
            ['product_id' => $p, 'quantity' => 1, 'colour' => 'white', 'size' => 'XXL'],
            ['product_id' => $p, 'quantity' => 1],
        ], 'en');
        $this->assertCount(1, $r['items']);
        $this->assertSame('black', $r['items'][0]['colour']);
        $this->assertCount(3, $r['notes']);
        foreach ($r['notes'] as $note) {
            $this->assertStringContainsString($this->pname($p), $note);
            $this->assertStringContainsString('no longer offered', $note);
        }
        $bg = saved_cart_validate_items(self::$pdo, [['product_id' => $p, 'quantity' => 1, 'colour' => 'purple', 'size' => 'M']], 'bg');
        $this->assertStringContainsString($this->pname($p, 'name_bg'), $bg['notes'][0]);
        $this->assertStringContainsString('вече не се предлага', $bg['notes'][0]);
    }

    public function test_validate_drops_print_item_whose_design_file_is_missing(): void
    {
        $p = $this->product(['type' => 'print']);
        $this->printOptions($p, ['black', 'white', 'red'], ['M', 'L']);
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/print-designs/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $present = 'test-restore-' . uniqid() . '.png';
        file_put_contents($dir . $present, 'x');
        try {
            $r = saved_cart_validate_items(self::$pdo, [
                ['product_id' => $p, 'quantity' => 1, 'colour' => 'black', 'size' => 'M', 'design_file' => 'uploads/print-designs/does-not-exist.png'],
                ['product_id' => $p, 'quantity' => 1, 'colour' => 'black', 'size' => 'M', 'design_file' => '../config.php'],
                ['product_id' => $p, 'quantity' => 1, 'colour' => 'white', 'size' => 'M', 'design_file' => 'uploads/print-designs/' . $present],
                ['product_id' => $p, 'quantity' => 1, 'colour' => 'red', 'size' => 'L'],
            ], 'en');
        } finally {
            @unlink($dir . $present);
        }
        $this->assertCount(2, $r['items']);
        $this->assertSame('white', $r['items'][0]['colour']);
        $this->assertSame('red', $r['items'][1]['colour']);
        $this->assertCount(2, $r['notes']);
        $this->assertStringContainsString($this->pname($p), $r['notes'][0]);
    }

    // ── merge ─────────────────────────────────────────────────────────────────

    public function test_merge_higher_quantity_wins_and_appends_new_lines(): void
    {
        $current = [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1, 'variant_id' => 7, 'variant_label' => 'М'],
            ['product_id' => 3, 'quantity' => 4, 'colour' => 'black', 'size' => 'M', 'design_file' => 'a.png'],
        ];
        $saved = [
            ['product_id' => 1, 'quantity' => 1],
            ['product_id' => 2, 'quantity' => 3, 'variant_id' => 7, 'variant_label' => 'М'],
            ['product_id' => 2, 'quantity' => 1, 'variant_id' => 8, 'variant_label' => 'Л'],
            ['product_id' => 3, 'quantity' => 9, 'colour' => 'black', 'size' => 'M', 'design_file' => 'b.png'],
        ];
        $r = saved_cart_merge($current, $saved);
        $this->assertTrue($r['merged']);
        $this->assertCount(5, $r['items']);
        $this->assertSame(2, $r['items'][0]['quantity']);
        $this->assertSame(3, $r['items'][1]['quantity']);
        $this->assertSame(4, $r['items'][2]['quantity']);
        $this->assertSame(8, $r['items'][3]['variant_id']);
        $this->assertSame('b.png', $r['items'][4]['design_file']);

        $empty = saved_cart_merge([], $saved);
        $this->assertFalse($empty['merged']);
        $this->assertCount(4, $empty['items']);
    }

    // ── pledge ────────────────────────────────────────────────────────────────

    public function test_restore_pledge_refuses_paid_pledge(): void
    {
        $pl = $this->pledge(['reward_id' => 'new', 'status' => 'paid']);
        $row = ['pledge_id' => $pl['pledge_id']];
        $err = saved_cart_restore_pledge(self::$pdo, $row, 'en');
        $this->assertIsString($err);
        $this->assertNotSame('', $err);
        $this->assertArrayNotHasKey('checkout_data', $_SESSION);
    }

    public function test_restore_pledge_sets_checkout_data_for_pending(): void
    {
        skip_unless_modules_on('shop');
        $pl = $this->pledge(['reward_id' => 'new']);
        $err = saved_cart_restore_pledge(self::$pdo, ['pledge_id' => $pl['pledge_id']], 'en');
        $this->assertNull($err);
        $d = $_SESSION['checkout_data'];
        $this->assertSame('Мария', $d['customer_name']);
        $this->assertSame('maria@example.com', $d['customer_email']);
        $this->assertSame('0888123456', $d['customer_phone']);
        $this->assertSame($pl['number'], $d['_pledge_number']);
        $this->assertSame($pl['pledge_id'], (int)$d['_pledge_id']);
        $this->assertEquals(25.0, (float)$d['_pledge_amount']);
        $this->assertTrue(order_session_owns($pl['number']));
    }

    public function test_restore_pledge_refuses_when_reward_gone_or_campaign_closed(): void
    {
        skip_unless_modules_on('shop');
        $gone = $this->pledge(['reward_id' => 'new', 'reward_active' => 0]);
        $this->assertIsString(saved_cart_restore_pledge(self::$pdo, ['pledge_id' => $gone['pledge_id']], 'bg'));

        $ok = $this->pledge(['reward_id' => 'new']);
        setting_set('campaign_active', '0');
        try {
            $this->assertIsString(saved_cart_restore_pledge(self::$pdo, ['pledge_id' => $ok['pledge_id']], 'bg'));
        } finally {
            setting_set('campaign_active', '1');
        }
        $this->assertArrayNotHasKey('checkout_data', $_SESSION);
    }

    public function test_restore_pledge_refuses_when_campaign_module_off(): void
    {
        skip_unless_modules_on('shop');
        $ok  = $this->pledge(['reward_id' => 'new']);
        $off = getenv('NGO_TEST_MODULES_OFF');
        putenv('NGO_TEST_MODULES_OFF=campaign');
        try {
            $this->assertIsString(saved_cart_restore_pledge(self::$pdo, ['pledge_id' => $ok['pledge_id']], 'en'));
        } finally {
            putenv($off === false ? 'NGO_TEST_MODULES_OFF' : 'NGO_TEST_MODULES_OFF=' . $off);
        }
        $this->assertArrayNotHasKey('checkout_data', $_SESSION);
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function http(string $path, string $jar, array $post = [], bool $follow = false, string $browser = ''): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        ]);
        if ($browser !== '') curl_setopt($ch, CURLOPT_COOKIE, 'om_cart=' . $browser);
        if ($post) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw  = (string)curl_exec($ch);
        $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return ['code' => $code, 'location' => $m[1] ?? '', 'body' => substr($raw, $hs)];
    }

    private function startServer(): void
    {
        skip_unless_modules_on('shop');
        self::$base = TestServer::start();
        if (self::$base === '') $this->markTestSkipped('Could not start a local test server.');
    }

    private function jarCookie(string $jar, string $name): string
    {
        foreach (file($jar) ?: [] as $line) {
            $line = preg_replace('/^#HttpOnly_/', '', trim($line));
            $f = explode("\t", $line);
            if (count($f) >= 7 && $f[5] === $name) return $f[6];
        }
        return '';
    }

    public function test_restore_with_unknown_token_shows_expired_and_leaks_nothing(): void
    {
        $this->startServer();
        $secret = 'Тайна-' . uniqid();
        $p = $this->product(['name_bg' => $secret, 'name_en' => $secret, 'active' => 0]);
        $ordered = $this->savedRow(bin2hex(random_bytes(32)), [['product_id' => $p, 'quantity' => 1]], null, date('Y-m-d H:i:s'));
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            foreach ([bin2hex(random_bytes(32)), $ordered, 'not-a-token', str_repeat('A', 64)] as $t) {
                $r = $this->http('/cart/restore.php?t=' . $t, $jar);
                $this->assertSame(302, $r['code'], $t);
                $this->assertStringContainsString('/cart/', $r['location']);
                $page = $this->http('/cart/restore.php?t=' . $t, $jar, [], true);
                $this->assertStringContainsString('Тази количка вече не е налична.', $page['body']);
                $this->assertStringNotContainsString($secret, $page['body']);
            }
        } finally { @unlink($jar); }
    }

    public function test_restore_onto_nonempty_cart_merges_and_keeps_device_token(): void
    {
        $this->startServer();
        $a = $this->product(['stock' => 9]);
        $b = $this->product(['stock' => 9]);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            // This device: a real session with one item in its cart.
            $page = $this->http('/magazin/', $jar);
            $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m), 'no csrf token on shop page');
            $this->http('/cart/add.php', $jar, ['csrf_token' => $m[1], 'product_id' => $a, 'quantity' => 1]);
            $device = $this->jarCookie($jar, 'om_cart');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $device);
            self::$tokens[] = $device;

            // The reminder email's row belongs to another browser.
            $token = $this->savedRow(bin2hex(random_bytes(32)), [
                ['product_id' => $a, 'quantity' => 3], ['product_id' => $b, 'quantity' => 2],
            ]);
            $r = $this->http('/cart/restore.php?t=' . $token, $jar);
            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString('/cart/', $r['location']);
            $this->assertSame($device, $this->jarCookie($jar, 'om_cart'), 'device token must not change');

            $s = self::$pdo->prepare('SELECT items FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
            $s->execute([$device]);
            $items = json_decode((string)$s->fetchColumn(), true);
            $byId = array_column($items, 'quantity', 'product_id');
            $this->assertSame([$a => 3, $b => 2], $byId);

            $cart = $this->http('/cart/', $jar);
            $this->assertStringContainsString('Добавихме артикулите от напомнянето ви в количката.', $cart['body']);
        } finally { @unlink($jar); }
    }

    public function test_pledge_link_goes_to_checkout_or_shows_why_not(): void
    {
        $this->startServer();
        skip_unless_modules_on('campaign');
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $ok = $this->pledge(['reward_id' => 'new']);
            $t  = $this->savedRow(bin2hex(random_bytes(32)), [], $ok['pledge_id']);
            $r  = $this->http('/cart/restore.php?t=' . $t, $jar);
            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString('/checkout/?step=1', $r['location']);

            $paid = $this->pledge(['reward_id' => 'new', 'status' => 'paid']);
            $t2   = $this->savedRow(bin2hex(random_bytes(32)), [], $paid['pledge_id']);
            $r2   = $this->http('/cart/restore.php?t=' . $t2, $jar);
            $this->assertStringContainsString('/campaign/', $r2['location']);
            $page = $this->http('/cart/restore.php?t=' . $t2, $jar, [], true);
            $this->assertStringContainsString('Тази подкрепа вече е обработена.', $page['body']);
            $this->assertNotNull($this->dismissedAt($t2), 'a pledge that cannot be finished stops showing in the notice');
            $this->assertNull($this->dismissedAt($t));
        } finally { @unlink($jar); }
    }

    private function dismissedAt(string $token): ?string
    {
        $s = self::$pdo->prepare('SELECT dismissed_at FROM saved_carts WHERE token = ?');
        $s->execute([$token]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    }

    public function test_pledge_link_goes_to_the_shop_when_campaign_module_off(): void
    {
        $this->startServer();
        $ok = $this->pledge(['reward_id' => 'new']);
        $t  = $this->savedRow(bin2hex(random_bytes(32)), [], $ok['pledge_id']);
        $off = getenv('NGO_TEST_MODULES_OFF');
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        // A server of its own that runs the site with the campaign module off.
        TestServer::stop();
        putenv('NGO_TEST_MODULES_OFF=campaign');
        try {
            self::$base = TestServer::start();
            if (self::$base === '') $this->markTestSkipped('Could not start a local test server.');
            $r = $this->http('/cart/restore.php?t=' . $t, $jar);
            $this->assertSame(302, $r['code']);
            $this->assertSame('/magazin/', $r['location']);
            $this->assertNotNull($this->dismissedAt($t));
            $page = $this->http('/magazin/', $jar);
            $this->assertStringContainsString('Кампанията вече не приема подкрепа.', $page['body']);
        } finally {
            @unlink($jar);
            TestServer::stop();
            putenv($off === false ? 'NGO_TEST_MODULES_OFF' : 'NGO_TEST_MODULES_OFF=' . $off);
        }
    }

    public function test_notice_link_restores_the_line_it_names(): void
    {
        $this->startServer();
        skip_unless_modules_on('campaign');
        $browser = bin2hex(random_bytes(32));
        $a  = $this->product(['stock' => 9]);
        $this->savedRow($browser, [['product_id' => $a, 'quantity' => 2]]);
        $pl = $this->pledge(['reward_id' => 'new']);
        $this->savedRow($browser, [], $pl['pledge_id']);

        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $r = $this->http('/cart/restore.php?what=pledge&lang=bg', $jar, [], false, $browser);
            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString('/checkout/?step=1', $r['location'], 'the pledge line restores the pledge, even with a cart saved');
        } finally { @unlink($jar); }

        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            foreach (['/cart/restore.php?lang=bg', '/cart/restore.php?what=cart&lang=bg', '/cart/restore.php?what=nonsense&lang=bg'] as $path) {
                $r = $this->http($path, $jar, [], false, $browser);
                $this->assertSame(302, $r['code'], $path);
                $this->assertSame('/cart/', $r['location'], $path . ': the cart comes first');
            }
        } finally { @unlink($jar); }

        // Only a pledge saved: asking for the cart finds nothing; no choice gets the pledge.
        $only = bin2hex(random_bytes(32));
        $pl2  = $this->pledge(['reward_id' => 'new']);
        $this->savedRow($only, [], $pl2['pledge_id']);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $page = $this->http('/cart/restore.php?what=cart&lang=bg', $jar, [], true, $only);
            $this->assertStringContainsString('Тази количка вече не е налична.', $page['body']);
            $r = $this->http('/cart/restore.php?lang=bg', $jar, [], false, $only);
            $this->assertStringContainsString('/checkout/?step=1', $r['location']);
        } finally { @unlink($jar); }
    }

    public function test_pledge_checkout_step_one_saves_the_pledge_for_the_notice(): void
    {
        $this->startServer();
        skip_unless_modules_on('campaign');
        setting_set('cart_reminder_email', '0');   // the notice alone is enough
        $pl = $this->pledge(['reward_id' => 'new']);
        $t  = $this->savedRow(bin2hex(random_bytes(32)), [], $pl['pledge_id']);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $this->assertStringContainsString('/checkout/?step=1', $this->http('/cart/restore.php?t=' . $t, $jar)['location']);
            // Start from no saved row at all, as a pledge that was never saved before.
            self::$pdo->prepare('DELETE FROM saved_carts WHERE token = ?')->execute([$t]);
            $this->assertSame(200, $this->http('/checkout/?step=1', $jar)['code']);
            $device = $this->jarCookie($jar, 'om_cart');
            $this->assertTrue(saved_cart_token_valid($device));
            self::$tokens[] = $device;

            $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE pledge_id = ?');
            $s->execute([$pl['pledge_id']]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(1, $rows);
            $this->assertSame($device, $rows[0]['browser_token']);
            $this->assertSame('[]', $rows[0]['items']);
            $this->assertNull($rows[0]['email']);
            $s = self::$pdo->prepare('SELECT COUNT(*) FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
            $s->execute([$device]);
            $this->assertSame(0, (int)$s->fetchColumn(), 'no shop row in pledge mode');
        } finally {
            @unlink($jar);
            setting_set('cart_reminder_email', '1');
        }
    }

    /**
     * A reward pledge has no cart lines, so step 1 used to crash half-way through
     * the page (the order summary read rows of an empty cart). The status is
     * already 200 by then, so check the form itself is all there.
     */
    public function test_pledge_checkout_step_one_shows_the_whole_form_and_the_checkbox(): void
    {
        $this->startServer();
        skip_unless_modules_on('campaign');
        setting_set('cart_reminder_email', '1');
        $pl  = $this->pledge(['reward_id' => 'new']);
        $t   = $this->savedRow(bin2hex(random_bytes(32)), [], $pl['pledge_id']);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $this->assertStringContainsString('/checkout/?step=1', $this->http('/cart/restore.php?t=' . $t, $jar)['location']);
            $page = $this->http('/checkout/?step=1', $jar);
            $this->assertSame(200, $page['code']);
            $this->assertStringNotContainsString('Нещо се обърка', $page['body']);
            $this->assertStringContainsString('name="customer_email"', $page['body']);
            $this->assertStringContainsString('id="cart_reminder"', $page['body']);
            $this->assertStringContainsString('</html>', $page['body']);
            $device = $this->jarCookie($jar, 'om_cart');
            if ($device !== null) self::$tokens[] = $device;
        } finally {
            @unlink($jar);
        }
    }

    public function test_restore_onto_empty_cart_never_adopts_the_links_browser_token(): void
    {
        $this->startServer();
        $a = $this->product(['stock' => 9]);
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $other = bin2hex(random_bytes(32));
            $token = $this->savedRow($other, [['product_id' => $a, 'quantity' => 2]]);
            $r = $this->http('/cart/restore.php?t=' . $token, $jar);
            $this->assertSame(302, $r['code']);
            $device = $this->jarCookie($jar, 'om_cart');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $device);
            self::$tokens[] = $device;
            $this->assertNotSame($other, $device);

            $s = self::$pdo->prepare('SELECT items FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
            $s->execute([$device]);
            $items = json_decode((string)$s->fetchColumn(), true);
            $this->assertSame([$a => 2], array_column($items, 'quantity', 'product_id'));

            $d = self::$pdo->prepare('SELECT dismissed_at FROM saved_carts WHERE token = ?');
            $d->execute([$token]);
            $this->assertNotNull($d->fetchColumn());
        } finally { @unlink($jar); }
    }
}
