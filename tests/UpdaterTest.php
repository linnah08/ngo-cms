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
 *    convention as tests/SpamFilterTest.php.
 *  - updater_apply() IS exercised end-to-end, but never against this repo:
 *    updater_set_root_override() points it at a throwaway directory and its
 *    $deps argument replaces the network, the migration runner and the audit
 *    logger. It therefore touches no live file, no GitHub API and no database.
 */
final class UpdaterTest extends TestCase
{
    /** @var string[] Throwaway directories to remove after each test. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';
        // This checkout has a .git folder, and a fork's site.config.php may set
        // FEATURE_SELF_UPDATE to false — either would refuse every apply below.
        // The tests further down that are ABOUT that refusal clear the override.
        updater_set_self_update_override(true);
    }

    /**
     * Releasing the root override matters more than the cleanup: leaving it set
     * would point every later test — and anything else in the process — at a
     * deleted directory.
     */
    protected function tearDown(): void
    {
        updater_set_root_override(null);
        updater_set_self_update_override(null);
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                updater_rrmdir($dir);
            } elseif (is_file($dir)) {
                @unlink($dir);
            }
        }
        $this->tempDirs = [];
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

    // ── cPanel-managed .htaccess blocks ─────────────────────────────────────

    /** Exactly what MultiPHP Manager appended to the test site's .htaccess. */
    private const CPANEL_HANDLER = "# php -- BEGIN cPanel-generated handler, do not edit\n"
        . "# Set the \u{201C}ea-php83\u{201D} package as the default \u{201C}PHP\u{201D} programming language.\n"
        . "<IfModule mime_module>\n"
        . "  AddHandler application/x-httpd-ea-php83___lsphp .php .php8 .phtml\n"
        . "</IfModule>\n"
        . "# php -- END cPanel-generated handler, do not edit\n";

    public function test_cpanel_handler_block_is_not_a_customization(): void
    {
        $shipped = "Options -Indexes\nRewriteEngine On\n";
        $live    = $shipped . "\n" . self::CPANEL_HANDLER;

        $hashes = array_map(fn($c) => hash('sha256', $c), updater_strip_host_blocks($live));
        $this->assertContains(hash('sha256', $shipped), $hashes);

        $skipped = updater_diff_conflicts(['.htaccess' => hash('sha256', $shipped)], ['.htaccess'],
            fn() => array_merge([hash('sha256', $live)], $hashes));
        $this->assertSame([], $skipped);
    }

    public function test_real_edit_alongside_cpanel_block_is_still_a_customization(): void
    {
        $shipped = "Options -Indexes\nRewriteEngine On\n";
        $live    = $shipped . "Redirect 301 /old /new\n\n" . self::CPANEL_HANDLER;

        $hashes  = array_map(fn($c) => hash('sha256', $c), updater_strip_host_blocks($live));
        $skipped = updater_diff_conflicts(['.htaccess' => hash('sha256', $shipped)], ['.htaccess'],
            fn() => array_merge([hash('sha256', $live)], $hashes));
        $this->assertSame(['.htaccess'], $skipped);
    }

    public function test_cpanel_block_is_carried_into_new_htaccess_and_stays_strippable(): void
    {
        $newRelease = "Options -Indexes\nRewriteEngine On\n# new rule\n";
        $merged     = updater_merge_host_blocks($newRelease, [self::CPANEL_HANDLER]);

        $this->assertStringContainsString('AddHandler application/x-httpd-ea-php83', $merged);
        // Next update must still recognise the file as unedited.
        $this->assertContains($newRelease, updater_strip_host_blocks($merged));
        // Merging twice doesn't duplicate the block.
        $this->assertSame($merged, updater_merge_host_blocks($merged, [self::CPANEL_HANDLER]));
    }

    public function test_file_without_host_blocks_has_no_stripped_variants(): void
    {
        $this->assertSame([], updater_strip_host_blocks("Options -Indexes\n"));
        $this->assertTrue(updater_is_host_managed('.htaccess'));
        $this->assertTrue(updater_is_host_managed('admin/.htaccess'));
        $this->assertFalse(updater_is_host_managed('config.php'));
    }

    public function test_install_folder_is_never_written_by_an_update(): void
    {
        $files = ['config.php', 'install/index.php', 'admin/install-notes.php', 'installer.php'];
        $this->assertSame(['config.php', 'admin/install-notes.php', 'installer.php'], updater_files_to_apply($files));
    }

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

    // ── Progress: percent mapping ───────────────────────────────────────────────

    public function test_progress_phases_span_the_whole_bar_without_gaps(): void
    {
        $phases = updater_progress_phases();
        $prevEnd = 0;
        foreach ($phases as $name => [$start, $end]) {
            $this->assertSame($prevEnd, $start, "Phase '{$name}' must start where the previous one ended");
            $this->assertGreaterThanOrEqual($start, $end, "Phase '{$name}' must not run backwards");
            $prevEnd = $end;
        }
        $this->assertSame(100, $prevEnd, 'The phases together must reach exactly 100%');
    }

    public function test_progress_percent_without_a_total_parks_at_the_phase_start(): void
    {
        // A step that cannot measure itself must not invent movement.
        $this->assertSame(30, updater_progress_percent('download'));
        $this->assertSame(30, updater_progress_percent('download', 0, 0));
        $this->assertSame(30, updater_progress_percent('download', 5, 0));
    }

    public function test_progress_percent_interpolates_within_a_phase(): void
    {
        // 'apply' owns 65..90, so halfway through the files is halfway through
        // that 25-point band.
        $this->assertSame(65,  updater_progress_percent('apply', 0,   100));
        $this->assertSame(77,  updater_progress_percent('apply', 50,  100));
        $this->assertSame(90,  updater_progress_percent('apply', 100, 100));
    }

    public function test_progress_percent_never_exceeds_its_phase(): void
    {
        $this->assertSame(90, updater_progress_percent('apply', 999, 100));
    }

    public function test_progress_percent_of_unknown_phase_is_zero(): void
    {
        // 'failed' deliberately has no band — a failed update keeps the percent
        // it reached instead of jumping to a made-up number.
        $this->assertSame(0, updater_progress_percent('failed'));
        $this->assertSame(0, updater_progress_percent('nonsense', 5, 10));
    }

    public function test_progress_percent_is_monotonic_across_a_whole_run(): void
    {
        $sequence = [
            ['check',    0,  0],
            ['backup',   1,  4],
            ['backup',   4,  4],
            ['download', 0,  0],
            ['extract',  0,  0],
            ['apply',    1, 10],
            ['apply',   10, 10],
            ['migrate',  0,  0],
            ['done',     0,  0],
        ];
        $prev = -1;
        foreach ($sequence as [$phase, $current, $total]) {
            $pct = updater_progress_percent($phase, $current, $total);
            $this->assertGreaterThanOrEqual($prev, $pct, "Percent went backwards at phase '{$phase}'");
            $prev = $pct;
        }
        $this->assertSame(100, $prev);
    }

    // ── Progress: state file ────────────────────────────────────────────────────

    public function test_progress_write_read_clear_round_trip(): void
    {
        $root = $this->makeTempRoot();
        updater_set_root_override($root);

        $this->assertNull(updater_progress_read(), 'No progress file yet');

        updater_progress_write(['phase' => 'apply', 'percent' => 72, 'message' => 'Обновяване…', 'status' => 'running']);
        $state = updater_progress_read();

        $this->assertIsArray($state);
        $this->assertSame('apply', $state['phase']);
        $this->assertSame(72, $state['percent']);
        $this->assertSame('Обновяване…', $state['message'], 'Cyrillic must survive the JSON round trip');
        $this->assertArrayHasKey('updated_at', $state, 'updated_at is what staleness detection relies on');

        updater_progress_clear();
        $this->assertNull(updater_progress_read());
    }

    public function test_progress_write_creates_the_logs_directory(): void
    {
        $root = $this->makeTempRoot();
        updater_set_root_override($root);
        $this->assertDirectoryDoesNotExist($root . '/logs');

        updater_progress_write(['phase' => 'check', 'percent' => 0, 'status' => 'running']);

        $this->assertFileExists($root . '/logs/update-progress.json');
    }

    public function test_progress_read_of_corrupted_file_is_null_not_a_crash(): void
    {
        $root = $this->makeTempRoot();
        updater_set_root_override($root);
        @mkdir($root . '/logs', 0755, true);
        file_put_contents($root . '/logs/update-progress.json', '{not json');

        $this->assertNull(updater_progress_read());
    }

    // ── Progress: staleness ─────────────────────────────────────────────────────

    public function test_fresh_running_state_is_not_stale(): void
    {
        $this->assertFalse(updater_progress_is_stale(
            ['status' => 'running', 'updated_at' => 1_000_000], 90, 1_000_030
        ));
    }

    public function test_running_state_untouched_for_too_long_is_stale(): void
    {
        // This is the case a purely indeterminate bar can never detect: the
        // worker died and nothing will ever finish the update.
        $this->assertTrue(updater_progress_is_stale(
            ['status' => 'running', 'updated_at' => 1_000_000], 90, 1_000_200
        ));
    }

    public function test_finished_state_is_never_stale(): void
    {
        foreach (['success', 'partial', 'failed'] as $status) {
            $this->assertFalse(
                updater_progress_is_stale(['status' => $status, 'updated_at' => 1], 90, 9_999_999),
                "A '{$status}' state is finished, not stalled"
            );
        }
    }

    public function test_running_state_with_no_timestamp_is_stale(): void
    {
        $this->assertTrue(updater_progress_is_stale(['status' => 'running'], 90, 1_000_000));
    }

    // ── updater_apply() end to end (throwaway root, no network, no DB) ──────────

    public function test_apply_updates_files_stamps_version_and_reports_progress(): void
    {
        $this->requireZip();

        $root = $this->makeTempRoot();
        file_put_contents($root . '/VERSION', "1.0.0\n");
        @mkdir($root . '/includes', 0755, true);
        file_put_contents($root . '/includes/thing.php', '<?php // old');

        $zip = $this->makeReleaseZip([
            'VERSION'            => "2.0.0\n",
            'includes/thing.php' => '<?php // new',
            'admin/brand-new.php' => '<?php // added by the release',
            'install/index.php'  => '<?php // the wizard, which must not come back',
        ]);

        updater_set_root_override($root);

        $states = [];
        $logged = [];
        $result = updater_apply(
            function (array $state) use (&$states): void { $states[] = $state; },
            $this->deps($zip, $logged)
        );

        $this->assertSame('success', $result['status'], 'Apply failed: ' . ($result['error'] ?? ''));
        $this->assertSame('1.0.0', $result['from_version']);
        $this->assertSame('2.0.0', $result['to_version']);
        $this->assertSame([], $result['skipped']);

        // Files
        $this->assertSame("2.0.0\n", file_get_contents($root . '/VERSION'));
        $this->assertSame('<?php // new', file_get_contents($root . '/includes/thing.php'));
        $this->assertFileExists($root . '/admin/brand-new.php');
        $this->assertFileDoesNotExist(
            $root . '/install/index.php',
            'install/ must never be written back onto a live site'
        );

        // A backup was taken before anything was touched, and the site is not
        // left in maintenance mode.
        $this->assertNotEmpty(glob($root . '/backups/pre-update-1.0.0-*.zip'), 'No pre-update backup was created');
        $this->assertFalse(updater_is_maintenance_mode(), 'Maintenance mode must be cleared on success');

        // Progress
        $phases = array_values(array_unique(array_column($states, 'phase')));
        $this->assertSame(['check', 'backup', 'download', 'extract', 'apply', 'migrate', 'done'], $phases);

        $percents = array_column($states, 'percent');
        $this->assertSame($percents, $this->sortedCopy($percents), 'Reported progress must never go backwards');
        $this->assertSame(100, end($percents), 'A finished update must report 100%');

        foreach ($states as $state) {
            $this->assertNotSame('', $state['message'], 'Every reported step needs wording for the status line');
        }

        // Audit log
        $this->assertCount(1, $logged);
        $this->assertSame('success', $logged[0][2]);
    }

    public function test_apply_skips_customized_files_and_reports_partial(): void
    {
        $this->requireZip();

        $root = $this->makeTempRoot();
        file_put_contents($root . '/VERSION', "1.0.0\n");
        @mkdir($root . '/includes', 0755, true);

        // The baseline says this file shipped as 'pristine', but the live copy
        // no longer matches — the adopter edited it, so the release must leave
        // it alone.
        file_put_contents($root . '/includes/thing.php', '<?php // customized by the adopter');
        file_put_contents($root . '/includes/untouched.php', '<?php // pristine');
        file_put_contents($root . '/checksums.json', json_encode([
            'includes/thing.php'     => hash('sha256', '<?php // pristine'),
            'includes/untouched.php' => hash('sha256', '<?php // pristine'),
        ]));

        $zip = $this->makeReleaseZip([
            'VERSION'                => "2.0.0\n",
            'includes/thing.php'     => '<?php // new',
            'includes/untouched.php' => '<?php // new',
        ]);

        updater_set_root_override($root);
        $logged = [];
        $result = updater_apply(null, $this->deps($zip, $logged));

        $this->assertSame('partial', $result['status']);
        $this->assertSame(['includes/thing.php'], $result['skipped']);
        $this->assertSame(
            '<?php // customized by the adopter',
            file_get_contents($root . '/includes/thing.php'),
            "A customized file must survive the update untouched"
        );
        $this->assertSame('<?php // new', file_get_contents($root . '/includes/untouched.php'));
        $this->assertSame('partial', $logged[0][2]);
    }

    public function test_apply_reports_a_failed_migration_and_still_clears_maintenance_mode(): void
    {
        $this->requireZip();

        $root = $this->makeTempRoot();
        file_put_contents($root . '/VERSION', "1.0.0\n");
        $zip = $this->makeReleaseZip(['VERSION' => "2.0.0\n"]);

        updater_set_root_override($root);
        $logged = [];
        $deps = $this->deps($zip, $logged);
        $deps['migrator'] = static fn(): array => ['success' => false, 'error' => 'table is missing'];

        $states = [];
        $result = updater_apply(function (array $s) use (&$states): void { $states[] = $s; }, $deps);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('migration failed', (string) $result['error']);
        $this->assertFalse(
            updater_is_maintenance_mode(),
            'A failed update must never leave the site stuck in maintenance mode'
        );
        $this->assertSame('failed', $logged[0][2]);

        // The bar must stop short rather than claim completion.
        $this->assertNotSame('done', end($states)['phase']);
        $this->assertLessThan(100, end($states)['percent']);
    }

    public function test_apply_with_nothing_to_apply_touches_nothing(): void
    {
        $this->requireZip();

        $root = $this->makeTempRoot();
        file_put_contents($root . '/VERSION', "2.0.0\n");
        $zip = $this->makeReleaseZip(['VERSION' => "2.0.0\n"]);

        updater_set_root_override($root);
        $logged = [];
        $result = updater_apply(null, $this->deps($zip, $logged));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('No update is available to apply.', $result['error']);
        $this->assertDirectoryDoesNotExist($root . '/backups', 'Nothing to apply must mean nothing was backed up');
        $this->assertSame([], $logged, 'A no-op must not write an audit row');
    }

    public function test_apply_reports_a_progress_callback_that_throws_without_failing(): void
    {
        $this->requireZip();

        $root = $this->makeTempRoot();
        file_put_contents($root . '/VERSION', "1.0.0\n");
        $zip = $this->makeReleaseZip(['VERSION' => "2.0.0\n"]);

        updater_set_root_override($root);
        $logged = [];
        $result = updater_apply(
            static function (array $state): void { throw new RuntimeException('the UI blew up'); },
            $this->deps($zip, $logged)
        );

        $this->assertSame('success', $result['status'], 'Reporting must never be able to break the update');
        $this->assertSame("2.0.0\n", file_get_contents($root . '/VERSION'));
    }

    // ── Sites updated through git never self-update ─────────────────────────────

    public function test_self_update_is_allowed_on_a_plain_install(): void
    {
        updater_set_self_update_override(null);
        if (defined('FEATURE_SELF_UPDATE') && !FEATURE_SELF_UPDATE) {
            $this->markTestSkipped('This site switches self-update off in its own config — see the subprocess test below.');
        }
        updater_set_root_override($this->makeTempRoot());
        $this->assertTrue(updater_self_update_allowed());
    }

    public function test_a_git_folder_switches_self_update_off(): void
    {
        updater_set_self_update_override(null);
        $root = $this->makeTempRoot();
        mkdir($root . '/.git');
        updater_set_root_override($root);
        $this->assertFalse(updater_self_update_allowed());
    }

    public function test_apply_on_a_git_site_touches_nothing(): void
    {
        $this->requireZip();

        updater_set_self_update_override(null);
        $root = $this->makeTempRoot();
        mkdir($root . '/.git');
        file_put_contents($root . '/VERSION', "1.0.0\n");
        file_put_contents($root . '/theme.css', '/* the fork’s own */');
        $zip = $this->makeReleaseZip(['VERSION' => "2.0.0\n", 'theme.css' => '/* upstream */']);
        updater_set_root_override($root);

        $logged = [];
        $result = updater_apply(null, $this->deps($zip, $logged));

        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame("1.0.0\n", file_get_contents($root . '/VERSION'));
        $this->assertSame('/* the fork’s own */', file_get_contents($root . '/theme.css'));
        $this->assertFalse(updater_is_maintenance_mode(), 'A refused update must not leave maintenance mode on');
        $this->assertSame([], $logged, 'Nothing was attempted, so nothing is logged');
    }

    public function test_feature_self_update_true_wins_over_a_git_folder(): void
    {
        // The install that tests this feature is itself a git clone, so an
        // explicit true has to beat the .git heuristic.
        $root = $this->makeTempRoot();
        mkdir($root . '/.git');
        $code = sprintf(
            'define("ROOT_PATH", %s); define("FEATURE_SELF_UPDATE", true); require %s; echo var_export(updater_self_update_allowed(), true);',
            var_export($root, true),
            var_export(dirname(__DIR__) . '/includes/updater.php', true)
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertSame('true', trim((string) $out));
    }

    public function test_feature_self_update_false_switches_it_off(): void
    {
        // Constants can't be undefined, so this runs in its own PHP process.
        $root = $this->makeTempRoot();
        $code = sprintf(
            'define("ROOT_PATH", %s); define("FEATURE_SELF_UPDATE", false); require %s; echo var_export(updater_self_update_allowed(), true);',
            var_export($root, true),
            var_export(dirname(__DIR__) . '/includes/updater.php', true)
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertSame('false', trim((string) $out));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function requireZip(): void
    {
        if (!updater_zip_available()) {
            $this->markTestSkipped('ext-zip is not available — updater_apply() cannot run without it.');
        }
    }

    private function makeTempRoot(): string
    {
        $dir = sys_get_temp_dir() . '/om-updater-root-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    /** @param array<string,string> $files relative path => contents */
    private function makeReleaseZip(array $files): string
    {
        $path = sys_get_temp_dir() . '/om-updater-release-' . bin2hex(random_bytes(6)) . '.zip';
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $rel => $contents) {
            $zip->addFromString($rel, $contents);
        }
        $zip->close();
        $this->tempDirs[] = $path;
        return $path;
    }

    /**
     * The $deps bundle that keeps a run entirely local: a fetcher that serves a
     * canned GitHub release plus the zip bytes, a migrator that succeeds, and a
     * logger that collects its calls instead of writing to the database.
     *
     * @param array<int,array> $logged collected by reference
     */
    private function deps(string $zipPath, array &$logged): array
    {
        $zipUrl = 'https://example.test/release.zip';

        return [
            'http' => static function (string $url) use ($zipUrl, $zipPath): array {
                if (str_contains($url, 'api.github.com')) {
                    return ['ok' => true, 'status' => 200, 'error' => null, 'body' => json_encode([
                        'tag_name' => 'v2.0.0',
                        'body'     => 'Release notes',
                        'assets'   => [['name' => 'release.zip', 'browser_download_url' => $zipUrl]],
                    ])];
                }
                if ($url === $zipUrl) {
                    return ['ok' => true, 'status' => 200, 'error' => null, 'body' => (string) file_get_contents($zipPath)];
                }
                return ['ok' => false, 'status' => 404, 'body' => '', 'error' => 'unexpected URL: ' . $url];
            },
            'migrator' => static fn(): array => ['success' => true, 'error' => null],
            'logger'   => static function (string $from, string $to, string $status, array $skipped, ?string $error) use (&$logged): void {
                $logged[] = [$from, $to, $status, $skipped, $error];
            },
        ];
    }

    /** @param int[] $values */
    private function sortedCopy(array $values): array
    {
        sort($values);
        return $values;
    }
}
