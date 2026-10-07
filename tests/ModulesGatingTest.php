<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What switching a module off does to the rest of the site (step 2 of the
 * optional modules): its pages are guarded, links to it disappear from menus,
 * the front page and the sitemap, its emails and scheduled jobs stop — and the
 * things that must keep working with it off (unsubscribe links, donations, …)
 * still do.
 *
 * Anything that switches a module off runs in a child PHP process
 * (FEATURE_<NAME> constants cannot be undefined), so this process keeps the
 * site's own values.
 */
final class ModulesGatingTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function src(string $rel): string
    {
        $src = file_get_contents(self::root() . '/' . $rel);
        if (!is_string($src)) throw new RuntimeException("$rel is missing");
        return $src;
    }

    /**
     * Run PHP code in a child process with the test bootstrap loaded and the
     * given modules switched off (FEATURE_<NAME> = false, defined before
     * config.php so the site's own switches do not win). Returns stdout.
     *
     * The worktree's own content/organisation.json (gitignored) would override
     * the constants, so a site with saved switches skips instead.
     */
    private function child(string $code, array $off = [], bool $allowFail = false): string
    {
        if (is_file(self::root() . '/content/organisation.json')) {
            $saved = json_decode((string) file_get_contents(self::root() . '/content/organisation.json'), true);
            foreach ($off as $name) {
                if (isset($saved['feature_' . $name])) $this->markTestSkipped('This site saves its module switches in content/organisation.json.');
            }
        }
        $defs = '';
        foreach ($off as $name) $defs .= "define('FEATURE_" . strtoupper($name) . "', false);\n";
        $boot = var_export(self::root() . '/tests/bootstrap.php', true);
        $file = tempnam(sys_get_temp_dir(), 'om_gating_');
        file_put_contents($file, "<?php\n{$defs}require {$boot};\n" . $code);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        @unlink($file);
        if (!$allowFail) $this->assertSame(0, $rc, implode("\n", $out));
        return implode("\n", $out);
    }

    // ── Links to modules ─────────────────────────────────────────────────────

    public function test_module_for_path_matches_public_paths(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'shop' => ['label' => 'Магазин', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [],
                           'public_paths' => ['/magazin/', '/en/shop/', '/api/review-submit.php']],
                'blog' => ['label' => 'Блог', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [],
                           'public_paths' => ['/magazin/blog/']],
            ]);
            echo json_encode([
                module_for_path('/magazin/'), module_for_path('/magazin'), module_for_path('/magazin/x/?a=1#b'),
                module_for_path('/magazinx/'), module_for_path('/magazin/blog/post/'), module_for_path(rtrim(SITE_URL, '/') . '/en/shop/'),
                module_for_path('/api/review-submit.php'), module_for_path('/api/review-submit.phpx'),
                module_for_path('https://other-site.invalid/magazin/'), module_for_path('mailto:a@b.c'), module_for_path('#top'),
                module_for_path('//evil.example/magazin/'), module_for_path(''),
            ]);
            PHP, ['shop']);
        $this->assertSame(['shop', 'shop', 'shop', null, 'blog', 'shop', 'shop', null, null, null, null, null, null], json_decode($out, true), $out);
    }

    public function test_links_to_an_off_module_are_dropped_with_their_keys_kept(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'shop' => ['label' => 'Магазин', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [], 'public_paths' => ['/magazin/']],
            ]);
            echo json_encode([
                'visible' => [module_link_visible('/magazin/'), module_link_visible('/za-nas/')],
                'menu'    => module_filter_links([['url' => '/za-nas/', 'label' => 'A'], ['url' => '/magazin/', 'label' => 'B'], ['url' => '/kontakti/', 'label' => 'C']]),
            ]);
            PHP, ['shop']);
        $r = json_decode($out, true);
        $this->assertSame([false, true], $r['visible'], $out);
        $this->assertSame(['0', '2'], array_map('strval', array_keys($r['menu'])), 'the on-page editor needs the original indexes');
    }

    public function test_menus_front_page_buttons_cards_and_sitemap_use_the_link_check(): void
    {
        $this->assertStringContainsString("module_filter_links(\$_menus['header'][\$lang] ?? [])", self::src('templates/header.php'));
        $footer = self::src('templates/footer.php');
        $this->assertStringContainsString("module_filter_links(\$_fmenus['footer_nav'][\$lang]   ?? [])", $footer);
        $this->assertStringContainsString("module_filter_links(\$_fmenus['footer_help'][\$lang]  ?? [])", $footer);
        $home = self::src('includes/home_render.php');
        $this->assertStringContainsString('if (!module_link_visible($url)) continue;', $home);
        $this->assertStringContainsString("if (!home_section_module_on((string) \$s['type'])) continue;", $home);
        $this->assertStringContainsString('module_link_visible($link)', self::src('templates/home/cards.php'));
        $this->assertStringContainsString('module_link_visible($u[0])', self::src('sitemap.php'));
        $this->assertStringContainsString('menu-pair-module-off', self::src('admin/menus.php'), 'the menu editor says why an item is hidden');
    }

    public function test_front_page_buttons_to_an_off_module_disappear(): void
    {
        $out = $this->child(<<<'PHP'
            modules_registry([
                'campaign' => ['label' => 'Кампании', 'description' => 'd', 'off_warning' => 'w', 'needs' => [], 'admin_pages' => [], 'public_paths' => ['/campaign/', '/en/campaign/']],
            ]);
            require_once ROOT_PATH . '/includes/home_render.php';
            echo home_buttons('s1', [
                'btn1_label' => ['bg' => 'Кампания', 'en' => ''], 'btn1_url' => ['bg' => '/campaign/', 'en' => ''],
                'btn2_label' => ['bg' => 'За нас', 'en' => ''],   'btn2_url' => ['bg' => '/za-nas/', 'en' => ''],
            ], 'bg', [['btn', ''], ['btn', '']]);
            echo '|', home_section_module_on('campaign') ? 'on' : 'off', '|', home_section_module_on('mission') ? 'on' : 'off';
            PHP, ['campaign']);
        $this->assertStringNotContainsString('/campaign/', $out);
        $this->assertStringContainsString('href="/za-nas/"', $out);
        $this->assertStringEndsWith('|off|on', $out);
    }

    /** [module, an address of it that the sitemap would otherwise list] */
    public static function sitemapModules(): array
    {
        return [
            'donations' => ['donations', '/donation/'],
        ];
    }

    #[DataProvider('sitemapModules')]
    public function test_sitemap_omits_an_off_module(string $module, string $path): void
    {
        if (!defined('DB_HOST')) $this->markTestSkipped('No database.');
        $code = '$_SERVER["REQUEST_URI"] = "/sitemap.xml"; require ROOT_PATH . "/sitemap.php";';
        $off  = $this->child($code, [$module]);
        $this->assertStringContainsString('<urlset', $off);
        $this->assertStringNotContainsString('<loc>' . rtrim(SITE_URL, '/') . $path, $off);
        $this->assertStringContainsString('<loc>' . rtrim(SITE_URL, '/') . '/za-nas/</loc>', $off, 'core pages stay');
    }
}
