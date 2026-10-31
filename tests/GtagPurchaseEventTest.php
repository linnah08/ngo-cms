<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A purchase reaches Google Ads / GA4: the thank-you pages hand it to the header
 * as $gtag_events, and the header sends it with gtag() after the config lines —
 * a Tag Manager style dataLayer object alone is never read by gtag.js.
 *
 * The header runs in a child PHP process with the constants defined first, so
 * the test process's own constants are never touched.
 */
final class GtagPurchaseEventTest extends TestCase
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
    private function renderHeader(array $consts, string $consent, array $events): string
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

    private const PURCHASE = [['purchase', ['transaction_id' => 'OM-20261031-AB12', 'value' => 42.5, 'currency' => 'EUR',
                                            'items' => [['item_name' => 'Чаша', 'price' => 21.25, 'quantity' => 2]]]]];

    public function test_purchase_is_sent_with_gtag_after_the_config_lines(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => 'G-AB12CD34EF', 'GTM_ID' => ''], 'all', self::PURCHASE);

        $event = strpos($html, "gtag('event', \"purchase\", {");
        $this->assertNotFalse($event, $html);
        $this->assertGreaterThan(strpos($html, "gtag('config', 'AW-123456789')"), $event, 'an event before config reaches no ID');
        $this->assertGreaterThan(strpos($html, "gtag('config', 'G-AB12CD34EF')"), $event);
        $this->assertStringContainsString('"transaction_id":"OM-20261031-AB12"', $html);
        $this->assertStringContainsString('"value":42.5', $html);
        $this->assertStringContainsString('"currency":"EUR"', $html);
        $this->assertStringContainsString('"item_name":"Чаша"', $html);
    }

    public function test_nothing_is_sent_without_cookie_consent(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => ''], 'necessary', self::PURCHASE);
        $this->assertStringNotContainsString('purchase', $html);
    }

    public function test_nothing_is_sent_when_no_google_tag_is_set_up(): void
    {
        $html = $this->renderHeader(['GOOGLE_ADS_ID' => '', 'GA4_ID' => ''], 'all', self::PURCHASE);
        $this->assertStringNotContainsString("gtag('event'", $html);
    }

    public function test_product_names_cannot_break_out_of_the_script(): void
    {
        $events = [['purchase', ['items' => [['item_name' => "</script><script>alert('x')</script>"]]]]];
        $html   = $this->renderHeader(['GOOGLE_ADS_ID' => 'AW-123456789', 'GA4_ID' => ''], 'all', $events);
        $this->assertStringNotContainsString("<script>alert(", $html);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $html, 'the name is sent, escaped');
    }

    public function test_thank_you_pages_hand_their_purchase_to_the_header(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        foreach (['checkout/confirmation/index.php', 'donation/confirmation/index.php'] as $page) {
            $src = (string) file_get_contents($root . '/' . $page);
            $this->assertMatchesRegularExpression('/\$gtag_events = !\$owner \? \[\] : \[\[\'purchase\'/', $src, $page);
            $this->assertLessThan(strpos($src, "templates/header.php'"), strpos($src, '$gtag_events ='), "$page sets the events before the header renders");
        }
    }

    public function test_viewing_the_cart_is_not_reported_as_a_conversion(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/cart/index.php');
        $this->assertStringNotContainsString("'conversion'", $src);
    }
}
