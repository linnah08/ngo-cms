<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module switches in Admin → Модули over real HTTP: who may see and save
 * them, CSRF, that only registered module names with a boolean value are
 * written to content/organisation.json, and what switching a module off does —
 * menu item gone, admin page says so, public page 404s. Also the Организация
 * page's own fields (newsletter band), which share that file.
 *
 * Boots its own `php -S` server (like ProductEditNullFieldsHttpTest). The live
 * content/organisation.json of this checkout is backed up and restored.
 */
#[Group('http')]
#[Group('admin')]
final class ModuleSwitchesHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'ModuleSwitches_Test_42!';
    private static string $adminEmail  = 'test.modules.admin@example.test';
    private static string $authorEmail = 'test.modules.author@example.test';
    /** @var int[] */
    private static array $uids = [];

    private static string $orgFile;
    private static ?string $orgBackup = null;

    public static function setUpBeforeClass(): void
    {
        self::$root    = dirname(__DIR__, 2);
        self::$orgFile = self::$root . '/content/organisation.json';
        if (is_file(self::$orgFile)) self::$orgBackup = (string) file_get_contents(self::$orgFile);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_modules_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_modules_srv_err_');
        self::$serverLogs = [$outLog, $errLog];
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root],
            [1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']],
            $pipes,
            self::$root
        );
        if (is_resource($proc)) self::$serverProc = $proc;

        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) { self::$serverReady = true; break; }
            usleep(100_000);
        }

        if (!self::$serverReady || !test_db_available()) return;

        $pdo = get_pdo();
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException) {}
        $pdo->prepare('DELETE FROM admin_users WHERE email IN (?, ?)')->execute([self::$adminEmail, self::$authorEmail]);
        foreach ([[self::$adminEmail, 'admin'], [self::$authorEmail, 'author']] as [$email, $role]) {
            $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute(['Modules Test ' . $role, $email, password_hash(self::$password, PASSWORD_BCRYPT), $role]);
            self::$uids[] = (int) $pdo->lastInsertId();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$orgBackup !== null) {
            file_put_contents(self::$orgFile, self::$orgBackup);
        } elseif (isset(self::$orgFile)) {
            @unlink(self::$orgFile);
        }

        if (test_db_available() && self::$uids) {
            $in = implode(',', array_fill(0, count(self::$uids), '?'));
            get_pdo()->prepare("DELETE FROM admin_users WHERE id IN ($in)")->execute(self::$uids);
        }

        if (is_resource(self::$serverProc)) {
            $status = proc_get_status(self::$serverProc);
            proc_terminate(self::$serverProc, 9);
            for ($i = 0; $i < 20; $i++) {
                if (!proc_get_status(self::$serverProc)['running']) break;
                usleep(50_000);
            }
            if (!empty($status['pid'])) @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
        }
        foreach (self::$serverLogs as $log) @unlink($log);
    }

    protected function setUp(): void
    {
        if (!self::$serverReady) $this->markTestSkipped('Could not start local php -S test server.');
        if (!test_db_available() || count(self::$uids) !== 2) $this->markTestSkipped('DB not available.');
    }

    /** @return array{0:int,1:string,2:string} [status, body, Location] */
    private function http(string $path, ?string $jar = null, ?array $post = null): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return [$code, substr($raw, $hs), $m[1] ?? ''];
    }

    private function login(string $email): string
    {
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_modules_jar_');
        $this->http('/admin/login.php', $jar, ['email' => $email, 'password' => self::$password]);
        return $jar;
    }

    private function csrf(string $body): string
    {
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m), 'form carries a CSRF token');
        return $m[1];
    }

    private function validForm(string $csrf, array $over = []): array
    {
        return array_merge([
            'csrf_token'    => $csrf,
            'site_name_bg'  => 'Фондация Тест',
            'site_name_en'  => 'Test Foundation',
            'site_email'    => 'info@example.org',
            'brand_theme'   => array_key_first(brand_themes()),
            'brand_primary' => '#2D3A8C',
            'brand_accent'  => '#5B6FD6',
        ], $over);
    }

    /** Switch modules on/off directly in organisation.json (restored after the class). */
    private function setModules(array $on): void
    {
        $data = $this->saved();
        foreach ($on as $name => $state) $data['feature_' . $name] = $state ? '1' : '0';
        file_put_contents(self::$orgFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function saved(): array
    {
        clearstatcache();
        if (!is_file(self::$orgFile)) return [];
        return json_decode((string) file_get_contents(self::$orgFile), true) ?: [];
    }

    private function modulesForm(string $csrf, array $over = []): array
    {
        return array_merge(['csrf_token' => $csrf], $over);
    }

    public function test_anonymous_visitor_is_sent_to_login_and_cannot_save(): void
    {
        $before = $this->saved();
        [$code, , $loc] = $this->http('/admin/modules.php', null, $this->modulesForm('x', ['feature_donations' => '1']));
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
        $this->assertSame($before, $this->saved());
    }

    public function test_author_is_refused(): void
    {
        $jar = $this->login(self::$authorEmail);
        [$code] = $this->http('/admin/modules.php', $jar);
        $this->assertSame(403, $code);
        $before = $this->saved();
        [$code] = $this->http('/admin/modules.php', $jar, $this->modulesForm('x'));
        $this->assertSame(403, $code);
        $this->assertSame($before, $this->saved());
        [, $dash] = $this->http('/admin/dashboard.php', $jar);
        $this->assertStringNotContainsString('href="/admin/modules.php"', $dash, 'the menu item is for admins only');
        @unlink($jar);
    }

    public function test_admin_sees_a_labelled_switch_per_module_with_state_in_words(): void
    {
        $jar = $this->login(self::$adminEmail);
        [$code, $body] = $this->http('/admin/modules.php', $jar);
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('href="/admin/modules.php"', $body, 'Модули is in the admin menu');
        foreach (modules_registry() as $name => $m) {
            $this->assertMatchesRegularExpression(
                '/<label for="mod-' . $name . '"[^>]*>\s*<input type="checkbox" role="switch" id="mod-' . $name . '" name="feature_' . $name . '" value="1"/',
                $body
            );
            $this->assertStringContainsString('>' . $m['label'] . '</h2>', $body);
            $this->assertMatchesRegularExpression('/id="state-' . $name . '"[^>]*>\s*[✓✕] (Включен|Изключен)/u', $body);
        }
    }

    public function test_post_without_csrf_is_refused(): void
    {
        $jar = $this->login(self::$adminEmail);
        $before = $this->saved();
        [$code, $body] = $this->http('/admin/modules.php', $jar, $this->modulesForm('deadbeef'));
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Промените не бяха запазени.', $body);
        $this->assertSame($before, $this->saved());
    }

    public function test_switching_a_module_off_hides_it_everywhere_and_on_brings_it_back(): void
    {
        $jar = $this->login(self::$adminEmail);
        [, $page] = $this->http('/admin/modules.php', $jar);

        // Donations ticked, campaign unticked (absent); an unknown module is ignored.
        [$code, , $loc] = $this->http('/admin/modules.php', $jar,
            $this->modulesForm($this->csrf($page), ['feature_donations' => '1', 'feature_evil' => '1']));
        $this->assertSame(302, $code);
        $this->assertStringEndsWith('/admin/modules.php', $loc);

        $saved = $this->saved();
        $this->assertSame('1', $saved['feature_donations'] ?? null);
        $this->assertSame('0', $saved['feature_campaign'] ?? null);
        $this->assertArrayNotHasKey('feature_evil', $saved);

        [, $after] = $this->http('/admin/modules.php', $jar);
        $this->assertStringContainsString('Промените са запазени', $after, 'persistent success message after the redirect');
        $this->assertMatchesRegularExpression('/id="state-campaign"[^>]*>\s*✕ Изключен/u', $after);
        $this->assertMatchesRegularExpression('/id="state-donations"[^>]*>\s*✓ Включен/u', $after);
        $this->assertStringNotContainsString('href="/admin/campaign.php"', $after, 'menu item hidden');

        // The campaign's admin page says the module is off, inside the admin, with a way back.
        [$code, $off] = $this->http('/admin/campaign.php', $jar);
        $this->assertSame(404, $code);
        $this->assertStringContainsString('Този модул е изключен', $off);
        $this->assertStringContainsString('„Кампании“', $off);
        $this->assertStringContainsString('<a href="/admin/modules.php" class="btn btn--primary"', $off);

        // The public page does not exist.
        [$code, $pub] = $this->http('/campaign/');
        $this->assertSame(404, $code);
        $this->assertStringContainsString('Страницата не е намерена', $pub);

        // Saving Организация does not touch the switches.
        [, $org] = $this->http('/admin/organisation.php', $jar);
        [$code] = $this->http('/admin/organisation.php', $jar, $this->validForm($this->csrf($org)));
        $this->assertSame(302, $code);
        $this->assertSame('0', $this->saved()['feature_campaign'] ?? null);

        // On again: everything is back.
        [, $page] = $this->http('/admin/modules.php', $jar);
        $this->http('/admin/modules.php', $jar,
            $this->modulesForm($this->csrf($page), ['feature_donations' => '1', 'feature_campaign' => '1']));
        $this->assertSame('1', $this->saved()['feature_campaign'] ?? null);
        [, $dash] = $this->http('/admin/dashboard.php', $jar);
        $this->assertStringContainsString('href="/admin/campaign.php"', $dash);
        [, $back] = $this->http('/admin/campaign.php', $jar);
        $this->assertStringNotContainsString('Този модул е изключен', $back);
        @unlink($jar);
    }

    public function test_non_boolean_value_is_refused_and_nothing_is_saved(): void
    {
        $jar = $this->login(self::$adminEmail);
        [, $page] = $this->http('/admin/modules.php', $jar);
        $before = $this->saved();
        [$code, $body] = $this->http('/admin/modules.php', $jar,
            $this->modulesForm($this->csrf($page), ['feature_donations' => 'yes']));
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Невалидна стойност за „Дарения“', $body);
        $this->assertSame($before, $this->saved());
    }

    // ── Newsletter band colour (Визия) ──────────────────────────────────────

    public function test_admin_sees_the_newsletter_band_choice_as_labelled_radios_with_a_preview(): void
    {
        $this->setModules(['newsletter' => true]);   // the band belongs to Бюлетин
        $jar = $this->login(self::$adminEmail);
        [$code, $body] = $this->http('/admin/organisation.php', $jar);
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('<legend style="font-weight:600;margin-bottom:.25rem;padding:0;">Цвят на лентата за бюлетина</legend>', $body);
        foreach (newsletter_band_choices() as $value => $label) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*>\s*<input type="radio" name="newsletter_band" value="' . $value . '"[^>]*>\s*<span>' . preg_quote($label, '/') . '<\/span>/u',
                $body
            );
        }
        $this->assertMatchesRegularExpression('/<label id="f-newsletter_band_color" for="nlColor"[^>]*>Цвят на лентата\s*<input type="color" name="newsletter_band_color" id="nlColor"/u', $body);
        $this->assertStringContainsString('id="nlPreview"', $body);
        $this->assertMatchesRegularExpression('/id="nlReadable" role="status" aria-live="polite"[^>]*>.*Текстът се чете (добре|трудно)/su', $body);
    }

    public function test_a_saved_band_colour_reaches_the_public_page_with_contrasting_text(): void
    {
        $this->setModules(['newsletter' => true]);   // the band belongs to Бюлетин
        $jar = $this->login(self::$adminEmail);
        [, $page] = $this->http('/admin/organisation.php', $jar);
        [$code] = $this->http('/admin/organisation.php', $jar, $this->validForm($this->csrf($page), [
            'newsletter_band' => 'custom', 'newsletter_band_color' => '#fbb04a',
        ]));
        $this->assertSame(302, $code);
        $saved = $this->saved();
        $this->assertSame('custom', $saved['newsletter_band'] ?? null);
        $this->assertSame('#FBB04A', $saved['newsletter_band_color'] ?? null);

        [, $home] = $this->http('/kontakti/');
        $this->assertStringContainsString('--newsletter-bg:#FBB04A;', $home);
        $this->assertStringContainsString('--newsletter-fg:' . THEME_TEXT_DARK . ';', $home, 'a light band gets dark text');

        // A custom choice with no valid colour is refused, and nothing changes.
        [, $page] = $this->http('/admin/organisation.php', $jar);
        [$code, $body] = $this->http('/admin/organisation.php', $jar, $this->validForm($this->csrf($page), [
            'newsletter_band' => 'custom', 'newsletter_band_color' => 'red',
        ]));
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Моля, изберете цвят за лентата за бюлетина от палитрата.', $body);
        $this->assertSame('#FBB04A', $this->saved()['newsletter_band_color'] ?? null);
    }

    public function test_settings_of_switched_off_modules_are_hidden_and_kept(): void
    {
        $this->setModules(['newsletter' => true, 'donations' => true]);
        $jar = $this->login(self::$adminEmail);
        [, $page] = $this->http('/admin/organisation.php', $jar);
        $this->assertStringContainsString('Покана за дарение в бюлетина', $page);
        $this->http('/admin/organisation.php', $jar, $this->validForm($this->csrf($page), [
            'newsletter_band' => 'accent', 'newsletter_donate_heading_bg' => 'Помогнете ни',
        ]));
        $this->assertSame('accent', $this->saved()['newsletter_band'] ?? null);

        $this->setModules(['newsletter' => false, 'donations' => false]);
        [, $page] = $this->http('/admin/organisation.php', $jar);
        $this->assertStringNotContainsString('Цвят на лентата за бюлетина', $page);
        $this->assertStringNotContainsString('Покана за дарение в бюлетина', $page);
        $this->assertStringContainsString('<h2 class="admin-card__title">Банкова сметка</h2>', $page);
        [$code] = $this->http('/admin/organisation.php', $jar, $this->validForm($this->csrf($page)));
        $this->assertSame(302, $code);
        $this->assertSame('accent', $this->saved()['newsletter_band'] ?? null, 'saving the page does not wipe a hidden setting');
        $this->assertSame('Помогнете ни', $this->saved()['newsletter_donate_heading_bg'] ?? null);

        // The signature and manual certificates exist only for donations and campaigns.
        $this->setModules(['donations' => false, 'campaign' => false]);
        [$code] = $this->http('/admin/manual-cert.php', $jar);
        $this->assertSame(404, $code);
        [, $orders] = $this->http('/admin/orders.php', $jar);
        $this->assertStringNotContainsString('/admin/manual-cert.php', $orders);
        [, $templates] = $this->http('/admin/email-templates.php', $jar);
        $this->assertStringNotContainsString('Неуспешно плащане на дарение', $templates);
        @unlink($jar);
    }
}
