<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * includes/scheduled_jobs.php — Admin → Автоматични задачи and the dashboard
 * warning for cron jobs that aren't running.
 */
final class ScheduledJobsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php';
    }

    public function test_every_job_script_exists_and_is_tracked(): void
    {
        $this->assertCount(5, scheduled_jobs());
        foreach (scheduled_jobs() as $key => $job) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/' . $job['script'];
            $this->assertFileExists($path, $key);
            $this->assertStringContainsString("scheduled_job_track('{$key}')", (string) file_get_contents($path), $key);
            $this->assertCount(5, $job['cron'], $key);
            $this->assertGreaterThan(0, $job['grace'], $key);
        }
    }

    public function test_status(): void
    {
        $now = 1_800_000_000;
        $this->assertSame('ok',      scheduled_job_status(7200, $now - 600,   $now - 86400, $now));
        $this->assertSame('late',    scheduled_job_status(7200, $now - 7201,  $now - 86400, $now));
        $this->assertSame('waiting', scheduled_job_status(7200, null,         $now - 3600,  $now), 'just updated — give cron a chance');
        $this->assertSame('missing', scheduled_job_status(7200, null,         $now - 7201,  $now));
    }

    public function test_command_uses_real_paths_and_logs_output(): void
    {
        $job = scheduled_jobs()['unpaid_orders'];
        $this->assertSame(
            '/usr/local/bin/php /home/ngo/public_html/cron/unpaid-orders-cron.php >> /home/ngo/public_html/logs/cron-unpaid_orders.log 2>&1',
            scheduled_job_command($job, 'unpaid_orders', '/home/ngo/public_html/', '/usr/local/bin/php')
        );
    }

    public function test_command_quotes_unusual_paths(): void
    {
        $cmd = scheduled_job_command(scheduled_jobs()['monthly_report'], 'monthly_report', "/home/a b/site's", '/usr/local/bin/php');
        $this->assertStringContainsString("'/home/a b/site'\\''s/cron/monthly-report-cron.php'", $cmd);
    }

    public function test_ago(): void
    {
        $now = 1_800_000_000;
        $this->assertSame('току-що',      scheduled_jobs_ago($now - 30, $now));
        $this->assertSame('преди 15 мин.', scheduled_jobs_ago($now - 900, $now));
        $this->assertSame('преди 3 ч.',    scheduled_jobs_ago($now - 3 * 3600, $now));
        $this->assertSame('преди 5 дни',   scheduled_jobs_ago($now - 5 * 86400, $now));
    }

    // ── Recording runs (child process, real settings table) ──────────────────

    private function runJob(string $key, string $body, array $args = [], string $env = ''): string
    {
        $root   = var_export($_SERVER['DOCUMENT_ROOT'], true);
        $script = tempnam(sys_get_temp_dir(), 'om_job_') . '.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = {$root};
            require {$root} . '/includes/scheduled_jobs.php';
            scheduled_job_track(" . var_export($key, true) . ");
            {$body}");
        $cmd = $env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        exec($cmd . ' < /dev/null 2>&1', $out);
        @unlink($script);
        return implode("\n", $out);
    }

    public function test_run_is_recorded_only_when_the_job_finishes(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No local DB');
        $key = 'phpunit_' . bin2hex(random_bytes(4));
        try {
            $this->runJob($key, 'throw new RuntimeException("boom");');
            $this->assertNull(scheduled_job_last_run($key), 'uncaught exception → not recorded');

            $this->runJob($key, 'undefined_function_xyz();');
            $this->assertNull(scheduled_job_last_run($key), 'fatal error → not recorded');

            $this->runJob($key, 'echo "ok";', ['--dry-run']);
            $this->assertNull(scheduled_job_last_run($key), 'dry run → not recorded');

            $this->runJob($key, 'echo "nothing to do"; exit(0);');
            $last = scheduled_job_last_run($key);
            $this->assertNotNull($last, 'normal finish (even an early exit) → recorded');
            $this->assertEqualsWithDelta(time(), $last, 30);
        } finally {
            setting_delete('job_last_run_' . $key);
            setting_delete('job_last_cron_' . $key);
        }
    }

    public function test_cron_run_counts_as_scheduled_and_visit_run_does_not(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No local DB');
        $key = 'phpunit_' . bin2hex(random_bytes(4));
        try {
            $this->runJob($key, 'echo "ok";');
            $this->assertNotNull(scheduled_job_last_cron($key), 'no terminal → the server schedule ran it');

            setting_delete('job_last_cron_' . $key);
            setting_delete('job_last_run_' . $key);
            $this->runJob($key, 'echo "ok";', [], 'NGO_CMS_JOB_SOURCE=visit ');
            $this->assertNotNull(scheduled_job_last_run($key), 'a visit run keeps the job working');
            $this->assertNull(scheduled_job_last_cron($key), '…but is not the server schedule');
        } finally {
            foreach (['run', 'cron', 'manual'] as $k) setting_delete("job_last_{$k}_" . $key);
        }
    }

    public function test_manual_run_is_kept_apart(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No local DB');
        $key = 'phpunit_' . bin2hex(random_bytes(4));
        try {
            scheduled_job_record_run($key, 1_800_000_000, 'manual');
            $this->assertNull(scheduled_job_last_run($key), 'a run by hand is not "working"');
            $this->assertSame(1_800_000_000, scheduled_job_last_manual($key));
        } finally {
            setting_delete('job_last_manual_' . $key);
        }
    }

    public function test_run_source(): void
    {
        $this->assertSame('visit',  scheduled_job_run_source('visit', true));
        $this->assertSame('manual', scheduled_job_run_source('', true), 'a terminal → someone typed it');
        $this->assertSame('cron',   scheduled_job_run_source('', false));
        $this->assertSame('cron',   scheduled_job_run_source('anything', false));
    }

    // ── Schedule maths ────────────────────────────────────────────────────────

    private function at(string $when): int
    {
        return (new DateTimeImmutable($when, new DateTimeZone(date_default_timezone_get())))->getTimestamp();
    }

    public function test_cron_fields(): void
    {
        $this->assertTrue(scheduled_cron_field_matches('*', 7));
        $this->assertTrue(scheduled_cron_field_matches('*/15', 45));
        $this->assertFalse(scheduled_cron_field_matches('*/15', 7));
        $this->assertTrue(scheduled_cron_field_matches('6', 6));
        $this->assertFalse(scheduled_cron_field_matches('6', 16));
        $this->assertTrue(scheduled_cron_field_matches('1,15', 15));
        $this->assertTrue(scheduled_cron_field_matches('1-5', 3));
        $this->assertFalse(scheduled_cron_field_matches('1-5', 6));
    }

    public function test_previous_scheduled_time(): void
    {
        $jobs = scheduled_jobs();
        $this->assertSame($this->at('2026-10-11 10:00'), scheduled_cron_previous($jobs['unpaid_orders']['cron'], $this->at('2026-10-11 10:07')));
        $this->assertSame($this->at('2026-10-11 10:15'), scheduled_cron_previous($jobs['unpaid_orders']['cron'], $this->at('2026-10-11 10:15')));
        $this->assertSame($this->at('2026-10-10 06:00'), scheduled_cron_previous($jobs['publish_articles']['cron'], $this->at('2026-10-11 05:59')), 'before 6:00 → yesterday');
        $this->assertSame($this->at('2026-10-11 06:00'), scheduled_cron_previous($jobs['publish_articles']['cron'], $this->at('2026-10-11 14:00')));
        $this->assertSame($this->at('2026-10-01 06:00'), scheduled_cron_previous($jobs['monthly_report']['cron'], $this->at('2026-10-11 14:00')));
        $this->assertSame($this->at('2026-09-01 06:00'), scheduled_cron_previous($jobs['monthly_report']['cron'], $this->at('2026-10-01 05:00')));
        $this->assertSame($this->at('2026-10-05 08:00'), scheduled_cron_previous(['0', '8', '*', '*', '1'], $this->at('2026-10-11 09:00')), 'weekday 1 = Monday');
    }

    public function test_due_on_visit(): void
    {
        $job = scheduled_jobs()['publish_articles']; // 6:00 daily, 36h grace
        $now = $this->at('2026-10-11 09:00');
        $six = $this->at('2026-10-11 06:00');

        $this->assertTrue(scheduled_job_due_on_visit($job, $six - 86400, null, null, $now), 'yesterday\'s run done, today\'s not');
        $this->assertTrue(scheduled_job_due_on_visit($job, null, null, null, $now), 'never ran');
        $this->assertFalse(scheduled_job_due_on_visit($job, $six + 60, null, null, $now), 'already done today');
        $this->assertFalse(scheduled_job_due_on_visit($job, $six - 86400, $six - 86400, null, $now), 'the server schedule runs it — leave it to cron');
        $this->assertFalse(scheduled_job_due_on_visit($job, $six - 86400, null, $now - 60, $now), 'a visit started it a minute ago');
        $this->assertTrue(scheduled_job_due_on_visit($job, $six - 86400, null, $now - 3600, $now), 'that run never finished — try again');
        $this->assertFalse(scheduled_job_due_on_visit($job, $six - 86400, null, null, $six + 60), 'cron gets the first minutes to itself');
        $this->assertTrue(scheduled_job_due_on_visit($job, $six - 86400, $six - 3 * 86400, null, $now), 'cron stopped days ago → visits take over');
    }

    // ── The server's schedule ────────────────────────────────────────────────

    private const ROOT = '/home/ngo/public_html/site';
    private const PHP  = '/usr/local/bin/php';
    private const OTHER = "MAILTO=\"\"\nSHELL=\"/bin/bash\"\n0 6 * * * /usr/local/bin/php /home/ngo/public_html/other/cron/monthly-report-cron.php >> /x.log 2>&1\n# a comment\n";

    public function test_cron_line_is_labelled_and_runs_the_command(): void
    {
        $job  = scheduled_jobs()['unpaid_orders'];
        $line = scheduled_job_cron_line($job, 'unpaid_orders', self::ROOT, self::PHP);
        $this->assertStringStartsWith('*/15 * * * * ' . scheduled_job_command($job, 'unpaid_orders', self::ROOT, self::PHP) . ' # ngo-cms:', $line);
        $this->assertStringEndsWith(':unpaid_orders', $line);
        $this->assertNotSame(scheduled_job_cron_marker('unpaid_orders', self::ROOT), scheduled_job_cron_marker('unpaid_orders', '/home/ngo/public_html/other'), 'two sites on one account have different labels');
        $this->assertStringContainsString('\%', scheduled_job_cron_line($job, 'unpaid_orders', '/home/ngo/100%site', self::PHP), '% must be escaped in crontab');
    }

    public function test_adding_keeps_every_other_line(): void
    {
        $new = scheduled_jobs_crontab_with(self::OTHER, ['unpaid_orders', 'monthly_report'], self::ROOT, self::PHP);
        $this->assertStringStartsWith(self::OTHER, $new, 'other sites\' jobs, settings and comments untouched');
        $this->assertSame('ours', scheduled_job_in_crontab($new, 'unpaid_orders', scheduled_jobs()['unpaid_orders'], self::ROOT));
        $this->assertSame('ours', scheduled_job_in_crontab($new, 'monthly_report', scheduled_jobs()['monthly_report'], self::ROOT));
        $this->assertNull(scheduled_job_in_crontab(self::OTHER, 'monthly_report', scheduled_jobs()['monthly_report'], self::ROOT), 'another site\'s monthly report is not ours');
        $this->assertSame($new, scheduled_jobs_crontab_with($new, ['unpaid_orders', 'monthly_report'], self::ROOT, self::PHP), 'adding twice changes nothing');
        $this->assertStringEndsWith("\n", $new);

        $newer = scheduled_jobs_crontab_with($new, ['unpaid_orders'], self::ROOT, '/opt/alt/php85/usr/bin/php');
        $this->assertSame(1, substr_count($newer, ':unpaid_orders'), 'our line is replaced, not duplicated');
        $this->assertStringContainsString('/opt/alt/php85/usr/bin/php', $newer);
    }

    public function test_adding_from_empty_crontab(): void
    {
        $new = scheduled_jobs_crontab_with('', ['error_digest'], self::ROOT, self::PHP);
        $this->assertSame(scheduled_job_cron_line(scheduled_jobs()['error_digest'], 'error_digest', self::ROOT, self::PHP) . "\n", $new);
    }

    public function test_job_added_by_hand_is_left_alone(): void
    {
        $byHand = self::OTHER . "*/15 * * * * /usr/bin/php " . self::ROOT . "/cron/unpaid-orders-cron.php\n";
        $this->assertSame('by_hand', scheduled_job_in_crontab($byHand, 'unpaid_orders', scheduled_jobs()['unpaid_orders'], self::ROOT));
        $this->assertSame($byHand, scheduled_jobs_crontab_with($byHand, ['unpaid_orders'], self::ROOT, self::PHP), 'not added a second time');
        $this->assertSame($byHand, scheduled_jobs_crontab_without($byHand, ['unpaid_orders'], self::ROOT), 'and never removed — only labelled lines are');
    }

    public function test_removing_takes_only_our_line(): void
    {
        $with = scheduled_jobs_crontab_with(self::OTHER, ['unpaid_orders', 'monthly_report'], self::ROOT, self::PHP);
        $without = scheduled_jobs_crontab_without($with, ['unpaid_orders'], self::ROOT);
        $this->assertSame(scheduled_jobs_crontab_with(self::OTHER, ['monthly_report'], self::ROOT, self::PHP), $without);
        $this->assertSame(self::OTHER, scheduled_jobs_crontab_without($without, ['monthly_report'], self::ROOT));
    }

    /** A pretend `crontab` program: [runner, state with ->tab and ->calls]. */
    private function fakeCrontab(?string $start, array $opts = []): array
    {
        $state = (object) ['tab' => $start, 'calls' => []];
        $run = function (array $argv) use ($state, $opts): ?array {
            $state->calls[] = $argv[1];
            if (!empty($opts['no_programs'])) return null;
            if ($argv[1] === '-l') {
                if (!empty($opts['read_fails'])) return ['exit' => 1, 'stdout' => '', 'stderr' => 'crontab: Permission denied'];
                return $state->tab === null
                    ? ['exit' => 1, 'stdout' => '', 'stderr' => 'no crontab for ngo']
                    : ['exit' => 0, 'stdout' => $state->tab, 'stderr' => ''];
            }
            $content = (string) file_get_contents($argv[1]);
            $state->tab = !empty($opts['mangle']) && count($state->calls) <= 2 ? "garbage\n" : $content;
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        };
        return [$run, $state];
    }

    private function backupDir(): string
    {
        $d = sys_get_temp_dir() . '/ngo_cron_test_' . bin2hex(random_bytes(4));
        mkdir($d);
        return $d;
    }

    public function test_change_installs_and_keeps_a_copy(): void
    {
        [$run, $state] = $this->fakeCrontab(self::OTHER);
        $dir = $this->backupDir();
        $r = scheduled_jobs_crontab_change(fn ($c) => $c . "* * * * * new\n", $run, $dir);
        $this->assertTrue($r['ok']);
        $this->assertSame(self::OTHER . "* * * * * new\n", $state->tab);
        $copies = glob($dir . '/crontab-backup-*.txt');
        $this->assertCount(1, $copies);
        $this->assertSame(self::OTHER, file_get_contents($copies[0]));
        array_map('unlink', $copies); rmdir($dir);
    }

    public function test_change_from_no_crontab(): void
    {
        [$run, $state] = $this->fakeCrontab(null);
        $dir = $this->backupDir();
        $this->assertTrue(scheduled_jobs_crontab_change(fn ($c) => $c . "* * * * * new\n", $run, $dir)['ok']);
        $this->assertSame("* * * * * new\n", $state->tab);
        array_map('unlink', glob($dir . '/*')); rmdir($dir);
    }

    public function test_change_writes_nothing_when_the_schedule_cant_be_read(): void
    {
        foreach ([['read_fails' => true], ['no_programs' => true]] as $opts) {
            [$run, $state] = $this->fakeCrontab(self::OTHER, $opts);
            $r = scheduled_jobs_crontab_change(fn ($c) => "* * * * * new\n", $run, $this->backupDir());
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('ръчно', $r['message'], 'tells the admin what to do instead');
            $this->assertSame(['-l'], $state->calls, 'never installs over a schedule it couldn\'t read');
            $this->assertSame(self::OTHER, $state->tab);
        }
    }

    public function test_change_puts_the_old_schedule_back_if_it_didnt_stick(): void
    {
        [$run, $state] = $this->fakeCrontab(self::OTHER, ['mangle' => true]);
        $dir = $this->backupDir();
        $r = scheduled_jobs_crontab_change(fn ($c) => $c . "* * * * * new\n", $run, $dir);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('върнахме предишния', $r['message']);
        $this->assertSame(self::OTHER, $state->tab, 'the old schedule is back');
        array_map('unlink', glob($dir . '/*')); rmdir($dir);
    }

    public function test_no_change_needed_touches_nothing(): void
    {
        [$run, $state] = $this->fakeCrontab(self::OTHER);
        $this->assertTrue(scheduled_jobs_crontab_change(fn ($c) => $c, $run, '/nonexistent')['ok']);
        $this->assertSame(['-l'], $state->calls);
    }

    public function test_terminal_command_adds_safely_once(): void
    {
        if (!is_executable('/bin/sh')) $this->markTestSkipped('no sh');
        $job  = scheduled_jobs()['unpaid_orders'];
        $cmd  = scheduled_job_terminal_command($job, 'unpaid_orders', self::ROOT, self::PHP);
        $home = sys_get_temp_dir() . '/ngo_term_' . bin2hex(random_bytes(4));
        mkdir($home . '/bin', 0777, true);
        file_put_contents($home . '/tab', self::OTHER);
        file_put_contents($home . '/bin/crontab', "#!/bin/sh\nif [ \"\$1\" = -l ]; then cat \"\$HOME/tab\"; else cp \"\$1\" \"\$HOME/tab\"; fi\n");
        chmod($home . '/bin/crontab', 0755);
        $env = 'HOME=' . escapeshellarg($home) . ' PATH=' . escapeshellarg($home . '/bin:/usr/bin:/bin');
        exec($env . ' /bin/sh -c ' . escapeshellarg($cmd) . ' 2>&1', $o1, $e1);
        exec($env . ' /bin/sh -c ' . escapeshellarg($cmd) . ' 2>&1', $o2, $e2);
        $tab = (string) file_get_contents($home . '/tab');
        exec('rm -rf ' . escapeshellarg($home));
        $this->assertSame(0, $e1, implode("\n", $o1));
        $this->assertSame(0, $e2);
        $this->assertSame(self::OTHER . scheduled_job_cron_line($job, 'unpaid_orders', self::ROOT, self::PHP) . "\n", $tab, 'added once, everything else kept');
    }

    public function test_visits_never_start_jobs_on_the_dev_server(): void
    {
        // `php -S` serves local copies of the live database (and these tests):
        // starting jobs there could mail real customers from a laptop.
        require_once __DIR__ . '/Support/TestServer.php';
        $base = TestServer::start();
        if ($base === '') $this->markTestSkipped('test server did not start');
        $stamp = $_SERVER['DOCUMENT_ROOT'] . '/logs/.jobs-visit-checked';
        $had   = is_file($stamp) ? filemtime($stamp) : null;
        try {
            $ch = curl_init($base . '/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            $body = (string) curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $this->assertSame(200, $code, substr($body, 0, 500));
            $this->assertSame($had, is_file($stamp) ? filemtime($stamp) : null, 'the visit runner did not even look');
        } finally {
            TestServer::stop();
        }
    }

    public function test_command_line_never_starts_jobs_on_visit(): void
    {
        $stamp = $_SERVER['DOCUMENT_ROOT'] . '/logs/.jobs-visit-checked';
        $had   = is_file($stamp) ? filemtime($stamp) : null;
        scheduled_jobs_run_on_visit();
        $this->assertSame($had, is_file($stamp) ? filemtime($stamp) : null);
    }

    // ── Starting a job from a visit ──────────────────────────────────────────

    public function test_spawn_runs_the_job_in_the_background_as_a_visit_run(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No local DB');
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/updater.php';
        if (!updater_proc_open_enabled() || scheduled_jobs_cli_php() === null) $this->markTestSkipped('cannot start programs here');

        $key = 'phpunit_' . bin2hex(random_bytes(4));
        $rel = 'tests/fixtures/scheduled-job-' . $key . '.php';
        $abs = $_SERVER['DOCUMENT_ROOT'] . '/' . $rel;
        @mkdir(dirname($abs));
        file_put_contents($abs, "<?php\n\$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);\nrequire dirname(__DIR__, 2) . '/includes/scheduled_jobs.php';\nscheduled_job_track(" . var_export($key, true) . ");\necho \"ran\\n\";\n");
        $log = $_SERVER['DOCUMENT_ROOT'] . '/logs/cron-' . $key . '.log';
        try {
            $t0 = microtime(true);
            $this->assertTrue(scheduled_job_spawn($key, ['script' => $rel] + scheduled_jobs()['unpaid_orders']));
            $this->assertLessThan(2.0, microtime(true) - $t0, 'returns without waiting for the job');
            for ($i = 0; $i < 100 && scheduled_job_last_run($key) === null; $i++) usleep(100_000);
            $this->assertNotNull(scheduled_job_last_run($key), 'the job ran and was recorded');
            $this->assertNull(scheduled_job_last_cron($key), 'as a visit run, not the server schedule');
            $this->assertSame("ran\n", file_get_contents($log), 'its output went to its log');
        } finally {
            @unlink($abs); @unlink($log); @rmdir(dirname($abs)); // only if we made it and it is empty
            foreach (['run', 'cron', 'manual'] as $k) setting_delete("job_last_{$k}_" . $key);
        }
    }
}
