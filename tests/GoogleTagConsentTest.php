<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Google tags under Consent Mode v2 (templates/header.php, templates/footer.php):
 * they always load, everything denied by default, granted only after
 * „Приемам всички“. A page's own events ($gtag_events, e.g. add_to_cart) go after
 * the config lines. Purchases are not sent from the browser any more — the server
 * sends them when the payment is confirmed (includes/ga4.php).
 *
 * The header runs in a child PHP process with the constants defined first, so
 * the test process's own constants are never touched.
 */
final class GoogleTagConsentTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/om-gtag-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    /** Render templates/header.php with $consts defined, the given consent cookie and page events. */
    private function renderHeader(array $consts, string $consent, array $events, array $session = []): string
    {
        $root    = $_SERVER['DOCUMENT_ROOT'];
        $defines = '';
        foreach ($consts as $name => $value) {
            $defines .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $script = $this->tmp . '/run.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = " . var_export($root, true) . ";
            \$_SERVER['REQUEST_URI']   = '/';
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['HTTP_HOST']     = 'localhost';
            \$_COOKIE['om_cookie_consent'] = " . var_export($consent, true) . ";
            session_save_path(" . var_export($this->tmp, true) . "); @session_start();
            \$_SESSION = " . var_export($session, true) . ";
            {$defines}
            require " . var_export($root . '/config.php', true) . ";
            \$page_title  = 'Test';
            \$gtag_events = " . var_export($events, true) . ";
            require " . var_export($root . '/templates/header.php', true) . ";
        ");
        // A constant the site's own config also defines warns "already defined" —
        // expected here, so warnings stay out of the captured output.
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d error_log=/dev/null ' . escapeshellarg($script), $out, $rc);
        $text = implode("\n", $out);
        $this->assertSame(0, $rc, $text);
        return $text;
    }

    private const ADD_TO_CART = [['add_to_cart', ['currency' => 'EUR', 'value' => 42.5,
                                                  'items' => [['item_id' => '7', 'item_name' => 'Чаша', 'price' => 21.25, 'quantity' => 2]]]]];

    private const DENIED  = "gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied'})";
    private const GRANTED = "gtag('consent', 'update', {ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted'})";

    public function test_page_events_are_sent_with_gtag_after_the_config_lines(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => 'G-AB12CD34EF', 'GTM_ID' => ''], 'all', self::ADD_TO_CART);

        $event = strpos($html, "gtag('event', \"add_to_cart\", {");
        $this->assertNotFalse($event, $html);
        $this->assertGreaterThan(strpos($html, "gtag('config', 'AW-123456789')"), $event, 'an event before config reaches no ID');
        $this->assertGreaterThan(strpos($html, "gtag('config', 'G-AB12CD34EF')"), $event);
        $this->assertStringContainsString('"value":42.5', $html);
        $this->assertStringContainsString('"item_name":"Чаша"', $html);
    }

    public function test_everything_is_denied_before_any_google_tag_loads(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => 'G-AB12CD34EF', 'GTM_ID' => 'GTM-TEST123'], 'all', []);

        $denied = strpos($html, self::DENIED);
        $this->assertNotFalse($denied, $html);
        $this->assertLessThan(strpos($html, self::GRANTED), $denied, 'the default comes first, the visitor\'s choice updates it');
        $this->assertLessThan(strpos($html, 'gtm.js?id='), strpos($html, self::GRANTED), 'Tag Manager reads consent only if it is set first');
        $this->assertLessThan(strpos($html, "gtag('config', 'AW-123456789')"), strpos($html, self::GRANTED));
        $this->assertSame(1, substr_count($html, 'function gtag('), 'gtag is defined once');
    }

    public function test_declined_or_undecided_loads_the_tags_with_consent_denied(): void
    {
        foreach (['essential', ''] as $choice) {
            $html = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => 'G-AB12CD34EF', 'GTM_ID' => ''], $choice, self::ADD_TO_CART);
            $this->assertStringContainsString(self::DENIED, $html, "consent '$choice'");
            $this->assertStringNotContainsString(self::GRANTED, $html, "consent '$choice'");
            $this->assertStringContainsString('gtag/js?id=AW-123456789', $html, 'gtag still loads, for cookieless signals');
            $this->assertStringContainsString("gtag('event', \"add_to_cart\"", $html);
        }
    }

    public function test_the_banner_only_updates_consent_and_can_be_reopened(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php');
        $fn  = substr($src, (int) strpos($src, 'function cookieConsent('));
        $this->assertStringContainsString("gtag('consent', 'update', {ad_storage: all, ad_user_data: all, ad_personalization: all, analytics_storage: all})", $fn);
        $this->assertStringNotContainsString('gtag/js?id=', $fn, 'the tags are already on the page — nothing is loaded on accept');
        $this->assertStringContainsString('onclick="cookieSettings()"', $src, 'the footer reopens the banner to change the choice');
    }

    public function test_a_successful_add_to_cart_is_sent_once_on_the_next_page(): void
    {
        $atc  = self::ADD_TO_CART[0][1];
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => '', 'GA4_ID' => 'G-AB12CD34EF', 'GTM_ID' => ''], 'essential', [], ['ga_add_to_cart' => $atc]);
        $this->assertSame(1, substr_count($html, "gtag('event', \"add_to_cart\""), $html);

        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/cart/add.php');
        $this->assertMatchesRegularExpression('/if \(\$actually_added && \(\$added_qty \?\? 0\) > 0\) \{\s+\$_price[^}]+\$_SESSION\[\'ga_add_to_cart\'\]/', $src,
                                              'queued only when units were really added');
    }

    public function test_nothing_is_sent_when_no_google_tag_is_set_up(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => '', 'GA4_ID' => ''], 'all', self::ADD_TO_CART);
        $this->assertStringNotContainsString("gtag(", $html);
    }

    public function test_product_names_cannot_break_out_of_the_script(): void
    {
        $events = [['purchase', ['items' => [['item_name' => "</script><script>alert('x')</script>"]]]]];
        $html   = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => ''], 'all', $events);
        $this->assertStringNotContainsString("<script>alert(", $html);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $html, 'the name is sent, escaped');
    }

    public function test_thank_you_pages_no_longer_report_the_purchase_themselves(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        foreach (['checkout/confirmation/index.php', 'donation/confirmation/index.php'] as $page) {
            $src = (string) file_get_contents($root . '/' . $page);
            $this->assertStringNotContainsString("'purchase'", $src, "$page — the server sends it once paid; a reload would count it twice");
            $this->assertStringNotContainsString('dataLayer.push', $src, $page);
        }
    }

    public function test_viewing_the_cart_is_not_reported_as_a_conversion(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/cart/index.php');
        $this->assertStringNotContainsString("'conversion'", $src);
    }
}
