<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The optional „Дари сега“ button in the shop page's top banner
 * (Admin → Организация → Страница на магазина, SHOP_DONATE_BUTTON).
 * Constants cannot be undone, so each case runs in its own PHP process.
 */
final class ShopDonateButtonTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Runs $code after the test bootstrap with the given constants defined first. */
    private function child(string $code, array $defines = []): string
    {
        $saved = self::root() . '/content/organisation.json';
        if (is_file($saved)) {
            $json = (array) json_decode((string) file_get_contents($saved), true);
            foreach (['shop_donate_button', 'feature_donations'] as $k) {
                if (array_key_exists($k, $json)) $this->markTestSkipped('This site saves the setting in content/organisation.json.');
            }
        }
        $defs = '';
        foreach ($defines as $c => $v) $defs .= 'define(' . var_export($c, true) . ', ' . var_export($v, true) . ");\n";
        $boot = var_export(self::root() . '/tests/bootstrap.php', true);
        $file = tempnam(sys_get_temp_dir(), 'om_shop_donate_');
        file_put_contents($file, "<?php\n{$defs}require {$boot};\nrequire_once ROOT_PATH . '/includes/donation.php';\n" . $code);
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_off_by_default(): void
    {
        $this->assertSame('|off', $this->child("echo shop_donate_button('bg'), '|', shop_donate_button_on() ? 'on' : 'off';"));
    }

    public function test_switched_on_it_links_to_the_donation_page_in_the_visitors_language(): void
    {
        $out = $this->child("echo shop_donate_button('bg'), \"\\n\", shop_donate_button('en', 'btn btn--white', 'min-width:10rem;');", ['SHOP_DONATE_BUTTON' => '1', 'FEATURE_DONATIONS' => true]);
        [$bg, $en] = explode("\n", $out);
        $this->assertStringStartsWith('<a href="/donation/" class="btn btn--primary">', $bg);
        $this->assertStringContainsString('Дари сега</span></a>', $bg);
        $this->assertStringStartsWith('<a href="/en/donation/" class="btn btn--white" style="min-width:10rem;">', $en);
        $this->assertStringContainsString('Donate now</span></a>', $en);
        // Editable on the page in Edit mode, under its wording key.
        $this->assertStringContainsString('data-cms-section="ui" data-cms-field="shop.hero.donate_button"', $bg);
    }

    public function test_never_shown_while_donations_are_off(): void
    {
        $this->assertSame('|off', $this->child("echo shop_donate_button('bg'), '|', shop_donate_button_on() ? 'on' : 'off';",
            ['SHOP_DONATE_BUTTON' => '1', 'FEATURE_DONATIONS' => false]));
    }

    public function test_the_organisation_form_saves_an_explicit_on_or_off(): void
    {
        $out = $this->child("require_once ROOT_PATH . '/includes/organisation.php';"
            . "echo org_fields()['shop_donate_button'], '|',"
            . " org_validate(['shop_donate_button' => '1'], ['classic'])['values']['shop_donate_button'] ?? 'none', '|',"
            . " org_validate([], ['classic'])['values']['shop_donate_button'] ?? 'none', '|',"
            . " org_validate(['shop_donate_button' => ['x']], ['classic'])['values']['shop_donate_button'] ?? 'none';",
            ['FEATURE_DONATIONS' => true]);
        $this->assertSame('SHOP_DONATE_BUTTON|1|0|0', $out);
    }

    public function test_hidden_in_the_form_without_the_shop_or_donations(): void
    {
        $this->assertSame('hidden', $this->child("require_once ROOT_PATH . '/includes/organisation.php';"
            . "echo in_array('shop_donate_button', org_hidden_fields(), true) ? 'hidden' : 'shown';", ['FEATURE_DONATIONS' => false]));
        $this->assertSame('shown', $this->child("require_once ROOT_PATH . '/includes/organisation.php';"
            . "echo in_array('shop_donate_button', org_hidden_fields(), true) ? 'hidden' : 'shown';", ['FEATURE_DONATIONS' => true]));
    }

    public function test_the_shop_page_and_a_sites_own_header_both_get_it(): void
    {
        $src = (string) file_get_contents(self::root() . '/magazin/index.php');
        $this->assertStringContainsString("'shop_donate_on' => shop_donate_button_on()", $src, 'a site header learns whether it is on');
        $this->assertStringContainsString('shop_donate_button($lang)', $src, 'the plain header prints it');
        $this->assertStringContainsString('name="shop_donate_button"', (string) file_get_contents(self::root() . '/admin/organisation.php'));
    }
}
