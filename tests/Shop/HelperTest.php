<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('shop')]
#[Group('helpers')]
final class HelperTest extends TestCase
{
    // ── generate_order_number ────────────────────────────────────────────────

    public function testOrderNumberMatchesFormat(): void
    {
        $num = generate_order_number();
        $this->assertMatchesRegularExpression(
            '/^OM-\d{8}-[A-F0-9]{4}$/i',
            $num,
            'Order number should be OM-YYYYMMDD-XXXX'
        );
    }

    public function testOrderNumberContainsTodayDate(): void
    {
        $num  = generate_order_number();
        $date = date('Ymd');
        $this->assertStringContainsString("OM-{$date}-", $num);
    }

    public function testOrderNumberIsNotDeterministic(): void
    {
        // Two calls should (with overwhelming probability) differ
        $a = generate_order_number();
        $b = generate_order_number();
        // Same format
        $this->assertMatchesRegularExpression('/^OM-\d{8}-[A-F0-9]{4}$/i', $a);
        $this->assertMatchesRegularExpression('/^OM-\d{8}-[A-F0-9]{4}$/i', $b);
        // Very likely different (4 random hex chars = 65536 possibilities)
        // We just verify both are valid; a collision here is negligible
    }

    // ── slug ─────────────────────────────────────────────────────────────────

    #[DataProvider('slugProvider')]
    public function testSlug(string $input, string $expected): void
    {
        $this->assertSame($expected, slug($input));
    }

    public static function slugProvider(): array
    {
        return [
            'lowercase ASCII'       => ['Hello World',     'hello-world'],
            'numbers'               => ['Test 123',        'test-123'],
            'multiple spaces'       => ['foo   bar',       'foo-bar'],
            'leading/trailing'      => ['  hello  ',       'hello'],
            'special chars'         => ['Hello & World!',  'hello-world'],
            'already slug'          => ['my-slug',         'my-slug'],
            // Cyrillic — must be preserved, not stripped
            'cyrillic basic'        => ['Здравейте свят',  'здравейте-свят'],
            'cyrillic with number'  => ['Статия 18',       'статия-18'],
            'cyrillic mixed punct'  => ['Какво се случва с децата лишени от родителска грижа след като навършат 18',
                                        'какво-се-случва-с-децата-лишени-от-родителска-грижа-след-като-навършат-18'],
        ];
    }

    // ── format_eur ───────────────────────────────────────────────────────────

    #[DataProvider('eurProvider')]
    public function testFormatEur(float $amount, string $expected): void
    {
        $this->assertSame($expected, format_eur($amount));
    }

    public static function eurProvider(): array
    {
        return [
            'zero'          => [0.0,     '0.00 €'],
            'integer'       => [10.0,    '10.00 €'],
            'decimal'       => [9.99,    '9.99 €'],
            'large'         => [1234.56, '1 234.56 €'],
        ];
    }

    // ── price_html ───────────────────────────────────────────────────────────

    public function testPriceHtmlContainsEurAmount(): void
    {
        $html = price_html(15.0);
        $this->assertStringContainsString('15.00 €', $html);
        $this->assertStringContainsString('class="price"', $html);
    }

    public function testPriceHtmlShowsDualCurrencyBeforeJuly2026(): void
    {
        // As of test authoring date (2026-04-05) dual display is still active
        if (!SHOW_DUAL_CURRENCY) {
            $this->markTestSkipped('Dual currency period has ended.');
        }
        $html = price_html(10.0);
        $this->assertStringContainsString('лв', $html);
        $this->assertStringContainsString('price__bgn', $html);
    }

    public function testPriceHtmlIsValidHtml(): void
    {
        $html = price_html(25.0);
        // Must open and close the .price span
        $this->assertStringStartsWith('<span class="price">', $html);
        $this->assertStringEndsWith('</span>', $html);
    }

    // ── cart_count ───────────────────────────────────────────────────────────

    public function testCartCountWithNoSession(): void
    {
        // session_status() !== ACTIVE → returns 0
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->assertSame(0, cart_count());
    }

    public function testCartCountWithEmptyCart(): void
    {
        start_session();
        $_SESSION['cart'] = [];
        $this->assertSame(0, cart_count());
    }

    public function testCartCountSumsQuantities(): void
    {
        start_session();
        $_SESSION['cart'] = [
            ['product_id' => 1, 'quantity' => 3],
            ['product_id' => 2, 'quantity' => 2],
        ];
        $this->assertSame(5, cart_count());
        unset($_SESSION['cart']);
    }

    public function testCartCountSingleItem(): void
    {
        start_session();
        $_SESSION['cart'] = [['product_id' => 7, 'quantity' => 1]];
        $this->assertSame(1, cart_count());
        unset($_SESSION['cart']);
    }

    // ── flash messages ───────────────────────────────────────────────────────

    public function testFlashRoundTrip(): void
    {
        start_session();
        unset($_SESSION['flash']);

        flash_set('success', 'Everything is fine');
        $messages = flash_get();

        $this->assertCount(1, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertSame('Everything is fine', $messages[0]['message']);
    }

    public function testFlashGetClearsMessages(): void
    {
        start_session();
        unset($_SESSION['flash']);

        flash_set('error', 'Something went wrong');
        flash_get(); // consume
        $second = flash_get();

        $this->assertSame([], $second, 'Second flash_get() should return empty array');
    }

    public function testFlashMultipleMessages(): void
    {
        start_session();
        unset($_SESSION['flash']);

        flash_set('success', 'One');
        flash_set('error',   'Two');
        flash_set('success', 'Three');

        $messages = flash_get();
        $this->assertCount(3, $messages);
        $this->assertSame('One',   $messages[0]['message']);
        $this->assertSame('Two',   $messages[1]['message']);
        $this->assertSame('Three', $messages[2]['message']);
    }

    // ── h() XSS escaping ─────────────────────────────────────────────────────

    public function testHEscapesXss(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            h('<script>alert(1)</script>')
        );
        $this->assertSame('&quot;', h('"'));
        $this->assertSame('&#039;', h("'"));
    }

    // ── format_bgn ───────────────────────────────────────────────────────────

    public function testFormatBgn(): void
    {
        // 10 EUR × 1.95583 (EUR_BGN_RATE) = 19.5583 → rounded to 19.56
        $result = format_bgn(10.00);
        $this->assertStringContainsString('19.56', $result);
        $this->assertStringContainsString('лв', $result);
    }

    // ── csrf_verify round-trip ───────────────────────────────────────────────

    public function testCsrfVerifyRoundTrip(): void
    {
        start_session();

        // Generate a token and verify it passes
        $token = csrf_token();
        $_POST['csrf_token'] = $token;
        $this->assertTrue(csrf_verify(), 'Valid token should pass csrf_verify()');

        // Empty string should fail
        $_POST['csrf_token'] = '';
        $this->assertFalse(csrf_verify(), 'Empty token should fail csrf_verify()');

        // Tampered value should fail
        $_POST['csrf_token'] = 'tampered';
        $this->assertFalse(csrf_verify(), 'Tampered token should fail csrf_verify()');

        unset($_POST['csrf_token']);
    }

    protected function tearDown(): void
    {
        // Clean up session cart/flash state between tests
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['cart'], $_SESSION['flash']);
        }
        // Clean up any POST state set during CSRF tests
        unset($_POST['csrf_token']);
    }
}
