<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__) . '/Support/TestServer.php';
require_once dirname(__DIR__, 2) . '/includes/files.php';

/**
 * admin/upload-file.php over real HTTP: login and CSRF are required, authors may
 * upload too, a file that is not a PDF is refused, and an uploaded PDF is served.
 */
#[Group('http')]
#[Group('admin')]
final class FileUploadHttpTest extends TestCase
{
    private static string $root;
    private static string $base = '';

    private static string $password    = 'Files_Test_42!';
    private static string $authorEmail = 'test.files.author@example.test';
    private static int $uid = 0;

    /** @var string[] files this test uploaded, removed afterwards */
    private static array $stored = [];

    public static function setUpBeforeClass(): void
    {
        self::$root       = dirname(__DIR__, 2);
        self::$base       = TestServer::start();
        if (self::$base === '' || !test_db_available()) return;

        $pdo = get_pdo();
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException) {}
        $pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute([self::$authorEmail]);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Files Test', self::$authorEmail, password_hash(self::$password, PASSWORD_BCRYPT), 'author']);
        self::$uid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$stored as $path) @unlink(self::$root . $path);
        if (test_db_available() && self::$uid) {
            get_pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$uid]);
        }
        TestServer::stop();
    }

    protected function setUp(): void
    {
        if (self::$base === '') $this->markTestSkipped('Could not start local php -S test server.');
        if (!test_db_available() || !self::$uid) $this->markTestSkipped('DB not available.');
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
            // An array with a CURLFile goes as multipart/form-data, the way the browser sends it.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
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
        $jar = (string) tempnam(sys_get_temp_dir(), 'ngo_files_jar_');
        $this->http('/admin/login.php', $jar, ['email' => self::$authorEmail, 'password' => self::$password]);
        [, $page] = $this->http('/admin/dashboard.php', $jar);
        $this->assertSame(1, preg_match('/window\._csrfToken = "([0-9a-f]+)"/', $page, $m), 'admin pages carry a CSRF token');
        return [$jar, $m[1]];
    }

    private function file(string $body, string $name): CURLFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ngo_up_');
        file_put_contents($tmp, $body);
        return new CURLFile($tmp, 'application/pdf', $name);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        [$code, , $loc] = $this->http('/admin/upload-file.php', null, ['file' => $this->file($this->pdf(), 'a.pdf')]);
        $this->assertContains($code, [302, 303]);
        $this->assertStringContainsString('login', $loc);
    }

    public function test_wrong_csrf_is_refused(): void
    {
        [$jar] = $this->login();
        [$code, $body] = $this->http('/admin/upload-file.php', $jar, ['csrf_token' => 'deadbeef', 'file' => $this->file($this->pdf(), 'a.pdf')]);
        @unlink($jar);
        $this->assertSame(403, $code);
        $this->assertFalse(json_decode($body, true)['ok']);
    }

    public function test_a_file_that_is_not_a_pdf_is_refused(): void
    {
        [$jar, $csrf] = $this->login();
        [, $body] = $this->http('/admin/upload-file.php', $jar, ['csrf_token' => $csrf, 'file' => $this->file('<?php echo 1;', 'evil.pdf')]);
        @unlink($jar);
        $d = json_decode($body, true);
        $this->assertFalse($d['ok']);
        $this->assertSame('type', $d['reason']);
    }

    public function test_an_author_uploads_a_pdf_that_is_then_served(): void
    {
        [$jar, $csrf] = $this->login();
        [$code, $body] = $this->http('/admin/upload-file.php', $jar, ['csrf_token' => $csrf, 'file' => $this->file($this->pdf(), 'Коледен базар.pdf')]);
        @unlink($jar);
        $d = json_decode($body, true);
        $this->assertSame(200, $code);
        $this->assertTrue($d['ok'], $d['message'] ?? $body);
        self::$stored[] = $d['path'];

        $this->assertMatchesRegularExpression('#^/assets/files/uploads/koleden-bazar-[0-9a-f]{6}\.pdf$#', $d['path']);
        $this->assertSame('Коледен базар (PDF, 1 KB)', $d['text']);
        [$get, $served] = $this->http($d['path']);
        $this->assertSame(200, $get);
        $this->assertSame($this->pdf(), $served);
    }
}
