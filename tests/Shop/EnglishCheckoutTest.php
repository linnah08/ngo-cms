<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../Support/TOrScanner.php';

/**
 * English buyers stay in English from "Add to cart" to the order confirmation:
 * /en/cart/, /en/checkout/, /en/checkout/confirmation/ and
 * /en/checkout/payment-failed/ are thin wrappers around the one shared page,
 * and every buyer-facing string comes from t_or() (strings.json + built-in
 * BG/EN defaults).
 */
#[Group('shop')]
final class EnglishCheckoutTest extends TestCase
{
    private static function root(): string
    {
        return $_SERVER['DOCUMENT_ROOT'];
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    // ── shop_path() ──────────────────────────────────────────────────────────

    public static function pathProvider(): array
    {
        return [
            ['shop',           '/magazin/',                 '/en/shop/'],
            ['cart',           '/cart/',                    '/en/cart/'],
            ['checkout',       '/checkout/',                '/en/checkout/'],
            ['confirmation',   '/checkout/confirmation/',   '/en/checkout/confirmation/'],
            ['payment-failed', '/checkout/payment-failed/', '/en/checkout/payment-failed/'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testShopPathPerLanguage(string $page, string $bg, string $en): void
    {
        $this->assertSame($bg, shop_path($page, 'bg'));
        $this->assertSame($en, shop_path($page, 'en'));
        $this->assertSame($bg, shop_path($page, 'nonsense'), 'anything but en is BG');
    }

    public function testShopPathRejectsUnknownPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        shop_path('nope', 'en');
    }

    #[DataProvider('pathProvider')]
    public function testEveryEnglishPathHasAWrapperForTheSharedPage(string $page, string $bg, string $en): void
    {
        $wrapper = self::root() . $en . 'index.php';
        $this->assertFileExists($wrapper, "$en needs a page");
        $this->assertStringContainsString(
            "require \$_SERVER['DOCUMENT_ROOT'] . '" . ($page === 'shop' ? '/magazin/' : $bg) . "index.php'",
            (string) file_get_contents($wrapper),
            "$en must reuse the shared page, not copy it"
        );
    }

    // ── t_or() / post_lang() ─────────────────────────────────────────────────

    public function testTOrUsesDefaultsWhenStringsJsonLacksTheKey(): void
    {
        $this->assertSame('Здравей', t_or('test.no.such.key', 'Здравей', 'Hello', 'bg'));
        $this->assertSame('Hello',   t_or('test.no.such.key', 'Здравей', 'Hello', 'en'));
    }

    public function testTOrPrefersStringsJson(): void
    {
        $en = json_decode(self::src('content/en/strings.json'), true);
        $this->assertSame($en['nav.shop'], t_or('nav.shop', 'x', 'y', 'en'));
    }

    public function testTOrFillsPlaceholdersAndSurvivesAPercentSign(): void
    {
        $this->assertSame('Only 3 left', t_or('test.no.such.key', '', 'Only {n} left', 'en', ['n' => 3]));
        // A site owner rewording a string with "%" must not break the page (sprintf would throw).
        $this->assertSame('50% off 3', t_or('test.no.such.key', '', '50% off {n}', 'en', ['n' => 3]));
    }

    public function testPostLang(): void
    {
        $saved = $_POST;
        try {
            $_POST = ['_lang' => 'en'];
            $this->assertSame('en', post_lang());
            $_POST = ['_lang' => '<script>'];
            $this->assertSame('bg', post_lang());
            $_POST = [];
            $this->assertSame('bg', post_lang());
        } finally {
            $_POST = $saved;
        }
    }

    public function testPrintValidationSpeaksTheBuyersLanguage(): void
    {
        $variants = ['colours' => [['name' => 'navy']], 'sizes' => ['M']];
        $this->assertSame(['Please choose a colour.', 'Please choose a size.'], print_validate_item($variants, '', '', 'en'));
        $this->assertSame(['Моля изберете валиден цвят.', 'Моля изберете валиден размер.'], print_validate_item($variants, '', ''));
        $this->assertCount(2, print_validate_design_file(['type' => 'text/plain', 'size' => 20 * 1024 * 1024], 'en'));
        $this->assertStringContainsString('JPEG', print_validate_design_file(['type' => 'text/plain', 'size' => 1], 'en')[0]);
    }

    public function testDeliveryTypeLabels(): void
    {
        $en = checkout_delivery_type_labels('en');
        $bg = checkout_delivery_type_labels('bg');
        $this->assertSame(['office', 'apt', 'address', 'locker'], array_keys($en));
        $this->assertSame('To your door', $en['address']);
        $this->assertSame('До врата', $bg['address']);
    }

    public function testBankPaymentTextsFollowTheOrderLanguage(): void
    {
        $this->assertSame('Order OM-1', shop_payment_title('OM-1', 'en'));
        $this->assertSame('Поръчка OM-1', shop_payment_title('OM-1', 'bg'));
        $this->assertSame(SITE_NAME_EN . ' — order OM-1', shop_payment_description('OM-1', 'en'));
        $this->assertSame(SITE_NAME_BG . ' — поръчка OM-1', shop_payment_description('OM-1', 'bg'));
    }

    // ── every t_or() default is in strings.json ──────────────────────────────

    public function testEveryTOrStringIsInBothStringsFiles(): void
    {
        $bg    = json_decode(self::src('content/bg/strings.json'), true);
        $en    = json_decode(self::src('content/en/strings.json'), true);
        $calls = t_or_scan(self::root());
        $this->assertNotEmpty($calls);

        $seen = [];
        foreach ($calls as $c) {
            $where = str_replace(self::root() . '/', '', $c['file']) . ':' . $c['line'];
            $this->assertNotSame('', $c['key'], "t_or() at $where must use literal key and defaults");
            $this->assertArrayHasKey($c['key'], $bg, "content/bg/strings.json is missing {$c['key']} ($where)");
            $this->assertArrayHasKey($c['key'], $en, "content/en/strings.json is missing {$c['key']} ($where)");
            // A site may reword its strings.json, so the text itself may differ from the
            // default — but it must not be empty and must keep the {placeholders}.
            foreach (['bg' => $bg, 'en' => $en] as $l => $strings) {
                $this->assertNotSame('', trim((string) $strings[$c['key']]), "$l {$c['key']} is empty");
                preg_match_all('/\{\w+\}/', $c[$l], $want);
                preg_match_all('/\{\w+\}/', (string) $strings[$c['key']], $have);
                sort($want[0]);
                sort($have[0]);
                $this->assertSame($want[0], $have[0], "$l {$c['key']} in strings.json lost or gained a {placeholder} ($where)");
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $c['en'], "EN text for {$c['key']} is not English");
            if (isset($seen[$c['key']])) {
                $this->assertSame($seen[$c['key']], [$c['bg'], $c['en']], "{$c['key']} has two different defaults ($where)");
            }
            $seen[$c['key']] = [$c['bg'], $c['en']];
        }
    }

    // ── no Bulgarian-only text left in the buyer's path ──────────────────────

    /**
     * Cyrillic that may stay: text for the admin (error reports, the admin
     * notification), the stored BG item name, and blocks that already switch
     * on $lang (the consent wording and the newsletter opt-in).
     */
    private const ALLOWED = [
        'payment_error_report(',
        "'Нова поръчка #'",
        "'name_bg'      => 'Дарение за '",
        'Приемам <a href',
        'и <a href="<?= $_c_privacy ?>"',
        'и съм запознат/а',
        ": 'Искам да получавам новини от '",
    ];

    public static function buyerFileProvider(): array
    {
        return array_map(fn ($f) => [$f], [
            'cart/add.php', 'cart/update.php', 'cart/remove.php', 'cart/index.php',
            'checkout/index.php', 'checkout/confirmation/index.php', 'checkout/payment-failed/index.php',
            'templates/product-flash.php', 'includes/print_helpers.php',
        ]);
    }

    #[DataProvider('buyerFileProvider')]
    public function testNoUntranslatedBulgarianInBuyerPages(string $rel): void
    {
        $code = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        $bad = [];
        foreach (explode("\n", $code) as $i => $line) {
            if (!preg_match('/[\x{0400}-\x{04FF}]/u', $line)) continue;
            if (str_contains($line, 't_or(')) continue;
            if (str_starts_with(ltrim($line), '//')) continue;             // a JS comment inside <script>
            if (preg_match("/\\\$lang === '(bg|en)' \\?/", $line)) continue; // an inline BG/EN pair
            foreach (self::ALLOWED as $ok) {
                if (str_contains($line, $ok)) continue 2;
            }
            $bad[] = ($i + 1) . ': ' . trim($line);
        }
        $this->assertSame([], $bad, "$rel has Bulgarian text an English buyer would see");
    }

    #[DataProvider('buyerFileProvider')]
    public function testNoHardcodedBulgarianShopRedirects(string $rel): void
    {
        $src = self::src($rel);
        $this->assertDoesNotMatchRegularExpression("#Location: /(cart|checkout)/#", $src, "$rel must redirect via shop_path()");
        $this->assertDoesNotMatchRegularExpression('#href="/(cart|checkout)/#', $src, "$rel must link via shop_path()");
    }

    public function testPaymentReturnsSendTheBuyerBackInTheirLanguage(): void
    {
        foreach (['api/payment-return.php', 'api/iris-payment-return.php'] as $rel) {
            $src = self::src($rel);
            $this->assertStringContainsString("shop_path('confirmation', \$order['lang'] ?? 'bg')", $src, $rel);
            $this->assertStringContainsString("shop_path('payment-failed', \$order['lang'] ?? 'bg')", $src, $rel);
        }
        $this->assertStringContainsString("shop_path('checkout', \$pledge_lang)", self::src('campaign/checkout.php'));
    }

    public function testOrderPagesMoveToTheOrdersLanguage(): void
    {
        // A bank return on the BG path must still show an English order in English.
        $this->assertStringContainsString("shop_path('confirmation', \$order_lang)", self::src('checkout/confirmation/index.php'));
        $this->assertStringContainsString("shop_path('payment-failed', \$lang)", self::src('checkout/payment-failed/index.php'));
    }

    public function testShopPostFormsSayWhichLanguageTheyCameFrom(): void
    {
        $cart = self::src('cart/index.php');
        $this->assertSame(2, substr_count($cart, '<input type="hidden" name="_lang" value="<?= h($lang) ?>">'), 'cart remove + update forms');
        $this->assertStringContainsString('<input type="hidden" name="_lang" value="<?= h($lang) ?>">', self::src('checkout/index.php'));
        foreach (['cart/update.php', 'cart/remove.php', 'cart/add.php'] as $rel) {
            $this->assertStringContainsString('post_lang()', self::src($rel), $rel);
        }
    }

    // ── rendered: header + notice on an English page ─────────────────────────

    #[RunInSeparateProcess]
    public function testEnglishHeaderCartLinkIsEnglish(): void
    {
        $_SERVER['REQUEST_URI'] = '/en/shop/';
        start_session();
        $_SESSION['cart'] = [['product_id' => 1, 'quantity' => 2]];
        $page_title = 'Shop';
        global $_path_map_bg_to_en; // header.php's _switch_lang() reads it as a global
        ob_start();
        require self::root() . '/templates/header.php';
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('#<a href="/en/cart/" class="cart-link" aria-label="Cart, 2 items">#', $html);
        $this->assertDoesNotMatchRegularExpression('#aria-label="[^"]*[\x{0400}-\x{04FF}]#u', str_replace('aria-label="Български"', '', $html),
            'no Bulgarian aria-label on an English page (the BG language link names itself in Bulgarian on purpose)');
    }

    #[RunInSeparateProcess]
    public function testBulgarianHeaderCartLink(): void
    {
        $_SERVER['REQUEST_URI'] = '/magazin/';
        start_session();
        $_SESSION['cart'] = [['product_id' => 1, 'quantity' => 1]];
        $page_title = 'Магазин';
        global $_path_map_bg_to_en; // header.php's _switch_lang() reads it as a global
        ob_start();
        require self::root() . '/templates/header.php';
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('#<a href="/cart/" class="cart-link" aria-label="Количка, 1 артикул">#u', $html);
    }

    #[RunInSeparateProcess]
    public function testLanguageSwitchOnCartAndCheckout(): void
    {
        $_SERVER['REQUEST_URI'] = '/checkout/';
        $page_title = 'x';
        global $_path_map_bg_to_en; // header.php's _switch_lang() reads it as a global
        ob_start();
        require self::root() . '/templates/header.php';
        ob_end_clean();
        $this->assertSame('/en/checkout/', _switch_lang('/checkout/', 'bg'));
        $this->assertSame('/en/cart/', _switch_lang('/cart/', 'bg'));
        $this->assertSame('/checkout/confirmation/', _switch_lang('/en/checkout/confirmation/', 'en'));
        $this->assertSame('/cart/', _switch_lang('/en/cart/', 'en'));
    }

    #[RunInSeparateProcess]
    public function testEnglishAddedNoticeLinksToEnglishCartAndCheckout(): void
    {
        $_SERVER['REQUEST_URI'] = '/en/shop/some-product/';
        $flash = [['type' => 'success', 'message' => 'Added to cart!']];
        $just_added = true; // as after cart/add.php's ?gads=atc redirect
        ob_start();
        require self::root() . '/templates/product-flash.php';
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('#<a href="/en/cart/"[^>]*>\s*View cart#', $html);
        $this->assertMatchesRegularExpression('#<a href="/en/checkout/"[^>]*>\s*Checkout now#', $html);
        $this->assertStringContainsString('aria-label="Dismiss message"', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $html);
    }
}
