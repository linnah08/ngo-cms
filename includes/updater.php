<?php
/**
 * Self-update mechanism (Phase 1 — backend only).
 *
 * Lets a self-hosted adopter update to a new GitHub release without git,
 * SSH, or composer: check for a new release, back up the current codebase,
 * download + stage the new release, apply it file-by-file (skipping any file
 * the adopter has customized), run new migrations, and record the outcome.
 *
 * Public functions (contract used by admin/updates.php — keep these exact
 * names/return shapes; parameters are only ever added as optional ones):
 *   - updater_get_local_version(): string
 *   - updater_check_latest(bool $force = false): array
 *   - updater_apply(?callable $onProgress = null, array $deps = []): array
 *   - updater_is_maintenance_mode(): bool
 *   - updater_self_update_allowed(): bool
 *
 * Progress reporting (used by admin/update-apply-ajax.php and polled by
 * admin/update-progress-ajax.php). updater_apply() only *emits* progress, it
 * never persists it — that keeps the transport out of this file:
 *   - updater_progress_percent(string $phase, int $current = 0, int $total = 0): int
 *   - updater_progress_write(array $state): void
 *   - updater_progress_read(): ?array
 *   - updater_progress_clear(): void
 *   - updater_progress_is_stale(array $state, int $maxAge = 90, ?int $now = null): bool
 *
 * A couple of small pure helpers are also exposed for testability:
 *   - updater_version_needs_update(string $local, string $latest): bool
 *   - updater_diff_conflicts(array $oldChecksums, array $newFiles, callable $liveHasher): array
 *   - updater_owner_files_to_keep(array $newFiles, callable $liveHasher): array
 */

if (!defined('UPDATER_GITHUB_REPO')) {
    define('UPDATER_GITHUB_REPO', 'linnah08/ngo-cms');
}
if (!defined('UPDATER_CACHE_TTL')) {
    define('UPDATER_CACHE_TTL', 3600); // 1 hour
}

// ── Paths ──────────────────────────────────────────────────────────────────────

/**
 * Test seam: points every path helper below at a throwaway directory, so a
 * whole updater_apply() run can be exercised without touching the live site.
 * Production never calls this — pass null to go back to ROOT_PATH.
 */
function updater_set_root_override(?string $path): void
{
    $GLOBALS['__updater_root_override'] = ($path !== null && $path !== '') ? rtrim($path, '/') : null;
}

function updater_root(): string
{
    $override = $GLOBALS['__updater_root_override'] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }
    if (defined('ROOT_PATH') && ROOT_PATH !== '') {
        return ROOT_PATH;
    }
    return dirname(__DIR__);
}

/**
 * May this install update itself from the admin?
 *
 * Not when a developer looks after it through git — a fork with its own theme,
 * or a dev checkout. A release ZIP knows nothing of the fork's changes, and a
 * git-deployed site has no checksums.json baseline, so conflict detection is
 * off and those changes would simply be overwritten. Such a site sets
 * FEATURE_SELF_UPDATE to false in site.config.php; a .git folder in the site
 * root counts as the same answer.
 */
function updater_self_update_allowed(): bool
{
    if (defined('FEATURE_SELF_UPDATE') && !FEATURE_SELF_UPDATE) {
        return false;
    }
    return !file_exists(updater_root() . '/.git');
}

function updater_cache_file(): string
{
    return updater_root() . '/logs/update-check-cache.json';
}

// ── Version ────────────────────────────────────────────────────────────────────

/**
 * Reads the repo-root VERSION file. $path is an optional override for tests;
 * production/admin callers always call this with no arguments.
 */
function updater_get_local_version(?string $path = null): string
{
    $file = $path ?? (updater_root() . '/VERSION');
    if (!is_file($file)) {
        return 'unknown';
    }
    $version = trim((string) file_get_contents($file));
    return $version === '' ? 'unknown' : $version;
}

/**
 * Pure comparison so the update-available logic can be unit tested without
 * touching the filesystem or the network. 'unknown' local version (VERSION
 * file missing/empty) always counts as needing an update.
 */
function updater_version_needs_update(string $localVersion, string $latestVersion): bool
{
    if ($latestVersion === '') {
        return false;
    }
    if ($localVersion === 'unknown') {
        return true;
    }
    return version_compare($latestVersion, $localVersion, '>');
}

// ── HTTP ───────────────────────────────────────────────────────────────────────

/**
 * Default HTTP GET implementation, injectable via $httpFetcher params so
 * tests never hit the real network. Always returns 'status' (0 on a
 * network-level failure, e.g. no connectivity) so callers can branch on it
 * without needing 'ok' to be true (a 404 from GitHub is a meaningful,
 * non-error response — "no releases published yet").
 */
function updater_http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ngo-cms-updater',
            'Accept: application/vnd.github+json',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err ?: 'Request failed'];
    }
    return [
        'ok'     => $code >= 200 && $code < 300,
        'status' => $code,
        'body'   => (string) $body,
        'error'  => null,
    ];
}

/**
 * Downloads straight to disk with byte-level progress. The release zip is both
 * too big to hold in memory the way updater_http_get() does, and the one step
 * slow enough that the admin needs to watch it move.
 *
 * $onBytes receives (bytesSoFar, expectedTotal); expectedTotal stays 0 until
 * the server reports a Content-Length, which callers must tolerate.
 */
function updater_download_stream(string $url, string $destPath, ?callable $onBytes = null): bool
{
    $fh = @fopen($destPath, 'wb');
    if ($fh === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 900,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ngo-cms-updater',
            'Accept: application/octet-stream',
        ],
    ]);
    if ($onBytes !== null) {
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, $expected, $got) use ($onBytes) {
            $onBytes((int) $got, (int) $expected);
            return 0; // anything non-zero aborts the transfer
        });
    }

    $ok   = curl_exec($ch) !== false;
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $code < 200 || $code >= 300 || !is_file($destPath) || filesize($destPath) === 0) {
        @unlink($destPath);
        return false;
    }
    return true;
}

/**
 * With no $httpFetcher (production) this streams to disk and can report
 * progress. Tests inject a fetcher, which keeps the old buffered behaviour so
 * a fake response body still works.
 */
function updater_download_to_file(
    string $url,
    string $destPath,
    ?callable $httpFetcher = null,
    ?callable $onBytes = null
): bool {
    if ($httpFetcher === null) {
        return updater_download_stream($url, $destPath, $onBytes);
    }
    $resp = $httpFetcher($url);
    if (!is_array($resp) || empty($resp['ok']) || !isset($resp['body']) || $resp['body'] === '') {
        return false;
    }
    return file_put_contents($destPath, $resp['body']) !== false;
}

// ── Check for updates ──────────────────────────────────────────────────────────

/**
 * $httpFetcher is an optional injection point for tests (defaults to a real
 * cURL call against the GitHub API). Never throws — any network/API failure
 * comes back as a plain-language message in the 'error' key.
 */
function updater_check_latest(bool $force = false, ?callable $httpFetcher = null): array
{
    $httpFetcher ??= 'updater_http_get';

    $result = [
        'local_version'    => updater_get_local_version(),
        'latest_version'   => '',
        'update_available' => false,
        'notes'            => '',
        'zip_url'          => null,
        'error'            => null,
    ];

    $cacheFile = updater_cache_file();
    $cached    = null;

    if (!$force && is_file($cacheFile)) {
        $raw     = @file_get_contents($cacheFile);
        $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['fetched_at'])
            && (time() - (int) $decoded['fetched_at']) < UPDATER_CACHE_TTL) {
            $cached = $decoded;
        }
    }

    if ($cached === null) {
        $url  = 'https://api.github.com/repos/' . UPDATER_GITHUB_REPO . '/releases/latest';
        $resp = $httpFetcher($url);

        if (!is_array($resp) || !array_key_exists('status', $resp)) {
            $result['error'] = 'Could not check for updates right now. Please try again later.';
            return $result;
        }

        $status = (int) $resp['status'];

        if ($status === 404) {
            // No releases published on GitHub yet — not an error.
            $cached = ['fetched_at' => time(), 'no_release' => true];
        } elseif ($status < 200 || $status >= 300 || empty($resp['ok'])) {
            $result['error'] = 'Could not check for updates right now. Please try again later.';
            return $result;
        } else {
            $data = json_decode((string) $resp['body'], true);
            if (!is_array($data) || !isset($data['tag_name'])) {
                $result['error'] = 'The update server returned an unexpected response.';
                return $result;
            }

            // The conflict-detection baseline is the checksums.json that shipped
            // inside this install's own zip, never a separately downloaded one —
            // so only the zip asset is needed here.
            $zipUrl = null;
            foreach ((array) ($data['assets'] ?? []) as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $dl   = (string) ($asset['browser_download_url'] ?? '');
                if ($dl === '') continue;
                if (str_ends_with(strtolower($name), '.zip')) {
                    $zipUrl = $dl;
                    break;
                }
            }

            $cached = [
                'fetched_at' => time(),
                'no_release' => false,
                'tag_name'   => (string) $data['tag_name'],
                'notes'      => (string) ($data['body'] ?? ''),
                'zip_url'    => $zipUrl,
            ];
        }

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
    }

    if (!empty($cached['no_release'])) {
        $result['latest_version']   = $result['local_version'];
        $result['update_available'] = false;
        return $result;
    }

    $latest = ltrim((string) ($cached['tag_name'] ?? ''), 'v');

    $result['latest_version']   = $latest;
    $result['notes']            = (string) ($cached['notes'] ?? '');
    $result['zip_url']          = $cached['zip_url'] ?? null;
    $result['update_available'] = updater_version_needs_update($result['local_version'], $latest);

    return $result;
}

// ── Maintenance mode ───────────────────────────────────────────────────────────

function updater_maintenance_file(): string
{
    return updater_root() . '/.maintenance';
}

function updater_is_maintenance_mode(): bool
{
    return is_file(updater_maintenance_file());
}

function updater_set_maintenance(bool $on): void
{
    $flag = updater_maintenance_file();
    if ($on) {
        @file_put_contents($flag, (string) time());
    } elseif (is_file($flag)) {
        @unlink($flag);
    }
}

// ── File listing / hashing ──────────────────────────────────────────────────────

/**
 * Relative (forward-slash) paths of every file under $baseDir, skipping any
 * top-level entry whose name is in $excludeTopLevel. Sorted for determinism.
 */
function updater_list_files(string $baseDir, array $excludeTopLevel = []): array
{
    $baseDir = rtrim($baseDir, '/');
    $exclude = array_flip($excludeTopLevel);
    $result  = [];

    if (!is_dir($baseDir)) {
        return $result;
    }

    $dirIterator = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($exclude, $baseDir) {
        $rel = ltrim(substr($current->getPathname(), strlen($baseDir)), '/');
        $top = explode('/', $rel, 2)[0];
        return !isset($exclude[$top]);
    });
    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $result[] = ltrim(substr($fileInfo->getPathname(), strlen($baseDir)), '/');
    }
    sort($result);
    return $result;
}

function updater_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Pure diffing logic, extracted so it's unit-testable without touching disk:
 * for each path the new release touches, if we have an old baseline hash for
 * it AND the live file's current hash no longer matches that baseline (i.e.
 * the adopter customized it), skip it. A path with no baseline entry (new
 * file introduced by the release) or no live file (nothing to conflict with)
 * is never skipped.
 *
 * $liveHasher: callable(string $relativePath): string|array|null — sha256 of
 * the live file (or several acceptable hashes of it), null if it doesn't exist.
 */
function updater_diff_conflicts(array $oldChecksums, array $newFiles, callable $liveHasher): array
{
    $skipped = [];
    foreach ($newFiles as $path) {
        if (!array_key_exists($path, $oldChecksums)) {
            continue; // no baseline — new file, not a conflict
        }
        $liveHashes = $liveHasher($path);
        if ($liveHashes === null) {
            continue; // nothing live to conflict with
        }
        $matches = false;
        foreach ((array) $liveHashes as $h) {
            if (hash_equals((string) $oldChecksums[$path], (string) $h)) { $matches = true; break; }
        }
        if (!$matches) {
            $skipped[] = $path;
        }
    }
    return $skipped;
}

// ── Host-managed blocks ───────────────────────────────────────────────────────
// cPanel writes its own blocks into .htaccess (e.g. the PHP version handler
// added by MultiPHP Manager, which the install guide tells every adopter to
// use). Those aren't adopter customizations: without this, every such site
// would be warned on every update and never receive .htaccess updates.

/** The "BEGIN cPanel-generated … END cPanel-generated" blocks in $content. */
function updater_host_blocks(string $content): array
{
    preg_match_all('/^[^\n]*BEGIN cPanel-generated[^\n]*\n.*?^[^\n]*END cPanel-generated[^\n]*(?:\n|$)/ms', $content, $m);
    return $m[0];
}

/**
 * $content with its host blocks removed, as a few candidates because the
 * blank line cPanel puts around a block varies. Empty if there are none.
 */
function updater_strip_host_blocks(string $content): array
{
    $blocks = updater_host_blocks($content);
    if ($blocks === []) return [];
    $afterBlank = $bare = $beforeBlank = $content;
    foreach ($blocks as $blk) {
        $afterBlank  = str_replace("\n" . $blk, '', $afterBlank);
        $bare        = str_replace($blk, '', $bare);
        $beforeBlank = str_replace($blk . "\n", '', $beforeBlank);
    }
    return array_values(array_unique([$afterBlank, $bare, $beforeBlank]));
}

/** New release content with the site's host blocks carried over. */
function updater_merge_host_blocks(string $newContent, array $blocks): string
{
    foreach ($blocks as $blk) {
        if (str_contains($newContent, $blk)) continue;
        $newContent = rtrim($newContent, "\n") . "\n\n" . rtrim($blk, "\n") . "\n";
    }
    return $newContent;
}

/** Whether host blocks are honoured for this path. */
function updater_is_host_managed(string $rel): bool
{
    return basename($rel) === '.htaccess';
}

/**
 * Release files to write onto a live site. install/ is left out: the site is
 * already installed, and re-creating the wizard would undo the adopter's
 * "delete the install folder" step on every update.
 */
function updater_files_to_apply(array $newFiles): array
{
    return array_values(array_filter($newFiles, static fn(string $r) => !str_starts_with($r, 'install/')));
}

/**
 * Files the site owner replaces from the admin (Admin → Организация uploads the
 * logo and regenerates the favicon). The release ships placeholders at these
 * paths, but an existing live copy is always the owner's and is never
 * overwritten — even on a first self-update with no checksums.json baseline,
 * when conflict detection is otherwise off.
 */
function updater_owner_files(): array
{
    return ['assets/images/logo.png', 'assets/images/favicon.png'];
}

/**
 * The subset of $newFiles that are owner files with a live copy — kept as they
 * are, and not reported as "skipped" (keeping them is expected, not a conflict).
 */
function updater_owner_files_to_keep(array $newFiles, callable $liveHasher): array
{
    $owner = array_flip(updater_owner_files());
    $keep  = [];
    foreach ($newFiles as $path) {
        if (isset($owner[$path]) && $liveHasher($path) !== null) {
            $keep[] = $path;
        }
    }
    return $keep;
}

// ── Progress ───────────────────────────────────────────────────────────────────
// updater_apply() reports where it is so the admin doesn't stare at a blank
// tab for several minutes. The phases and their share of the bar live here as
// pure data, so the arithmetic is unit-testable without running an update.

function updater_progress_file(): string
{
    return updater_root() . '/logs/update-progress.json';
}

/**
 * Each phase's [start, end] share of the bar. Weighted by how long the step
 * actually takes on a real site: zipping the backup and downloading the
 * release dominate, extracting and migrating barely register.
 */
function updater_progress_phases(): array
{
    return [
        'check'    => [0, 5],
        'backup'   => [5, 30],
        'download' => [30, 55],
        'extract'  => [55, 65],
        'apply'    => [65, 90],
        'migrate'  => [90, 100],
        'done'     => [100, 100],
    ];
}

/**
 * Pure phase → percent mapping. Within a phase, $current/$total interpolates
 * between that phase's start and end; with no usable total the phase start is
 * used, so a step that can't measure itself parks the bar instead of faking
 * movement. 'failed' is deliberately not a phase: a failed update keeps
 * whatever percent it had reached rather than jumping to a made-up number.
 */
function updater_progress_percent(string $phase, int $current = 0, int $total = 0): int
{
    $phases = updater_progress_phases();
    if (!isset($phases[$phase])) {
        return 0;
    }
    [$start, $end] = $phases[$phase];
    if ($total <= 0 || $current <= 0) {
        return $start;
    }
    if ($current >= $total) {
        return $end;
    }
    return (int) floor($start + (($end - $start) * ($current / $total)));
}

/**
 * Writes the state the admin UI polls. Every failure is swallowed on purpose:
 * a site whose logs/ directory is not writable must still be able to update —
 * it just falls back to an indeterminate bar.
 */
function updater_progress_write(array $state): void
{
    $file = updater_progress_file();
    $dir  = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    $state['updated_at'] = time();
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($file, $json, LOCK_EX);
    }
}

function updater_progress_read(): ?array
{
    $file = updater_progress_file();
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function updater_progress_clear(): void
{
    $file = updater_progress_file();
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * True when a still-running state hasn't been touched for $maxAge seconds —
 * the update process died (out of memory, worker restart) and nothing is ever
 * going to finish it. Without this the bar would sit at 42% forever and the
 * admin would have no idea whether to wait or call support. Pure apart from
 * the clock, which $now overrides in tests.
 */
function updater_progress_is_stale(array $state, int $maxAge = 90, ?int $now = null): bool
{
    if ((string) ($state['status'] ?? 'running') !== 'running') {
        return false;
    }
    $updatedAt = (int) ($state['updated_at'] ?? 0);
    if ($updatedAt <= 0) {
        return true;
    }
    return (($now ?? time()) - $updatedAt) > $maxAge;
}

// ── Backup ─────────────────────────────────────────────────────────────────────

function updater_zip_available(): bool
{
    return class_exists('ZipArchive');
}

/** Directories excluded from the pre-update backup: not code, or regenerable. */
function updater_backup_excludes(): array
{
    return ['vendor', 'node_modules', '.git', 'logs', 'documents', 'content', 'backups'];
}

/**
 * Zips the current live codebase (application code only — see
 * updater_backup_excludes()) into backups/pre-update-{from}-{timestamp}.zip.
 * Returns the backup file path, or null if ZipArchive is unavailable or the
 * backup could not be created — callers must treat null as "abort, do not
 * touch any live files".
 *
 * $onProgress is called as ($done, $total, $message). It reports twice over:
 * once per file while the archive is being assembled, then once more before
 * close(), because close() is where ZipArchive actually compresses everything
 * and is usually the longest single wait in the whole update.
 */
function updater_create_backup(string $fromVersion, ?callable $onProgress = null): ?string
{
    if (!updater_zip_available()) {
        return null;
    }

    $root       = updater_root();
    $backupsDir = $root . '/backups';
    if (!is_dir($backupsDir) && !@mkdir($backupsDir, 0755, true) && !is_dir($backupsDir)) {
        return null;
    }

    $safeFrom = preg_replace('/[^A-Za-z0-9._-]/', '_', $fromVersion);
    $safeFrom = $safeFrom !== '' ? $safeFrom : 'unknown';
    $file     = $backupsDir . '/pre-update-' . $safeFrom . '-' . date('Ymd-His') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $files = updater_list_files($root, updater_backup_excludes());
    $total = count($files);
    $done  = 0;
    foreach ($files as $rel) {
        $zip->addFile($root . '/' . $rel, $rel);
        $done++;
        if ($onProgress !== null) {
            $onProgress($done, $total, 'Създаване на резервно копие…');
        }
    }
    if ($onProgress !== null) {
        $onProgress($total, $total, 'Компресиране на резервното копие…');
    }
    $closed = $zip->close();

    if (!$closed || !is_file($file) || filesize($file) === 0) {
        @unlink($file);
        return null;
    }

    return $file;
}

// ── Audit log ──────────────────────────────────────────────────────────────────

function updater_log_attempt(string $from, string $to, string $status, array $skipped, ?string $error): void
{
    try {
        if (!function_exists('get_pdo')) {
            require_once updater_root() . '/admin/includes/db.php';
        }
        $pdo = get_pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `platform_updates` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `from_version`  VARCHAR(50)  NOT NULL,
                `to_version`    VARCHAR(50)  NOT NULL,
                `status`        VARCHAR(20)  NOT NULL,
                `skipped_files` TEXT NULL,
                `error_message` TEXT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->prepare(
            'INSERT INTO `platform_updates` (`from_version`, `to_version`, `status`, `skipped_files`, `error_message`) VALUES (?,?,?,?,?)'
        )->execute([
            $from,
            $to,
            $status,
            $skipped ? json_encode(array_values($skipped), JSON_UNESCAPED_SLASHES) : null,
            $error,
        ]);
    } catch (\Throwable $e) {
        // Never let audit-logging failure mask the real update result; fall
        // back to the plain error log.
        if (function_exists('_om_log')) {
            _om_log('UPDATER', 'Failed to write platform_updates audit row: ' . $e->getMessage());
        }
    }
}

// ── Apply ──────────────────────────────────────────────────────────────────────

function updater_tmp_dir(string $prefix): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . bin2hex(random_bytes(8));
}

/**
 * Orchestrates a full self-update. Never throws — every failure path (network,
 * missing ZipArchive, backup failure, download/extract errors, migration
 * failure) is caught and returned as a result array instead. Always leaves
 * `.maintenance` removed, even on failure, so the site is never stuck down.
 *
 * $onProgress is an optional reporting hook, called as the update moves
 * through its phases with one array per step:
 *   ['phase' => string, 'percent' => int, 'message' => string,
 *    'current' => int, 'total' => int]
 * This function never persists it — admin/update-apply-ajax.php passes a
 * callback that writes the file the admin UI polls. A callback that throws can
 * never fail the update.
 *
 * $deps is a test seam; production calls updater_apply() with no arguments:
 *   'http'     => callable(string $url): array — stands in for every network call
 *   'migrator' => callable(): array{success: bool, error: ?string}
 *   'logger'   => callable(string, string, string, array, ?string): void
 * Combined with updater_set_root_override(), that makes a full run exercisable
 * against a throwaway directory with no network and no database.
 *
 * Return shape:
 *   ['status' => 'success'|'partial'|'failed', 'from_version' => string,
 *    'to_version' => string, 'skipped' => string[], 'error' => string|null]
 */
function updater_apply(?callable $onProgress = null, array $deps = []): array
{
    $http     = $deps['http']     ?? null;
    $migrator = $deps['migrator'] ?? null;
    $logger   = $deps['logger']   ?? 'updater_log_attempt';

    $root = updater_root();
    $from = updater_get_local_version();

    $result = [
        'status'       => 'failed',
        'from_version' => $from,
        'to_version'   => '',
        'skipped'      => [],
        'error'        => null,
    ];

    // Checked here, not only in the UI, so no caller can overwrite a site that
    // is updated through git. Nothing was attempted, so nothing is logged.
    if (!updater_self_update_allowed()) {
        $result['error'] = 'Този сайт се обновява от разработчика, не от админ панела.';
        return $result;
    }

    // Repeated identical states are dropped rather than reported: the apply
    // loop runs once per file and can fire thousands of times, but the bar
    // only ever has 100 positions, so there is nothing to report until the
    // percent (or the wording) actually changes.
    $lastPhase   = '';
    $lastPercent = -1;
    $lastMessage = '';
    $emit = static function (
        string $phase,
        string $message,
        int $current = 0,
        int $total = 0
    ) use ($onProgress, &$lastPhase, &$lastPercent, &$lastMessage): void {
        $percent = updater_progress_percent($phase, $current, $total);
        if ($phase === $lastPhase && $percent === $lastPercent && $message === $lastMessage) {
            return;
        }
        $lastPhase   = $phase;
        $lastPercent = $percent;
        $lastMessage = $message;
        if ($onProgress === null) {
            return;
        }
        try {
            $onProgress([
                'phase'   => $phase,
                'percent' => $percent,
                'message' => $message,
                'current' => $current,
                'total'   => $total,
            ]);
        } catch (\Throwable $e) {
            // Reporting must never be able to break the update it reports on.
        }
    };

    // (a) Re-check latest — bail out before touching anything if there's
    // nothing to apply or the check itself failed. Nothing was attempted yet,
    // so no audit row is written for this branch.
    $emit('check', 'Проверка за нова версия…');
    $check = updater_check_latest(true, $http);
    if (!empty($check['error'])) {
        $result['error'] = $check['error'];
        return $result;
    }
    $result['to_version'] = (string) ($check['latest_version'] ?? '');
    if (empty($check['update_available']) || empty($check['zip_url'])) {
        $result['error'] = 'No update is available to apply.';
        return $result;
    }
    $to     = $result['to_version'];
    $zipUrl = (string) $check['zip_url'];

    // (b) Backup — abort before touching any live file if this fails.
    if (!updater_zip_available()) {
        $result['error'] = 'The PHP ZipArchive extension is required to back up your site before updating.';
        $logger($from, $to, 'failed', [], $result['error']);
        return $result;
    }
    $emit('backup', 'Създаване на резервно копие…');
    $backupPath = updater_create_backup(
        $from,
        static function (int $done, int $total, string $message) use ($emit): void {
            $emit('backup', $message, $done, $total);
        }
    );
    if ($backupPath === null) {
        $result['error'] = 'Could not create a backup of your site — the update was not applied.';
        $logger($from, $to, 'failed', [], $result['error']);
        return $result;
    }

    $stagingDir = null;

    try {
        // (c) Maintenance mode on.
        updater_set_maintenance(true);

        // (d) Download + fully extract the new release into a staging dir —
        // never extract in place.
        $stagingDir = updater_tmp_dir('ngo-cms-update-');
        $zipTmp     = $stagingDir . '.zip';

        $emit('download', 'Изтегляне на новата версия…');
        $downloaded = updater_download_to_file(
            $zipUrl,
            $zipTmp,
            $http,
            static function (int $got, int $expected) use ($emit): void {
                $emit('download', 'Изтегляне на новата версия…', $got, $expected);
            }
        );
        if (!$downloaded) {
            throw new RuntimeException('Could not download the update package.');
        }

        $emit('extract', 'Разопаковане на пакета…');
        $zip = new ZipArchive();
        if ($zip->open($zipTmp) !== true) {
            @unlink($zipTmp);
            throw new RuntimeException('The downloaded update package is corrupted.');
        }
        if (!@mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
            $zip->close();
            @unlink($zipTmp);
            throw new RuntimeException('Could not create a staging directory to extract the update.');
        }
        $extracted = $zip->extractTo($stagingDir);
        $zip->close();
        @unlink($zipTmp);
        if ($extracted === false) {
            throw new RuntimeException('Could not extract the update package.');
        }

        // Old baseline checksums come from THIS install's own last update —
        // not from GitHub. If it doesn't exist yet (first-ever self-update),
        // there is no baseline to diff against, so skip conflict detection
        // entirely and just overwrite everything normally.
        $oldChecksumsFile = $root . '/checksums.json';
        $oldChecksums     = [];
        if (is_file($oldChecksumsFile)) {
            $decoded = json_decode((string) file_get_contents($oldChecksumsFile), true);
            if (is_array($decoded)) $oldChecksums = $decoded;
        }

        $newFiles = updater_files_to_apply(updater_list_files($stagingDir));

        // (e) Diff + apply — skip any file the adopter customized.
        $liveHasher = static function (string $rel) use ($root): ?array {
            $p = $root . '/' . $rel;
            if (!is_file($p)) return null;
            $content = (string) file_get_contents($p);
            $hashes  = [hash('sha256', $content)];
            if (updater_is_host_managed($rel)) {
                foreach (updater_strip_host_blocks($content) as $stripped) {
                    $hashes[] = hash('sha256', $stripped);
                }
            }
            return $hashes;
        };
        $keptSet  = array_flip(updater_owner_files_to_keep($newFiles, $liveHasher));
        $newFiles = array_values(array_filter($newFiles, static fn($rel) => !isset($keptSet[$rel])));
        $skipped = $oldChecksums === [] ? [] : updater_diff_conflicts($oldChecksums, $newFiles, $liveHasher);
        $skippedSet = array_flip($skipped);

        $totalFiles = count($newFiles);
        $doneFiles  = 0;
        $emit('apply', 'Обновяване на файловете…', 0, $totalFiles);

        foreach ($newFiles as $rel) {
            $doneFiles++;
            if (isset($skippedSet[$rel])) {
                $emit('apply', 'Обновяване на файловете…', $doneFiles, $totalFiles);
                continue;
            }
            $src    = $stagingDir . '/' . $rel;
            $dst    = $root . '/' . $rel;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir) && !@mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
                throw new RuntimeException("Could not create directory for: {$rel}");
            }
            $blocks = (updater_is_host_managed($rel) && is_file($dst))
                ? updater_host_blocks((string) file_get_contents($dst)) : [];
            $written = $blocks === []
                ? @copy($src, $dst)
                : @file_put_contents($dst, updater_merge_host_blocks((string) file_get_contents($src), $blocks)) !== false;
            if (!$written) {
                throw new RuntimeException("Could not write file: {$rel}");
            }
            $emit('apply', 'Обновяване на файловете…', $doneFiles, $totalFiles);
        }

        updater_rrmdir($stagingDir);
        $stagingDir = null;

        // (f) Run any new migrations in-process. run_migrations() is opaque —
        // it reports nothing per-step — so this phase parks the bar at the
        // start of its band rather than inventing movement it can't measure.
        $emit('migrate', 'Обновяване на базата данни…');
        if ($migrator !== null) {
            $migrationResult = $migrator();
        } else {
            require_once $root . '/migrate.php'; // defines run_migrations(); CLI auto-run is a no-op here
            $migrationResult = function_exists('run_migrations')
                ? run_migrations()
                : ['success' => true, 'error' => null];
        }

        // (g) Finalize: stamp VERSION, drop maintenance mode, audit log.
        file_put_contents($root . '/VERSION', $to . "\n");
        updater_set_maintenance(false);

        if (!$migrationResult['success']) {
            $result['status']  = 'failed';
            $result['skipped'] = array_values($skipped);
            $result['error']   = 'Files were updated but a database migration failed: '
                                . ($migrationResult['error'] ?? 'unknown error');
            $logger($from, $to, 'failed', $skipped, $result['error']);
            return $result;
        }

        $status = $skipped === [] ? 'success' : 'partial';
        $result['status']  = $status;
        $result['skipped'] = array_values($skipped);
        $emit('done', 'Готово.');
        $logger($from, $to, $status, $skipped, null);
        return $result;

    } catch (\Throwable $e) {
        // Never leave the site stuck in maintenance mode.
        updater_set_maintenance(false);
        if ($stagingDir !== null) {
            updater_rrmdir($stagingDir);
            @unlink($stagingDir . '.zip');
        }
        $result['status'] = 'failed';
        $result['error']  = 'The update failed: ' . $e->getMessage();
        $logger($from, $to, 'failed', $result['skipped'], $result['error']);
        return $result;
    }
}
