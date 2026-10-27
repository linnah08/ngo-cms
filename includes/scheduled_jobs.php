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
            'what'        => 'Изпраща на купувача имейл, когато плащането с карта или IRIS не е минало, и отказва поръчката след 3 дни, като връща продуктите в наличност.',
            'script'      => 'cron/unpaid-orders-cron.php',
            'cron'        => ['*/15', '*', '*', '*', '*'],
            'every'       => 'на всеки 15 минути',
            'grace'       => 2 * 3600,
            'needed_when' => 'Нужна е само ако е включено плащането с карта или IRIS (Админ → Плащания).',
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
        'sumup_stock' => [
            'label'       => 'Продажби от SumUp',
            'what'        => 'Всяка нощ взима от SumUp продажбите на място с картовия терминал и намалява наличността на продадените продукти в онлайн магазина. Върнатите продажби връщат продуктите в наличност.',
            'script'      => 'cron/sumup-stock-cron.php',
            'cron'        => ['30', '2', '*', '*', '*'],
            'every'       => 'всяка нощ',
            'grace'       => 36 * 3600,
            'needed_when' => 'Нужна е само ако SumUp е свързан (Админ → SumUp).',
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

/**
 * Where a run came from:
 *   'cron'   — the server's schedule (cron has no terminal),
 *   'visit'  — started by a page visit (scheduled_jobs_run_on_visit sets the env var),
 *   'manual' — someone ran the command by hand in a terminal.
 */
function scheduled_job_run_source(?string $env = null, ?bool $stdinIsTty = null): string
{
    $env = $env ?? (getenv('NGO_CMS_JOB_SOURCE') ?: '');
    if ($env === 'visit') return 'visit';
    $stdinIsTty = $stdinIsTty ?? (defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN));
    return $stdinIsTty ? 'manual' : 'cron';
}

/**
 * job_last_run_<key>    — last automatic run (server schedule or visit): what "working" means.
 * job_last_cron_<key>   — last run from the server's schedule.
 * job_last_manual_<key> — last run by hand. Doesn't count as working, but the
 *                         work was done, so a visit won't redo it.
 */
function scheduled_job_record_run(string $key, ?int $when = null, string $source = 'cron'): void
{
    $when = (string) ($when ?? time());
    if ($source === 'manual') {
        setting_set('job_last_manual_' . $key, $when);
        return;
    }
    setting_set('job_last_run_' . $key, $when);
    if ($source === 'cron') setting_set('job_last_cron_' . $key, $when);
}

function scheduled_job_setting_time(string $name): ?int
{
    $v = setting_get($name);
    return ctype_digit($v) ? (int) $v : null;
}

function scheduled_job_last_run(string $key): ?int
{
    return scheduled_job_setting_time('job_last_run_' . $key);
}

function scheduled_job_last_cron(string $key): ?int
{
    return scheduled_job_setting_time('job_last_cron_' . $key);
}

function scheduled_job_last_manual(string $key): ?int
{
    return scheduled_job_setting_time('job_last_manual_' . $key);
}

/**
 * Call at the top of a job script. Records the run when the script finishes —
 * but not after a crash or uncaught exception, and not for a --dry-run.
 */
function scheduled_job_track(string $key): void
{
    global $argv;
    if (in_array('--dry-run', (array) ($argv ?? []), true)) return;
    $source = scheduled_job_run_source();

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

    register_shutdown_function(function () use ($key, $source, &$failed): void {
        $e = error_get_last();
        $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        if (!$failed && !$fatal) scheduled_job_record_run($key, null, $source);
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
                if (!module_enabled_with_needs('newsletter')) return false;
                $stmt = get_pdo()->query("SELECT 1 FROM newsletter_campaigns WHERE status = 'draft' AND send_date IS NOT NULL LIMIT 1");
                return (bool) $stmt->fetchColumn();

            case 'monthly_report':
                return (bool) get_pdo()->query('SELECT 1 FROM orders LIMIT 1')->fetchColumn();

            case 'sumup_stock':
                return setting_get('sumup_api_key') !== '';

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
        if (scheduled_job_visit_run_in_progress($key, $last, $now)) continue;
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

/** The site's own folder — the same one the job scripts live in. */
function scheduled_jobs_site_root(): string
{
    $root = dirname(__DIR__);
    return rtrim((string) (realpath($root) ?: $root), '/');
}

/**
 * The PHP command-line binary to run the jobs with: one checked to really be
 * a CLI of the site's own PHP version (remembered, so the check runs once),
 * else the best guess. Null only when no checked one could be found.
 */
function scheduled_jobs_cli_php(): ?string
{
    $want   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $cached = setting_get('jobs_php_cli');
    if (str_starts_with($cached, $want . '|')) {
        $bin = substr($cached, strlen($want) + 1);
        if ($bin !== '' && @is_executable($bin)) return $bin;
        // None found earlier — look again at most once an hour.
        if ($bin === '' && (int) setting_get('jobs_php_cli_checked') > time() - 3600) return null;
    }
    require_once __DIR__ . '/updater.php';
    $bin = updater_php_cli_binary();
    setting_set('jobs_php_cli', $want . '|' . ($bin ?? ''));
    setting_set('jobs_php_cli_checked', (string) time());
    return $bin;
}

// ── Schedule maths ────────────────────────────────────────────────────────────

/** Does $value match one cron field — '*', '*\/N', 'N', 'A-B', or a comma list of those? */
function scheduled_cron_field_matches(string $field, int $value): bool
{
    foreach (explode(',', $field) as $part) {
        if ($part === '*') return true;
        if (preg_match('#^\*/(\d+)$#', $part, $m)) {
            if ((int) $m[1] > 0 && $value % (int) $m[1] === 0) return true;
        } elseif (preg_match('#^(\d+)-(\d+)$#', $part, $m)) {
            if ($value >= (int) $m[1] && $value <= (int) $m[2]) return true;
        } elseif (ctype_digit($part) && (int) $part === $value) {
            return true;
        }
    }
    return false;
}

/** The latest moment at or before $now that the schedule names (looks back two months). */
function scheduled_cron_previous(array $cron, int $now): ?int
{
    [$min, $hour, $dom, $mon, $dow] = $cron;
    $day = (new DateTimeImmutable('@' . $now))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->setTime(0, 0);
    for ($i = 0; $i < 62; $i++, $day = $day->modify('-1 day')) {
        $domOk = scheduled_cron_field_matches($dom, (int) $day->format('j'));
        $dowOk = scheduled_cron_field_matches($dow, (int) $day->format('w'));
        // cron: when both day fields are set, either one is enough.
        $dayOk = ($dom !== '*' && $dow !== '*') ? ($domOk || $dowOk) : ($domOk && $dowOk);
        if (!$dayOk || !scheduled_cron_field_matches($mon, (int) $day->format('n'))) continue;
        for ($h = 23; $h >= 0; $h--) {
            if (!scheduled_cron_field_matches($hour, $h)) continue;
            for ($m = 59; $m >= 0; $m--) {
                if (!scheduled_cron_field_matches($min, $m)) continue;
                $t = $day->setTime($h, $m)->getTimestamp();
                if ($t <= $now) return $t;
            }
        }
    }
    return null;
}

// ── Running on visits (when the server has no schedule for a job) ────────────

/** A real cron run at the scheduled minute always gets this long to go first. */
const SCHEDULED_JOBS_VISIT_MARGIN = 300;
/** A run a visit started counts as still going for this long (then it's tried again). */
const SCHEDULED_JOBS_VISIT_RUNNING = 1800;

/**
 * Should a page visit start this job now? Only when the server's schedule
 * isn't running it, its time has come, and nothing (a cron, visit or manual
 * run) has done it since — and a run a visit started isn't still going.
 */
function scheduled_job_due_on_visit(array $job, ?int $lastDone, ?int $lastCron, ?int $startedAt, int $now): bool
{
    if ($lastCron !== null && $now - $lastCron <= $job['grace']) return false;
    if ($startedAt !== null && $now - $startedAt < SCHEDULED_JOBS_VISIT_RUNNING
        && ($lastDone === null || $lastDone < $startedAt)) return false;
    $prev = scheduled_cron_previous($job['cron'], $now - SCHEDULED_JOBS_VISIT_MARGIN);
    return $prev !== null && ($lastDone === null || $lastDone < $prev);
}

function scheduled_job_visit_run_in_progress(string $key, ?int $lastRun, int $now): bool
{
    $started = scheduled_job_setting_time('job_visit_started_' . $key);
    return $started !== null && $now - $started < SCHEDULED_JOBS_VISIT_RUNNING
        && ($lastRun === null || $lastRun < $started);
}

/** Start one job in the background, as its own PHP process. False when this host won't allow it. */
function scheduled_job_spawn(string $key, array $job): bool
{
    require_once __DIR__ . '/updater.php';
    if (!updater_proc_open_enabled()) return false;
    $php = scheduled_jobs_cli_php();
    if ($php === null) return false;
    $root = scheduled_jobs_site_root();
    $cmd  = 'NGO_CMS_JOB_SOURCE=visit ' . scheduled_job_command($job, $key, $root, $php) . ' < /dev/null &';
    $null = ['file', '/dev/null', 'w'];
    $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, $root);
    if (!is_resource($proc)) return false;
    proc_close($proc); // returns as soon as the shell has put the job in the background
    return true;
}

/**
 * Called on every page view (public and admin footers). At most once a minute
 * it looks for jobs whose time has come but that the server's schedule isn't
 * running, and starts them in the background after the page has been sent —
 * so a site works even if nobody ever adds the jobs in cPanel.
 * A site can switch this off with define('SCHEDULED_JOBS_ON_VISIT', false) in site.config.php.
 */
function scheduled_jobs_run_on_visit(): void
{
    // Never from the command line or PHP's built-in dev server (`php -S`, also
    // used by tests): a local copy of the live database must not mail real
    // customers. Real hosting always runs PHP another way.
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') return;
    if (defined('SCHEDULED_JOBS_ON_VISIT') && !SCHEDULED_JOBS_ON_VISIT) return;
    $stamp = dirname(__DIR__) . '/logs/.jobs-visit-checked';
    if ((int) @filemtime($stamp) > time() - 60) return;

    register_shutdown_function(static function () use ($stamp): void {
        try {
            $lock = @fopen(dirname(__DIR__) . '/logs/.jobs-visit.lock', 'c');
            if (!$lock) return;
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
            try {
                clearstatcache(true, $stamp);
                $now = time();
                if ((int) @filemtime($stamp) > $now - 60) return;
                @touch($stamp, $now);
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); // the visitor doesn't wait

                foreach (scheduled_jobs() as $key => $job) {
                    $lastDone = max(scheduled_job_last_run($key) ?? 0, scheduled_job_last_manual($key) ?? 0) ?: null;
                    $started  = scheduled_job_setting_time('job_visit_started_' . $key);
                    if (!scheduled_job_due_on_visit($job, $lastDone, scheduled_job_last_cron($key), $started, $now)) continue;
                    if (!scheduled_job_needed($key)) continue;
                    if (!scheduled_job_spawn($key, $job)) return; // this host can't start programs
                    setting_set('job_visit_started_' . $key, (string) $now);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable $e) {
            error_log('scheduled_jobs_run_on_visit: ' . $e->getMessage());
        }
    });
}

// ── The server's schedule (crontab) ───────────────────────────────────────────
// Several sites can share one hosting account and so one crontab. Each line
// this site adds carries a label naming the site and the job; lines without
// our label — another site's jobs, or ones added by hand — are never changed.

function scheduled_job_cron_marker(string $key, string $siteRoot): string
{
    return '# ngo-cms:' . substr(hash('sha256', rtrim($siteRoot, '/')), 0, 10) . ':' . $key;
}

/** The whole crontab line: schedule, command, label. (% is special in crontab.) */
function scheduled_job_cron_line(array $job, string $key, string $siteRoot, string $php): string
{
    return implode(' ', $job['cron']) . ' '
        . str_replace('%', '\%', scheduled_job_command($job, $key, $siteRoot, $php)) . ' '
        . scheduled_job_cron_marker($key, $siteRoot);
}

/**
 * Is the job in this crontab? 'ours' — a line with our label; 'by_hand' — an
 * unlabelled line running this site's script (added in cPanel); null — no.
 */
function scheduled_job_in_crontab(string $crontab, string $key, array $job, string $siteRoot): ?string
{
    $marker = scheduled_job_cron_marker($key, $siteRoot);
    $script = rtrim($siteRoot, '/') . '/' . $job['script'];
    $found  = null;
    foreach (preg_split('/\R/', $crontab) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (str_ends_with($t, $marker)) return 'ours';
        if (str_contains($t, $script)) $found = 'by_hand';
    }
    return $found;
}

/**
 * The crontab with a line for each of $keys added, or replaced if ours is
 * already there (e.g. a newer PHP). A job already added by hand is
 * left alone. Every other line stays exactly as it was.
 */
function scheduled_jobs_crontab_with(string $current, array $keys, string $siteRoot, string $php): string
{
    $jobs  = scheduled_jobs();
    $lines = $current === '' ? [] : preg_split('/\R/', rtrim($current, "\r\n"));
    foreach ($keys as $key) {
        $job    = $jobs[$key];
        $want   = scheduled_job_cron_line($job, $key, $siteRoot, $php);
        $marker = scheduled_job_cron_marker($key, $siteRoot);
        $state  = scheduled_job_in_crontab(implode("\n", $lines), $key, $job, $siteRoot);
        if ($state === 'by_hand') continue;
        if ($state === 'ours') {
            foreach ($lines as $i => $l) {
                if (str_ends_with(trim($l), $marker)) $lines[$i] = $want;
            }
            continue;
        }
        $lines[] = $want;
    }
    return $lines ? implode("\n", $lines) . "\n" : '';
}

/** The crontab without this site's own (labelled) lines for $keys. */
function scheduled_jobs_crontab_without(string $current, array $keys, string $siteRoot): string
{
    $markers = array_map(fn ($k) => scheduled_job_cron_marker($k, $siteRoot), $keys);
    $kept    = [];
    foreach ($current === '' ? [] : preg_split('/\R/', rtrim($current, "\r\n")) as $l) {
        foreach ($markers as $m) {
            if (str_ends_with(trim($l), $m)) continue 2;
        }
        $kept[] = $l;
    }
    return $kept ? implode("\n", $kept) . "\n" : '';
}

/**
 * Runs a program, no shell. Null when this host doesn't let PHP start programs.
 * @return array{exit:int, stdout:string, stderr:string}|null
 */
function scheduled_jobs_run(array $argv): ?array
{
    require_once __DIR__ . '/updater.php';
    $r = updater_run_process($argv, 20);
    return ($r === null || $r['timed_out']) ? null : $r;
}

/** The current crontab ('' when there is none), or null when it can't be read. */
function scheduled_jobs_crontab_read(?callable $run = null): ?string
{
    $r = ($run ?? 'scheduled_jobs_run')(['crontab', '-l']);
    if ($r === null) return null;
    if ($r['exit'] === 0) return $r['stdout'];
    return stripos($r['stderr'], 'no crontab') !== false ? '' : null;
}

/**
 * Changes the crontab safely: reads it, saves a copy in logs/, installs
 * $change(current) and reads it back to check it is exactly that — if not,
 * the copy is put back.
 *
 * @return array{ok:bool, message:string}
 */
function scheduled_jobs_crontab_change(callable $change, ?callable $run = null, ?string $backupDir = null): array
{
    $run       = $run ?? 'scheduled_jobs_run';
    $backupDir = $backupDir ?? dirname(__DIR__) . '/logs';
    $cantHere  = 'Хостингът не позволява на сайта сам да променя графика. Добавете задачата ръчно — стъпките са под нея.';

    $current = scheduled_jobs_crontab_read($run);
    if ($current === null) return ['ok' => false, 'message' => $cantHere];
    $new = $change($current);
    if ($new === $current) return ['ok' => true, 'message' => ''];

    $install = static function (string $content) use ($run): bool {
        $tmp = tempnam(sys_get_temp_dir(), 'ngo_cron_');
        if ($tmp === false || file_put_contents($tmp, $content) === false) return false;
        $r = $run(['crontab', $tmp]);
        @unlink($tmp);
        return $r !== null && $r['exit'] === 0;
    };

    $backup = $backupDir . '/crontab-backup-' . date('Ymd-His') . '.txt';
    if (@file_put_contents($backup, $current) === false) {
        return ['ok' => false, 'message' => 'Не успяхме да запазим копие на сегашния график, затова не променихме нищо. Опитайте отново или добавете задачата ръчно.'];
    }
    if (!$install($new)) return ['ok' => false, 'message' => $cantHere];

    $after = scheduled_jobs_crontab_read($run);
    if ($after === null || rtrim($after) !== rtrim($new)) {
        $install($current);
        return ['ok' => false, 'message' => 'Графикът не се запази както трябва, затова върнахме предишния. Нищо не е изгубено. Опитайте отново или добавете задачата ръчно.'];
    }
    return ['ok' => true, 'message' => ''];
}

/**
 * A one-line command for the hosting's Terminal that adds the job safely:
 * copy of the current schedule first, never adds it twice, shows the result.
 */
function scheduled_job_terminal_command(array $job, string $key, string $siteRoot, string $php): string
{
    $line   = scheduled_job_cron_line($job, $key, $siteRoot, $php);
    $marker = scheduled_job_cron_marker($key, $siteRoot);
    return 'set -e; B=~/crontab-backup-$(date +%Y%m%d-%H%M%S).txt; '
        . 'crontab -l > "$B" 2> "$B.err" || grep -q "no crontab" "$B.err"; rm -f "$B.err"; cp "$B" "$B.new"; '
        . 'grep -qF -- ' . escapeshellarg($marker) . ' "$B.new" || printf "%s\n" ' . escapeshellarg($line) . ' >> "$B.new"; '
        . 'crontab "$B.new"; rm "$B.new"; crontab -l';
}
