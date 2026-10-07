<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The optional-module registry (includes/modules.php) and the helpers built on
 * it: dependency logic, the public guard, the install wizard's module step.
 *
 * Anything that define()s FEATURE_* constants or swaps the registry runs in a
 * child PHP process, so this process keeps the site's own values.
 */
final class ModulesRegistryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /** Run PHP code in a child process with includes/organisation.php loaded; returns stdout. */
    private function child(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'om_modules_');
        $org  = var_export(self::root() . '/includes/organisation.php', true);
        file_put_contents($file, "<?php\nrequire {$org};\n" . $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_every_module_is_fully_described(): void
    {
        $reg = modules_registry();
        $this->assertNotEmpty($reg);
        foreach ($reg as $name => $m) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $name, 'module names become FEATURE_<NAME> constants');
            foreach (['label', 'description', 'off_warning'] as $k) {
                $this->assertIsString($m[$k] ?? null, "$name.$k");
                $this->assertNotSame('', trim($m[$k]), "$name.$k must not be empty");
            }
            foreach (['needs', 'admin_pages', 'public_paths'] as $k) {
                $this->assertIsArray($m[$k] ?? null, "$name.$k");
                $this->assertTrue(array_is_list($m[$k]), "$name.$k is a plain list");
            }
            foreach ($m['public_paths'] as $path) {
                $this->assertStringStartsWith('/', $path, "$name: public paths are URL prefixes");
            }
            if (array_key_exists('pending', $m)) {
                $this->assertIsCallable($m['pending'], "$name.pending");
            }
        }
    }

    public function test_needs_refer_to_other_registered_modules(): void
    {
        $reg = modules_registry();
        $this->assertNotEmpty($reg);
        foreach ($reg as $name => $m) {
            foreach ($m['needs'] as $need) {
                $this->assertArrayHasKey($need, $reg, "$name needs an unknown module '$need'");
                $this->assertNotSame($name, $need, "$name cannot need itself");
            }
        }
    }

    public function test_needs_have_no_cycles(): void
    {
        $reg   = modules_registry();
        $state = [];   // name => 1 visiting, 2 done
        $visit = function (string $n, array $path) use (&$visit, &$state, $reg): void {
            if (($state[$n] ?? 0) === 2) return;
            $this->assertNotSame(1, $state[$n] ?? 0, 'dependency cycle: ' . implode(' → ', [...$path, $n]));
            $state[$n] = 1;
            foreach ($reg[$n]['needs'] as $need) $visit($need, [...$path, $n]);
            $state[$n] = 2;
        };
        foreach (array_keys($reg) as $n) $visit($n, []);
    }

    public function test_every_module_page_exists_and_carries_its_guard(): void
    {
        $seen = [];
        foreach (modules_registry() as $name => $m) {
            foreach ($m['admin_pages'] as $page) {
                $this->assertArrayNotHasKey($page, $seen, "$page belongs to two modules");
                $seen[$page] = $name;
                $file = self::root() . '/admin/' . $page;
                $this->assertFileExists($file);
                $this->assertStringContainsString("module_admin_guard('{$name}');", (string) file_get_contents($file));
                $this->assertSame($name, module_for_admin_page($page));
            }
        }
        $this->assertNull(module_for_admin_page('dashboard.php'));
        $this->assertTrue(module_admin_page_visible('dashboard.php'), 'core pages are always in the menu');
    }

    public function test_every_module_has_a_switch_in_the_organisation_store(): void
    {
        foreach (array_keys(modules_registry()) as $name) {
            $this->assertSame('FEATURE_' . strtoupper($name), org_fields()[module_field($name)] ?? null);
        }
    }

    // ── feature_enabled() is unchanged; dependency logic sits on top ─────────

    public function test_a_module_whose_needs_are_off_counts_as_off(): void
    {
        $out = $this->child(<<<'PHP'
            define('FEATURE_SHOP', false);
            define('FEATURE_REVIEWS', true);
            modules_registry([
                'shop'    => ['label' => 'Магазин', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => ['products.php'], 'public_paths' => ['/magazin/']],
                'reviews' => ['label' => 'Отзиви', 'description' => 'd', 'off_warning' => 'w', 'needs' => ['shop'], 'admin_pages' => ['product-reviews.php'], 'public_paths' => []],
                'loop_a'  => ['label' => 'A', 'description' => 'd', 'off_warning' => 'w', 'needs' => ['loop_b'], 'admin_pages' => [], 'public_paths' => []],
                'loop_b'  => ['label' => 'B', 'description' => 'd', 'off_warning' => 'w', 'needs' => ['loop_a'], 'admin_pages' => [], 'public_paths' => []],
            ]);
            echo json_encode([
                'feature'  => feature_enabled('reviews'),
                'with'     => module_enabled_with_needs('reviews'),
                'missing'  => module_missing_needs('reviews'),
                'menu'     => module_admin_page_visible('product-reviews.php'),
                'loop'     => module_enabled_with_needs('loop_a'),
                'unknown'  => module_enabled_with_needs('no_such_module'),
                // Shop is off and stays off, so the greyed-out reviews switch keeps its value.
                'save'     => modules_validate_switches([], ['shop' => false, 'reviews' => true, 'loop_a' => true, 'loop_b' => true])['values'],
                'problems' => modules_needs_problems(['reviews' => true]),
                'fine'     => modules_needs_problems(['reviews' => true, 'shop' => true]),
            ], JSON_UNESCAPED_UNICODE);
            PHP);
        $r = json_decode($out, true);
        $this->assertIsArray($r, $out);
        $this->assertTrue($r['feature'], 'feature_enabled() reads only its own switch, as before');
        $this->assertFalse($r['with']);
        $this->assertSame(['shop'], $r['missing']);
        $this->assertFalse($r['menu']);
        $this->assertTrue($r['loop'], 'a cycle never loops forever or switches anything off');
        $this->assertTrue($r['unknown'], 'an unregistered name is just feature_enabled()');
        $this->assertSame('1', $r['save']['feature_reviews']);
        $this->assertSame('0', $r['save']['feature_shop']);
        $this->assertCount(1, $r['problems']);
        $this->assertStringContainsString('„Отзиви“ има нужда от „Магазин“', $r['problems'][0]);
        $this->assertSame([], $r['fine']);
    }

    public function test_pending_never_throws(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite not available');
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (array_keys(modules_registry()) as $name) {
            $this->assertNull(module_pending($name, $pdo), "$name: a missing table means nothing to report");
            $this->assertNull(module_pending($name, null));
        }
    }

    // ── Public guard ─────────────────────────────────────────────────────────

    public function test_public_guard_shows_the_site_404_when_the_module_is_off(): void
    {
        $out = $this->child(<<<'PHP'
            define('FEATURE_CAMPAIGN', false);
            $_SERVER['REQUEST_URI'] = '/campaign/';
            module_public_guard('campaign');
            echo 'REACHED THE PAGE';
            PHP);
        $this->assertStringContainsString('Страницата не е намерена', $out);
        $this->assertStringNotContainsString('REACHED THE PAGE', $out);
    }

    public function test_public_guard_lets_the_page_through_when_on(): void
    {
        $out = $this->child(<<<'PHP'
            define('FEATURE_CAMPAIGN', true);
            module_public_guard('campaign');
            echo 'REACHED THE PAGE';
            PHP);
        $this->assertSame('REACHED THE PAGE', $out);
    }

    public function test_every_public_entry_point_of_a_module_is_guarded(): void
    {
        $entries = [
            'donations' => ['donation/index.php', 'donation/checkout.php', 'donation/confirmation/index.php'],
            'campaign'  => ['campaign/index.php', 'campaign/checkout.php', 'campaign/confirmation/index.php',
                            'campaign/payment-failed/index.php', 'api/campaign-payment-return.php'],
            'events'    => ['sabitiya/index.php', 'sabitiya/checkout.php', 'sabitiya/confirmation/index.php',
                            'tickets/index.php', 'api/event-payment-return.php'],
        ];
        foreach ($entries as $name => $files) {
            foreach ($files as $rel) {
                $this->assertStringContainsString("module_public_guard('{$name}');",
                    (string) file_get_contents(self::root() . '/' . $rel), $rel);
            }
        }
    }

    // ── Install wizard ───────────────────────────────────────────────────────

    public function test_install_flags_give_every_module_an_explicit_value(): void
    {
        $flags = modules_install_flags(['campaign', 'evil', 5]);
        $this->assertSame(
            array_map(fn($n) => 'FEATURE_' . strtoupper($n), array_keys(modules_registry())),
            array_keys($flags)
        );
        $this->assertTrue($flags['FEATURE_CAMPAIGN']);
        $this->assertFalse($flags['FEATURE_DONATIONS'], 'unticked = off on a new install');
        $this->assertArrayNotHasKey('FEATURE_EVIL', $flags);
        $this->assertSame([false], array_values(array_unique(modules_install_flags([]))), 'nothing ticked = everything off');
    }

    public function test_wizard_writes_the_flags_into_site_config(): void
    {
        $src = (string) file_get_contents(self::root() . '/install/index.php');
        $this->assertStringContainsString('$module_flags   = modules_install_flags($chosen_modules);', $src);
        $this->assertMatchesRegularExpression("/write_config\(\\\$ROOT \. '\/site\.config\.php', array_merge\(\[.*?\], \\\$module_flags\)/s", $src);
        $this->assertStringNotContainsString("'FEATURE_DONATIONS' => true", $src, 'no module is switched on by default');
        $this->assertStringContainsString('href="/admin/modules.php"', $src, 'the finish screen says where to change them');
    }

    /** Render the wizard's empty form from a copy (the real checkout is already installed). */
    public function test_wizard_offers_every_module_unticked(): void
    {
        $tmp = sys_get_temp_dir() . '/om-wizard-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/install', 0777, true);
        mkdir($tmp . '/includes');
        copy(self::root() . '/install/index.php', $tmp . '/install/index.php');
        foreach (['themes.php', 'organisation.php', 'modules.php', 'url.php'] as $f) {
            copy(self::root() . '/includes/' . $f, $tmp . '/includes/' . $f);
        }
        foreach (glob(self::root() . '/includes/themes-*.php') ?: [] as $f) copy($f, $tmp . '/includes/' . basename($f));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/install/index.php') . ' 2>&1', $out, $rc);
        exec('rm -rf ' . escapeshellarg($tmp));
        $html = implode("\n", $out);
        $this->assertSame(0, $rc, $html);

        $this->assertStringContainsString('4. Какво ще ползвате?', $html);
        foreach (modules_registry() as $name => $m) {
            $this->assertMatchesRegularExpression(
                '/<label class="module-card" for="module-' . $name . '">\s*<input type="checkbox" id="module-' . $name . '" name="modules\[\]" value="' . $name . '" >/',
                $html,
                "$name: a labelled, unticked checkbox"
            );
            $this->assertStringContainsString(htmlspecialchars($m['description'], ENT_QUOTES), $html);
        }
    }
}
