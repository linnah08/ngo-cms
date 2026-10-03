<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The notice a buyer sees on the product page right after "Add to cart"
 * (templates/product-flash.php). It must nudge them on to the cart / checkout
 * instead of leaving them on a bare "Added to cart!", and it must be usable
 * with a keyboard and a screen reader.
 */
#[Group('shop')]
final class AddedToCartNoticeTest extends TestCase
{
    private function render(array $flash, bool $just_added = true): string
    {
        ob_start();
        (static function (array $flash, bool $just_added): void {
            require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-flash.php';
        })($flash, $just_added);
        return (string) ob_get_clean();
    }

    public function testOtherSuccessMessagesGetNoCartButtons(): void
    {
        // The footer newsletter form redirects back to the product page with its own success flash.
        $html = $this->render([['type' => 'success', 'message' => 'Записахте се успешно за бюлетина!']], false);

        $this->assertStringContainsString('Записахте се успешно за бюлетина!', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('/checkout/', $html);
        $this->assertStringNotContainsString('Виж количката', $html);
        $this->assertStringNotContainsString('<script', $html, 'no focus grab for a notice the buyer did not just cause');
    }

    public function testAddIsRecognisedFromTheRedirect(): void
    {
        // cart/add.php appends ?gads=atc only after a successful add.
        $_GET['gads'] = 'atc';
        try {
            ob_start();
            (static function (): void {
                $flash = [['type' => 'success', 'message' => 'Добавено в количката!']];
                require $_SERVER['DOCUMENT_ROOT'] . '/templates/product-flash.php';
            })();
            $html = (string) ob_get_clean();
        } finally {
            unset($_GET['gads']);
        }
        $this->assertStringContainsString('/checkout/', $html);
        $this->assertStringContainsString(
            "?gads=atc",
            (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/cart/add.php'),
            'the notice relies on cart/add.php marking a successful add this way'
        );
    }

    public function testSuccessOffersCartAndCheckout(): void
    {
        $html = $this->render([['type' => 'success', 'message' => 'Добавено в количката!']]);

        $this->assertStringContainsString('Добавено в количката!', $html);
        $this->assertMatchesRegularExpression('#<a href="/cart/"[^>]*>\s*Виж количката#u', $html);
        $this->assertMatchesRegularExpression('#<a href="/checkout/"[^>]*>\s*Завърши поръчката#u', $html);
    }

    public function testSuccessIsAnnouncedFocusedAndDismissible(): void
    {
        $html = $this->render([['type' => 'success', 'message' => 'Добавено в количката!']]);

        $this->assertStringContainsString('role="status"', $html, 'success must sit in a live region');
        $this->assertStringContainsString('tabindex="-1"', $html, 'notice must be focusable so it can take focus on load');
        $this->assertMatchesRegularExpression(
            '#<button type="button" data-cart-notice-close aria-label="Затвори съобщението"#u',
            $html,
            'icon-only close button needs a real <button> and an aria-label'
        );
        $this->assertStringContainsString('width:44px;height:44px', $html, 'close button tap target');
        $this->assertStringContainsString('notice.focus()', $html);
        $this->assertStringContainsString("'Escape'", $html);
        $this->assertStringContainsString("getElementById('addToCartBtn')", $html, 'focus returns to the add button on dismiss');
    }

    public function testNoticeHasNoMotion(): void
    {
        // Nothing to switch off for prefers-reduced-motion: the notice must not animate.
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/product-flash.php');
        $this->assertStringNotContainsString('animation:', $src);
        $this->assertStringNotContainsString('transition:', $src);
        $this->assertStringNotContainsString("behavior:'smooth'", $src);
    }

    public function testErrorIsAnAlertWithoutCheckoutNudge(): void
    {
        $html = $this->render([['type' => 'error', 'message' => 'Няма повече налични бройки.']]);

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Няма повече налични бройки.', $html);
        $this->assertStringNotContainsString('/checkout/', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testErrorAndSuccessTogetherBothRender(): void
    {
        // cart/add.php sets both when the quantity was capped by stock.
        $html = $this->render([
            ['type' => 'error',   'message' => 'Налични са само 2 бр.'],
            ['type' => 'success', 'message' => 'Добавено в количката!'],
        ]);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertSame(1, substr_count($html, '<script'));
    }

    public function testMessageIsEscaped(): void
    {
        $html = $this->render([['type' => 'success', 'message' => '<img src=x onerror=alert(1)>']]);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function testNoFlashRendersNothing(): void
    {
        $this->assertSame('', trim($this->render([])));
    }

    public function testCopyExistsInBothLanguages(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $bg = json_decode((string) file_get_contents("$root/content/bg/strings.json"), true);
        $en = json_decode((string) file_get_contents("$root/content/en/strings.json"), true);
        $this->assertIsArray($bg, 'content/bg/strings.json must be valid JSON');
        $this->assertIsArray($en, 'content/en/strings.json must be valid JSON');

        foreach (['shop.added.view_cart', 'shop.added.checkout', 'shop.added.dismiss'] as $key) {
            $this->assertNotEmpty($bg[$key] ?? '', "BG string $key missing");
            $this->assertNotEmpty($en[$key] ?? '', "EN string $key missing");
            $this->assertNotSame($bg[$key], $en[$key], "EN string $key is untranslated");
        }
    }

    public function testProductPageUsesTheNotice(): void
    {
        // magazin/index.php serves both /magazin/<slug>/ and /en/shop/<slug>/.
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/magazin/index.php');
        $this->assertStringContainsString("/templates/product-flash.php", $src);
        $this->assertStringContainsString('id="addToCartBtn"', $src, 'dismiss hands focus back to this button');
    }
}
