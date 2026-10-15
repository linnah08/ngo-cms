<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Donation recipients (includes/site_extensions.php): one — the organisation
 * itself — unless a site lists others in its own extensions file.
 */
final class DonationRecipientsTest extends TestCase
{
    private static array $tmp = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
        foreach (self::$tmp as $f) @unlink($f);
        self::$tmp = [];
    }

    private function site(string $php): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        file_put_contents($f, $php);
        self::$tmp[] = $f;
        $GLOBALS['_om_site_extensions_file'] = $f;
    }

    public function test_without_a_site_file_there_is_one_recipient_and_no_choice(): void
    {
        $GLOBALS['_om_site_extensions_file'] = sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4)) . '.php';
        $this->assertSame([DONATION_RECIPIENT_DEFAULT], array_keys(donation_recipients()));
        $this->assertFalse(donation_recipients_choosable());
        $this->assertSame(DONATION_RECIPIENT_DEFAULT, donation_recipient_clean('anything'));
        if (defined('DONATION_PURPOSE_BG')) $this->assertSame(DONATION_PURPOSE_BG, donation_recipient_purpose(null, 'bg'));
    }

    public function test_a_site_can_list_recipients_and_donors_choose(): void
    {
        $this->site('<?php return ["recipients" => [
            "foundation" => ["label_bg" => "Фондацията", "purpose_bg" => "За фондацията", "purpose_en" => "For the foundation"],
            "partner"    => ["label_bg" => "Партньор", "label_en" => "Partner", "purpose_bg" => "За партньора", "default" => true,
                             "hint_bg" => "Център за деца", "hint_url" => "/novini/1/"],
        ]];');
        $this->assertTrue(donation_recipients_choosable());
        $this->assertSame('partner', donation_recipient_default());
        $this->assertSame('foundation', donation_recipient_clean('foundation'));
        $this->assertSame('partner', donation_recipient_clean('<script>'), 'unknown → the default');
        $this->assertSame('For the foundation', donation_recipient_purpose('foundation', 'en'));
        $this->assertSame('За партньора', donation_recipient_purpose('partner', 'en'), 'no English purpose → the Bulgarian one');
        $this->assertSame('Partner', donation_recipient_label('partner', 'en'));
        $this->assertSame('partner', donation_line_recipient(['type' => 'donation']), 'an old line without a recipient gets the default');
    }

    public function test_a_broken_site_file_never_breaks_donations(): void
    {
        foreach (['<?php return "nope";', '<?php throw new Exception("x");', '<?php return ["recipients" => "nope"];',
                  '<?php return ["recipients" => ["Bad Key" => ["label_bg" => "x", "purpose_bg" => "y"], "ok" => ["label_bg" => ""]]];'] as $php) {
            $this->site($php);
            $this->assertSame([DONATION_RECIPIENT_DEFAULT], array_keys(donation_recipients()), $php);
        }
    }

    public function test_exactly_one_default(): void
    {
        $this->site('<?php return ["recipients" => [
            "aa" => ["label_bg" => "А", "purpose_bg" => "П", "default" => true],
            "bb" => ["label_bg" => "Б", "purpose_bg" => "П", "default" => true],
        ]];');
        $this->assertSame(['aa' => true, 'bb' => false], array_map(fn($r) => $r['default'], donation_recipients()));
    }

    public function test_every_form_that_takes_a_donation_stores_the_recipient(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['donation/checkout.php', 'checkout/index.php', 'admin/manual-cert.php'] as $f) {
            $src = (string) file_get_contents("$root/$f");
            $this->assertStringContainsString('donation_recipient_clean(', $src, $f);
            $this->assertStringContainsString("'recipient'", $src, $f);
            $this->assertStringContainsString('includes/site_extensions.php', $src, "$f loads the helpers itself (sites that keep an older config.php)");
        }
        $this->assertStringContainsString('templates/donation-recipients.php', (string) file_get_contents("$root/templates/donation-form.php"));
    }

    public function test_learn_more_links_per_language_fall_back_to_one_link(): void
    {
        $this->site('<?php return ["recipients" => [
            "foundation" => ["label_bg" => "Фондацията", "purpose_bg" => "П", "hint_bg" => "Х", "hint_url" => "/za-nas/"],
            "both"       => ["label_bg" => "Двата", "purpose_bg" => "П", "hint_bg" => "Х",
                             "hint_url_bg" => "/novini/25/", "hint_url_en" => "/en/news/25/"],
            "bg_only"    => ["label_bg" => "Само БГ", "purpose_bg" => "П", "hint_bg" => "Х",
                             "hint_url" => "https://example.org/", "hint_url_bg" => "/novini/7/"],
            "none"       => ["label_bg" => "Без", "purpose_bg" => "П"],
        ]];');
        $this->assertSame(['/za-nas/', '/za-nas/'], [donation_recipient_hint_url('foundation', 'bg'), donation_recipient_hint_url('foundation', 'en')], 'hint_url alone serves both languages');
        $this->assertSame(['/novini/25/', '/en/news/25/'], [donation_recipient_hint_url('both', 'bg'), donation_recipient_hint_url('both', 'en')]);
        $this->assertSame(['/novini/7/', 'https://example.org/'], [donation_recipient_hint_url('bg_only', 'bg'), donation_recipient_hint_url('bg_only', 'en')], 'a missing language falls back to hint_url');
        $this->assertSame(['', ''], [donation_recipient_hint_url('none', 'bg'), donation_recipient_hint_url('none', 'en')]);
    }

    public function test_only_site_paths_and_web_addresses_are_kept_as_links(): void
    {
        foreach (['javascript:alert(1)', '//evil.example/', '/\\evil.example/', 'novini/1/', '/a b/', '/x"onmouseover=1', 'ftp://x/'] as $bad) {
            $this->assertSame('', donation_recipient_url_clean($bad), $bad);
        }
        foreach (['/novini/1/', '/en/news/1/#more', 'https://example.org/page?x=1', 'http://example.org'] as $ok) {
            $this->assertSame($ok, donation_recipient_url_clean($ok), $ok);
        }
        $this->assertSame('', donation_recipient_url_clean(['/x/']));
    }

    public function test_the_choice_links_to_the_page_in_the_visitors_language(): void
    {
        $this->site('<?php return ["recipients" => [
            "foundation" => ["label_bg" => "Фондацията", "purpose_bg" => "П"],
            "partner"    => ["label_bg" => "Партньор", "label_en" => "Partner", "purpose_bg" => "П",
                             "hint_bg" => "Център", "hint_en" => "Centre",
                             "hint_url_bg" => "/novini/25/", "hint_url_en" => "/en/news/25/"],
        ]];');
        $render = function (string $lang): string {
            ob_start();
            require dirname(__DIR__, 2) . '/templates/donation-recipients.php';
            return (string) ob_get_clean();
        };
        $bg = $render('bg');
        $en = $render('en');
        $this->assertStringContainsString('href="/novini/25/"', $bg);
        $this->assertStringNotContainsString('/en/news/25/', $bg);
        $this->assertStringContainsString('href="/en/news/25/"', $en);
        $this->assertStringNotContainsString('/novini/25/', $en);
    }
}
