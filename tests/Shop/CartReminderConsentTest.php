<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

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
        foreach ($this->saved_settings as $k => $v) setting_set($k, $v);
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
        $this->assertStringContainsString('saved_cart_consent_label(post_lang())', $handler);
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
