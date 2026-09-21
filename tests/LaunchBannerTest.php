<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pre-launch notice strip (templates/header.php), switched on in
 * Admin → Организация while the site is still being filled in.
 *
 * It is a notice and nothing else — it must never gate a page, hide a price or
 * stop an order, because a card acquirer reviewing the site for a virtual POS
 * has to be able to walk every page and the whole checkout.
 *
 * Anything that define()s constants runs in a child PHP process, so the test
 * process's own SITE_* constants are never touched (same approach as
 * OrganisationTest).
 */
final class LaunchBannerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/organisation.php';
        $this->tmp = sys_get_temp_dir() . '/om-banner-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    private function runPhp(string $code): array
    {
        $script = $this->tmp . '/run.php';
        file_put_contents($script, $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 '
             . escapeshellarg($script) . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /** Run code with the banner constants pre-defined, against the real config.php. */
    private function withConstants(array $consts, string $echo): string
    {
        $defines = '';
        foreach ($consts as $name => $value) {
            $defines .= "define('" . $name . "', " . var_export($value, true) . ");\n";
        }
        $config = var_export($_SERVER['DOCUMENT_ROOT'] . '/config.php', true);
        [$rc, $out] = $this->runPhp("<?php
            \$_SERVER['DOCUMENT_ROOT'] = " . var_export($_SERVER['DOCUMENT_ROOT'], true) . ";
            \$_SERVER['REQUEST_URI']   = '/';
            \$_SERVER['HTTP_HOST']     = 'localhost';
            {$defines}
            require {$config};
            {$echo}
        ");
        $this->assertSame(0, $rc, $out);
        return $out;
    }

    // ── Off by default ───────────────────────────────────────────────────────

    public function test_banner_is_off_when_nothing_is_configured(): void
    {
        // A site that has never touched the setting must not suddenly grow a strip.
        $this->assertFalse(launch_banner_enabled());
    }

    public function test_default_copy_is_used_when_no_text_is_set(): void
    {
        $this->assertStringContainsString('Сайтът ни е нов', launch_banner_text('bg'));
        $this->assertStringContainsString('Our site is new', launch_banner_text('en'));
    }

    public function test_text_is_never_empty_so_the_strip_is_never_blank(): void
    {
        foreach (['bg', 'en'] as $lang) {
            $this->assertNotSame('', trim(launch_banner_text($lang)));
        }
    }

    // ── The switch ───────────────────────────────────────────────────────────

    public function test_constant_turns_the_banner_on_and_off(): void
    {
        $on = $this->withConstants(
            ['SITE_LAUNCH_BANNER' => '1'],
            "echo launch_banner_enabled() ? 'on' : 'off';"
        );
        $this->assertSame('on', $on);

        // '0' is what the admin checkbox writes when unticked — it must read as off,
        // not as a non-empty string that happens to be truthy.
        $off = $this->withConstants(
            ['SITE_LAUNCH_BANNER' => '0'],
            "echo launch_banner_enabled() ? 'on' : 'off';"
        );
        $this->assertSame('off', $off);

        $bool_off = $this->withConstants(
            ['SITE_LAUNCH_BANNER' => false],
            "echo launch_banner_enabled() ? 'on' : 'off';"
        );
        $this->assertSame('off', $bool_off);
    }

    public function test_owner_text_replaces_the_default_per_language(): void
    {
        $out = $this->withConstants([
            'SITE_LAUNCH_BANNER'    => '1',
            'SITE_LAUNCH_BANNER_BG' => 'Тестваме магазина',
            'SITE_LAUNCH_BANNER_EN' => 'We are testing the shop',
        ], "echo launch_banner_text('bg'), '|', launch_banner_text('en');");

        $this->assertSame('Тестваме магазина|We are testing the shop', $out);
    }

    public function test_whitespace_only_text_falls_back_to_the_default(): void
    {
        $out = $this->withConstants([
            'SITE_LAUNCH_BANNER'    => '1',
            'SITE_LAUNCH_BANNER_BG' => "   \n ",
        ], "echo launch_banner_text('bg');");

        $this->assertStringContainsString('Сайтът ни е нов', $out);
    }

    public function test_one_language_may_be_customised_without_the_other(): void
    {
        $out = $this->withConstants([
            'SITE_LAUNCH_BANNER'    => '1',
            'SITE_LAUNCH_BANNER_EN' => 'Soft launch',
        ], "echo launch_banner_text('bg'), '|', launch_banner_text('en');");

        [$bg, $en] = explode('|', $out);
        $this->assertStringContainsString('Сайтът ни е нов', $bg);
        $this->assertSame('Soft launch', $en);
    }

    // ── The admin form ───────────────────────────────────────────────────────

    private function orgInput(array $over = []): array
    {
        return array_merge([
            'site_name_bg'  => 'Тест',
            'site_name_en'  => 'Test',
            'site_email'    => 'info@example.org',
            'brand_theme'   => 'modern',
            'brand_primary' => '#2D3A8C',
            'brand_accent'  => '#5B6FD6',
        ], $over);
    }

    private function validate(array $over = []): array
    {
        return org_validate($this->orgInput($over), array_keys(brand_themes()));
    }

    public function test_unticked_checkbox_saves_an_explicit_off(): void
    {
        // An unticked checkbox is absent from the POST entirely. Storing '' would
        // read back as "not set", leaving no way to switch the banner off again.
        $r = $this->validate();
        $this->assertSame('0', $r['values']['launch_banner']);
    }

    public function test_ticked_checkbox_saves_on(): void
    {
        $r = $this->validate(['launch_banner' => '1']);
        $this->assertSame('1', $r['values']['launch_banner']);
    }

    public function test_only_the_exact_checkbox_value_counts_as_on(): void
    {
        foreach (['on', 'true', 'yes', '2', ' 1'] as $bogus) {
            $r = $this->validate(['launch_banner' => $bogus]);
            $this->assertSame('0', $r['values']['launch_banner'], $bogus);
        }
    }

    public function test_overlong_text_is_rejected_per_language(): void
    {
        $r = $this->validate([
            'launch_banner_bg' => str_repeat('я', 201),
            'launch_banner_en' => str_repeat('a', 201),
        ]);
        $this->assertArrayHasKey('launch_banner_bg', $r['errors']);
        $this->assertArrayHasKey('launch_banner_en', $r['errors']);
    }

    public function test_text_at_the_limit_is_accepted(): void
    {
        $r = $this->validate(['launch_banner_bg' => str_repeat('я', 200)]);
        $this->assertArrayNotHasKey('launch_banner_bg', $r['errors']);
    }

    public function test_empty_text_is_fine(): void
    {
        $r = $this->validate(['launch_banner_bg' => '', 'launch_banner_en' => '']);
        $this->assertArrayNotHasKey('launch_banner_bg', $r['errors']);
        $this->assertArrayNotHasKey('launch_banner_en', $r['errors']);
    }

    // ── Saving round trip ────────────────────────────────────────────────────

    public function test_saved_settings_come_back_as_the_banner_constants(): void
    {
        $file = $this->tmp . '/organisation.json';
        $vals = $this->validate([
            'launch_banner'    => '1',
            'launch_banner_bg' => 'Скоро',
            'launch_banner_en' => 'Soon',
        ])['values'];

        $this->assertTrue(org_save_overrides($vals, $file));

        $loaded = org_load_overrides($file);
        $this->assertSame('1',     $loaded['SITE_LAUNCH_BANNER']);
        $this->assertSame('Скоро', $loaded['SITE_LAUNCH_BANNER_BG']);
        $this->assertSame('Soon',  $loaded['SITE_LAUNCH_BANNER_EN']);
    }

    public function test_switching_off_survives_the_round_trip(): void
    {
        $file = $this->tmp . '/organisation.json';
        $this->assertTrue(org_save_overrides($this->validate()['values'], $file));

        $loaded = org_load_overrides($file);
        $this->assertSame('0', $loaded['SITE_LAUNCH_BANNER']);
    }

    // ── The strip itself ─────────────────────────────────────────────────────

    public function test_banner_text_is_escaped_in_the_template(): void
    {
        // The text is owner-supplied and lands in HTML, so it must go through h().
        $header = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/header.php');
        $this->assertStringContainsString('h(launch_banner_text(get_lang()))', $header);
    }

    public function test_banner_cannot_be_dismissed_away(): void
    {
        // It first shipped with a × that wrote localStorage, and a single stray
        // tap then hid it for good — on every page, for the rest of that
        // browser's life. A notice worth showing must not be that easy to lose.
        $header = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/header.php');
        $start  = strpos($header, 'launch_banner_enabled()');
        $this->assertNotFalse($start);
        $block  = substr($header, $start, 2000);

        foreach (['localStorage', 'sessionStorage', '<button'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $block, $forbidden);
        }
    }

    public function test_banner_never_gates_a_page(): void
    {
        // A guard against the notice quietly becoming a wall: DSK must be able to
        // reach every page and complete a checkout while it is on.
        $header = (string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/templates/header.php');
        $start  = strpos($header, 'launch_banner_enabled()');
        $this->assertNotFalse($start);
        $block  = substr($header, $start, 2000);

        foreach (['header(', 'exit', 'die(', 'http_response_code'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $block, $forbidden);
        }
    }
}
