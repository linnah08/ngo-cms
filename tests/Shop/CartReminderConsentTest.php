<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../Support/TestServer.php';

/** Opt-in reminder checkbox at checkout step 1 and the consent it records. */
#[Group('shop')]
#[Group('cart')]
final class CartReminderConsentTest extends TestCase
{
    private static ?PDO $pdo = null;
    private string $browser;
    private array $saved_settings = [];
    private array $saved_cookie = [];
    private array $saved_session = [];
    private array $pledge_ids = [];
    private array $product_ids = [];
    private array $browsers = [];

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
        $this->pledge_ids = [];
        if (!self::$pdo) return;
        foreach (['cart_reminder_email', 'cart_reminder_notice'] as $k) {
            $this->saved_settings[$k] = setting_get($k, '0');
        }
        $this->browser = bin2hex(random_bytes(32));
        $_COOKIE['om_cart'] = $this->browser;
        $_SESSION = ['cart' => [['product_id' => 1, 'qty' => 1]]];
        setting_set('cart_reminder_email', '1');
        setting_set('cart_reminder_notice', '0');
    }

    protected function tearDown(): void
    {
        $_COOKIE  = $this->saved_cookie;
        $_SESSION = $this->saved_session;
        if (!self::$pdo) return;
        self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$this->browser]);
        foreach ($this->browsers as $b) self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$b]);
        foreach ($this->product_ids as $id) self::$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        foreach ($this->saved_settings as $k => $v) setting_set($k, $v);
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
    }

    private function requireDb(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
    }

    private function shopRow(): ?array
    {
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
        $s->execute([$this->browser]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function pledgeRow(int $id): ?array
    {
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE pledge_id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function src(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/checkout/index.php');
    }

    // ── markup ───────────────────────────────────────────────────────────────

    public function test_checkbox_renders_unticked_with_label_when_email_switch_on(): void
    {
        $this->requireDb();
        $html = saved_cart_consent_checkbox_html('en', false);
        $this->assertStringContainsString("Email me a reminder if I don't finish my order", html_entity_decode($html, ENT_QUOTES));
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="cart_reminder"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="cart_reminder"/', $html);
        $this->assertMatchesRegularExpression('/<label[^>]*for="cart_reminder"/', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertStringContainsString('Изпратете ми напомняне по имейл, ако не завърша поръчката', saved_cart_consent_label('bg'));
        $this->assertStringContainsString('checked', saved_cart_consent_checkbox_html('en', true));
        $this->assertStringContainsString('checked', saved_cart_consent_checkbox_html('bg', true));

        // The step-1 form renders it for shop and pledge alike (not gated on pledge mode).
        $s = self::src();
        $this->assertStringContainsString('saved_cart_consent_checkbox_html(', $s);
        $form = substr($s, strpos($s, 'name="customer_email"'));
        $form = substr($form, 0, strpos($form, 'needs_invoice'));
        $this->assertStringContainsString('saved_cart_consent_checkbox_html(', $form);
    }

    public function test_checkbox_absent_when_email_switch_off(): void
    {
        $this->requireDb();
        setting_set('cart_reminder_email', '0');
        $this->assertSame('', saved_cart_consent_checkbox_html('bg', false));
        $this->assertSame('', saved_cart_consent_checkbox_html('en', true));
    }

    public function test_handler_records_only_after_validation_with_rendered_text(): void
    {
        $s = self::src();
        $this->assertStringContainsString('saved_cart_record_consent(', $s);
        $this->assertStringContainsString("['cart_reminder']", $s);
        $handler = substr($s, strpos($s, "if (\$action === 'contact')"));
        $handler = substr($handler, 0, strpos($handler, "if (\$action === 'delivery')"));
        $this->assertGreaterThan(strpos($handler, 'if (!$errors) {', strpos($handler, 'needs_invoice')), strpos($handler, 'saved_cart_record_consent('));
        $this->assertMatchesRegularExpression('/catch \(Throwable \$e\)\s*\{\s*error_log/', $handler);
    }

    // ── step 1 over HTTP ─────────────────────────────────────────────────────

    private function http(string $url, string $jar, array $post = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        ]);
        if ($post) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw  = (string)curl_exec($ch);
        $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return ['code' => $code, 'location' => $m[1] ?? '', 'body' => substr($raw, $hs)];
    }

    private function jarCookie(string $jar, string $name): string
    {
        foreach (file($jar) ?: [] as $line) {
            $f = explode("\t", (string)preg_replace('/^#HttpOnly_/', '', trim($line)));
            if (count($f) >= 7 && $f[5] === $name) return $f[6];
        }
        return '';
    }

    /** Puts a product in a fresh session's cart, ticks the box at step 1 of $checkout; returns the saved row. */
    private function stepOneWithReminder(string $shop, string $checkout): array
    {
        skip_unless_modules_on('shop');
        $base = TestServer::start();
        if ($base === '') $this->markTestSkipped('Could not start a local test server.');
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`) VALUES (?,?,?,?,?,?,?)')
            ->execute(['test-consent-' . uniqid(), 'Тениска', 'Shirt', 10.00, 9, 1, 'standard']);
        $pid = $this->product_ids[] = (int)self::$pdo->lastInsertId();
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            $page = $this->http($base . $shop, $jar);
            $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m), 'no csrf token on the shop page');
            $this->http($base . '/cart/add.php', $jar, ['csrf_token' => $m[1], 'product_id' => $pid, 'quantity' => 1]);
            $this->browsers[] = $browser = $this->jarCookie($jar, 'om_cart');
            $this->assertTrue(saved_cart_token_valid($browser));

            $form = $this->http($base . $checkout, $jar);
            $this->assertSame(200, $form['code']);
            $this->assertStringContainsString('name="cart_reminder"', $form['body']);
            preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $form['body'], $m);
            $r = $this->http($base . $checkout, $jar, [
                'csrf_token' => $m[1], 'action' => 'contact', 'customer_name' => 'Anna Smith',
                'customer_email' => 'consent-test@example.com', 'customer_phone' => '0888123456', 'cart_reminder' => '1',
            ]);
            $this->assertSame(302, $r['code']);
            $this->assertStringContainsString('step=2', $r['location']);
        } finally {
            @unlink($jar);
        }
        $s = self::$pdo->prepare('SELECT * FROM saved_carts WHERE browser_token = ? AND pledge_id IS NULL');
        $s->execute([$browser]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function test_english_step_one_records_english_language_and_wording(): void
    {
        $this->requireDb();
        $row = $this->stepOneWithReminder('/en/shop/', '/en/checkout/');
        $this->assertSame('en', $row['lang']);
        $this->assertSame("Email me a reminder if I don't finish my order", $row['remind_consent_text']);
        $this->assertSame('consent-test@example.com', $row['email']);
        $this->assertSame(1, (int)$row['remind_opt_in']);
    }

    public function test_bulgarian_step_one_records_bulgarian_language_and_wording(): void
    {
        $this->requireDb();
        $row = $this->stepOneWithReminder('/magazin/', '/checkout/');
        $this->assertSame('bg', $row['lang']);
        $this->assertSame('Изпратете ми напомняне по имейл, ако не завърша поръчката', $row['remind_consent_text']);
    }

    // ── recording ────────────────────────────────────────────────────────────

    public function test_ticked_records_consent_text_and_time(): void
    {
        $this->requireDb();
        $text = saved_cart_consent_label('en');
        saved_cart_record_consent(self::$pdo, true, 'a@example.com', 'Anna Smith', 'en', $text);
        $row = $this->shopRow();
        $this->assertNotNull($row, 'row is created when none exists yet');
        $this->assertSame('a@example.com', $row['email']);
        $this->assertSame('Anna Smith', $row['name']);
        $this->assertSame(1, (int)$row['remind_opt_in']);
        $this->assertSame($text, $row['remind_consent_text']);
        $this->assertNotNull($row['remind_consent_at']);
    }

    public function test_unticked_after_ticked_clears_email(): void
    {
        $this->requireDb();
        saved_cart_record_consent(self::$pdo, true, 'a@example.com', 'Anna Smith', 'bg', 'x');
        saved_cart_record_consent(self::$pdo, false, 'a@example.com', 'Anna Smith', 'bg', 'x');
        $row = $this->shopRow();
        $this->assertSame(0, (int)$row['remind_opt_in']);
        $this->assertNull($row['email']);
        $this->assertNull($row['name']);
    }

    public function test_pledge_mode_ticked_creates_pledge_row_and_leaves_shop_row_untouched(): void
    {
        $this->requireDb();
        saved_cart_sync(self::$pdo, [['product_id' => 1, 'qty' => 1]], 'bg');
        $before = $this->shopRow();
        $pid = random_int(900000000, 999999999);
        try {
            saved_cart_record_consent(self::$pdo, true, 'p@example.com', 'Petar Petrov', 'bg', 'txt', $pid);
            $row = $this->pledgeRow($pid);
            $this->assertNotNull($row);
            $this->assertSame($this->browser, $row['browser_token']);
            $this->assertSame('[]', $row['items']);
            $this->assertSame(1, (int)$row['remind_opt_in']);
            $this->assertSame('p@example.com', $row['email']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['token']);
            $this->assertSame($before, $this->shopRow());

            // A second call updates the same row.
            saved_cart_record_consent(self::$pdo, false, 'p@example.com', 'Petar Petrov', 'bg', 'txt', $pid);
            $row2 = $this->pledgeRow($pid);
            $this->assertSame($row['id'], $row2['id']);
            $this->assertSame(0, (int)$row2['remind_opt_in']);
            $this->assertNull($row2['email']);
            $this->assertSame($before, $this->shopRow());
        } finally {
            self::$pdo->prepare('DELETE FROM saved_carts WHERE pledge_id = ?')->execute([$pid]);
        }
    }
}
