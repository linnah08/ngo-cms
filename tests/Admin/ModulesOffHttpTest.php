<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each optional module switched off, over real HTTP: its admin menu links are
 * gone, its admin pages say „Този модул е изключен“, its public pages are the
 * site's 404 — and what must keep working with it off still does.
 *
 * Boots its own `php -S` server (as ModuleSwitchesHttpTest does) and writes the
 * switches straight into this checkout's content/organisation.json, which is
 * backed up and restored afterwards.
 */
#[Group('http')]
#[Group('admin')]
final class ModulesOffHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password   = 'ModulesOff_Test_42!';
    private static string $adminEmail = 'test.modules-off.admin@example.test';
    private static int $uid = 0;
    private static ?string $jar = null;

    private static string $orgFile;
    private static ?string $orgBackup = null;

    public static function setUpBeforeClass(): void
    {
        self::$root    = dirname(__DIR__, 2);
        self::$orgFile = self::$root . '/content/organisation.json';
        if (is_file(self::$orgFile)) self::$orgBackup = (string) file_get_contents(self::$orgFile);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_modoff_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_modoff_srv_err_');
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
        $pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::$adminEmail]);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Modules Off Test', self::$adminEmail, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$orgBackup !== null) {
            file_put_contents(self::$orgFile, self::$orgBackup);
        } elseif (isset(self::$orgFile)) {
            @unlink(self::$orgFile);
        }
        if (test_db_available() && self::$uid) {
            get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
        }
        if (self::$jar) @unlink(self::$jar);
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
        if (!test_db_available() || !self::$uid) $this->markTestSkipped('DB not available.');
    }

    protected function tearDown(): void
    {
        // Back to the site's own switches after every test.
        if (self::$orgBackup !== null) file_put_contents(self::$orgFile, self::$orgBackup);
        else @unlink(self::$orgFile);
    }

    /** Switch exactly these modules off (everything else on), keeping the rest of organisation.json. */
    private function switchOff(string ...$off): void
    {
        $data = self::$orgBackup !== null ? (json_decode(self::$orgBackup, true) ?: []) : [];
        foreach (array_keys(modules_registry()) as $name) {
            $data[module_field($name)] = in_array($name, $off, true) ? '0' : '1';
        }
        file_put_contents(self::$orgFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{0:int,1:string,2:string} [status, body, Location] */
    private function http(string $path, bool $asAdmin = false, ?array $post = null): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($asAdmin) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar());
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar());
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return [$code, substr($raw, $hs), $m[1] ?? ''];
    }

    private function jar(): string
    {
        if (self::$jar === null) {
            self::$jar = (string) tempnam(sys_get_temp_dir(), 'om_modoff_jar_');
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_COOKIEJAR => self::$jar, CURLOPT_COOKIEFILE => self::$jar,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['email' => self::$adminEmail, 'password' => self::$password]),
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        return self::$jar;
    }

    // ── Every module: menu, admin pages, public pages ────────────────────────

    public static function modules(): array
    {
        $out = [];
        foreach (array_keys(modules_registry()) as $name) $out[$name] = [$name];
        return $out;
    }

    #[DataProvider('modules')]
    public function test_a_switched_off_module_is_gone_from_the_admin_and_the_site(string $module): void
    {
        $m = modules_registry()[$module];
        $this->switchOff($module);

        [$code, $dash] = $this->http('/admin/dashboard.php', true);
        $this->assertSame(200, $code);
        foreach ($m['admin_pages'] as $page) {
            $this->assertStringNotContainsString('class="admin-nav__link', $this->navLinkTo($dash, $page), "$page is not in the menu");
            [$code, $body] = $this->http('/admin/' . $page, true);
            $this->assertSame(404, $code, $page);
            $this->assertStringContainsString('Този модул е изключен', $body, $page);
            $this->assertStringContainsString('„' . $m['label'] . '“', $body, $page);
        }
        foreach ($m['public_paths'] as $path) {
            if (!str_ends_with($path, '/')) continue;   // endpoints are covered by their own tests
            [$code, $body] = $this->http($path);
            $this->assertSame(404, $code, $path);
            $this->assertMatchesRegularExpression('/Страницата не е намерена|Page not found/u', $body, $path);
        }
        [$code, $home] = $this->http('/');
        $this->assertSame(200, $code, 'the home page still works');
        foreach ($m['public_paths'] as $path) {
            if (str_ends_with($path, '/')) $this->assertStringNotContainsString('href="' . $path . '"', $home, "home page links to $path");
        }
    }

    /** The menu link to an admin page, or '' when there is none. */
    private function navLinkTo(string $html, string $page): string
    {
        return preg_match('#<a href="/admin/' . preg_quote($page, '#') . '"\s+class="admin-nav__link[^"]*"#', $html, $m) ? $m[0] : '';
    }

    // ── comments_reviews ─────────────────────────────────────────────────────

    public function test_comments_page_is_contacts_only_when_comments_are_off(): void
    {
        $this->switchOff('comments_reviews');
        [$code, $body] = $this->http('/admin/comments.php?source=comments', true);
        $this->assertSame(200, $code, 'contact messages still have their page');
        $this->assertStringNotContainsString('Коментари към статии', $body);
        $this->assertMatchesRegularExpression('#<a href="/admin/comments.php"\s+class="admin-nav__link[^"]*">\s*Контакти#u', $body);

        [$code] = $this->http('/api/comment-submit.php', false, ['csrf_token' => 'x']);
        $this->assertSame(404, $code);
    }

    // ── social ───────────────────────────────────────────────────────────────

    public function test_article_editor_has_no_social_tab_when_social_is_off(): void
    {
        $this->switchOff('social');
        [$code, $body] = $this->http('/admin/article-edit.php?tab=social', true);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('tab=social', $body);
        $this->assertStringNotContainsString('>Социални мрежи</a>', $body);
        $this->assertStringNotContainsString('/admin/linkedin-ajax.php', $body);

        [, $pay] = $this->http('/admin/payment.php', true);
        $this->assertStringNotContainsString('bufferConnectBtn', $pay, 'Buffer settings step aside');

        [$code, $json] = $this->http('/admin/social-ajax.php', true, ['csrf_token' => 'x']);
        $this->assertSame(404, $code);
        $this->assertStringContainsString('Социални мрежи', (string) (json_decode($json, true)['error'] ?? ''));

        $this->switchOff();
        [, $on] = $this->http('/admin/article-edit.php', true);
        $this->assertStringContainsString('>Социални мрежи</a>', $on, 'back when on');
    }

    // ── ai_helpers ───────────────────────────────────────────────────────────

    public function test_no_translate_buttons_when_ai_helpers_is_off(): void
    {
        $this->switchOff('ai_helpers');
        [$code, $body] = $this->http('/admin/pages.php?page=about', true);
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('✦ Translate', $body);
        $this->assertStringNotContainsString("querySelectorAll('[data-translate-from]')", $body);
        $this->assertStringContainsString('window._aiHelpersOn = false;', $body);

        [$code, $json] = $this->http('/admin/translate-ajax.php', true, ['csrf_token' => 'x', 'text' => 'Здравей']);
        $this->assertSame(404, $code);
        $this->assertFalse(json_decode($json, true)['ok'] ?? null);

        $this->switchOff();
        [, $on] = $this->http('/admin/pages.php?page=about', true);
        $this->assertStringContainsString("querySelectorAll('[data-translate-from]')", $on, 'back when on');
    }
}
