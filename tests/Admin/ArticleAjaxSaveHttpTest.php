<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saving an article in the background (admin/article-edit.php with _ajax=1) over real HTTP:
 * JSON reply with the slug, and publish_now publishing a draft in both languages.
 */
#[Group('http')]
#[Group('admin')]
final class ArticleAjaxSaveHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'Asave_Test_42!';
    private static string $email    = 'test.asave.admin@example.test';
    private static int $uid = 0;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_asave_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_asave_srv_err_');
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
        $pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::$email]);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Ajax Save Test', self::$email, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    /** @var string[] article slugs (BG and EN share them in these tests) to remove afterwards */
    private static array $made = [];

    public static function tearDownAfterClass(): void
    {
        foreach (array_unique(self::$made) as $s) {
            foreach (['bg', 'en'] as $l) @unlink(dirname(__DIR__, 2) . '/content/articles/' . $l . '/' . $s . '.json');
        }
        if (test_db_available() && self::$uid) {
            get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
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
        if (!test_db_available() || !self::$uid) $this->markTestSkipped('DB not available.');
    }

    /** @return array{0:int,1:string,2:string} [status, body, Location] */
    private function http(string $path, ?string $jar = null, array|string|null $post = null): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if (is_string($post)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($post !== null) {
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

    private function login(): array
    {
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_asave_jar_');
        $this->http('/admin/login.php', $jar, ['email' => self::$email, 'password' => self::$password]);
        [, $page] = $this->http('/admin/article-edit.php', $jar);
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $page, $m), 'editor carries a CSRF token');
        return [$jar, $m[1]];
    }

    private function save(string $jar, string $csrf, array $f, string $slug = ''): array
    {
        $path = '/admin/article-edit.php' . ($slug !== '' ? '?slug=' . urlencode($slug) : '');
        [$code, $out] = $this->http($path, $jar, $f + ['csrf_token' => $csrf, '_ajax' => '1']);
        $res = json_decode($out, true);
        if (is_array($res) && !empty($res['slug'])) { self::$made[] = $res['slug']; self::$made[] = $res['slug_en']; }
        return [$code, $res];
    }

    private function json_of(string $lang, string $slug): array
    {
        return json_decode((string) file_get_contents(self::$root . '/content/articles/' . $lang . '/' . $slug . '.json'), true);
    }

    public function testAjaxSaveReturnsJson(): void
    {
        [$jar, $csrf] = $this->login();
        [$code, $res] = $this->save($jar, $csrf, ['title' => 'asave-test-one', 'content' => '<p>A</p>', 'status' => 'draft']);
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertTrue($res['ok']);
        $this->assertSame('draft', $res['status']);
        $this->assertSame([], $res['warnings']);
        $this->assertFileExists(self::$root . '/content/articles/bg/' . $res['slug'] . '.json');
    }

    public function testAjaxSaveReturnsNewSlug(): void
    {
        [$jar, $csrf] = $this->login();
        [, $a] = $this->save($jar, $csrf, ['title' => 'asave-test-two', 'content' => '<p>A</p>', 'status' => 'draft']);
        [, $b] = $this->save($jar, $csrf, ['title' => 'asave-test-two', 'slug' => 'asave-test-two-renamed',
            'content' => '<p>A</p>', 'status' => 'draft'], $a['slug']);
        @unlink($jar);
        $this->assertTrue($b['ok']);
        $this->assertSame('asave-test-two-renamed', $b['slug']);
        $this->assertFileExists(self::$root . '/content/articles/bg/asave-test-two-renamed.json');
    }

    public function testPublishNowPublishesDraft(): void
    {
        [$jar, $csrf] = $this->login();
        [, $res] = $this->save($jar, $csrf, [
            'title' => 'asave-test-three', 'title_en' => 'asave-test-three-en', 'content' => '<p>A</p>', 'content_en' => '<p>B</p>',
            'status' => 'draft', 'scheduled' => '1', 'publish_now' => '1',
        ]);
        @unlink($jar);
        $this->assertSame('published', $res['status']);
        $bg = $this->json_of('bg', $res['slug']);
        $en = $this->json_of('en', $res['slug_en']);
        $this->assertSame('published', $bg['status']);
        $this->assertSame('published', $en['status']);
        $this->assertFalse($bg['scheduled']);
        $this->assertFalse($en['scheduled']);
    }

    public function testMissingTitleIsJsonErrorWithoutRedirect(): void
    {
        [$jar, $csrf] = $this->login();
        [$code, $out, $loc] = $this->http('/admin/article-edit.php', $jar, ['csrf_token' => $csrf, '_ajax' => '1', 'title' => '']);
        @unlink($jar);
        $res = json_decode($out, true);
        $this->assertFalse($res['ok']);
        $this->assertNotSame('', $res['error']);
        $this->assertSame('', $loc);
    }

    public function testBadCsrfIsJson400(): void
    {
        [$jar] = $this->login();
        [$code, $out] = $this->http('/admin/article-edit.php', $jar, ['csrf_token' => 'bad', '_ajax' => '1', 'title' => 'x']);
        @unlink($jar);
        $this->assertSame(400, $code);
        $this->assertSame('Сесията е изтекла — презаредете страницата.', json_decode($out, true)['error']);
    }
}
