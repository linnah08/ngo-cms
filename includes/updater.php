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
 * names/signatures/return shapes):
 *   - updater_get_local_version(): string
 *   - updater_check_latest(bool $force = false): array
 *   - updater_apply(): array
 *   - updater_is_maintenance_mode(): bool
 *
 * A couple of small pure helpers are also exposed for testability:
 *   - updater_version_needs_update(string $local, string $latest): bool
 *   - updater_diff_conflicts(array $oldChecksums, array $newFiles, callable $liveHasher): array
 */

if (!defined('UPDATER_GITHUB_REPO')) {
    define('UPDATER_GITHUB_REPO', 'linnah08/ngo-cms');
}
if (!defined('UPDATER_CACHE_TTL')) {
    define('UPDATER_CACHE_TTL', 3600); // 1 hour
}

// ── Paths ──────────────────────────────────────────────────────────────────────

function updater_root(): string
{
    if (defined('ROOT_PATH') && ROOT_PATH !== '') {
        return ROOT_PATH;
    }
    return dirname(__DIR__);
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

function updater_download_to_file(string $url, string $destPath, ?callable $httpFetcher = null): bool
{
    $httpFetcher ??= 'updater_http_get';
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
 * $liveHasher: callable(string $relativePath): ?string — current sha256 of
 * the live file, or null if it doesn't exist.
 */
function updater_diff_conflicts(array $oldChecksums, array $newFiles, callable $liveHasher): array
{
    $skipped = [];
    foreach ($newFiles as $path) {
        if (!array_key_exists($path, $oldChecksums)) {
            continue; // no baseline — new file, not a conflict
        }
        $liveHash = $liveHasher($path);
        if ($liveHash === null) {
            continue; // nothing live to conflict with
        }
        if (!hash_equals((string) $oldChecksums[$path], (string) $liveHash)) {
            $skipped[] = $path;
        }
    }
    return $skipped;
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
 */
function updater_create_backup(string $fromVersion): ?string
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

    foreach (updater_list_files($root, updater_backup_excludes()) as $rel) {
        $zip->addFile($root . '/' . $rel, $rel);
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
 * Return shape:
 *   ['status' => 'success'|'partial'|'failed', 'from_version' => string,
 *    'to_version' => string, 'skipped' => string[], 'error' => string|null]
 */
function updater_apply(): array
{
    $from   = updater_get_local_version();
    $result = [
        'status'       => 'failed',
        'from_version' => $from,
        'to_version'   => '',
        'skipped'      => [],
        'error'        => null,
    ];

    // (a) Re-check latest — bail out before touching anything if there's
    // nothing to apply or the check itself failed. Nothing was attempted yet,
    // so no audit row is written for this branch.
    $check = updater_check_latest(true);
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
        updater_log_attempt($from, $to, 'failed', [], $result['error']);
        return $result;
    }
    $backupPath = updater_create_backup($from);
    if ($backupPath === null) {
        $result['error'] = 'Could not create a backup of your site — the update was not applied.';
        updater_log_attempt($from, $to, 'failed', [], $result['error']);
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

        if (!updater_download_to_file($zipUrl, $zipTmp)) {
            throw new RuntimeException('Could not download the update package.');
        }

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
        $oldChecksumsFile = updater_root() . '/checksums.json';
        $oldChecksums     = [];
        if (is_file($oldChecksumsFile)) {
            $decoded = json_decode((string) file_get_contents($oldChecksumsFile), true);
            if (is_array($decoded)) $oldChecksums = $decoded;
        }

        $newFiles = updater_list_files($stagingDir);

        // (e) Diff + apply — skip any file the adopter customized.
        $root       = updater_root();
        $liveHasher = static function (string $rel) use ($root): ?string {
            $p = $root . '/' . $rel;
            return is_file($p) ? hash_file('sha256', $p) : null;
        };
        $skipped = $oldChecksums === [] ? [] : updater_diff_conflicts($oldChecksums, $newFiles, $liveHasher);
        $skippedSet = array_flip($skipped);

        foreach ($newFiles as $rel) {
            if (isset($skippedSet[$rel])) continue;
            $src    = $stagingDir . '/' . $rel;
            $dst    = $root . '/' . $rel;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir) && !@mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
                throw new RuntimeException("Could not create directory for: {$rel}");
            }
            if (!@copy($src, $dst)) {
                throw new RuntimeException("Could not write file: {$rel}");
            }
        }

        updater_rrmdir($stagingDir);
        $stagingDir = null;

        // (f) Run any new migrations in-process.
        require_once $root . '/migrate.php'; // defines run_migrations(); CLI auto-run is a no-op here
        $migrationResult = function_exists('run_migrations')
            ? run_migrations()
            : ['success' => true, 'error' => null];

        // (g) Finalize: stamp VERSION, drop maintenance mode, audit log.
        file_put_contents($root . '/VERSION', $to . "\n");
        updater_set_maintenance(false);

        if (!$migrationResult['success']) {
            $result['status']  = 'failed';
            $result['skipped'] = array_values($skipped);
            $result['error']   = 'Files were updated but a database migration failed: '
                                . ($migrationResult['error'] ?? 'unknown error');
            updater_log_attempt($from, $to, 'failed', $skipped, $result['error']);
            return $result;
        }

        $status = $skipped === [] ? 'success' : 'partial';
        $result['status']  = $status;
        $result['skipped'] = array_values($skipped);
        updater_log_attempt($from, $to, $status, $skipped, null);
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
        updater_log_attempt($from, $to, 'failed', $result['skipped'], $result['error']);
        return $result;
    }
}
