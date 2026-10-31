<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A site's own links in the admin menu (includes/site_extensions.php 'admin_nav'). */
final class SiteAdminNavTest extends TestCase
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

    public function test_without_a_site_file_there_are_no_extra_links(): void
    {
        $GLOBALS['_om_site_extensions_file'] = '/nonexistent/site-extensions.php';
        $this->assertSame([], site_admin_nav());
        $this->assertSame([], site_admin_nav('admin'));
    }

    public function test_links_are_checked_and_shown_to_the_roles_they_name(): void
    {
        $this->site('<?php return ["admin_nav" => [
            "quiz"     => ["label" => "Тест", "href" => "/admin/site/quiz.php?tab=1", "roles" => ["admin", "author"], "active_nav" => "quiz-page"],
            "report"   => ["label" => "Отчет", "href" => "/partner/report/"],
            "external" => ["label" => "X", "href" => "https://evil.example/"],
            "protocol" => ["label" => "X", "href" => "//evil.example/"],
            "slash"    => ["label" => "X", "href" => "/\\\\evil.example/"],
            "script"   => ["label" => "X", "href" => "javascript:alert(1)"],
            "nolabel"  => ["href" => "/x/"],
            "Bad Key"  => ["label" => "X", "href" => "/x/"],
            "badactive"=> ["label" => "Y", "href" => "/y/", "active_nav" => "<b>"],
            "flat"     => "not an array",
        ]];');
        $all = site_admin_nav();
        $this->assertSame(['quiz', 'report', 'badactive'], array_keys($all), 'other hosts, scripts, missing labels and bad keys are skipped');
        $this->assertSame('/admin/site/quiz.php?tab=1', $all['quiz']['href']);
        $this->assertSame('quiz-page', $all['quiz']['active_nav']);
        $this->assertSame('report', $all['report']['active_nav'], 'active_nav defaults to the key');
        $this->assertSame('badactive', $all['badactive']['active_nav']);
        $this->assertSame(['admin'], $all['report']['roles'], 'administrators only unless the site says otherwise');

        $this->assertSame(['quiz', 'report', 'badactive'], array_keys(site_admin_nav('admin')));
        $this->assertSame(['quiz'], array_keys(site_admin_nav('author')));
        $this->assertSame([], site_admin_nav('shop_admin'));
    }

    public function test_the_admin_menu_renders_them_escaped_and_loads_the_helpers_itself(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/includes/admin-nav.php');
        $this->assertStringContainsString("includes/site_extensions.php", $nav, 'a site that kept an older config.php still gets the helpers');

        $f = tempnam(sys_get_temp_dir(), 'ext') . '.php';
        self::$tmp[] = $f;
        file_put_contents($f, '<?php return ["admin_nav" => [
            "quiz" => ["label" => "Тест <b>", "href" => "/admin/site/quiz.php?a=1&b=2", "roles" => ["admin"], "active_nav" => "quiz-page"],
        ]];');
        $page = run_admin_page('admin/dashboard.php', [], ['method' => 'GET', 'globals' => ['_om_site_extensions_file' => $f]]);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('href="/admin/site/quiz.php?a=1&amp;b=2"', $page['body']);
        $this->assertStringContainsString('<span>Тест &lt;b&gt;</span>', $page['body']);
        $this->assertStringContainsString('<h2 class="admin-nav__group">Още</h2>', $page['body'], 'in the side menu, under its own heading');
        $this->assertLessThan(strpos($page['body'], 'id="adminPanelSettings"'), strpos($page['body'], 'quiz.php'), 'in the side menu, not the Настройки panel');
    }
}
