<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 2) . '/includes/menus.php';

/**
 * Admin → Менюта over real HTTP: who may save, CSRF, the saved shape (BG and
 * EN lists of equal length, same order) and the refused save of a row with no
 * Bulgarian text. The live content/menus.json is backed up and restored.
 */
#[Group('http')]
#[Group('admin')]
final class MenusHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'Menus_Test_42!';
    private static string $adminEmail  = 'test.menus.admin@example.test';
    private static string $authorEmail = 'test.menus.author@example.test';
    /** @var int[] */
    private static array $uids = [];

    private static string $menusFile;
    private static ?string $menusBackup = null;

    public static function setUpBeforeClass(): void
    {
        self::$root    = dirname(__DIR__, 2);
        self::$menusFile = self::$root . '/content/menus.json';
        if (is_file(self::$menusFile)) self::$menusBackup = (string) file_get_contents(self::$menusFile);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_menus_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_menus_srv_err_');
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
                ->execute(['Menus Test ' . $role, $email, password_hash(self::$password, PASSWORD_BCRYPT), $role]);
            self::$uids[] = (int) $pdo->lastInsertId();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$menusBackup !== null) {
            file_put_contents(self::$menusFile, self::$menusBackup);
        } elseif (isset(self::$menusFile)) {
            @unlink(self::$menusFile);
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
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_menus_jar_');
        $this->http('/admin/login.php', $jar, ['email' => $email, 'password' => self::$password]);
        return $jar;
    }

    private function csrf(string $body): string
    {
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $body, $m), 'form carries a CSRF token');
        return $m[1];
    }

    private function saved(): array
    {
        clearstatcache();
        if (!is_file(self::$menusFile)) return [];
        return json_decode((string) file_get_contents(self::$menusFile), true) ?: [];
    }

    private function form(string $csrf, array $rows): array
    {
        $f = ['csrf_token' => $csrf, 'section' => 'footer_help', 'label_bg' => [], 'url_bg' => [], 'label_en' => [], 'url_en' => []];
        foreach ($rows as $r) {
            foreach (['label_bg', 'url_bg', 'label_en', 'url_en'] as $k) $f[$k][] = $r[$k] ?? '';
        }
        return $f;
    }

    public function test_anonymous_visitor_is_sent_to_login_and_cannot_save(): void
    {
        $before = $this->saved();
        [$code, , $loc] = $this->http('/admin/menus.php', null, $this->form('x', [['label_bg' => 'А', 'url_bg' => '/a/']]));
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
        $this->assertSame($before, $this->saved());
    }

    public function test_author_is_refused(): void
    {
        $jar = $this->login(self::$authorEmail);
        $before = $this->saved();
        [$code] = $this->http('/admin/menus.php', $jar, $this->form('x', [['label_bg' => 'А', 'url_bg' => '/a/']]));
        @unlink($jar);
        $this->assertSame(403, $code);
        $this->assertSame($before, $this->saved());
    }

    public function test_post_without_valid_csrf_is_refused(): void
    {
        $jar = $this->login(self::$adminEmail);
        $before = $this->saved();
        [$code, $body] = $this->http('/admin/menus.php', $jar, $this->form('deadbeef', [['label_bg' => 'А', 'url_bg' => '/a/']]));
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Менюто не беше запазено', $body);
        $this->assertSame($before, $this->saved());
    }

    public function test_admin_sees_labelled_pair_fields(): void
    {
        $jar = $this->login(self::$adminEmail);
        [$code, $body] = $this->http('/admin/menus.php', $jar);
        @unlink($jar);
        $this->assertSame(200, $code);
        foreach (['ml-bg' => 'Текст (BG)', 'mu-bg' => 'Адрес (BG)', 'ml-en' => 'Текст (EN)', 'mu-en' => 'Адрес (EN)'] as $id => $text) {
            $this->assertMatchesRegularExpression('#<label for="' . $id . '-__UID__"[^>]*>' . preg_quote($text, '#') . '</label>#u', $body);
        }
        $this->assertStringContainsString('role="status" aria-live="polite"', $body);
    }

    public function test_save_writes_mirrored_lists_and_refuses_a_row_without_bulgarian_text(): void
    {
        $jar = $this->login(self::$adminEmail);
        [, $page] = $this->http('/admin/menus.php', $jar);
        $csrf = $this->csrf($page);
        $before = $this->saved();

        // A row with only English text: refused, nothing written, persistent field error.
        [$code, $body] = $this->http('/admin/menus.php', $jar, $this->form($csrf, [
            ['label_bg' => 'Контакти', 'url_bg' => '/kontakti/'],
            ['label_bg' => '', 'url_bg' => '', 'label_en' => 'Press', 'url_en' => '/en/press/'],
        ]));
        $this->assertSame(200, $code);
        $this->assertSame($before, $this->saved());
        $this->assertStringContainsString('не е запазено — поправете отбелязаните редове', $body);
        $this->assertStringContainsString('Попълнете текста на български или премахнете реда.', $body);
        $this->assertMatchesRegularExpression('#name="label_bg\[\]" value=""[^>]*aria-invalid="true"#', $body);
        $this->assertStringContainsString('value="Press"', $body, 'the English text comes back, not lost');

        [$code] = $this->http('/admin/menus.php', $jar, $this->form($this->csrf($body), [
            ['label_bg' => 'Контакти', 'url_bg' => '/kontakti/'],
            ['label_bg' => 'Новини', 'url_bg' => '/novini/', 'label_en' => 'Latest', 'url_en' => ''],
        ]));
        @unlink($jar);
        $this->assertSame(200, $code);
        $saved = $this->saved()['footer_help'] ?? [];
        $this->assertSame([['url' => '/kontakti/', 'label' => 'Контакти'], ['url' => '/novini/', 'label' => 'Новини']], $saved['bg'] ?? null);
        $this->assertSame([['url' => '/en/contacts/', 'label' => menu_known_label_en('Контакти')], ['url' => '/en/news/', 'label' => 'Latest']], $saved['en'] ?? null);
    }
}
