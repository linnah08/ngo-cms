<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for includes/updater.php (the self-update backend).
 *
 * Network and filesystem side effects are avoided throughout:
 *  - updater_check_latest() takes an injectable $httpFetcher so no test ever
 *    hits the real GitHub API.
 *  - updater_diff_conflicts() is a pure function tested in isolation with a
 *    fake $liveHasher — no real files are read.
 *  - run_migrations() (from the migrate.php refactor) is exercised against
 *    the local dev DB only, following the same test_db_available() self-skip
 *    convention as tests/SpamFilterTest.php; it never touches this repo's
 *    own live application files (that's updater_apply(), which is not
 *    exercised end-to-end here on purpose).
 */
final class UpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';
    }

    // ── updater_get_local_version() ─────────────────────────────────────────────

    public function test_reads_real_version_file(): void
    {
        $expected = trim((string) file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/VERSION'));
        $this->assertSame($expected, updater_get_local_version());
        $this->assertNotSame('', $expected, 'Repo VERSION file should not be empty');
    }

    public function test_missing_version_file_returns_unknown(): void
    {
        $missing = sys_get_temp_dir() . '/ngo-cms-test-missing-version-' . bin2hex(random_bytes(4));
        $this->assertFalse(is_file($missing));
        $this->assertSame('unknown', updater_get_local_version($missing));
    }

    public function test_empty_version_file_returns_unknown(): void
    {
        $tmp = sys_get_temp_dir() . '/ngo-cms-test-empty-version-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, "   \n");
        try {
            $this->assertSame('unknown', updater_get_local_version($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    // ── updater_version_needs_update() (pure version_compare logic) ────────────

    public function test_unknown_local_version_always_needs_update(): void
    {
        $this->assertTrue(updater_version_needs_update('unknown', '1.0.0'));
    }

    public function test_same_version_does_not_need_update(): void
    {
        $this->assertFalse(updater_version_needs_update('1.2.3', '1.2.3'));
    }

    public function test_newer_latest_needs_update(): void
    {
        $this->assertTrue(updater_version_needs_update('1.0.0', '1.2.0'));
    }

    public function test_older_or_equal_latest_does_not_need_update(): void
    {
        $this->assertFalse(updater_version_needs_update('1.2.0', '1.0.0'));
        $this->assertFalse(updater_version_needs_update('1.2.0', '1.2.0'));
    }

    public function test_no_latest_version_never_needs_update(): void
    {
        $this->assertFalse(updater_version_needs_update('1.0.0', ''));
        $this->assertFalse(updater_version_needs_update('unknown', ''));
    }

    // ── updater_check_latest() with a mocked HTTP layer ─────────────────────────

    public function test_check_latest_reports_update_available(): void
    {
        $fetcher = function (string $url): array {
            $body = json_encode([
                'tag_name' => 'v99.0.0',
                'body'     => 'Release notes here.',
                'assets'   => [
                    ['name' => 'ngo-platform-v99.0.0.zip', 'browser_download_url' => 'https://example.test/release.zip'],
                    ['name' => 'checksums.json', 'browser_download_url' => 'https://example.test/checksums.json'],
                ],
            ]);
            return ['ok' => true, 'status' => 200, 'body' => $body, 'error' => null];
        };

        $result = updater_check_latest(true, $fetcher);

        $this->assertNull($result['error']);
        $this->assertSame('99.0.0', $result['latest_version']);
        $this->assertTrue($result['update_available']);
        $this->assertSame('https://example.test/release.zip', $result['zip_url']);
        $this->assertSame('Release notes here.', $result['notes']);
    }

    public function test_check_latest_no_releases_is_not_an_error(): void
    {
        $fetcher = fn(string $url): array => ['ok' => false, 'status' => 404, 'body' => '{"message":"Not Found"}', 'error' => null];

        $result = updater_check_latest(true, $fetcher);

        $this->assertNull($result['error']);
        $this->assertFalse($result['update_available']);
    }

    public function test_check_latest_network_failure_returns_plain_error_not_exception(): void
    {
        $fetcher = fn(string $url): array => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not resolve host'];

        $result = updater_check_latest(true, $fetcher);

        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertFalse($result['update_available']);
    }

    public function test_check_latest_unexpected_body_returns_error(): void
    {
        $fetcher = fn(string $url): array => ['ok' => true, 'status' => 200, 'body' => 'not json', 'error' => null];

        $result = updater_check_latest(true, $fetcher);

        $this->assertIsString($result['error']);
    }

    // ── updater_diff_conflicts() (pure checksum-conflict-skip logic) ───────────

    public function test_diff_conflicts_skips_customized_files(): void
    {
        $old = [
            'templates/header.php' => 'hash-a',
            'includes/mailer.php'  => 'hash-b',
        ];
        $newFiles = ['templates/header.php', 'includes/mailer.php'];

        // Adopter edited header.php (live hash differs) but never touched mailer.php.
        $liveHasher = fn(string $rel): ?string => match ($rel) {
            'templates/header.php' => 'hash-a-CUSTOMIZED',
            'includes/mailer.php'  => 'hash-b',
            default                => null,
        };

        $skipped = updater_diff_conflicts($old, $newFiles, $liveHasher);

        $this->assertSame(['templates/header.php'], $skipped);
    }

    public function test_diff_conflicts_does_not_skip_new_files_with_no_baseline(): void
    {
        $old      = ['includes/mailer.php' => 'hash-b'];
        $newFiles = ['includes/mailer.php', 'includes/brand-new-feature.php'];

        $liveHasher = fn(string $rel): ?string => match ($rel) {
            'includes/mailer.php' => 'hash-b',
            default               => null, // brand-new-feature.php doesn't exist live yet
        };

        $skipped = updater_diff_conflicts($old, $newFiles, $liveHasher);

        $this->assertSame([], $skipped);
    }

    public function test_diff_conflicts_does_not_skip_when_live_file_missing(): void
    {
        $old      = ['includes/deleted-locally.php' => 'hash-c'];
        $newFiles = ['includes/deleted-locally.php'];

        $liveHasher = fn(string $rel): ?string => null; // adopter deleted it locally

        $skipped = updater_diff_conflicts($old, $newFiles, $liveHasher);

        $this->assertSame([], $skipped);
    }

    public function test_diff_conflicts_empty_baseline_skips_nothing(): void
    {
        $skipped = updater_diff_conflicts([], ['includes/mailer.php'], fn($rel) => 'whatever');
        $this->assertSame([], $skipped);
    }

    // ── updater_is_maintenance_mode() ───────────────────────────────────────────

    public function test_is_maintenance_mode_reflects_flag_file(): void
    {
        // Sanity-check against the real flag file without ever leaving it set —
        // this must remain false for every other test/page in this repo.
        $this->assertFalse(updater_is_maintenance_mode(), '.maintenance should not exist during normal test runs');
    }

    // ── run_migrations() (migrate.php refactor) ─────────────────────────────────

    public function test_run_migrations_returns_success_shape(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No local DB configured — see tests/bootstrap.php::test_db_available().');
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/migrate.php';
        $result = run_migrations();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($result['success'], 'run_migrations() failed: ' . ($result['error'] ?? ''));
        $this->assertNull($result['error']);

        // Running it again immediately must be a safe no-op (idempotent runner).
        $second = run_migrations();
        $this->assertTrue($second['success']);
    }

    public function test_run_migrations_creates_platform_updates_table(): void
    {
        if (!test_db_available()) {
            $this->markTestSkipped('No local DB configured — see tests/bootstrap.php::test_db_available().');
        }

        require_once $_SERVER['DOCUMENT_ROOT'] . '/migrate.php';
        run_migrations();

        require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';
        $pdo = get_pdo();
        // Just confirm the table from migration 035 exists and is queryable.
        $stmt = $pdo->query('SELECT COUNT(*) FROM platform_updates');
        $this->assertNotFalse($stmt);
    }
}
