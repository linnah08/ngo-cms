<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Site settings that change what the shared header, footer and contact pages
 * show: SITE_PHONE_PUBLIC, SITE_NOINDEX, the donations module and SITE_IBAN,
 * and the checkout's newsletter wording. They came from the lafetki fork, which
 * had patched the shared files for each of them.
 *
 * The constants are defined in a child PHP process before config.php loads, so
 * the test process's own constants are never touched (as in LaunchBannerTest).
 */
final class PublicChromeSettingsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/om-chrome-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    /** Render $files (paths from the site root) with $consts defined first; returns the HTML. */
    private function render(array $consts, array $files, string $uri = '/kontakti/', string $pre = ''): string
    {
        $root    = $_SERVER['DOCUMENT_ROOT'];
        $defines = '';
        foreach ($consts as $name => $value) {
            $defines .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $req = '';
        foreach ($files as $f) $req .= 'require ' . var_export($root . '/' . $f, true) . ";\n";
        $script = $this->tmp . '/run.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = " . var_export($root, true) . ";
            \$_SERVER['REQUEST_URI']   = " . var_export($uri, true) . ";
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['HTTP_HOST']     = 'localhost';
            {$defines}
            require " . var_export($root . '/config.php', true) . ";
            {$pre}
            \$page_title = 'Test';
            {$req}
        ");
        // A constant the site's own config also defines warns "already defined" —
        // expected here, so warnings stay out of the captured page.
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d error_log=/dev/null ' . escapeshellarg($script), $out, $rc);
        $html = implode("\n", $out);
        $this->assertSame(0, $rc, $html);
        return $html;
    }

    private const PHONE = '+359 2 000 0000';

    // ── Phone ────────────────────────────────────────────────────────────────

    public function test_phone_is_shown_by_default(): void
    {
        if (defined('SITE_PHONE_PUBLIC') && !SITE_PHONE_PUBLIC) {
            $this->markTestSkipped('This site hides its phone in site.config.php.');
        }
        $html = $this->render(['SITE_PHONE' => self::PHONE], ['templates/header.php', 'templates/footer.php']);
        $this->assertStringContainsString('tel:' . self::PHONE, $html);
        $this->assertStringContainsString('"telephone":"' . self::PHONE . '"', $html, 'JSON-LD carries it');
    }

    public function test_phone_hidden_everywhere_it_is_optional_when_site_phone_public_is_false(): void
    {
        $consts = ['SITE_PHONE' => self::PHONE, 'SITE_PHONE_PUBLIC' => false];
        foreach ([['kontakti/index.php', '/kontakti/'], ['en/contacts/index.php', '/en/contacts/'],
                  ['kak-da-pomogna/index.php', '/kak-da-pomogna/'], ['en/how-to-help/index.php', '/en/how-to-help/']] as [$page, $uri]) {
            $html = $this->render($consts, [$page], $uri);
            $this->assertStringContainsString('</footer>', $html, "$page rendered in full");
            $this->assertStringNotContainsString(self::PHONE, $html, "$page (with its header and footer) still shows the phone");
            $this->assertStringNotContainsString('"telephone"', $html, "$page JSON-LD still carries the phone");
        }
    }

    public function test_contact_pages_use_the_public_phone_not_site_phone(): void
    {
        // Source guard for the pages above that the render may not reach on every
        // setup: none of them prints SITE_PHONE directly any more.
        foreach (['templates/header.php', 'templates/footer.php', 'kontakti/index.php', 'en/contacts/index.php',
                  'kak-da-pomogna/index.php', 'en/how-to-help/index.php', 'includes/seo.php'] as $f) {
            $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $f);
            $this->assertDoesNotMatchRegularExpression('/\bSITE_PHONE\b(?!_)/', $src, "$f prints SITE_PHONE; use site_phone_public()");
        }
    }

    // ── Noindex ──────────────────────────────────────────────────────────────

    public function test_noindex_only_when_switched_on(): void
    {
        $robots = '<meta name="robots" content="noindex, nofollow">';
        if (!(defined('SITE_NOINDEX') && SITE_NOINDEX)) { // a pre-launch site sets it in site.config.php
            $this->assertStringNotContainsString($robots, $this->render([], ['templates/header.php'], '/'));
        }
        $this->assertStringContainsString($robots, $this->render(['SITE_NOINDEX' => true], ['templates/header.php'], '/'));
        $this->assertStringContainsString($robots, $this->render(['SITE_NOINDEX' => true], ['templates/header.php'], '/en/'));
    }

    // ── IBAN ─────────────────────────────────────────────────────────────────

    public function test_iban_shown_when_donations_on_and_set(): void
    {
        $html = $this->render(['SITE_IBAN' => 'BG80BNBG96611020345678', 'FEATURE_DONATIONS' => true],
                              ['templates/header.php', 'templates/footer.php']);
        $this->assertSame(2, substr_count($html, 'BG80BNBG96611020345678'), 'top bar and footer');
    }

    public function test_iban_hidden_when_donations_are_off(): void
    {
        $html = $this->render(['SITE_IBAN' => 'BG80BNBG96611020345678', 'FEATURE_DONATIONS' => false],
                              ['templates/header.php', 'templates/footer.php']);
        $this->assertStringNotContainsString('BG80BNBG96611020345678', $html);
        $this->assertStringNotContainsString('footer-iban', $html);
    }

    public function test_iban_block_hidden_when_empty(): void
    {
        $html = $this->render(['SITE_IBAN' => '  ', 'FEATURE_DONATIONS' => true], ['templates/header.php', 'templates/footer.php']);
        $this->assertStringNotContainsString('footer-iban', $html, 'no blank labelled box');
        $this->assertStringNotContainsString(t('header.donate_iban') . ': <strong>', $html);
    }

    // ── Theme colours in the footer ──────────────────────────────────────────

    public function test_footer_bands_take_theme_colours_with_the_original_fallbacks(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php');
        $this->assertStringContainsString('background:var(--newsletter-bg,#037F9B)', $src);
        $this->assertStringContainsString('color:var(--newsletter-fg,#fff)', $src);
        $this->assertStringContainsString('background:var(--banner-accent,#0387A5);color:var(--banner-accent-fg,#fff)', $src);
    }

    // ── Checkout newsletter opt-in ───────────────────────────────────────────

    public function test_newsletter_optin_is_a_string_with_the_site_name(): void
    {
        foreach (['bg', 'en'] as $l) {
            $site = json_decode((string) @file_get_contents($_SERVER['DOCUMENT_ROOT'] . "/content/$l/strings.site.json"), true);
            if (isset($site['checkout.newsletter_optin'])) {
                $this->markTestSkipped('This site words the opt-in itself in strings.site.json.');
            }
        }
        $this->assertSame('Send me news from Example.',
            t_or('checkout.newsletter_optin', 'Искам да получавам новини от {name}.', 'Send me news from {name}.', 'en', ['name' => 'Example']));
        $this->assertSame('Искам да получавам новини от Пример.',
            t_or('checkout.newsletter_optin', 'Искам да получавам новини от {name}.', 'Send me news from {name}.', 'bg', ['name' => 'Пример']));
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/checkout/index.php');
        $this->assertStringContainsString("t_or('checkout.newsletter_optin'", $src);
        $this->assertStringContainsString('var(--required-ink,#a4243d)', $src, 'the required mark takes the theme colour');
    }

    public function test_site_strings_file_rewords_a_string_without_touching_strings_json(): void
    {
        foreach (['bg', 'en'] as $l) {
            mkdir($this->tmp . '/' . $l);
            file_put_contents($this->tmp . "/$l/strings.site.json", json_encode([
                'checkout.newsletter_optin' => $l === 'en' ? 'News about {name}, please.' : 'Новини за {name}, моля.',
                'not.a.string'              => ['x'],
                'empty.value'               => '',
            ], JSON_UNESCAPED_UNICODE));
        }
        $dir  = var_export($this->tmp, true);
        $html = $this->render([], [], '/', "\$GLOBALS['_om_site_strings_dir'] = {$dir};
            echo t_or('checkout.newsletter_optin', 'BG {name}', 'EN {name}', 'en', ['name' => 'Org']), '|';
            echo t_or('checkout.newsletter_optin', 'BG {name}', 'EN {name}', 'bg', ['name' => 'Орг']), '|';
            echo t_or('empty.value', 'bg-default', 'en-default', 'en'), '|';
            echo t_or('checkout.consents', 'Съгласия', 'Consents', 'en');");
        // Glob cleanup in tearDown is one level deep; remove the language dirs here.
        foreach (['bg', 'en'] as $l) { @unlink($this->tmp . "/$l/strings.site.json"); @rmdir($this->tmp . "/$l"); }
        $this->assertSame('News about Org, please.|Новини за Орг, моля.|en-default|Consents', trim($html));
    }

    public function test_a_missing_or_broken_site_strings_file_changes_nothing(): void
    {
        mkdir($this->tmp . '/en');
        file_put_contents($this->tmp . '/en/strings.site.json', '{not json');
        $dir  = var_export($this->tmp, true);
        $html = $this->render([], [], '/', "\$GLOBALS['_om_site_strings_dir'] = {$dir};
            echo t_or('checkout.consents', 'Съгласия', 'Consents', 'en'), '|', t_or('checkout.consents', 'Съгласия', 'Consents', 'bg');");
        @unlink($this->tmp . '/en/strings.site.json');
        @rmdir($this->tmp . '/en');
        $this->assertSame('Consents|Съгласия', trim($html));
    }
}
