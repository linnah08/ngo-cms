<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/admin/includes/admin-nav.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

/**
 * The admin menu (admin/includes/admin-nav.php): the side menu holds everyday
 * work, the Настройки panel in the top bar the setup pages, the account panel
 * the profile and Изход, and a new version shows as a notice in the top bar.
 */
final class AdminNavTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        unset($_SESSION[ADMIN_SESSION_NAME]);
        updater_set_root_override(null);
        updater_set_self_update_override(null);
        if ($this->root !== null) {
            foreach (glob($this->root . '/{,*/}*', GLOB_BRACE) as $f) if (is_file($f)) unlink($f);
            @rmdir($this->root . '/logs');
            @rmdir($this->root);
        }
    }

    private function as(string $role): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();   // or admin_user() starts it later and drops what is set here
        $_SESSION[ADMIN_SESSION_NAME] = ['id' => 1, 'role' => $role, 'name' => 'T', 'site' => admin_session_site(), 'time' => time()];
    }

    /** A pretend install at $local whose last update check found $latest. */
    private function install(string $local, ?string $latest, int $age = 0): void
    {
        $this->root = sys_get_temp_dir() . '/admin-nav-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/logs', 0755, true);
        file_put_contents($this->root . '/VERSION', $local);
        if ($latest !== null) {
            file_put_contents($this->root . '/logs/update-check-cache.json', json_encode(
                ['fetched_at' => time() - $age, 'no_release' => false, 'tag_name' => 'v' . $latest, 'notes' => '', 'zip_url' => null]));
        }
        updater_set_root_override($this->root);
        updater_set_self_update_override(true);
    }

    // ── Where things are ─────────────────────────────────────────────────────

    public function test_every_admin_page_lights_up_its_menu_item(): void
    {
        $this->as('admin');
        $items = admin_nav_items();
        $navs  = ['home'];   // admin/home-sections.php: 'pages' for a created page, 'home' for the front page
        foreach (glob(dirname(__DIR__, 2) . '/admin/*.php') as $file) {
            if (!module_admin_page_visible(basename($file))) continue;   // a switched-off module's pages are gone anyway
            if (preg_match("/\\\$active_nav\s*=\s*'([a-z0-9-]+)'/", (string) file_get_contents($file), $m)) $navs[] = $m[1];
        }
        // In the top bar, not the menus: Помощ and the account panel. Подпис is
        // only for the person who signs documents, which this test user is not.
        $navs = array_diff(array_unique($navs), ['support', 'account', 'signature']);
        $this->assertGreaterThan(20, count($navs));
        foreach ($navs as $nav) {
            $this->assertNotNull(admin_nav_current($items, $nav), "a page with \$active_nav = '$nav' has no menu item to light up");
        }
    }

    public function test_side_menu_is_everyday_work_and_the_panel_is_setup(): void
    {
        $this->as('admin');
        $items = admin_nav_items();
        $side  = array_merge(...array_values(admin_nav_grouped($items, 'side')));
        $set   = array_merge(...array_values(admin_nav_grouped($items, 'settings')));
        $this->assertContains('home', array_column($side, 'key'), 'Начална страница has its own link');
        $this->assertContains('orders', array_column($side, 'key'));
        foreach (['modules', 'users', 'updates', 'organisation', 'menus'] as $k) {
            $this->assertContains($k, array_column($set, 'key'), "$k is in Настройки");
            $this->assertNotContains($k, array_column($side, 'key'), "$k is not in the side menu");
        }
        $this->assertSame(['', 'Съдържание'], array_slice(array_keys(admin_nav_grouped($items, 'side')), 0, 2), 'Начало first, ungrouped');
        $this->assertLessThanOrEqual(16, count($side), 'the side menu stays short');
    }

    public function test_an_author_sees_writing_only_and_no_settings(): void
    {
        $this->as('author');
        $items = admin_nav_items();
        $keys  = array_column($items, 'key');
        $this->assertContains('articles', $keys);
        $this->assertNotContains('orders', $keys);
        $this->assertNotContains('modules', $keys);
        $this->assertSame([], admin_nav_grouped($items, 'settings'), 'no Настройки button for an author');
    }

    public function test_a_shop_manager_sees_the_shop_and_sumup_but_no_site_setup(): void
    {
        $this->as('shop_admin');
        $keys = array_column(admin_nav_items(), 'key');
        $this->assertContains('orders', $keys);
        $this->assertNotContains('modules', $keys);
        $this->assertNotContains('users', $keys);
        $this->assertNotContains('articles', $keys);
    }

    public function test_the_current_page_and_counts(): void
    {
        $items = [
            ['key' => 'a', 'area' => 'side', 'group' => '', 'nav' => ['a'], 'count' => 0],
            ['key' => 'n', 'area' => 'side', 'group' => '', 'nav' => ['newsletter', 'newsletter-send'], 'count' => 2],
            ['key' => 's', 'area' => 'settings', 'group' => 'Сайт', 'nav' => ['s'], 'count' => 3],
            ['key' => 't', 'area' => 'settings', 'group' => 'Система', 'nav' => ['t'], 'count' => 1],
        ];
        $this->assertSame('n', admin_nav_current($items, 'newsletter-send'), 'sub-pages light up their parent');
        $this->assertNull(admin_nav_current($items, 'nothing'));
        $this->assertNull(admin_nav_current($items, null));
        $this->assertSame(4, admin_nav_settings_count($items), 'the Настройки button adds up what waits inside it');
    }

    public function test_initials(): void
    {
        $this->assertSame('МИ', admin_nav_initials('мария иванова'));
        $this->assertSame('М', admin_nav_initials('  Мария '));
        $this->assertSame('АБ', admin_nav_initials('Ана Белова Цонева'));
        $this->assertSame('?', admin_nav_initials(''));
    }

    public function test_icons_are_decorative(): void
    {
        $svg = admin_nav_icon('cart');
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertStringContainsString('focusable="false"', $svg);
        $this->assertStringContainsString('<path', admin_nav_icon('no-such-icon'), 'an unknown name still draws something');
    }

    // ── New version notice ───────────────────────────────────────────────────

    public function test_the_notice_shows_a_newer_version_to_an_administrator(): void
    {
        $this->install('0.17.0', '0.18.0');
        $this->as('admin');
        $this->assertSame('0.18.0', admin_nav_update_version());
        $updates = array_values(array_filter(admin_nav_items(), static fn($i) => $i['key'] === 'updates'))[0];
        $this->assertSame('ново', $updates['tag']);
    }

    public function test_an_old_saved_result_still_counts(): void
    {
        $this->install('0.17.0', '0.18.0', 30 * 86400);
        $this->as('admin');
        $this->assertSame('0.18.0', admin_nav_update_version());
        $this->assertTrue(updater_cache_is_stale(), 'and the page asks for a fresh check');
        $this->assertTrue(admin_nav_update_check_due());
    }

    public function test_no_notice_when_up_to_date_unchecked_or_not_allowed(): void
    {
        $this->install('0.18.0', '0.18.0');
        $this->as('admin');
        $this->assertNull(admin_nav_update_version(), 'up to date');
        $this->assertFalse(admin_nav_update_check_due(), 'checked a moment ago');

        $this->tearDown();
        $this->install('0.17.0', null);
        $this->as('admin');
        $this->assertNull(admin_nav_update_version(), 'never checked');
        $this->assertTrue(admin_nav_update_check_due());

        $this->tearDown();
        $this->install('0.17.0', '0.18.0');
        $this->as('author');
        $this->assertNull(admin_nav_update_version(), 'only administrators can update');
        $this->assertFalse(admin_nav_update_check_due());

        $this->as('admin');
        updater_set_self_update_override(false);
        $this->assertNull(admin_nav_update_version(), 'a site a developer looks after never offers it');
        $this->assertFalse(admin_nav_update_check_due());
    }

    public function test_a_broken_saved_result_is_no_notice(): void
    {
        $this->install('0.17.0', null);
        file_put_contents($this->root . '/logs/update-check-cache.json', '{broken');
        $this->as('admin');
        $this->assertNull(updater_cached_update());
        $this->assertTrue(updater_cache_is_stale());
    }

    // ── The page itself ──────────────────────────────────────────────────────

    public function test_the_admin_page_has_the_menu_top_bar_and_panels(): void
    {
        $page = run_admin_page('admin/orders.php', [], ['method' => 'GET', 'role' => 'admin']);
        $this->assertSame(200, $page['status']);
        $html = $page['body'];

        $this->assertMatchesRegularExpression('#<a href="/admin/orders.php"\s+class="admin-nav__link active" aria-current="page">#', $html);
        $this->assertStringContainsString('<h2 class="admin-nav__group">Магазин</h2>', $html);
        $this->assertMatchesRegularExpression('#<button type="button" class="admin-hamburger" id="adminHamburger" aria-label="Меню" aria-expanded="false" aria-controls="adminSidebar">#', $html);
        $this->assertMatchesRegularExpression('#data-admin-panel aria-expanded="false" aria-controls="adminPanelSettings">#', $html);
        $this->assertMatchesRegularExpression('#id="adminPanelSettings" aria-label="Настройки" hidden>#', $html, 'closed until asked for');
        $this->assertMatchesRegularExpression('#id="adminPanelAccount" hidden>#', $html);
        $this->assertStringContainsString('href="/admin/logout.php"', $html);
        $this->assertMatchesRegularExpression('#href="/admin/support.php\?from=[^"]*" class="admin-tb__btn">#', $html, 'Помощ remembers where you came from');
        $this->assertStringNotContainsString('Докладвай проблем', $html, 'one help link, not two');

        $sidebar = substr($html, strpos($html, '<aside'), strpos($html, '</aside>') - strpos($html, '<aside'));
        $this->assertStringNotContainsString('/admin/modules.php', $sidebar, 'setup pages are in the Настройки panel');
        $this->assertStringNotContainsString('/admin/logout.php', $sidebar);
    }

    public function test_an_author_gets_no_settings_button(): void
    {
        $page = run_admin_page('admin/articles.php', [], ['method' => 'GET', 'role' => 'author']);
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('adminPanelSettings', $page['body']);
        $this->assertStringContainsString('id="adminPanelAccount"', $page['body']);
    }

    public function test_a_settings_page_marks_the_settings_button(): void
    {
        $page = run_admin_page('admin/modules.php', [], ['method' => 'GET', 'role' => 'admin']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('admin-tb__settings is-current', $page['body']);
        $this->assertMatchesRegularExpression('#<a href="/admin/modules.php"\s+class="admin-nav__link active" aria-current="page">#', $page['body']);
    }

    // ── Background check endpoint ────────────────────────────────────────────

    public function test_the_check_endpoint_needs_an_administrator_and_a_token(): void
    {
        $this->assertSame(403, run_admin_page('admin/update-check-ajax.php', [], ['role' => 'author'])['status']);
        $this->assertSame(400, run_admin_page('admin/update-check-ajax.php', [], ['role' => 'admin', 'csrf' => false])['status']);
        $this->assertSame(400, run_admin_page('admin/update-check-ajax.php', [], ['role' => 'admin', 'method' => 'GET'])['status']);
    }
}
