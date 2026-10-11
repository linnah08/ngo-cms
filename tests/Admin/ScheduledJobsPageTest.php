<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Admin → Автоматични задачи: „Добави в графика“ / „Махни от графика“.
 *
 * The page runs in its own process (run_admin_page) with a pretend `crontab`
 * first on PATH, so the real schedule of the machine running the tests is
 * never read or changed.
 */
final class ScheduledJobsPageTest extends TestCase
{
    private const OTHER = "MAILTO=\"\"\n0 6 * * * /usr/local/bin/php /home/x/other-site/cron/monthly-report-cron.php >> /x.log 2>&1\n";

    private string $dir;
    private string $oldPath;
    private array $oldBackups;
    private array $savedSettings = [];

    protected function setUp(): void
    {
        if (!test_db_available()) $this->markTestSkipped('No local DB');
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/scheduled_jobs.php';

        $this->dir = sys_get_temp_dir() . '/ngo_page_cron_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);
        file_put_contents($this->dir . '/bin/crontab', <<<'SH'
#!/bin/sh
T="$FAKE_CRONTAB_DIR/tab"
[ -f "$FAKE_CRONTAB_DIR/broken" ] && { echo "crontab: Permission denied" >&2; exit 1; }
if [ "$1" = "-l" ]; then
  [ -f "$T" ] || { echo "no crontab for test" >&2; exit 1; }
  cat "$T"
else
  cp "$1" "$T"
fi
SH);
        chmod($this->dir . '/bin/crontab', 0755);
        file_put_contents($this->dir . '/tab', self::OTHER);

        $this->oldPath = (string) getenv('PATH');
        putenv('PATH=' . $this->dir . '/bin:' . $this->oldPath);
        putenv('FAKE_CRONTAB_DIR=' . $this->dir);

        $this->oldBackups = glob($_SERVER['DOCUMENT_ROOT'] . '/logs/crontab-backup-*.txt') ?: [];
        foreach (array_keys(scheduled_jobs()) as $k) {
            $this->savedSettings['job_cron_added_' . $k] = setting_get('job_cron_added_' . $k);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) return;
        putenv('PATH=' . $this->oldPath);
        putenv('FAKE_CRONTAB_DIR');
        exec('rm -rf ' . escapeshellarg($this->dir));
        foreach (array_diff(glob($_SERVER['DOCUMENT_ROOT'] . '/logs/crontab-backup-*.txt') ?: [], $this->oldBackups) as $f) @unlink($f);
        foreach ($this->savedSettings as $k => $v) {
            $v === '' ? setting_delete($k) : setting_set($k, $v);
        }
    }

    private function tab(): string
    {
        return (string) @file_get_contents($this->dir . '/tab');
    }

    private function root(): string
    {
        return rtrim((string) realpath($_SERVER['DOCUMENT_ROOT']), '/');
    }

    public function test_admins_only(): void
    {
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all'], ['role' => 'author']);
        $this->assertSame(403, $r['status']);
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all'], ['role' => null]);
        $this->assertStringNotContainsString('Добави в графика', $r['body'], 'logged out sees nothing');
        $this->assertSame(self::OTHER, $this->tab());
    }

    public function test_needs_a_valid_token(): void
    {
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all'], ['csrf' => 'wrong']);
        $this->assertSame(400, $r['status']);
        $this->assertSame(self::OTHER, $this->tab());
    }

    public function test_add_one_job_keeps_everything_else(): void
    {
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'unpaid_orders']);
        $this->assertSame('success', $r['flash'][0]['type'] ?? null, $r['body']);
        $tab = $this->tab();
        $this->assertStringStartsWith(self::OTHER, $tab, 'the other site\'s job is untouched');
        $this->assertSame('ours', scheduled_job_in_crontab($tab, 'unpaid_orders', scheduled_jobs()['unpaid_orders'], $this->root()));
        $this->assertSame(substr_count(self::OTHER, "\n") + 1, substr_count($tab, "\n"), 'exactly one line added');

        $backups = array_values(array_diff(glob($_SERVER['DOCUMENT_ROOT'] . '/logs/crontab-backup-*.txt') ?: [], $this->oldBackups));
        $this->assertCount(1, $backups);
        $this->assertSame(self::OTHER, file_get_contents($backups[0]), 'a copy of the schedule before the change');
    }

    public function test_add_all_twice_adds_each_once(): void
    {
        run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all']);
        $once = $this->tab();
        run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all']);
        $this->assertSame($once, $this->tab());
        foreach (scheduled_jobs() as $k => $job) {
            $this->assertSame('ours', scheduled_job_in_crontab($once, $k, $job, $this->root()), $k);
        }
    }

    public function test_remove_takes_only_our_line(): void
    {
        run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'all']);
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'remove', 'job' => 'monthly_report']);
        $this->assertSame('success', $r['flash'][0]['type'] ?? null, $r['body']);
        $tab = $this->tab();
        $this->assertNull(scheduled_job_in_crontab($tab, 'monthly_report', scheduled_jobs()['monthly_report'], $this->root()));
        $this->assertSame('ours', scheduled_job_in_crontab($tab, 'unpaid_orders', scheduled_jobs()['unpaid_orders'], $this->root()));
        $this->assertStringContainsString('/home/x/other-site/cron/monthly-report-cron.php', $tab, 'the other site\'s monthly report stays');
    }

    public function test_unknown_job_or_action_changes_nothing(): void
    {
        foreach ([['action' => 'add', 'job' => 'rm -rf /'], ['action' => 'remove', 'job' => 'all'], ['action' => 'drop', 'job' => 'unpaid_orders'], ['job' => ['x']]] as $post) {
            $r = run_admin_page('admin/scheduled-jobs.php', $post);
            $this->assertSame('error', $r['flash'][0]['type'] ?? null, json_encode($post) . "\n" . $r['body']);
        }
        $this->assertSame(self::OTHER, $this->tab());
    }

    public function test_host_that_wont_allow_it_gets_a_plain_message(): void
    {
        touch($this->dir . '/broken');
        $r = run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'unpaid_orders']);
        $this->assertSame('error', $r['flash'][0]['type'] ?? null);
        $this->assertStringContainsString('ръчно', $r['flash'][0]['message']);
        unlink($this->dir . '/broken');
        $this->assertSame(self::OTHER, $this->tab());
    }

    public function test_page_shows_buttons_and_manual_steps(): void
    {
        run_admin_page('admin/scheduled-jobs.php', ['action' => 'add', 'job' => 'unpaid_orders']);
        $r = run_admin_page('admin/scheduled-jobs.php', [], ['method' => 'GET']);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('Махни от графика', $r['body'], 'the added job can be removed');
        $this->assertStringContainsString('Добави в графика', $r['body'], 'the others can be added');
        $this->assertStringContainsString('Добави всички в графика', $r['body']);
        $this->assertStringContainsString('Ръчна настройка', $r['body']);
        $this->assertStringContainsString('не в Terminal', $r['body'], 'says where the command goes');
        $this->assertSame(substr_count($r['body'], 'type="submit"'), substr_count($r['body'], 'name="csrf_token"'), 'every form carries the token');
    }
}
