<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Wording a site rewords in strings.site.json: the search-engine descriptions of
 * За нас and Магазин, the newsletter's unsubscribe line and the
 * "certificate needs signing" email to the admin. Each case runs in a fresh PHP
 * process, because lang_strings() caches per language.
 */
final class SiteWordingKeysTest extends TestCase
{
    private const KEYS = ['about.page_description', 'about.page_description_team', 'shop.page_description',
        'newsletter.email.unsubscribe', 'email.cert_sign.greeting', 'email.cert_sign.intro', 'email.cert_sign.button', 'email.cert_sign.hint'];

    private function run_with(array $site_bg, string $code): string
    {
        $root = dirname(__DIR__);
        $dir  = sys_get_temp_dir() . '/om-wording-' . bin2hex(random_bytes(4));
        mkdir($dir . '/bg', 0777, true);
        if ($site_bg) file_put_contents($dir . '/bg/strings.site.json', json_encode($site_bg, JSON_UNESCAPED_UNICODE));
        $file = tempnam(sys_get_temp_dir(), 'om_wording_');
        file_put_contents($file, "<?php\n\$GLOBALS['_om_site_strings_dir'] = " . var_export($dir, true) . ";\n"
            . 'require ' . var_export($root . '/tests/bootstrap.php', true) . ";\n" . $code);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        } finally {
            @unlink($file);
            @unlink($dir . '/bg/strings.site.json'); @rmdir($dir . '/bg'); @rmdir($dir);
        }
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_every_key_ships_in_both_languages(): void
    {
        foreach (['bg', 'en'] as $lang) {
            $s = json_decode((string) file_get_contents(dirname(__DIR__) . "/content/{$lang}/strings.json"), true);
            foreach (self::KEYS as $k) $this->assertNotSame('', (string) ($s[$k] ?? ''), "$lang: $k");
        }
    }

    public function test_the_unsubscribe_line_can_be_reworded(): void
    {
        $code = 'require_once ROOT_PATH . "/includes/newsletter.php";'
              . 'echo render_newsletter_email("", "<p>x</p>", "https://example.org/newsletter/unsubscribe.php?token=t", "bg");';
        $this->assertStringContainsString('Отпишете се от бюлетина</a>', $this->run_with([], $code));
        $out = $this->run_with(['newsletter.email.unsubscribe' => 'Отпиши се от бюлетина'], $code);
        $this->assertStringContainsString('Отпиши се от бюлетина</a>', $out);
        $this->assertStringNotContainsString('Отпишете', $out);
    }

    public function test_the_certificate_email_can_address_admins_informally(): void
    {
        $code = 'require_once ROOT_PATH . "/includes/mailer.php";'
              . 'echo render_email("cert-needs-signature", ["order" => ["customer_name" => "Иван", "items" => "[]", "total_eur" => 10],'
              . ' "document" => ["formatted_number" => "0000000001"], "order_id" => 5]);';
        $default = $this->run_with([], $code);
        $this->assertStringContainsString('Здравейте,', $default);
        $this->assertStringContainsString('Подпишете сертификата →', $default);
        $out = $this->run_with([
            'email.cert_sign.greeting' => 'Здравей,',
            'email.cert_sign.intro'    => 'Генериран е нов сертификат за дарение и очаква твоя подпис.',
            'email.cert_sign.button'   => 'Подпиши сертификата',
            'email.cert_sign.hint'     => 'Намери бутона „Подпиши“ до сертификата.',
        ], $code);
        foreach (['Здравей,', 'очаква твоя подпис.', 'Подпиши сертификата →', 'Намери бутона'] as $t) $this->assertStringContainsString($t, $out);
        $this->assertStringNotContainsString('Здравейте', $out);
    }

    public function test_the_page_descriptions_fill_in_the_organisation(): void
    {
        $out = $this->run_with(['shop.page_description' => 'Подкрепи {name} с покупка.'],
            'echo t_or("shop.page_description", "Подкрепете {name} с покупка от нашия магазин.", "x", "bg", ["name" => "Пример"]), "|",'
            . ' t_or("about.page_description", "Научете повече за {name} и нашата история.", "x", "bg", ["name" => "Пример"]);');
        $this->assertSame('Подкрепи Пример с покупка.|Научете повече за Пример и нашата история.', trim($out));
    }
}
