<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin → Модули: the on/off switches for the optional modules.
 *
 * Precedence of feature_enabled(): the saved switch (content/organisation.json)
 * beats FEATURE_<NAME> in site.config.php; with nothing saved the constant
 * applies; with no constant either the module is on. Anything that define()s
 * constants runs in a child PHP process so this process's own are untouched.
 */
final class ModuleSwitchesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
        $this->tmp = sys_get_temp_dir() . '/om-modules-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    /**
     * Boot like config.php does — saved overrides first, then site.config.php —
     * and print feature_enabled() for both modules as "donations|campaign".
     *
     * @param ?array  $saved     organisation.json contents; null = no file
     * @param string  $siteDefs  define() lines for site.config.php
     */
    private function boot(?array $saved, string $siteDefs, ?string $rawJson = null): string
    {
        if ($rawJson !== null) {
            file_put_contents($this->tmp . '/organisation.json', $rawJson);
        } elseif ($saved !== null) {
            file_put_contents($this->tmp . '/organisation.json', json_encode($saved));
        }
        file_put_contents($this->tmp . '/site.config.php', "<?php\n" . $siteDefs . "\n");

        $inc = var_export($_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php', true);
        $dir = var_export($this->tmp, true);
        $script = $this->tmp . '/run.php';
        file_put_contents($script, "<?php
            require {$inc};
            \$pre = org_define_overrides(org_load_overrides({$dir} . '/organisation.json'));
            org_require_config({$dir} . '/site.config.php', \$pre);
            echo var_export(feature_enabled('donations'), true), '|', var_export(feature_enabled('campaign'), true);
        ");
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    // ── Precedence ───────────────────────────────────────────────────────────────

    public function test_saved_off_beats_constant_on(): void
    {
        $this->assertSame('false|false', $this->boot(
            ['feature_donations' => '0', 'feature_campaign' => '0'],
            "define('FEATURE_DONATIONS', true);\ndefine('FEATURE_CAMPAIGN', true);"
        ), 'no "already defined" warning may leak either');
    }

    public function test_saved_on_beats_constant_off(): void
    {
        $this->assertSame('true|true', $this->boot(
            ['feature_donations' => '1', 'feature_campaign' => '1'],
            "define('FEATURE_DONATIONS', false);\ndefine('FEATURE_CAMPAIGN', false);"
        ));
    }

    public function test_unsaved_module_falls_back_to_the_constant(): void
    {
        // Other organisation fields saved, the modules never touched.
        $this->assertSame('false|true', $this->boot(
            ['site_phone' => '+359 88 000 0000'],
            "define('FEATURE_DONATIONS', false);\ndefine('FEATURE_CAMPAIGN', true);"
        ));
    }

    public function test_one_saved_module_does_not_affect_the_other(): void
    {
        $this->assertSame('true|false', $this->boot(
            ['feature_donations' => '1'],
            "define('FEATURE_DONATIONS', false);\ndefine('FEATURE_CAMPAIGN', false);"
        ));
    }

    public function test_missing_constant_and_nothing_saved_means_on(): void
    {
        $this->assertSame('true|true', $this->boot(null, ''));
    }

    /** The store is a file read with no database: a missing or broken file = the constant. */
    public function test_unreadable_store_falls_back_to_the_constant(): void
    {
        $this->assertSame('false|true', $this->boot(null,
            "define('FEATURE_DONATIONS', false);\ndefine('FEATURE_CAMPAIGN', true);"));
        $this->assertSame('false|true', $this->boot(null,
            "define('FEATURE_DONATIONS', false);\ndefine('FEATURE_CAMPAIGN', true);", '{broken json'));
        // A non-string value in the file is ignored, not treated as "off".
        $this->assertSame('true|true', $this->boot(['feature_donations' => false, 'feature_campaign' => ['x']], ''));
    }

    public function test_feature_enabled_does_not_touch_the_database(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php');
        $start = strpos($src, 'function feature_enabled(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, strpos($src, "\n}\n", $start) - $start);
        $this->assertStringNotContainsString('get_pdo', $body);
        $this->assertStringNotContainsString('setting_get', $body);
        $this->assertStringNotContainsString('file_get_contents', $body, 'the file is read once per request by config.php');
    }

    public function test_hand_edited_false_strings_mean_off(): void
    {
        $this->assertSame('false|false', $this->boot(null,
            "define('FEATURE_DONATIONS', 'false');\ndefine('FEATURE_CAMPAIGN', '0');"));
    }

    // ── Validation of the POSTed switches (Admin → Модули) ───────────────────────

    private function validate(array $in, array $current = ['donations' => true, 'campaign' => true, 'events' => true]): array
    {
        return modules_validate_switches($in, $current);
    }

    public function test_every_module_has_a_field_label_and_warning(): void
    {
        $mods = org_modules();   // the compatibility shape still answers
        $this->assertSame(array_keys(modules_registry()), array_keys($mods));
        $this->assertContains('donations', array_keys($mods));
        $this->assertContains('campaign', array_keys($mods));
        $this->assertContains('events', array_keys($mods));
        foreach ($mods as $name => $m) {
            $this->assertArrayHasKey($m['field'], org_fields());
            $this->assertSame('FEATURE_' . strtoupper($name), org_fields()[$m['field']]);
            $this->assertNotSame('', $m['label']);
            $this->assertNotSame('', $m['hint']);
            $this->assertNotSame('', $m['off_warning']);
        }
    }

    public function test_unticked_switch_saves_an_explicit_off(): void
    {
        $r = $this->validate([]);
        $this->assertSame([], $r['errors']);
        $this->assertSame(array_map('module_field', array_keys(modules_registry())), array_keys($r['values']), 'every module gets a value');
        $this->assertSame(['0'], array_values(array_unique($r['values'])), 'nothing ticked = everything off');
    }

    public function test_ticked_switch_saves_on(): void
    {
        $r = $this->validate(['feature_donations' => '1', 'feature_campaign' => '1', 'feature_events' => '1'], ['donations' => false, 'campaign' => false, 'events' => false]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('1', $r['values']['feature_donations']);
        $this->assertSame('1', $r['values']['feature_campaign']);
        $this->assertSame('1', $r['values']['feature_events']);
    }

    public static function notABoolean(): array
    {
        return [['yes'], ['true'], ['on'], ['2'], [''], [['1']], ['1 ']];
    }

    #[DataProvider('notABoolean')]
    public function test_anything_but_the_checkbox_value_is_refused(mixed $value): void
    {
        $r = $this->validate(['feature_campaign' => $value, 'feature_donations' => '1']);
        $this->assertArrayHasKey('feature_campaign', $r['errors']);
        $this->assertArrayNotHasKey('feature_donations', $r['errors']);
    }

    public function test_unknown_module_names_are_dropped(): void
    {
        $r = $this->validate(['feature_evil' => '1', 'feature_self_update' => '1']);
        $this->assertArrayNotHasKey('feature_evil', $r['values']);
        $this->assertArrayNotHasKey('feature_self_update', $r['values']);

        $file = $this->tmp . '/organisation.json';
        $this->assertTrue(org_save_overrides($r['values'] + ['feature_evil' => '1'], $file));
        $saved = (string) file_get_contents($file);
        $this->assertStringNotContainsString('feature_evil', $saved);
        $this->assertStringNotContainsString('self_update', $saved);
    }

    public function test_switch_round_trips_through_the_file(): void
    {
        $file = $this->tmp . '/organisation.json';
        $vals = $this->validate(['feature_campaign' => '1'])['values'];
        $this->assertTrue(org_save_overrides($vals, $file));
        $loaded = org_load_overrides($file);
        $this->assertSame('0', $loaded['FEATURE_DONATIONS']);
        $this->assertSame('1', $loaded['FEATURE_CAMPAIGN']);
    }

    /** The two pages share one file: saving one part must not wipe the other. */
    public function test_saving_modules_keeps_the_organisation_fields_and_vice_versa(): void
    {
        $file = $this->tmp . '/organisation.json';
        $org  = org_validate($this->orgInput(['site_phone' => '+359 2 000 0000']), array_keys(brand_themes()));
        $this->assertSame([], $org['errors']);
        $this->assertArrayNotHasKey('feature_donations', $org['values'], 'the Организация form no longer carries module switches');
        $this->assertTrue(org_save_overrides($org['values'], $file));

        $this->assertTrue(org_save_overrides($this->validate(['feature_campaign' => '1'])['values'], $file));
        $loaded = org_load_overrides($file);
        $this->assertSame('+359 2 000 0000', $loaded['SITE_PHONE']);
        $this->assertSame('0', $loaded['FEATURE_DONATIONS']);

        $this->assertTrue(org_save_overrides($org['values'], $file));
        $loaded = org_load_overrides($file);
        $this->assertSame('0', $loaded['FEATURE_DONATIONS'], 'saving Организация leaves the switches alone');
        $this->assertSame('1', $loaded['FEATURE_CAMPAIGN']);
    }

    private function orgInput(array $over = []): array
    {
        return array_merge([
            'site_name_bg'  => 'Фондация Тест',
            'site_name_en'  => 'Test Foundation',
            'site_email'    => 'info@example.org',
            'brand_theme'   => array_key_first(brand_themes()),
            'brand_primary' => '#2D3A8C',
            'brand_accent'  => '#5B6FD6',
        ], $over);
    }

    // ── Admin pages (static) ─────────────────────────────────────────────────────

    public function test_modules_page_renders_a_labelled_switch_per_module_and_confirms_off(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/modules.php');
        $this->assertStringContainsString('admin_require_admin();', $src);
        $this->assertLessThan(strpos($src, 'org_save_overrides($result'), strpos($src, 'csrf_verify()'));
        $this->assertStringContainsString('foreach ($registry as $name => $m)', $src);
        $this->assertMatchesRegularExpression('/<label for="mod-<\?= h\(\$name\) \?>"/', $src);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch" id="mod-<\?= h\(\$name\) \?>"/', $src);
        $this->assertStringContainsString('✓ Включен', $src, 'state in words, not colour only');
        $this->assertStringContainsString('_adminConfirm(', $src);
        $this->assertStringContainsString('dataset.pending', $src, 'unfinished work is part of the confirmation');
        $this->assertStringNotContainsString('window.confirm', $src);
    }

    public function test_organisation_page_points_to_the_modules_page_instead(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/admin/organisation.php');
        $this->assertStringNotContainsString('>Модули</h2>', $src);
        $this->assertStringNotContainsString('data-module-switch', $src);
        $this->assertStringContainsString('href="/admin/modules.php"', $src);
    }

    // ── Donations entry points follow the switch ─────────────────────────────────

    public function test_donation_checkout_is_gated(): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/donation/checkout.php');
        $gate = strpos($src, "module_public_guard('donations');");
        $this->assertNotFalse($gate);
        $this->assertLessThan(strpos($src, 'csrf_verify()'), $gate, 'gate before any processing');
    }

    public static function newsPages(): array
    {
        return [['novini/index.php'], ['en/news/index.php']];
    }

    #[DataProvider('newsPages')]
    public function test_news_donate_card_follows_the_switch(string $rel): void
    {
        $src = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $rel);
        $this->assertMatchesRegularExpression(
            "/<\?php if \(feature_enabled\('donations'\)\): \?>\s*<!-- Donate -->.*?donation\/.*?<\?php endif; \?>/s",
            $src
        );
    }
}
