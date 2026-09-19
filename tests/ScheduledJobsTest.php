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

    private function runJob(string $key, string $body, array $args = []): string
    {
        $root   = var_export($_SERVER['DOCUMENT_ROOT'], true);
        $script = tempnam(sys_get_temp_dir(), 'om_job_') . '.php';
        file_put_contents($script, "<?php
            \$_SERVER['DOCUMENT_ROOT'] = {$root};
            require {$root} . '/includes/scheduled_jobs.php';
            scheduled_job_track(" . var_export($key, true) . ");
            {$body}");
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        exec($cmd . ' 2>&1', $out);
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
        }
    }
}
