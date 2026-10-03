<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sweeps every directly-routable admin script for the guards CLAUDE.md requires:
 * a login check before any data access, CSRF on every POST handler, CSRF before
 * any paid external API call, and the .user.ini/pin-docroot.php pair in every
 * admin subdirectory. Files are discovered, not listed, so a new endpoint that
 * forgets a guard fails here without anyone remembering to add it.
 */
#[Group('security')]
final class AdminEndpointGuardsTest extends TestCase
{
    private const AUTH = '/admin_require_(login|admin|shop|editorial|iris)\(\)|admin_logged_in\(\)/';
    private const CSRF = '/csrf_verify\(\)|csrf_matches\(/';
    private const CLI  = "/(PHP_SAPI|php_sapi_name\(\))\s*!==?\s*'cli'/";
    private const PAID = '/deepl_translate\(|claude_suggest_[a-z]+\(|article_auto_excerpt\(|api\.anthropic\.com/';

    /** Files that are deliberately reachable without a staff session. */
    private const NO_AUTH = [
        'admin/login.php'          => 'the login form itself',
        'admin/login-forgot.php'   => 'password reset request, rate limited',
        'admin/login-reset.php'    => 'password reset, authorised by the emailed token',
        'admin/logout.php'         => 'only ends the session',
        'admin/translate.php'      => 'only redirects to articles.php',
        'admin/pin-docroot.php'    => 'auto_prepend_file, not an endpoint',
        'admin/api/pin-docroot.php' => 'auto_prepend_file, not an endpoint',
        'admin/api/error-alerts.php' => 'bearer-token API, checked separately below',
    ];

    /** POST handlers that legitimately have no CSRF token. */
    private const NO_CSRF = [
        'admin/login.php'            => 'no session exists yet; rate limited',
        'admin/login-forgot.php'     => 'no session exists yet; rate limited',
        'admin/login-reset.php'      => 'authorised by the single-use emailed token',
        'admin/api/error-alerts.php' => 'bearer token, not a browser session',
    ];

    /** Tracked admin PHP files (git ls-files, so local-only scratch files don't count). */
    private static function adminFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files -- admin 2>/dev/null', $out, $code);
        if ($code !== 0 || $out === []) {
            $out = array_map(
                fn($p) => substr($p, strlen($root) + 1),
                array_merge(glob($root . '/admin/*.php'), glob($root . '/admin/*/*.php'))
            );
        }
        return array_values(array_filter($out, fn($p) =>
            str_ends_with($p, '.php')
            && !str_starts_with($p, 'admin/includes/')
            && !str_starts_with($p, 'admin/js/')
            && !str_starts_with($p, 'admin/assets/')
            && is_file($root . '/' . $p)
        ));
    }

    public static function adminFileProvider(): array
    {
        $rows = [];
        foreach (self::adminFiles() as $p) $rows[$p] = [$p];
        return $rows;
    }

    private function src(string $rel): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $rel);
    }

    #[DataProvider('adminFileProvider')]
    public function testRequiresLoginBeforeTouchingDataOrInput(string $rel): void
    {
        $src = $this->src($rel);
        if (isset(self::NO_AUTH[$rel]) || preg_match(self::CLI, $src)) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->assertMatchesRegularExpression(self::AUTH, $src, "$rel must call admin_require_*() (or admin_logged_in()) — it is reachable over HTTP");

        preg_match(self::AUTH, $src, $m, PREG_OFFSET_CAPTURE);
        $authPos = $m[0][1];
        foreach (['$_POST', '$_GET', '$_FILES', 'php://input', 'get_pdo('] as $marker) {
            $pos = strpos($src, $marker);
            if ($pos !== false) {
                $this->assertLessThan($pos, $authPos, "$rel uses $marker before checking the login");
            }
        }
    }

    #[DataProvider('adminFileProvider')]
    public function testPostHandlersVerifyCsrf(string $rel): void
    {
        $src = $this->src($rel);
        if (isset(self::NO_CSRF[$rel]) || preg_match(self::CLI, $src) || !preg_match('/\$_POST|php:\/\/input/', $src)) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->assertMatchesRegularExpression(self::CSRF, $src, "$rel reads POST data and must verify CSRF via csrf_verify()/csrf_matches()");
        $this->assertStringNotContainsString('hash_equals(csrf_token()', $src, "$rel must use the shared csrf_matches(), not its own comparison");
    }

    #[DataProvider('adminFileProvider')]
    public function testPaidApiCallsComeAfterCsrf(string $rel): void
    {
        $src = $this->src($rel);
        if (!preg_match(self::PAID, $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->assertMatchesRegularExpression(self::CSRF, $src, "$rel spends money on an external API and must verify CSRF");
        preg_match(self::CSRF, $src, $c, PREG_OFFSET_CAPTURE);
        $this->assertLessThan($m[0][1], $c[0][1], "$rel must verify CSRF before calling {$m[0][0]}");
    }

    /** The endpoint-side check is useless if a caller forgets to send the token — the button just breaks. */
    public function testEveryCallerOfAPaidEndpointSendsTheCsrfToken(): void
    {
        $paid = [];
        foreach (self::adminFiles() as $rel) {
            if (preg_match(self::PAID, $this->src($rel))) $paid[] = '/' . $rel;
        }
        $this->assertNotEmpty($paid);

        $callers = array_merge(glob(ROOT_PATH . '/admin/*.php'), glob(ROOT_PATH . '/admin/includes/*.php'), glob(ROOT_PATH . '/admin/js/*.js'));
        $checked = 0;
        foreach ($callers as $path) {
            $src = (string) file_get_contents($path);
            preg_match_all("#fetch\(\s*['\"`](/admin/[a-z0-9/_-]+\.php)[^'\"`]*['\"`]\s*,(.{0,800}?)\}\s*\)#s", $src, $calls, PREG_SET_ORDER);
            foreach ($calls as [$call, $target]) {
                if (!in_array($target, $paid, true)) continue;
                $checked++;
                $this->assertStringContainsString('csrf_token', $call, basename($path) . " calls $target without sending csrf_token");
            }
        }
        $this->assertGreaterThan(0, $checked, 'expected to find at least one fetch() to a paid endpoint');
    }

    public function testErrorAlertsApiChecksItsBearerToken(): void
    {
        $src = $this->src('admin/api/error-alerts.php');
        $this->assertStringContainsString('error_alert_api_token()', $src);
        $this->assertStringContainsString('hash_equals(', $src, 'the bearer token must be compared in constant time');
    }

    public function testExemptionListsPointAtRealFiles(): void
    {
        foreach (array_keys(self::NO_AUTH + self::NO_CSRF) as $rel) {
            $this->assertFileExists(ROOT_PATH . '/' . $rel, "stale exemption: $rel no longer exists");
        }
    }

    /**
     * auto_prepend_file resolves relative to the executing script's directory, so
     * every admin subdirectory with routable PHP needs its own .user.ini +
     * pin-docroot.php. Missing it is a prod-only bare 500 (see CLAUDE.md).
     */
    public function testEveryAdminDirWithScriptsPinsDocumentRoot(): void
    {
        $dirs = [];
        foreach (self::adminFiles() as $p) $dirs[dirname($p)] = true;
        $this->assertNotEmpty($dirs);

        foreach (array_keys($dirs) as $dir) {
            $ini = ROOT_PATH . "/$dir/.user.ini";
            $pin = ROOT_PATH . "/$dir/pin-docroot.php";
            $this->assertFileExists($ini, "$dir/ has PHP endpoints but no .user.ini");
            $this->assertFileExists($pin, "$dir/ has PHP endpoints but no pin-docroot.php");
            $this->assertMatchesRegularExpression('/^\s*auto_prepend_file\s*=\s*pin-docroot\.php\s*$/m', (string) file_get_contents($ini));

            $depth = substr_count($dir, '/') + 1;
            $expected = $depth === 1 ? '/dirname\(__DIR__\)|dirname\(__DIR__,\s*1\)/' : "/dirname\(__DIR__,\s*$depth\)/";
            $this->assertMatchesRegularExpression($expected, (string) file_get_contents($pin), "$dir/pin-docroot.php must point $depth level(s) up to the project root");
        }
    }
}
