<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saving an Instagram crop (admin/social-ajax.php, action 'save_crop') over real HTTP:
 * login and CSRF are required, only the post's own photos and the two kinds are
 * accepted, and a good crop lands on the BG article as fractions with its shape.
 */
#[Group('http')]
#[Group('admin')]
final class SocialCropHttpTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    private static bool $serverReady = false;
    /** @var string[] */
    private static array $serverLogs = [];

    private static string $password = 'Crop_Test_42!';
    private static string $email    = 'test.crop.admin@example.test';
    private static int $uid = 0;

    private static string $slug;
    private static string $file;
    private static string $imgDir;
    private static string $src;

    public static function setUpBeforeClass(): void
    {
        self::$root   = dirname(__DIR__, 2);
        self::$slug   = '_test-crop-' . bin2hex(random_bytes(3));
        self::$file   = self::$root . '/content/articles/bg/' . self::$slug . '.json';
        self::$imgDir = self::$root . '/assets/images/articles/' . self::$slug;
        self::$src    = '/assets/images/articles/' . self::$slug . '/tall.jpg';
        @mkdir(self::$imgDir, 0755, true);
        $im = imagecreatetruecolor(90, 200);
        imagejpeg($im, self::$imgDir . '/tall.jpg');
        imagedestroy($im);
        @mkdir(dirname(self::$file), 0755, true);
        file_put_contents(self::$file, json_encode([
            'title' => 'Crop test', 'slug' => self::$slug, 'status' => 'draft', 'date' => '2026-10-06',
            'image' => self::$src, 'photos' => [['src' => self::$src, 'caption' => '']], 'content' => '',
        ]));

        $port       = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";
        $outLog = tempnam(sys_get_temp_dir(), 'om_crop_srv_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'om_crop_srv_err_');
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
            ->execute(['Crop Test', self::$email, password_hash(self::$password, PASSWORD_BCRYPT), 'admin']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$file);
        array_map('unlink', glob(self::$imgDir . '/*') ?: []);
        @rmdir(self::$imgDir);

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

    /** @return array{0:string,1:string} [cookie jar, CSRF token] */
    private function login(): array
    {
        $jar = (string) tempnam(sys_get_temp_dir(), 'om_crop_jar_');
        $this->http('/admin/login.php', $jar, ['email' => self::$email, 'password' => self::$password]);
        [, $page] = $this->http('/admin/article-edit.php?slug=' . rawurlencode(self::$slug), $jar);
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $page, $m), 'editor carries a CSRF token');
        return [$jar, $m[1]];
    }

    private function save(?string $jar, array $body): array
    {
        [$code, $out, $loc] = $this->http('/admin/social-ajax.php', $jar, json_encode(['action' => 'save_crop', 'slug' => self::$slug] + $body));
        return [$code, json_decode($out, true), $loc];
    }

    private function stored(): array
    {
        clearstatcache();
        return json_decode((string) file_get_contents(self::$file), true) ?: [];
    }

    private function rect(): array
    {
        return ['x' => 0, 'y' => 0.3, 'w' => 1, 'h' => 0.5625, 'ar' => 0.8];
    }

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        [$code, , $loc] = $this->save(null, ['csrf_token' => 'x', 'kind' => 'post', 'src' => self::$src, 'rect' => $this->rect()]);
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
        $this->assertArrayNotHasKey('insta_crops', $this->stored());
    }

    public function test_missing_or_wrong_csrf_is_refused(): void
    {
        [$jar] = $this->login();
        [, $noToken]  = $this->save($jar, ['kind' => 'post', 'src' => self::$src, 'rect' => $this->rect()]);
        [, $badToken] = $this->save($jar, ['csrf_token' => 'deadbeef', 'kind' => 'post', 'src' => self::$src, 'rect' => $this->rect()]);
        @unlink($jar);
        $this->assertFalse($noToken['ok']);
        $this->assertFalse($badToken['ok']);
        $this->assertArrayNotHasKey('insta_crops', $this->stored());
    }

    public function test_a_foreign_photo_or_unknown_kind_is_refused(): void
    {
        [$jar, $csrf] = $this->login();
        [, $foreign] = $this->save($jar, ['csrf_token' => $csrf, 'kind' => 'post', 'src' => '/assets/images/logo.png', 'rect' => $this->rect()]);
        [, $kind]    = $this->save($jar, ['csrf_token' => $csrf, 'kind' => 'reel', 'src' => self::$src, 'rect' => $this->rect()]);
        @unlink($jar);
        $this->assertFalse($foreign['ok']);
        $this->assertStringContainsString('не е от статията', $foreign['error']);
        $this->assertFalse($kind['ok']);
        $this->assertArrayNotHasKey('insta_crops', $this->stored());
    }

    public function test_a_good_crop_is_stored_on_the_bg_article(): void
    {
        [$jar, $csrf] = $this->login();
        [$code, $res] = $this->save($jar, ['csrf_token' => $csrf, 'kind' => 'post', 'src' => self::$src, 'rect' => $this->rect()]);
        [, $story]    = $this->save($jar, ['csrf_token' => $csrf, 'kind' => 'story', 'src' => self::$src,
                                           'rect' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.8, 'ar' => 0.5625]]);
        @unlink($jar);
        $this->assertSame(200, $code);
        $this->assertTrue($res['ok']);
        $this->assertTrue($story['ok']);
        $crops = $this->stored()['insta_crops'];
        $this->assertEqualsWithDelta(['x' => 0, 'y' => 0.3, 'w' => 1, 'h' => 0.5625, 'ar' => 0.8], $crops['post'][self::$src], 1e-9);
        $this->assertEqualsWithDelta(0.5625, $crops['story'][self::$src]['ar'], 1e-9);
        $this->assertSame('Crop test', $this->stored()['title'], 'the rest of the article is untouched');
    }
}
