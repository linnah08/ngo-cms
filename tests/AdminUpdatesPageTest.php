<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end tests for admin/updates.php (the self-update admin UI).
 *
 * Unlike tests/HttpTest.php and tests/Admin/LoginHttpTest.php (which hit the
 * Herd-parked http://example.test host), this test boots its own throwaway
 * `php -S` server against the checked-out repo root — the same approach
 * playwright.config.js already uses for browser tests. That keeps it working
 * in sandboxes/CI where example.test isn't parked, while still exercising the
 * real file over real HTTP (needed because admin_require_admin() issues a
 * redirect/exit and csrf_verify() depends on a real PHP session cookie round
 * trip — neither can be exercised by requiring the script in-process).
 */
#[Group('http')]
#[Group('admin')]
final class AdminUpdatesPageTest extends TestCase
{
    private static string $root;
    private static string $base;
    /** @var resource|null */
    private static $serverProc = null;
    /** @var array<int,resource> */
    private static array $serverPipes = [];
    private static bool $serverReady = false;
    /** @var string[] Temp files the server's stdout/stderr are redirected to. */
    private static array $serverLogs = [];

    private static string $adminEmail    = 'test.updates.admin@example.test';
    private static string $adminPassword = 'AdminUpdatesTest_Admin_42!';
    private static ?int   $adminUid      = null;

    private static string $authorEmail    = 'test.updates.author@example.test';
    private static string $authorPassword = 'AdminUpdatesTest_Author_42!';
    private static ?int   $authorUid      = null;

    /** Whether includes/updater.php (built by the parallel backend agent) exists. */
    private static bool $updaterExists = false;

    public static function setUpBeforeClass(): void
    {
        self::$root          = dirname(__DIR__);
        self::$updaterExists = file_exists(self::$root . '/includes/updater.php');

        $port      = 8000 + random_int(100, 900);
        self::$base = "http://127.0.0.1:{$port}";

        // stdout/stderr are redirected to files rather than pipes: a pipe nobody
        // drains fills its OS buffer once the built-in server has logged enough
        // requests, which then blocks the server process on write() forever —
        // and proc_close() blocks right along with it waiting for the process
        // to exit. Files have no such ceiling.
        $logDir = sys_get_temp_dir();
        $outLog = tempnam($logDir, 'om_updates_srv_out_');
        $errLog = tempnam($logDir, 'om_updates_srv_err_');
        self::$serverLogs = [$outLog, $errLog];
        $descriptors = [1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']];
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root],
            $descriptors,
            $pipes,
            self::$root
        );
        if (is_resource($proc)) {
            self::$serverProc = $proc;
        }

        // Poll until the built-in server responds (or give up after ~3s).
        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init(self::$base . '/admin/login.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno === 0) {
                self::$serverReady = true;
                break;
            }
            usleep(100_000);
        }

        if (!self::$serverReady || !test_db_available()) {
            return;
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/auth.php';
        $pdo = get_pdo();

        // This test class logs in 4 times over real HTTP against 127.0.0.1, the
        // same IP every throwaway `php -S` test server uses. admin_login's rate
        // limiter (admin/includes/auth.php) is keyed on action+ip, so earlier
        // test classes' login attempts (LoginTest, LoginHttpTest) share the same
        // budget in a full suite run and can leave us with too few tries left.
        // Reset our own slice of it before consuming any — the table may not
        // exist yet if no login test has run before this one in the process.
        try {
            $pdo->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
        } catch (\PDOException $e) {
            // Table doesn't exist yet — nothing to clear.
        }

        $adminHash = password_hash(self::$adminPassword, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Updates Test Admin', self::$adminEmail, $adminHash, 'admin']);
        self::$adminUid = (int) $pdo->lastInsertId();

        $authorHash = password_hash(self::$authorPassword, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO admin_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Updates Test Author', self::$authorEmail, $authorHash, 'author']);
        self::$authorUid = (int) $pdo->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        if (test_db_available()) {
            $pdo = get_pdo();
            if (self::$adminUid !== null) {
                $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$adminUid]);
            }
            if (self::$authorUid !== null) {
                $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([self::$authorUid]);
            }
        }

        if (is_resource(self::$serverProc)) {
            $status = proc_get_status(self::$serverProc);
            proc_terminate(self::$serverProc, 9); // SIGKILL — no graceful shutdown to wait on
            // Reap without proc_close()'s indefinite wait: poll briefly, then
            // give up. The child (and OS) clean up on their own regardless;
            // this just keeps a stuck child from ever hanging the test run.
            for ($i = 0; $i < 20; $i++) {
                $st = proc_get_status(self::$serverProc);
                if (!$st['running']) break;
                usleep(50_000);
            }
            if (!empty($status['pid'])) {
                @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
            }
        }

        foreach (self::$serverLogs as $log) {
            @unlink($log);
        }
    }

    private function requireServer(): void
    {
        if (!self::$serverReady) {
            $this->markTestSkipped('Could not start local php -S test server.');
        }
        if (!test_db_available()) {
            $this->markTestSkipped('DB not available — skipping admin/updates.php tests.');
        }
    }

    /**
     * Logs in via /admin/login.php and returns a cookie-jar path (caller must
     * unlink).
     *
     * The rate-limit row is cleared on every call, not just once in
     * setUpBeforeClass(): this class logs in more times than /admin/login.php
     * allows a single IP, and the symptom is a silent 302 on the next request
     * rather than an obvious login failure.
     */
    private function loginAs(string $email, string $password): string
    {
        if (test_db_available()) {
            try {
                get_pdo()->exec("DELETE FROM rate_limits WHERE action = 'admin_login' AND ip = '127.0.0.1'");
            } catch (\PDOException $e) {
                // Table doesn't exist yet — nothing to clear.
            }
        }

        $jar = tempnam(sys_get_temp_dir(), 'om_updates_test_');
        $ch  = curl_init(self::$base . '/admin/login.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['email' => $email, 'password' => $password]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_exec($ch);
        curl_close($ch);
        return $jar;
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function testUnauthenticatedGetIsRejected(): void
    {
        $this->requireServer();

        $ch = curl_init(self::$base . '/admin/updates.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(302, $code, 'Unauthenticated GET must redirect (to login), not render the page');
        $this->assertStringContainsString('/admin/login.php', $resp);
    }

    public function testNonAdminAuthorRoleIsRejected(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$authorEmail, self::$authorPassword);

        $ch = curl_init(self::$base . '/admin/updates.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);

        // admin_require_admin() must 403 an 'author' — this page rewrites app code.
        $this->assertSame(403, $code, 'An author (non-admin) role must be refused access to admin/updates.php');
    }

    // ── GET rendering ────────────────────────────────────────────────────────

    public function testGetRendersSuccessfullyForAdmin(): void
    {
        $this->requireServer();
        if (!self::$updaterExists) {
            $this->markTestSkipped('includes/updater.php does not exist yet (backend agent work pending).');
        }

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);

        $ch = curl_init(self::$base . '/admin/updates.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);

        $this->assertSame(200, $code, 'Admin GET of admin/updates.php must render (200), never fatal');
        $this->assertStringContainsString('Обновления', (string) $body);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    /**
     * Visits admin/dashboard.php once with the given cookie jar so the session
     * actually has a csrf_token seeded (csrf_verify() vacuously matches an
     * absent token against an absent field — realistic only because every
     * real admin GET calls csrf_field() first, which this warm-up reproduces).
     * Deliberately uses dashboard.php rather than updates.php so this CSRF
     * check stays verifiable independent of includes/updater.php.
     */
    private function warmUpSession(string $jar): void
    {
        $ch = curl_init(self::$base . '/admin/dashboard.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_exec($ch);
        curl_close($ch);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);
        $this->warmUpSession($jar);

        $ch = curl_init(self::$base . '/admin/updates.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_POST, true);
        // No csrf_token field at all.
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['action' => 'apply_update']));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);

        $this->assertSame(400, $code, 'POST without a CSRF token must be rejected before any update logic runs');
        $this->assertStringNotContainsString('Обновяването завърши успешно', (string) $body,
            'A CSRF-rejected POST must never apply the update');
    }

    public function testPostWithInvalidCsrfIsRejected(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);

        $ch = curl_init(self::$base . '/admin/updates.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'action'     => 'apply_update',
            'csrf_token' => 'not-the-real-token',
        ]));
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($jar);

        $this->assertSame(400, $code, 'POST with a wrong CSRF token must be rejected');
    }

    // ── admin/update-apply-ajax.php + admin/update-progress-ajax.php ─────────
    //
    // Only the rejection paths are exercised here, on purpose. A happy-path
    // test would have to send a VALID CSRF token to the apply endpoint, and
    // this harness runs the built-in server against the real checked-out repo
    // — so a passing token would start a genuine self-update and rewrite the
    // working tree. The successful path is covered instead by
    // tests/UpdaterTest.php, which drives updater_apply() against a throwaway
    // root with the network, migrations and audit log all injected.

    /**
     * POSTs a raw body to $path and returns [httpCode, body]. Deliberately not
     * shared with loginAs(): these requests need an exact body, not a form.
     */
    private function postRaw(string $path, string $body, ?string $jar = null, string $contentType = 'application/json'): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $contentType]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        $out  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $out];
    }

    private function getRaw(string $path, ?string $jar = null): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        $out  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $out];
    }

    private function versionFileContents(): string
    {
        return (string) file_get_contents(self::$root . '/VERSION');
    }

    // ── Apply endpoint: access control ───────────────────────────────────────

    public function testApplyEndpointRejectsUnauthenticated(): void
    {
        $this->requireServer();

        [$code, $body] = $this->postRaw('/admin/update-apply-ajax.php', '{}');

        $this->assertSame(302, $code, 'An anonymous POST must be bounced to login, never run an update');
        $this->assertStringNotContainsString('"ok":true', $body);
    }

    public function testApplyEndpointRejectsAuthorRole(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$authorEmail, self::$authorPassword);
        [$code] = $this->postRaw('/admin/update-apply-ajax.php', '{}', $jar);
        @unlink($jar);

        $this->assertSame(403, $code, 'An author must not be able to rewrite the application code');
    }

    public function testApplyEndpointRejectsGet(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);
        [$code] = $this->getRaw('/admin/update-apply-ajax.php', $jar);
        @unlink($jar);

        $this->assertSame(405, $code, 'An update must never be triggerable by a plain GET');
    }

    // ── Apply endpoint: CSRF ─────────────────────────────────────────────────

    public function testApplyEndpointRejectsMissingCsrfAndAppliesNothing(): void
    {
        $this->requireServer();

        $before = $this->versionFileContents();
        $jar    = $this->loginAs(self::$adminEmail, self::$adminPassword);
        $this->warmUpSession($jar);

        [$code, $body] = $this->postRaw('/admin/update-apply-ajax.php', json_encode([]), $jar);
        @unlink($jar);

        $this->assertSame(400, $code, 'A body with no CSRF token must be rejected before any update logic runs');
        $this->assertStringNotContainsString('"ok":true', $body);
        $this->assertSame($before, $this->versionFileContents(), 'A rejected request must not have applied anything');
    }

    public function testApplyEndpointRejectsInvalidCsrfAndAppliesNothing(): void
    {
        $this->requireServer();

        $before = $this->versionFileContents();
        $jar    = $this->loginAs(self::$adminEmail, self::$adminPassword);
        $this->warmUpSession($jar);

        [$code, $body] = $this->postRaw(
            '/admin/update-apply-ajax.php',
            json_encode(['csrf_token' => 'not-the-real-token']),
            $jar
        );
        @unlink($jar);

        $this->assertSame(400, $code);
        $this->assertStringNotContainsString('"ok":true', $body);
        $this->assertSame($before, $this->versionFileContents());
    }

    public function testApplyEndpointRejectsNonJsonBody(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);
        [$code] = $this->postRaw('/admin/update-apply-ajax.php', 'csrf_token=whatever', $jar, 'application/x-www-form-urlencoded');
        @unlink($jar);

        $this->assertSame(400, $code, 'A form-encoded body is not the contract — it must not fall through to CSRF-less apply');
    }

    // ── Progress endpoint ────────────────────────────────────────────────────

    public function testProgressEndpointRejectsUnauthenticated(): void
    {
        $this->requireServer();

        [$code] = $this->getRaw('/admin/update-progress-ajax.php');

        $this->assertSame(302, $code, 'Update state must not be readable anonymously');
    }

    public function testProgressEndpointRejectsAuthorRole(): void
    {
        $this->requireServer();

        $jar = $this->loginAs(self::$authorEmail, self::$authorPassword);
        [$code] = $this->getRaw('/admin/update-progress-ajax.php', $jar);
        @unlink($jar);

        $this->assertSame(403, $code);
    }

    public function testProgressEndpointReportsIdleWhenNothingIsRunning(): void
    {
        $this->requireServer();
        if (!self::$updaterExists) {
            $this->markTestSkipped('includes/updater.php does not exist.');
        }

        $jar = $this->loginAs(self::$adminEmail, self::$adminPassword);
        [$code, $body] = $this->getRaw('/admin/update-progress-ajax.php', $jar);
        @unlink($jar);

        $this->assertSame(200, $code);

        $data = json_decode($body, true);
        $this->assertIsArray($data, 'The progress endpoint must always answer with JSON: ' . $body);
        $this->assertTrue($data['ok']);
        $this->assertSame('idle', $data['phase'], 'No update is running during the test suite');
        $this->assertFalse($data['maintenance'], 'The repo must not be left in maintenance mode');
    }
}
