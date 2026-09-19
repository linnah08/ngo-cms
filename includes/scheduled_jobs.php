<?php
/**
 * Scheduled (cron) jobs: what they are, the exact command to paste into
 * cPanel → Cron Jobs, and whether each one is actually running.
 *
 * Each job script calls scheduled_job_track('<key>') at the top; when the run
 * ends without a crash, the time is stored in the settings table. Admin →
 * Автоматични задачи (admin/scheduled-jobs.php) and the dashboard warning read
 * it back through scheduled_job_status().
 */

require_once __DIR__ . '/settings.php';

/**
 * key => [
 *   label, what (plain Bulgarian), script (relative to the site root),
 *   cron  (minute, hour, day, month, weekday — cPanel's five fields),
 *   every (plain Bulgarian), grace (seconds without a run before we warn),
 *   needed_when (shown when the job isn't needed right now)
 * ]
 */
function scheduled_jobs(): array
{
    return [
        'unpaid_orders' => [
            'label'       => 'Неплатени поръчки',
            'what'        => 'Изпраща на купувача имейл, когато плащането с карта не е минало, и отказва поръчката след 24 часа, като връща продуктите в наличност.',
            'script'      => 'cron/unpaid-orders-cron.php',
            'cron'        => ['*/15', '*', '*', '*', '*'],
            'every'       => 'на всеки 15 минути',
            'grace'       => 2 * 3600,
            'needed_when' => 'Нужна е само ако е включено плащането с карта (Админ → Плащания).',
        ],
        'publish_articles' => [
            'label'       => 'Публикуване на планирани статии',
            'what'        => 'Публикува статиите, при които е отметнато „Публикувай автоматично на тази дата“, когато датата им дойде.',
            'script'      => 'admin/publish-scheduled.php',
            'cron'        => ['0', '6', '*', '*', '*'],
            'every'       => 'всяка сутрин',
            'grace'       => 36 * 3600,
            'needed_when' => 'Нужна е само когато имате статия, планирана за автоматично публикуване.',
        ],
        'newsletter_scheduled' => [
            'label'       => 'Изпращане на планирани бюлетини',
            'what'        => 'Изпраща бюлетините, на които сте задали дата за изпращане, когато датата дойде.',
            'script'      => 'cron/newsletter-send-scheduled-cron.php',
            'cron'        => ['0', '9', '*', '*', '*'],
            'every'       => 'всяка сутрин',
            'grace'       => 36 * 3600,
            'needed_when' => 'Нужна е само когато имате бюлетин с дата за изпращане.',
        ],
        'monthly_report' => [
            'label'       => 'Месечен отчет за поръчките',
            'what'        => 'На 1-во число изпраща на имейла на организацията отчет за поръчките, даренията и документите от предходния месец.',
            'script'      => 'cron/monthly-report-cron.php',
            'cron'        => ['0', '6', '1', '*', '*'],
            'every'       => 'на 1-во число всеки месец',
            'grace'       => 33 * 86400,
            'needed_when' => 'Нужна е, когато сайтът приема поръчки или дарения.',
        ],
        'error_digest' => [
            'label'       => 'Обобщение на грешките',
            'what'        => 'Изпраща дневно или седмично обобщение на грешките в сайта (Админ → Известия за грешки).',
            'script'      => 'admin/send-error-digest.php',
            'cron'        => ['0', '8', '*', '*', '*'],
            'every'       => 'всяка сутрин',
            'grace'       => 36 * 3600,
            'needed_when' => 'Нужна е само ако известията за грешки са включени с дневно или седмично обобщение.',
        ],
    ];
}

// ── Recording runs ────────────────────────────────────────────────────────────

function scheduled_job_record_run(string $key, ?int $when = null): void
{
    setting_set('job_last_run_' . $key, (string) ($when ?? time()));
}

function scheduled_job_last_run(string $key): ?int
{
    $v = setting_get('job_last_run_' . $key);
    return ctype_digit($v) ? (int) $v : null;
}

/**
 * Call at the top of a job script. Records the run when the script finishes —
 * but not after a crash or uncaught exception, and not for a --dry-run.
 */
function scheduled_job_track(string $key): void
{
    global $argv;
    if (in_array('--dry-run', (array) ($argv ?? []), true)) return;

    $failed = false;
    $prev   = set_exception_handler(null);
    set_exception_handler(function (Throwable $e) use (&$failed, $prev): void {
        $failed = true;
        if ($prev) {
            $prev($e);
        } else {
            fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n");
            exit(255);
        }
    });

    register_shutdown_function(function () use ($key, &$failed): void {
        $e = error_get_last();
        $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        if (!$failed && !$fatal) scheduled_job_record_run($key);
    });
}

// ── Status ────────────────────────────────────────────────────────────────────

/**
 * When run tracking started on this site. Jobs that have never reported are
 * given their grace period from this moment before being called missing, so
 * that sites whose cron was already set up aren't warned right after updating.
 */
function scheduled_jobs_tracking_since(): int
{
    $v = setting_get('jobs_tracking_since');
    if (ctype_digit($v)) return (int) $v;
    $now = time();
    setting_set('jobs_tracking_since', (string) $now);
    return $now;
}

/**
 * 'ok'      — ran within its grace period
 * 'late'    — has run before, but not for longer than the grace period
 * 'waiting' — no run recorded yet, still within the grace period
 * 'missing' — no run recorded, grace period over (most likely never set up)
 */
function scheduled_job_status(int $grace, ?int $lastRun, int $trackingSince, int $now): string
{
    if ($lastRun !== null) {
        return ($now - $lastRun) <= $grace ? 'ok' : 'late';
    }
    return ($now - $trackingSince) <= $grace ? 'waiting' : 'missing';
}

/** Is the job needed on this site right now? (Unknown → assume yes.) */
function scheduled_job_needed(string $key): bool
{
    try {
        switch ($key) {
            case 'unpaid_orders':
                return setting_get('dsk_enabled', '0') === '1';

            case 'publish_articles':
                require_once __DIR__ . '/articles.php';
                $dir = (defined('ARTICLES_PATH') ? ARTICLES_PATH : dirname(__DIR__) . '/content/articles') . '/bg';
                foreach (glob($dir . '/*.json') ?: [] as $file) {
                    $a = json_decode((string) @file_get_contents($file), true);
                    if (is_array($a) && article_is_scheduled($a, @filemtime($file) ?: null)) return true;
                }
                return false;

            case 'newsletter_scheduled':
                $stmt = get_pdo()->query("SELECT 1 FROM newsletter_campaigns WHERE status = 'draft' AND send_date IS NOT NULL LIMIT 1");
                return (bool) $stmt->fetchColumn();

            case 'monthly_report':
                return (bool) get_pdo()->query('SELECT 1 FROM orders LIMIT 1')->fetchColumn();

            case 'error_digest':
                return setting_get('error_alert_enabled') === '1'
                    && in_array(setting_get('error_alert_frequency', 'immediate'), ['daily', 'weekly'], true);
        }
    } catch (Throwable $e) {
        error_log('scheduled_job_needed(' . $key . '): ' . $e->getMessage());
    }
    return true;
}

/**
 * Jobs that need attention: needed on this site AND late or missing.
 * Returns [key => ['job' => array, 'status' => string, 'last' => ?int]].
 */
function scheduled_jobs_problems(?int $now = null): array
{
    $now   = $now ?? time();
    $since = scheduled_jobs_tracking_since();
    $out   = [];
    foreach (scheduled_jobs() as $key => $job) {
        $last   = scheduled_job_last_run($key);
        $status = scheduled_job_status($job['grace'], $last, $since, $now);
        if (($status === 'late' || $status === 'missing') && scheduled_job_needed($key)) {
            $out[$key] = ['job' => $job, 'status' => $status, 'last' => $last];
        }
    }
    return $out;
}

// ── Command ───────────────────────────────────────────────────────────────────

/** The PHP command-line binary cron should use — same version as the site when we can tell. */
function scheduled_jobs_php_binary(): string
{
    foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php'] as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    return '/usr/local/bin/php';
}

/** Quote a path only if it needs it, so the usual command stays readable. */
function scheduled_jobs_shell_path(string $path): string
{
    return preg_match('#^[A-Za-z0-9_./\-]+$#', $path) ? $path : escapeshellarg($path);
}

/**
 * The exact line for cPanel's "Command" field. Output goes to logs/ so cPanel
 * doesn't email the admin after every run.
 */
function scheduled_job_command(array $job, string $key, string $siteRoot, ?string $php = null): string
{
    $root = rtrim($siteRoot, '/');
    return scheduled_jobs_shell_path($php ?? scheduled_jobs_php_binary())
        . ' ' . scheduled_jobs_shell_path($root . '/' . $job['script'])
        . ' >> ' . scheduled_jobs_shell_path($root . '/logs/cron-' . $key . '.log') . ' 2>&1';
}

/** "преди 5 минути" / "преди 3 часа" / "преди 2 дни" */
function scheduled_jobs_ago(int $ts, int $now): string
{
    $d = max(0, $now - $ts);
    if ($d < 90)        return 'току-що';
    if ($d < 3600)      return 'преди ' . (int) round($d / 60) . ' мин.';
    if ($d < 2 * 86400) return 'преди ' . (int) round($d / 3600) . ' ч.';
    return 'преди ' . (int) round($d / 86400) . ' дни';
}
