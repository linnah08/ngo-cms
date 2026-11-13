<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The social preview endpoint (admin/social-preview-ajax.php) over real HTTP: login and
 * CSRF are required, it builds the three posts from the editor's unsaved text, and it
 * refuses an oversized body. Also the schedule endpoints' dry run (`check`), which the
 * panel calls with the same unsaved text before it saves and publishes the article.
 */
#[Group('http')]
#[Group('admin')]
final class SocialPreviewHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'Preview_Test_42!';
    private static string $email    = 'test.preview.admin@example.test';
    private static int $uid = 0;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_prev_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_prev_srv_err_');
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
            ->execute(['Preview Test', self::$email, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
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
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_prev_jar_');
        $this->http('/admin/login.php', $jar, ['email' => self::$email, 'password' => self::$password]);
        [, $page] = $this->http('/admin/article-edit.php', $jar);
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $page, $m), 'editor carries a CSRF token');
        return [$jar, $m[1]];
    }

    private function preview(?string $jar, array $body): array
    {
        [$code, $out, $loc] = $this->http('/admin/social-preview-ajax.php', $jar, json_encode($body));
        return [$code, json_decode($out, true), $loc];
    }

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        [$code, $res, $loc] = $this->preview(null, ['csrf_token' => 'x', 'title' => 'T', 'content' => '<p>A</p>']);
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
        $this->assertNotTrue($res['ok'] ?? false);
    }

    public function test_missing_or_wrong_csrf_is_refused(): void
    {
        [$jar] = $this->login();
        [, $none] = $this->preview($jar, ['title' => 'T', 'content' => '<p>A</p>']);
        [, $bad]  = $this->preview($jar, ['csrf_token' => 'deadbeef', 'title' => 'T', 'content' => '<p>A</p>']);
        @unlink($jar);
        $this->assertFalse($none['ok']);
        $this->assertFalse($bad['ok']);
    }

    public function test_unsaved_article_gets_its_posts_from_the_title(): void
    {
        [$jar, $csrf] = $this->login();
        [$code, $res] = $this->preview($jar, [
            'csrf_token' => $csrf, 'title' => 'Нова статия', 'content' => '<p>Текст.</p>',
            'title_en' => '', 'content_en' => '', 'slug' => '', 'slug_en' => '',
        ]);
        @unlink($jar);
        require_once self::$root . '/includes/articles.php';
        $slugs = article_compute_slugs('', '', 'Нова статия', '', '', article_slugs_latin());
        $this->assertSame(200, $code);
        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('Нова статия', $res['fb']['text']);
        $this->assertStringContainsString('Текст.', $res['fb']['text']);
        $this->assertStringContainsString(article_public_url($slugs['bg'], 'bg'), $res['fb']['text']);
        $this->assertStringContainsString(article_public_url($slugs['bg'], 'bg'), $res['insta']['text']);
        $this->assertSame(['text' => '', 'length' => 0, 'limit' => 3000, 'cut' => false], $res['linkedin'], 'no English body, no LinkedIn post');
        $this->assertFalse($res['fb']['cut']);
        $this->assertSame(social_text_length($res['fb']['text']), $res['fb']['length']);
    }

    public function test_linkedin_uses_the_english_text_and_url(): void
    {
        [$jar, $csrf] = $this->login();
        [, $res] = $this->preview($jar, [
            'csrf_token' => $csrf, 'title' => 'Заглавие', 'content' => '<p>Текст.</p>',
            'title_en' => 'Headline', 'content_en' => '<p>Body.</p>', 'slug' => '', 'slug_en' => 'my-en-slug',
        ]);
        @unlink($jar);
        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('Headline', $res['linkedin']['text']);
        $this->assertStringContainsString('/en/news/my-en-slug/', $res['linkedin']['text']);
        $this->assertStringNotContainsString('Заглавие', $res['linkedin']['text']);
    }

    public function test_oversized_input_is_refused_in_plain_words(): void
    {
        [$jar, $csrf] = $this->login();
        [, $res] = $this->preview($jar, ['csrf_token' => $csrf, 'title' => 'T', 'content' => str_repeat('a', 600 * 1024)]);
        @unlink($jar);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('твърде дълъг', $res['error']);
    }

    public function test_linkedin_takes_the_bulgarian_title_when_the_english_one_is_empty(): void
    {
        // The save stores an empty EN title as the BG one, so the post gets it too.
        [$jar, $csrf] = $this->login();
        [, $res] = $this->preview($jar, [
            'csrf_token' => $csrf, 'title' => 'Заглавие', 'content' => '<p>Текст.</p>',
            'title_en' => '', 'content_en' => '<p>Body.</p>', 'slug' => '', 'slug_en' => 'en-x',
        ]);
        @unlink($jar);
        $this->assertTrue($res['ok']);
        $this->assertStringStartsWith("Заглавие\n\nBody.", $res['linkedin']['text']);
    }

    // ── The dry run before scheduling ────────────────────────────────────────

    private function check(?string $jar, string $endpoint, array $body): array
    {
        [$code, $out, $loc] = $this->http('/admin/' . $endpoint, $jar, json_encode($body + ['action' => 'check']));
        return [$code, json_decode($out, true), $loc];
    }

    /** @return string[] the article files, to show the dry run writes none */
    private function articleFiles(): array
    {
        return array_merge(glob(self::$root . '/content/articles/bg/*.json') ?: [], glob(self::$root . '/content/articles/en/*.json') ?: []);
    }

    public function test_check_needs_login_and_csrf(): void
    {
        [$code, , $loc] = $this->check(null, 'social-ajax.php', ['csrf_token' => 'x', 'channel' => 'fb', 'scheduled_at' => 'now']);
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
        [$jar] = $this->login();
        [, $res] = $this->check($jar, 'linkedin-ajax.php', ['csrf_token' => 'deadbeef', 'scheduled_at' => 'now']);
        @unlink($jar);
        $this->assertFalse($res['ok']);
    }

    public function test_check_refuses_in_plain_words_for_a_new_article_and_writes_nothing(): void
    {
        [$jar, $csrf] = $this->login();
        $before = $this->articleFiles();
        $unsaved = ['csrf_token' => $csrf, 'title' => 'Нова', 'content' => '<p>Текст.</p>', 'photos' => []];
        [, $past]    = $this->check($jar, 'social-ajax.php', $unsaved + ['channel' => 'fb', 'scheduled_at' => '2001-01-01T10:00']);
        [, $noTime]  = $this->check($jar, 'social-ajax.php', $unsaved + ['channel' => 'insta', 'scheduled_at' => '']);
        [, $badCh]   = $this->check($jar, 'social-ajax.php', $unsaved + ['channel' => 'linkedin', 'scheduled_at' => 'now']);
        [, $liPast]  = $this->check($jar, 'linkedin-ajax.php', $unsaved + ['title_en' => '', 'content_en' => '<p>Body.</p>', 'scheduled_at' => '2001-01-01T10:00']);
        [, $badType] = $this->check($jar, 'social-ajax.php', ['csrf_token' => $csrf, 'channel' => 'fb', 'scheduled_at' => ['x'], 'title' => ['x'], 'photos' => 'x']);
        @unlink($jar);
        $this->assertSame(['ok' => false, 'error' => 'Датата трябва да е в бъдещето.'], $past);
        $this->assertSame(['ok' => false, 'error' => 'Изберете дата и час.'], $noTime);
        $this->assertSame(['ok' => false, 'error' => 'Невалиден канал.'], $badCh, 'LinkedIn goes through its own endpoint');
        $this->assertSame(['ok' => false, 'error' => 'Датата трябва да е в бъдещето.'], $liPast);
        $this->assertSame(['ok' => false, 'error' => 'Изберете дата и час.'], $badType, 'wrong types are refused, not a server error');
        $this->assertSame($before, $this->articleFiles(), 'the dry run saves no article');
    }
}
