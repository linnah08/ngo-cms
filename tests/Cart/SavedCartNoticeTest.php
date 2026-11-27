<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../Support/TestServer.php';

/**
 * The returning-visitor notice (templates/partials/saved-cart-notice.php) and
 * cart/dismiss-notice.php. DB tests self-skip without a database.
 */
#[Group('shop')]
#[Group('cart')]
final class SavedCartNoticeTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static string $base = '';
    private static array $product_ids = [];
    private static array $pledge_ids  = [];
    private static array $tokens      = [];
    private static array $saved_settings = [];

    public static function setUpBeforeClass(): void
    {
        if (!test_db_available()) return;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';
        self::$pdo = get_pdo();
        foreach (['cart_reminder_email', 'cart_reminder_notice', 'campaign_active', 'campaign_title', 'campaign_title_en'] as $k) {
            self::$saved_settings[$k] = setting_get($k, '');
        }
        setting_set('cart_reminder_notice', '1');
        setting_set('campaign_active', '1');
        setting_set('campaign_title', 'Тестова кауза');
        setting_set('campaign_title_en', 'Test cause');
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stop();
        if (!self::$pdo) return;
        foreach (self::$tokens as $t) {
            self::$pdo->prepare('DELETE FROM saved_carts WHERE browser_token = ?')->execute([$t]);
        }
        foreach (self::$pledge_ids as $id) {
            self::$pdo->prepare('DELETE FROM campaign_pledges WHERE id = ?')->execute([$id]);
        }
        foreach (self::$product_ids as $id) {
            self::$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        foreach (self::$saved_settings as $k => $v) setting_set($k, $v);
    }

    protected function setUp(): void
    {
        if (!self::$pdo) $this->markTestSkipped('No DB configured.');
        skip_unless_modules_on('shop');
        setting_set('cart_reminder_notice', '1');
        self::$base = TestServer::start();
        if (self::$base === '') $this->markTestSkipped('Could not start a local test server.');
    }

    private function product(): int
    {
        self::$pdo->prepare('INSERT INTO products (slug,name_bg,name_en,price_eur,stock,active,`type`) VALUES (?,?,?,?,?,?,?)')
            ->execute(['test-notice-' . uniqid(), 'Тениска', 'Shirt', 10.00, 9, 1, 'standard']);
        $id = (int)self::$pdo->lastInsertId();
        self::$product_ids[] = $id;
        return $id;
    }

    /** A saved row for a fresh browser; returns the browser token. */
    private function cartRow(int $qty_a = 1, int $qty_b = 1): string
    {
        $browser = bin2hex(random_bytes(32));
        self::$tokens[] = $browser;
        $items = [['product_id' => $this->product(), 'quantity' => $qty_a], ['product_id' => $this->product(), 'quantity' => $qty_b]];
        self::$pdo->prepare('INSERT INTO saved_carts (token,browser_token,items,lang,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())')
            ->execute([bin2hex(random_bytes(32)), $browser, json_encode($items), 'bg']);
        return $browser;
    }

    private function pledgeRow(): string
    {
        $browser = bin2hex(random_bytes(32));
        self::$tokens[] = $browser;
        self::$pdo->prepare('INSERT INTO campaign_pledges (pledge_number,name,email,phone,amount_eur,reward_id,payment_status) VALUES (?,?,?,?,?,?,?)')
            ->execute(['CP-TEST-' . strtoupper(bin2hex(random_bytes(3))), 'Мария', 'm@example.com', '0888123456', 25.00, null, 'pending']);
        $pid = (int)self::$pdo->lastInsertId();
        self::$pledge_ids[] = $pid;
        self::$pdo->prepare('INSERT INTO saved_carts (token,browser_token,pledge_id,items,lang,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')
            ->execute([bin2hex(random_bytes(32)), $browser, $pid, '[]', 'bg']);
        return $browser;
    }

    private function http(string $path, string $jar, string $browser = '', array $post = []): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        ]);
        if ($browser !== '') curl_setopt($ch, CURLOPT_COOKIE, 'om_cart=' . $browser);
        if ($post) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw  = (string)curl_exec($ch);
        $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return ['code' => $code, 'location' => $m[1] ?? '', 'body' => substr($raw, $hs)];
    }

    private function jar(): string { return tempnam(sys_get_temp_dir(), 'jar'); }

    public function test_notice_shows_count_and_restore_link_when_session_cart_empty(): void
    {
        $b = $this->cartRow(1, 1);
        $jar = $this->jar();
        try {
            $bg = $this->http('/magazin/', $jar, $b);
            $this->assertStringContainsString('Оставихте 2 продукта в количката.', $bg['body']);
            $this->assertStringContainsString('/cart/restore.php', $bg['body']);
            $en = $this->http('/en/shop/', $jar, $b);
            $this->assertStringContainsString('You left 2 items in your cart.', $en['body']);
        } finally { @unlink($jar); }
    }

    public function test_notice_shows_pledge_line(): void
    {
        skip_unless_modules_on('campaign');
        $b = $this->pledgeRow();
        $jar = $this->jar();
        try {
            $bg = $this->http('/magazin/', $jar, $b);
            $this->assertStringContainsString('Започнахте да подкрепите', $bg['body']);
            $this->assertStringContainsString('Тестова кауза', $bg['body']);
            $this->assertStringContainsString('/cart/restore.php', $bg['body']);
            $en = $this->http('/en/shop/', $jar, $b);
            $this->assertStringContainsString('You started supporting Test cause.', $en['body']);
        } finally { @unlink($jar); }
    }

    public function test_notice_hidden_when_session_cart_not_empty(): void
    {
        $b = $this->cartRow();
        $p = $this->product();
        $jar = $this->jar();
        try {
            $page = $this->http('/magazin/', $jar, $b);
            $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m));
            $this->http('/cart/add.php', $jar, $b, ['csrf_token' => $m[1], 'product_id' => $p, 'quantity' => 1]);
            $after = $this->http('/magazin/', $jar, $b);
            $this->assertStringNotContainsString('data-saved-cart-notice', $after['body']);
        } finally { @unlink($jar); }
    }

    public function test_notice_hidden_when_switch_off(): void
    {
        $b = $this->cartRow();
        $jar = $this->jar();
        setting_set('cart_reminder_notice', '0');
        try {
            $this->assertStringNotContainsString('data-saved-cart-notice', $this->http('/magazin/', $jar, $b)['body']);
        } finally { @unlink($jar); setting_set('cart_reminder_notice', '1'); }
    }

    public function test_notice_hidden_on_checkout_and_other_pages(): void
    {
        $b = $this->cartRow();
        $jar = $this->jar();
        try {
            foreach (['/checkout/', '/en/checkout/', '/'] as $path) {
                $this->assertStringNotContainsString('data-saved-cart-notice', $this->http($path, $jar, $b)['body'], $path);
            }
        } finally { @unlink($jar); }
    }

    public function test_notice_markup_accessible(): void
    {
        $b = $this->cartRow();
        $jar = $this->jar();
        try {
            $body = $this->http('/magazin/', $jar, $b)['body'];
            $this->assertStringContainsString('role="status"', $body);
            $this->assertMatchesRegularExpression('/<button[^>]*aria-label="[^"]+"/', $body);
            $this->assertStringContainsString('min-height:44px', $body);
        } finally { @unlink($jar); }
    }

    public function test_notice_hidden_after_dismiss_and_only_for_own_rows(): void
    {
        $b = $this->cartRow();
        $other = $this->cartRow();
        $jar = $this->jar();
        try {
            $page = $this->http('/magazin/', $jar, $b);
            $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m));
            $r = $this->http('/cart/dismiss-notice.php', $jar, $b, ['csrf_token' => $m[1], 'return' => '/en/shop/', 'victim' => '1', 'id' => '1']);
            $this->assertSame(302, $r['code']);
            $this->assertSame('/en/shop/', $r['location']);
            $this->assertStringNotContainsString('data-saved-cart-notice', $this->http('/magazin/', $jar, $b)['body']);
            $s = self::$pdo->prepare('SELECT dismissed_at FROM saved_carts WHERE browser_token = ?');
            $s->execute([$other]);
            $this->assertNull($s->fetchColumn(), 'another browser\'s row must stay');
        } finally { @unlink($jar); }
    }

    public function test_dismiss_requires_csrf(): void
    {
        $b = $this->cartRow();
        $jar = $this->jar();
        try {
            $this->http('/magazin/', $jar, $b);
            $this->http('/cart/dismiss-notice.php', $jar, $b, ['csrf_token' => 'nope', 'return' => '/magazin/']);
            $s = self::$pdo->prepare('SELECT dismissed_at FROM saved_carts WHERE browser_token = ?');
            $s->execute([$b]);
            $this->assertNull($s->fetchColumn());
        } finally { @unlink($jar); }
    }

    public function test_dismiss_rejects_offsite_redirect(): void
    {
        $b = $this->cartRow();
        $jar = $this->jar();
        try {
            $page = $this->http('/magazin/', $jar, $b);
            preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m);
            foreach (['https://evil.example/', '//evil.example/', '/\\evil.example', 'javascript:alert(1)', 'magazin/'] as $bad) {
                $r = $this->http('/cart/dismiss-notice.php', $jar, $b, ['csrf_token' => $m[1], 'return' => $bad]);
                $this->assertSame(302, $r['code'], $bad);
                $this->assertSame('/magazin/', $r['location'], $bad);
            }
            $get = $this->http('/cart/dismiss-notice.php', $jar, $b);
            $this->assertSame('/magazin/', $get['location']);
        } finally { @unlink($jar); }
    }
}
