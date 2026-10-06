<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The "Donate now" box at the bottom of subscriber newsletters (off unless the
 * site switches it on) and the newsletter sign-up card on the order and
 * donation confirmation pages.
 */
final class NewsletterDonateCtaTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
    }

    // ── Donate box in the newsletter ──────────────────────────────────────────

    public function test_off_by_default(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/site.config.example.php');
        $this->assertMatchesRegularExpression("/define\('NEWSLETTER_DONATE_CTA',\s*false\)/", $src,
            'a new site must start with the donate box off');
        if (!defined('NEWSLETTER_DONATE_CTA')) {
            $this->assertFalse(newsletter_donate_cta_enabled(), 'no setting at all means off, not on');
        }
    }

    public function test_switched_off_the_email_has_no_donate_box(): void
    {
        $this->assertSame('', newsletter_cta_block('bg', false));
        $html = render_newsletter_email('Здравейте,', '<p>Body</p>', 'https://example.org/unsub', 'bg');
        if (!newsletter_donate_cta_enabled()) {
            $this->assertStringNotContainsString('nl-donate', $html);
        }
    }

    public function test_box_links_to_the_donation_page_in_each_language(): void
    {
        $bg = newsletter_cta_block('bg', true);
        $en = newsletter_cta_block('en', true);
        $this->assertStringContainsString(SITE_URL . '/donation/', $bg);
        $this->assertStringContainsString(SITE_URL . '/en/donation/', $en);
        $this->assertStringNotContainsString('/en/donation/', $bg);
        $this->assertStringContainsString(h(t_or('newsletter.donate.button', 'Дарете сега', 'Donate now', 'bg')), $bg);
        $this->assertStringContainsString(h(t_or('newsletter.donate.button', 'Дарете сега', 'Donate now', 'en')), $en);
    }

    public function test_box_is_phone_safe_inline_html(): void
    {
        $html = newsletter_cta_block('en', true);
        $this->assertStringNotContainsString('<style', $html, 'email clients drop stylesheets');
        $this->assertDoesNotMatchRegularExpression('/\bwidth:\s*\d{3,}px/', $html, 'no fixed width that overflows a 375px phone');
        $this->assertDoesNotMatchRegularExpression('/\bwidth="\d+"/', $html);
    }

    public function test_default_wording_is_generic_and_bilingual(): void
    {
        foreach (['heading', 'text'] as $part) {
            $bg = newsletter_donate_cta_text($part, 'bg');
            $en = newsletter_donate_cta_text($part, 'en');
            $this->assertNotSame('', $bg);
            $this->assertNotSame($bg, $en);
        }
    }

    #[RunInSeparateProcess]
    public function test_switched_on_the_box_sits_between_body_and_unsubscribe(): void
    {
        define('NEWSLETTER_DONATE_CTA', '1');
        define('NEWSLETTER_DONATE_HEADING_EN', 'Help us keep going');
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/newsletter.php';
        if (!feature_enabled('donations')) {
            $this->assertFalse(newsletter_donate_cta_enabled(), 'no donate box while donations are off');
            return;
        }
        $this->assertTrue(newsletter_donate_cta_enabled());

        $html  = render_newsletter_email('Dear friend,', '<p>BODY</p>', 'https://example.org/newsletter/unsubscribe.php?token=x', 'en');
        $body  = strpos($html, 'BODY');
        $box   = strpos($html, 'nl-donate');
        $unsub = strpos($html, 'Unsubscribe from this newsletter');
        $this->assertNotFalse($box);
        $this->assertLessThan($box, $body);
        $this->assertLessThan($unsub, $box);
        $this->assertStringContainsString('Help us keep going', $html, "the site's own heading wins");

        // Its link is click-tracked like the rest of the body.
        $tracked = newsletter_inject_tracking($html, str_repeat('a', 32));
        $this->assertStringContainsString(rawurlencode(SITE_URL . '/en/donation/'), $tracked);
    }

    // ── Admin switch (Организация) ────────────────────────────────────────────

    private function orgInput(array $over = []): array
    {
        return array_merge([
            'site_name_bg' => 'Фондация Тест', 'site_name_en' => 'Test Foundation',
            'site_email'   => 'info@example.org', 'brand_theme' => 'classic',
            'brand_primary' => '#2D3A8C', 'brand_accent' => '#04ADBF',
        ], $over);
    }

    public function test_admin_switch_saves_an_explicit_on_or_off(): void
    {
        $on  = org_validate($this->orgInput(['newsletter_donate_cta' => '1']), array_keys(brand_themes()));
        $off = org_validate($this->orgInput(), array_keys(brand_themes()));
        $bad = org_validate($this->orgInput(['newsletter_donate_cta' => 'yes please']), array_keys(brand_themes()));
        $this->assertSame('1', $on['values']['newsletter_donate_cta']);
        $this->assertSame('0', $off['values']['newsletter_donate_cta'], 'an unticked box is saved as off, so it can be switched back off');
        $this->assertSame('0', $bad['values']['newsletter_donate_cta']);
        $this->assertSame('NEWSLETTER_DONATE_CTA', org_fields()['newsletter_donate_cta']);
    }

    public function test_admin_wording_has_length_limits(): void
    {
        $r = org_validate($this->orgInput([
            'newsletter_donate_heading_en' => str_repeat('x', 121),
            'newsletter_donate_text_bg'    => str_repeat('я', 301),
        ]), array_keys(brand_themes()));
        $this->assertArrayHasKey('newsletter_donate_heading_en', $r['errors']);
        $this->assertArrayHasKey('newsletter_donate_text_bg', $r['errors']);
        $this->assertArrayNotHasKey('newsletter_donate_heading_bg', $r['errors']);
    }

    // ── Sign-up card on the confirmation pages ────────────────────────────────

    private function renderCard(array $order, string $flow): string
    {
        ob_start();
        (static function (array $order, string $nl_flow): void {
            require $_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-confirm-card.php';
        })($order, $flow);
        return (string) ob_get_clean();
    }

    public function test_card_only_for_the_session_that_placed_the_order(): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $saved = $_SESSION[ORDER_SESSION_KEY] ?? null;
        $cookie = $_COOKIE['om_nl_sub'] ?? null;
        unset($_COOKIE['om_nl_sub']);
        try {
            unset($_SESSION[ORDER_SESSION_KEY], $_SESSION['flash']);
            $order = ['order_number' => 'OM-20260101-ABCD'];
            $this->assertSame('', trim($this->renderCard($order, 'order')), 'someone else\'s order: no card');

            newsletter_remember_order('OM-20260101-ABCD');
            $html = $this->renderCard($order, 'donation');
            $this->assertStringContainsString('action="/newsletter/subscribe-order.php"', $html);
            $this->assertStringContainsString('name="flow" value="donation"', $html);
            $this->assertStringContainsString('name="wants_news"', $html);
            $this->assertStringContainsString('data-cms-field="confirm_heading"', $html);
            $this->assertStringNotContainsString('type="email"', $html, 'the email is taken from the order');

            $_COOKIE['om_nl_sub'] = '1';
            $this->assertSame('', trim($this->renderCard($order, 'order')), 'already subscribed: no card');

            $_SESSION['flash'] = [['type' => 'success', 'message' => 'Subscribed OK']];
            $html = $this->renderCard($order, 'order');
            $this->assertStringContainsString('Subscribed OK', $html, 'the result of the sign-up is shown in place');
            $this->assertStringContainsString('role="status"', $html);
            $this->assertStringNotContainsString('<form', $html);
        } finally {
            $_SESSION[ORDER_SESSION_KEY] = $saved;
            if ($cookie === null) unset($_COOKIE['om_nl_sub']); else $_COOKIE['om_nl_sub'] = $cookie;
        }
    }

    public function test_both_confirmation_pages_include_the_card_and_its_fields_are_saveable(): void
    {
        foreach (['checkout/confirmation/index.php' => 'order', 'donation/confirmation/index.php' => 'donation'] as $rel => $flow) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
            $this->assertStringContainsString("\$nl_flow = '$flow'; require \$_SERVER['DOCUMENT_ROOT'] . '/templates/newsletter-confirm-card.php'", $src, $rel);
        }
        $save = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/inline-save.php');
        $this->assertStringContainsString("'confirm_heading'", $save);
        $this->assertStringContainsString("'confirm_button'", $save);
    }
}
