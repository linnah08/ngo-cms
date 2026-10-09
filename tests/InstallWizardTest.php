<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The install wizard's rules (install/wizard-lib.php): one step at a time, only
 * what a site can't start without is required, and every error is tied to the
 * field it is about.
 */
final class InstallWizardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/install/wizard-lib.php';
    }

    private static function finished(): array
    {
        return [
            'db'      => ['db_mode' => 'create'],
            'account' => ['admin_name' => '', 'admin_email' => 'me@example.org', 'password_hash' => password_hash('secret123', PASSWORD_DEFAULT)],
            'org'     => ['site_name_bg' => 'Фондация Пример', 'site_name_en' => '', 'site_url' => 'https://example.org', 'site_email' => 'info@example.org'],
            'look'    => ['brand_theme' => 'classic', 'brand_primary' => '#123456', 'brand_accent' => '#654321'],
            'modules' => ['modules' => []],
        ];
    }

    public function test_steps_open_in_order_and_later_ones_wait(): void
    {
        $this->assertSame(['db', 'account', 'org', 'look', 'modules', 'review'], array_keys(wizard_steps()));
        $this->assertSame('db', wizard_first_open_step([]));
        $this->assertFalse(wizard_step_reachable('org', ['db' => []]), 'a step can not be skipped by typing its address');
        $this->assertTrue(wizard_step_reachable('account', ['db' => []]));
        $this->assertTrue(wizard_step_reachable('db', ['db' => [], 'account' => []]), 'earlier steps stay open for changes');
        $this->assertSame('review', wizard_first_open_step(self::finished()));
        $this->assertFalse(wizard_step_reachable('nope', []));
    }

    public function test_on_cpanel_the_database_step_needs_nothing_typed(): void
    {
        $r = wizard_validate_db([], true, fn() => $this->fail('nothing to connect to yet'));
        $this->assertSame(['db_mode' => 'create'], $r['data']);
        $this->assertSame([], $r['errors']);
    }

    public function test_without_cpanel_the_hosts_details_are_required_and_tried(): void
    {
        $r = wizard_validate_db(['db_mode' => 'create'], false);
        $this->assertSame('existing', $r['data']['db_mode'], 'create is impossible without cPanel, whatever is posted');
        $this->assertArrayHasKey('db_name', $r['errors']);
        $this->assertArrayHasKey('db_user', $r['errors']);
        $this->assertArrayNotHasKey('db_pass', $r['errors'], 'an empty password is allowed — some hosts have none');

        $tried = null;
        $r = wizard_validate_db(['db_name' => 'acc_site', 'db_user' => 'acc_site', 'db_pass' => 'x'], false,
            function (...$args) use (&$tried) { $tried = $args; return 'Access denied'; });
        $this->assertSame(['localhost', 'acc_site', 'acc_site', 'x'], $tried, 'host defaults to localhost');
        $this->assertArrayHasKey('db_pass', $r['errors'], 'a refused connection is reported at step 1');
        $this->assertStringNotContainsString('Access denied', $r['errors']['db_pass'], 'no raw server error in the message');

        $r = wizard_validate_db(['db_name' => 'a', 'db_user' => 'b'], false, fn() => null);
        $this->assertSame([], $r['errors']);
    }

    public function test_account_needs_an_email_and_a_password_of_eight(): void
    {
        $r = wizard_validate_account([]);
        $this->assertSame(['admin_email', 'admin_password'], array_keys($r['errors']));
        $this->assertArrayHasKey('admin_email', wizard_validate_account(['admin_email' => 'nope', 'admin_password' => 'longenough'])['errors']);
        $this->assertArrayHasKey('admin_password', wizard_validate_account(['admin_email' => 'a@b.bg', 'admin_password' => 'short'])['errors']);

        $r = wizard_validate_account(['admin_email' => 'a@b.bg', 'admin_password' => '  longenough  ']);
        $this->assertSame([], $r['errors']);
        $this->assertTrue(password_verify('longenough', $r['data']['password_hash']), 'trimmed like the login form');
        $this->assertArrayNotHasKey('admin_password', $r['data'], 'the password is never kept in plain text');
        $this->assertSame('', $r['data']['admin_name'], 'the name is optional');
    }

    public function test_coming_back_to_the_account_step_keeps_the_password(): void
    {
        $r = wizard_validate_account(['admin_email' => 'a@b.bg', 'admin_password' => ''], 'earlier-hash');
        $this->assertSame([], $r['errors']);
        $this->assertSame('earlier-hash', $r['data']['password_hash']);
    }

    public function test_organisation_asks_only_for_name_address_and_email(): void
    {
        $r = wizard_validate_org([]);
        $this->assertSame(['site_name_bg', 'site_url', 'site_email'], array_keys($r['errors']), 'the English name is optional');
        $this->assertArrayHasKey('site_url', wizard_validate_org(['site_url' => 'ftp://example.org'])['errors']);
        $r = wizard_validate_org(['site_name_bg' => 'Пример', 'site_url' => 'https://example.org/', 'site_email' => 'info@example.org']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('https://example.org', $r['data']['site_url'], 'no trailing slash');
    }

    public function test_look_is_optional_and_never_stores_anything_odd(): void
    {
        $r = wizard_validate_look(['brand_theme' => '<script>', 'brand_primary' => 'red', 'brand_accent' => '#abcdef']);
        $this->assertSame([], $r['errors']);
        $this->assertSame('classic', $r['data']['brand_theme']);
        $this->assertSame(brand_themes()['classic']['primary'], $r['data']['brand_primary']);
        $this->assertSame('#abcdef', $r['data']['brand_accent']);
    }

    public function test_modules_keep_only_known_names(): void
    {
        $first = array_key_first(modules_registry());
        $r = wizard_validate_modules(['modules' => [$first, 'not-a-module', ['x']]]);
        $this->assertSame([$first], $r['data']['modules']);
        $this->assertSame([], wizard_validate_modules([])['errors'], 'choosing nothing is fine');
    }

    public function test_a_module_without_what_it_needs_is_refused(): void
    {
        foreach (modules_registry() as $name => $m) {
            if ($m['needs']) {
                $r = wizard_validate_modules(['modules' => [$name]]);
                $this->assertArrayHasKey('modules', $r['errors']);
                $this->assertSame([], wizard_validate_modules(['modules' => array_merge([$name], $m['needs'])])['errors']);
                return;
            }
        }
        $this->markTestSkipped('no module needs another one');
    }

    public function test_a_new_cpanel_database_gets_a_free_name(): void
    {
        $this->assertSame('acc_site', wizard_pick_db_name('acc', []));
        $this->assertSame('acc_site3', wizard_pick_db_name('acc', ['acc_site', 'acc_site2', 'other_site3']));
    }

    public function test_site_config_fills_what_the_wizard_no_longer_asks(): void
    {
        $c = wizard_site_config(self::finished());
        $this->assertSame('Фондация Пример', $c['SITE_NAME_EN'], 'an empty English name takes the Bulgarian one');
        foreach (['SITE_LEGAL_NAME_BG', 'SITE_PHONE', 'SITE_IBAN', 'SITE_BIC', 'SITE_BANK_NAME'] as $k) {
            $this->assertSame('', $c[$k], "$k is filled in later in Админ → Организация");
        }
        $this->assertSame('info@example.org', $c['SITE_EMAIL']);
        $this->assertSame('me@example.org', $c['SIGNING_ADMIN_EMAIL']);
        foreach (array_keys(modules_registry()) as $m) {
            $this->assertFalse($c['FEATURE_' . strtoupper($m)], 'every module gets an explicit value');
        }
    }

    public function test_every_field_says_whether_it_is_required(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/install/index.php');
        $this->assertStringContainsString("'(задължително)' : '(по желание)'", $src);
        $this->assertStringContainsString('aria-describedby', $src);
        $this->assertStringContainsString('id="error-summary"', $src);
        $this->assertStringContainsString('aria-current="step"', $src);
        // Every text input goes through $field(), which writes the marker.
        $this->assertDoesNotMatchRegularExpression('/<input type="(text|email|url|password)"/', $src);
    }

    public function test_the_wizard_removes_itself_but_never_from_a_git_checkout(): void
    {
        $site = sys_get_temp_dir() . '/wiz-' . bin2hex(random_bytes(4));
        mkdir($site . '/install', 0777, true);
        touch($site . '/install/index.php');
        touch($site . '/install/.htaccess');
        $this->assertTrue(wizard_remove_installer($site . '/install'));
        $this->assertDirectoryDoesNotExist($site . '/install');

        mkdir($site . '/install');
        touch($site . '/install/index.php');
        touch($site . '/.git');   // a worktree's .git is a file
        $this->assertFalse(wizard_remove_installer($site . '/install'), 'tracked files stay in a git checkout');
        $this->assertFileExists($site . '/install/index.php');
        $this->assertFalse(wizard_remove_installer($site), 'only ever a folder called install');
        exec('rm -rf ' . escapeshellarg($site));
    }

    public function test_nobody_is_asked_to_delete_files_or_change_permissions(): void
    {
        foreach (['index.php', 'install-run.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/install/' . $f);
            $this->assertStringNotContainsString('File Manager', $src, $f);
            $this->assertStringNotContainsString('изтрийте', $src, $f);
            $this->assertStringNotContainsString('755', $src, $f);
        }
    }
}
