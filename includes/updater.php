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
 * Boot check + rollback (see the sections of the same names below): after
 * applying, the site's key pages are loaded in a separate PHP process; if they
 * no longer load, every file the update wrote is put back automatically.
 *   - updater_boot_check(string $root, ?array $pages = null, ?string $method = null, array $opts = []): array
 *   - updater_boot_check_verdict(array $baseline, array $after): array
 *   - updater_restore_backup(string $root, string $backupZip, array $journal): array
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
 * FEATURE_SELF_UPDATE to false in site.config.php. With nothing set, a .git
 * folder in the site root is taken as the same answer — but an install that is
 * a git clone on purpose (the test site for this feature) says true and wins.
 */
function updater_self_update_allowed(): bool
{
    $override = $GLOBALS['__updater_self_update_override'] ?? null;
    if (is_bool($override)) {
        return $override;
    }
    // An explicit answer in site.config.php always wins, in both directions:
    // false on a fork that must never be overwritten, true on an install that
    // is a git clone on purpose — the test site for this very feature is one.
    if (defined('FEATURE_SELF_UPDATE')) {
        return (bool) FEATURE_SELF_UPDATE;
    }
    // Nothing said: a .git folder means a developer looks after this copy.
    return !file_exists(updater_root() . '/.git');
}

/**
 * Test seam, like updater_set_root_override(): lets a test drive updater_apply()
 * whatever the host site's own config says, so the apply tests still run on a
 * fork that has switched self-update off. Production never calls this.
 */
function updater_set_self_update_override(?bool $allowed): void
{
    $GLOBALS['__updater_self_update_override'] = $allowed;
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
 * Machine-generated files that are never an adopter's own work, whatever their
 * hash says. vendor/ is written by composer, and the autoload maps in
 * vendor/composer/ differ from the release's the moment a site's vendor was
 * installed with dev dependencies — which is every site deployed from git
 * rather than from the zip. Treating that as a customization would leave the
 * site on a stale autoloader, and the next release that adds a class fatals on
 * a site that was told its update succeeded.
 */
function updater_never_conflicts(string $rel): bool
{
    return str_starts_with($rel, 'vendor/');
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
        if (updater_never_conflicts($path)) {
            continue; // composer's, not the adopter's
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
 * Server configuration, which every host sets differently: PHP limits, the
 * rewrite rules, the handler cPanel writes. Keeping the live copy is the whole
 * point of these, so like owner files they are kept quietly — telling an admin
 * "you customized .user.ini, contact support" names a file they have never
 * opened, about a difference they did not make and cannot act on.
 */
function updater_is_host_config(string $rel): bool
{
    return in_array(basename($rel), ['.htaccess', '.user.ini', 'php.ini'], true);
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
        'apply'    => [65, 85],
        'migrate'  => [85, 90],
        'verify'   => [90, 95],
        'restore'  => [95, 100],
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
 * backups/, created on first use and locked against web access. The backup zip
 * holds db.config.php, and its name (version + timestamp) is guessable, so it
 * must never be downloadable — the same "Require all denied" logs/ gets.
 */
function updater_backups_dir(): ?string
{
    $dir = updater_root() . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
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
    $backupsDir = updater_backups_dir();
    if ($backupsDir === null) {
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
        // back to the plain error log. The skipped paths go in there too: the
        // admin is told nothing about them on purpose, so this row is the only
        // record of what an update left alone, and losing it because the
        // database was unreachable would leave support with nothing to read.
        if (function_exists('_om_log')) {
            _om_log('UPDATER', sprintf(
                'Failed to write platform_updates audit row (%s). Update %s -> %s: %s. Files left unchanged: %s',
                $e->getMessage(),
                $from,
                $to,
                $status,
                $skipped ? implode(', ', array_values($skipped)) : 'none'
            ));
        }
    }
}

// ── Boot check ─────────────────────────────────────────────────────────────────
// After an update is applied, and before the site leaves maintenance mode, the
// new tree has to prove it still serves pages. v0.17.0 showed why: a release
// whose files were all written correctly still took a site down, because the
// site's own kept config.php and a new file declared the same function. Only
// actually loading the site finds that.
//
// The pages are rendered in a SEPARATE PHP process, so a fatal error in them
// kills that process and not the updater, which then still has the chance to
// put the old files back. Two ways to get such a process, in order:
//   - cli:  the PHP command-line binary via proc_open(), with $_SERVER set up
//           the way a web request would have it. Works without any network.
//   - http: a request to SITE_URL, for hosts that disable proc_open(). The
//           site is in maintenance mode at that point, so the request carries
//           a one-time token that config.php accepts in its place.
// With neither, the check is recorded as "not checked" — never as a failure.
//
// The same check runs on the OLD tree first (the baseline). A page that does
// not pass there — the CLI lacks an extension, the home page needs a database
// the CLI cannot reach, the host cannot reach its own domain — says something
// about this server, not about the release, so only pages that pass the
// baseline can fail the update. Without that, an environment quirk would roll
// back every good release.

/** URL => script (relative to the site root) that serves it. */
function updater_boot_check_pages(): array
{
    return [
        '/'               => 'index.php',
        '/en/'            => 'en/index.php',
        '/admin/login.php' => 'admin/login.php',
    ];
}

function updater_proc_open_enabled(): bool
{
    if (!function_exists('proc_open') || !function_exists('proc_close')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

/**
 * Runs $argv (no shell) and waits at most $timeout seconds. Null when the
 * process could not be started at all.
 *
 * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}|null
 */
function updater_run_process(array $argv, int $timeout, ?string $cwd = null): ?array
{
    if (!updater_proc_open_enabled()) {
        return null;
    }
    $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = $err = '';
    $limit    = 4 * 1024 * 1024;
    $deadline = microtime(true) + $timeout;
    $timedOut = false;
    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = [];
        if (!feof($pipes[1])) $read[] = $pipes[1];
        if (!feof($pipes[2])) $read[] = $pipes[2];
        $w = $e = null;
        if (@stream_select($read, $w, $e, 0, 200000) === false) {
            usleep(50000);
        }
        foreach ($read as $pipe) {
            $chunk = (string) fread($pipe, 65536);
            if ($pipe === $pipes[1]) {
                if (strlen($out) < $limit) $out .= $chunk;
            } elseif (strlen($err) < $limit) {
                $err .= $chunk;
            }
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            @proc_terminate($proc, 9);
            break;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return ['exit' => (int) $exit, 'stdout' => $out, 'stderr' => $err, 'timed_out' => $timedOut];
}

/**
 * The PHP command-line binary matching the running PHP's version, or null.
 *
 * Under PHP-FPM / LiteSpeed, PHP_BINARY is the FastCGI server, not a CLI, so
 * the CLI is looked for next to it (cPanel's ea-phpXX keeps both in one bin/).
 * A candidate counts only if it really runs code and reports the same
 * major.minor — the system /usr/bin/php is often a different version.
 */
function updater_php_cli_binary(): ?string
{
    static $cache = [];
    $key = PHP_BINARY . '|' . PHP_BINDIR;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = null;
    if (!updater_proc_open_enabled()) {
        return null;
    }

    $want       = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates = [];
    if (PHP_BINARY !== '' && preg_match('/^php[0-9.]*$/', basename(PHP_BINARY))) {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = PHP_BINDIR . '/php';
    $candidates[] = PHP_BINDIR . '/php' . $want;
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/usr/bin/php';

    foreach (array_unique($candidates) as $bin) {
        $r = updater_run_process([$bin, '-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'], 10);
        if ($r !== null && !$r['timed_out'] && $r['exit'] === 0 && trim($r['stdout']) === $want) {
            return $cache[$key] = $bin;
        }
    }
    return null;
}

/**
 * The child script for the cli method. It is written to the system temp
 * directory for one check and deleted straight after — it never exists under
 * the web root, so no visitor can ever request it — and it refuses to run
 * under anything but the CLI regardless.
 */
function updater_boot_runner_source(): string
{
    return <<<'PHP'
<?php
// Boot check for one page, written by includes/updater.php and deleted after use.
if (PHP_SAPI !== 'cli') { exit; }
$root   = (string) ($argv[1] ?? '');
$script = (string) ($argv[2] ?? '');
$uri    = (string) ($argv[3] ?? '/');
$host   = (string) ($argv[4] ?? 'localhost');
$https  = ($argv[5] ?? '0') === '1';
$file   = $root . '/' . $script;

$_SERVER = array_merge($_SERVER, [
    'DOCUMENT_ROOT'   => $root,
    'REQUEST_URI'     => $uri,
    'REQUEST_METHOD'  => 'GET',
    'QUERY_STRING'    => '',
    'HTTP_HOST'       => $host,
    'SERVER_NAME'     => $host,
    'SERVER_PORT'     => $https ? '443' : '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SCRIPT_NAME'     => '/' . $script,
    'PHP_SELF'        => '/' . $script,
    'SCRIPT_FILENAME' => $file,
    'REMOTE_ADDR'     => '127.0.0.1',
    'HTTP_USER_AGENT' => 'ngo-cms-boot-check',
]);
if ($https) { $_SERVER['HTTPS'] = 'on'; }
$_GET = $_POST = $_COOKIE = $_FILES = $_REQUEST = [];

// Registered from inside a shutdown function, so it runs after every handler
// the page registers itself (config.php's own fatal handler included).
register_shutdown_function(static function (): void {
    register_shutdown_function(static function (): void {
        $err   = error_get_last();
        $fatal = null;
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $fatal = $err['message'] . ' in ' . $err['file'] . ':' . $err['line'];
        }
        $body = '';
        while (ob_get_level() > 0) { $body = (string) ob_get_clean() . $body; }
        $code = http_response_code();
        fwrite(STDOUT, $body . "\n@@NGO_BOOT_CHECK@@" . json_encode([
            'status' => $code === false ? 200 : (int) $code,
            'fatal'  => $fatal,
        ]));
    });
});

if (!is_file($file)) {
    http_response_code(404);
    exit;
}
chdir(dirname($file));
ob_start();
require $file;
PHP;
}

/** PHP error text in a rendered page — what a host with display_errors on shows. */
function updater_page_has_error_text(string $html): bool
{
    return (bool) preg_match(
        '/(?:^|\n|<br\s*\/?>)\s*(?:<b>)?(?:PHP )?(?:Fatal error|Parse error|Warning)(?:<\/b>)?:\s/i',
        $html
    );
}

/**
 * One page through the cli method.
 *
 * @return array{ok:bool, status:int, error:?string}
 */
function updater_boot_check_cli_page(string $php, string $runner, string $root, string $uri, string $script): array
{
    $host  = 'localhost';
    $https = '0';
    if (defined('SITE_URL')) {
        $parts = parse_url((string) SITE_URL);
        if (!empty($parts['host'])) $host = (string) $parts['host'];
        $https = (($parts['scheme'] ?? '') === 'https') ? '1' : '0';
    }
    $r = updater_run_process([$php, $runner, $root, $script, $uri, $host, $https], 30, $root);
    if ($r === null) {
        return ['ok' => false, 'status' => 0, 'error' => 'could not start PHP'];
    }
    if ($r['timed_out']) {
        return ['ok' => false, 'status' => 0, 'error' => 'timed out'];
    }
    $pos = strrpos($r['stdout'], "\n@@NGO_BOOT_CHECK@@");
    if ($pos === false) {
        $why = trim(substr($r['stderr'] !== '' ? $r['stderr'] : $r['stdout'], 0, 500));
        return ['ok' => false, 'status' => 0, 'error' => 'PHP exited with code ' . $r['exit'] . ($why !== '' ? ': ' . $why : '')];
    }
    $html = substr($r['stdout'], 0, $pos);
    $meta = json_decode(substr($r['stdout'], $pos + strlen("\n@@NGO_BOOT_CHECK@@")), true);
    $status = is_array($meta) ? (int) ($meta['status'] ?? 0) : 0;
    $fatal  = is_array($meta) ? ($meta['fatal'] ?? null) : 'unreadable result';

    if ($fatal !== null) {
        return ['ok' => false, 'status' => $status, 'error' => 'PHP fatal error: ' . $fatal];
    }
    if ($status < 200 || $status >= 400) {
        return ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . $status];
    }
    if (updater_page_has_error_text($html)) {
        return ['ok' => false, 'status' => $status, 'error' => 'PHP error shown on the page'];
    }
    return ['ok' => true, 'status' => $status, 'error' => null];
}

/** Where the one-time token for an http boot check lives: logs/, never web-readable. */
function updater_boot_token_file(string $root): string
{
    return $root . '/logs/boot-check.token';
}

/**
 * Default transport for the http method: a plain GET that never follows
 * redirects, with a short timeout. Status 0 = the site could not be reached.
 *
 * @return array{status:int, body:string}
 */
function updater_boot_http_probe(string $url, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: ngo-cms-boot-check'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $body === false ? 0 : $code, 'body' => (string) $body];
}

/**
 * Renders the site's key pages in a separate process and reports each one.
 *
 * $pages: URL => script; null means updater_boot_check_pages().
 * $method: 'cli' | 'http' | null (null picks the first that is available).
 * $opts (test seams): 'php' => CLI binary, 'site_url' => base URL for http,
 * 'http' => callable(string $url, array $headers): array{status:int, body:string}.
 *
 * @return array{method:string, pages:array<string,array{ok:bool,status:int,error:?string}>}
 *         method 'none' (and no pages) when there is no way to run the check.
 */
function updater_boot_check(string $root, ?array $pages = null, ?string $method = null, array $opts = []): array
{
    $pages ??= updater_boot_check_pages();
    $root    = rtrim($root, '/');
    $siteUrl = (string) ($opts['site_url'] ?? (defined('SITE_URL') ? SITE_URL : ''));

    $php = null;
    if ($method === null || $method === 'cli') {
        $php = $opts['php'] ?? updater_php_cli_binary();
        if ($php !== null) {
            $method = 'cli';
        } elseif ($method === 'cli') {
            return ['method' => 'none', 'pages' => []];
        }
    }
    if ($method === null || $method === 'http') {
        $canHttp = $siteUrl !== '' && (isset($opts['http']) || function_exists('curl_init'));
        $method  = $canHttp ? 'http' : 'none';
    }
    if ($method === 'none' || $pages === []) {
        return ['method' => 'none', 'pages' => []];
    }

    $results = [];

    if ($method === 'cli') {
        $runner = updater_tmp_dir('ngo-cms-boot-check-') . '.php';
        if (@file_put_contents($runner, updater_boot_runner_source()) === false) {
            return ['method' => 'none', 'pages' => []];
        }
        try {
            foreach ($pages as $uri => $script) {
                $results[$uri] = updater_boot_check_cli_page($php, $runner, $root, (string) $uri, (string) $script);
            }
        } finally {
            @unlink($runner);
        }
        return ['method' => 'cli', 'pages' => $results];
    }

    // http: config.php lets a request through maintenance mode only when it
    // carries the token that is in this file at that moment.
    $probe     = $opts['http'] ?? 'updater_boot_http_probe';
    $tokenFile = updater_boot_token_file($root);
    $logsDir   = dirname($tokenFile);
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    if (is_dir($logsDir) && !is_file($logsDir . '/.htaccess')) {
        @file_put_contents($logsDir . '/.htaccess', "Require all denied\n");
    }
    $token = bin2hex(random_bytes(24));
    if (@file_put_contents($tokenFile, $token, LOCK_EX) === false) {
        $token = '';
    }
    try {
        foreach ($pages as $uri => $script) {
            $resp   = $probe(rtrim($siteUrl, '/') . $uri, $token !== '' ? ['X-NGO-Boot-Check: ' . $token] : []);
            $status = (int) ($resp['status'] ?? 0);
            $body   = (string) ($resp['body'] ?? '');
            if ($status === 0) {
                $results[$uri] = ['ok' => false, 'status' => 0, 'error' => 'site not reachable'];
            } elseif ($status < 200 || $status >= 400) {
                $results[$uri] = ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . $status];
            } elseif (updater_page_has_error_text($body)) {
                $results[$uri] = ['ok' => false, 'status' => $status, 'error' => 'PHP error shown on the page'];
            } else {
                $results[$uri] = ['ok' => true, 'status' => $status, 'error' => null];
            }
        }
    } finally {
        @unlink($tokenFile);
    }
    return ['method' => 'http', 'pages' => $results];
}

/**
 * Pure verdict: does $after still serve every page that $baseline served?
 *
 * @return array{ok:?bool, reason:string} ok null = could not be checked.
 */
function updater_boot_check_verdict(array $baseline, array $after): array
{
    $trusted = array_keys(array_filter((array) ($baseline['pages'] ?? []), static fn($p) => !empty($p['ok'])));
    if (($baseline['method'] ?? 'none') === 'none' || $trusted === []) {
        return ['ok' => null, 'reason' => 'Boot check not run: this server offers no way to load the site in a separate process.'];
    }
    if (($after['method'] ?? 'none') === 'none') {
        return ['ok' => null, 'reason' => 'Boot check not run after the update.'];
    }
    $failures = [];
    foreach ($trusted as $uri) {
        $page = $after['pages'][$uri] ?? null;
        if (!is_array($page) || empty($page['ok'])) {
            $failures[] = $uri . ' → ' . (is_array($page) ? (string) ($page['error'] ?? 'failed') : 'not checked');
        }
    }
    if ($failures !== []) {
        return ['ok' => false, 'reason' => 'The site did not load after the update (' . $after['method'] . '): ' . implode('; ', $failures)];
    }
    return ['ok' => true, 'reason' => ''];
}

/**
 * Lets PHP-FPM drop its cached copy of each changed file at once. Without it
 * opcache can keep serving the previous code for a couple of seconds — long
 * enough for an http boot check to pass on code that is no longer there.
 */
function updater_opcache_invalidate(string $root, array $relPaths): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    foreach ($relPaths as $rel) {
        if (str_ends_with((string) $rel, '.php')) {
            @opcache_invalidate($root . '/' . $rel, true);
        }
    }
}

// ── Rollback ───────────────────────────────────────────────────────────────────
// What the apply step changed is recorded as it goes (the "journal"), so a
// failed update can be undone exactly:
//   - a file that existed before comes back from the pre-update backup zip;
//   - a file outside the backup (vendor/, content/ — see
//     updater_backup_excludes()) that existed before is copied aside into the
//     journal just before it is overwritten, and comes back from there;
//   - a file the release ADDED is deleted, and so are the folders the apply
//     created for it, if they are empty again.
// Only files the update itself wrote are touched. Restoring the whole zip, or
// deleting everything "not in the backup", would also hit what the backup
// leaves out on purpose — uploads written by visitors between the backup and
// now, logs, content — and gain nothing: nothing else changed those files.

/** Is $rel inside the pre-update backup zip? (Its top folder is not excluded.) */
function updater_backup_covers(string $rel): bool
{
    if (!str_contains($rel, '/')) {
        return true; // files in the site root are always in the backup
    }
    $top = explode('/', $rel, 2)[0];
    return !in_array($top, updater_backup_excludes(), true);
}

/**
 * Puts every file the update wrote back the way it was.
 *
 * $journal: ['written' => [rel => bool existedBefore], 'created_dirs' => [abs dir, ...],
 *            'saved_dir' => ?string, 'saved' => [rel => true]]
 *
 * @return array{ok:bool, error:?string}
 */
function updater_restore_backup(string $root, string $backupZip, array $journal): array
{
    $zip = null;
    if (is_file($backupZip)) {
        $zip = new ZipArchive();
        if ($zip->open($backupZip) !== true) {
            $zip = null;
        }
    }

    $failed = [];
    foreach ((array) ($journal['written'] ?? []) as $rel => $existed) {
        $rel = (string) $rel;
        $dst = $root . '/' . $rel;

        if (!empty($journal['saved'][$rel]) && !empty($journal['saved_dir'])) {
            $src = $journal['saved_dir'] . '/' . $rel;
            if (!is_file($src) || !@copy($src, $dst)) $failed[] = $rel;
            continue;
        }
        if ($existed) {
            $data = $zip !== null ? $zip->getFromName($rel) : false;
            if ($data === false || @file_put_contents($dst, $data) === false) {
                $failed[] = $rel;
            }
            continue;
        }
        // Added by the release: it was not there before, so it goes.
        if (is_file($dst) && !@unlink($dst)) {
            $failed[] = $rel;
        }
    }
    if ($zip !== null) {
        $zip->close();
    }

    // Deepest first, and only if empty — rmdir refuses anything else.
    $dirs = (array) ($journal['created_dirs'] ?? []);
    usort($dirs, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    foreach ($dirs as $dir) {
        if (is_dir($dir)) @rmdir($dir);
    }

    if ($failed !== []) {
        return ['ok' => false, 'error' => 'Could not restore: ' . implode(', ', array_slice($failed, 0, 20))
            . (count($failed) > 20 ? ' (+' . (count($failed) - 20) . ' more)' : '')];
    }
    return ['ok' => true, 'error' => null];
}

// ── Apply ──────────────────────────────────────────────────────────────────────

function updater_tmp_dir(string $prefix): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . bin2hex(random_bytes(8));
}


/**
 * A boot check that can never throw: anything unexpected means "not checked".
 */
function updater_safe_boot_check(callable $bootCheck, string $root, ?array $pages, ?string $method): array
{
    try {
        $r = $bootCheck($root, $pages, $method);
        return is_array($r) ? $r : ['method' => 'none', 'pages' => []];
    } catch (\Throwable $e) {
        return ['method' => 'none', 'pages' => []];
    }
}

/**
 * The boot check again, on the pages that passed $baseline and with the same
 * method — nothing else can tell a broken release from a quirk of this server.
 */
function updater_boot_check_again(callable $bootCheck, string $root, array $baseline): array
{
    $trusted = array_keys(array_filter((array) ($baseline['pages'] ?? []), static fn($p) => !empty($p['ok'])));
    $pages   = array_intersect_key(updater_boot_check_pages(), array_flip($trusted));
    if ($pages === [] || ($baseline['method'] ?? 'none') === 'none') {
        return ['method' => 'none', 'pages' => []];
    }
    return updater_safe_boot_check($bootCheck, $root, $pages, (string) $baseline['method']);
}

/**
 * Orchestrates a full self-update. Never throws — every failure path (network,
 * missing ZipArchive, backup failure, download/extract errors, migration
 * failure, a site that no longer loads) is caught and returned as a result
 * array instead. Always leaves `.maintenance` removed, even on failure, so the
 * site is never stuck down.
 *
 * Order, once the backup exists: maintenance on → baseline boot check of the
 * current site → download + extract → apply (journalled) → migrations → boot
 * check → finalize. If the boot check fails, or anything throws after the
 * first file was written, every file the update wrote is put back (see
 * updater_restore_backup()), the restored site is checked again, and the run
 * ends as 'rolled_back' — or 'rollback_failed' if even that does not load.
 *
 * The boot check runs AFTER the migrations on purpose: a new release may need
 * its new columns to render the home page, and checking before them would
 * roll back good releases. The database is not rolled back with the files —
 * it is not in the backup, and migrations here only ever add (columns,
 * tables), which the previous code simply does not use.
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
 *   'http'       => callable(string $url): array — stands in for every network call
 *   'migrator'   => callable(): array{success: bool, error: ?string}
 *   'logger'     => callable(string, string, string, array, ?string): void
 *   'boot_check' => callable(string $root, ?array $pages, ?string $method): array
 *                   — see updater_boot_check()
 * Combined with updater_set_root_override(), that makes a full run exercisable
 * against a throwaway directory with no network and no database.
 *
 * Return shape:
 *   ['status' => 'success'|'partial'|'failed'|'rolled_back'|'rollback_failed',
 *    'from_version' => string, 'to_version' => string, 'skipped' => string[],
 *    'error' => string|null,
 *    'backup' => string|null      — file name of the pre-update backup,
 *    'boot_check' => 'passed'|'failed'|'not_checked'|null]
 */
function updater_apply(?callable $onProgress = null, array $deps = []): array
{
    $http      = $deps['http']       ?? null;
    $migrator  = $deps['migrator']   ?? null;
    $logger    = $deps['logger']     ?? 'updater_log_attempt';
    $bootCheck = $deps['boot_check'] ?? 'updater_boot_check';

    $root = updater_root();
    $from = updater_get_local_version();

    $result = [
        'status'       => 'failed',
        'from_version' => $from,
        'to_version'   => '',
        'skipped'      => [],
        'error'        => null,
        'backup'       => null,
        'boot_check'   => null,
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
    $result['backup'] = basename($backupPath);

    $stagingDir = null;
    $skipped    = [];
    $baseline   = ['method' => 'none', 'pages' => []];
    // What the apply step changed, so it can be undone exactly — see
    // updater_restore_backup().
    $journal = ['written' => [], 'created_dirs' => [], 'saved_dir' => null, 'saved' => []];

    // Puts the previous version back, checks it loads, and records the outcome.
    $rollBack = static function (string $reason) use (
        $emit, $logger, $bootCheck, $root, $backupPath, $from, $to,
        &$journal, &$skipped, &$baseline, &$result
    ): array {
        // The log is for support: paths relative to the site read the same on any host.
        $reason = str_replace($root . '/', '', $reason);
        $emit('restore', 'Връщане на предишната версия…');
        try {
            $restore = updater_restore_backup($root, $backupPath, $journal);
            updater_opcache_invalidate($root, array_keys($journal['written']));
            $verdict = ['ok' => false, 'reason' => (string) $restore['error']];
            if ($restore['ok']) {
                $verdict = updater_boot_check_verdict($baseline, updater_boot_check_again($bootCheck, $root, $baseline));
            }
        } catch (\Throwable $e) {
            $restore = ['ok' => false, 'error' => $e->getMessage()];
            $verdict = ['ok' => false, 'reason' => $e->getMessage()];
        } finally {
            updater_set_maintenance(false);
        }

        $result['skipped'] = [];
        if ($restore['ok'] && $verdict['ok'] !== false) {
            // Only once the old site is back: the set-aside copies are no
            // longer needed. After a failed restore they are kept for support.
            if (!empty($journal['saved_dir'])) {
                updater_rrmdir($journal['saved_dir']);
            }
            $result['status'] = 'rolled_back';
            $result['error']  = $reason . ' The previous version (' . $from . ') was restored'
                . ($verdict['ok'] === true ? ' and loads normally.' : '. ' . $verdict['reason']);
        } else {
            $result['status'] = 'rollback_failed';
            $result['error']  = $reason . ' Restoring the previous version (' . $from . ') from '
                . $result['backup'] . ' did not work either: '
                . ($restore['ok'] ? $verdict['reason'] : (string) $restore['error']);
        }
        $logger($from, $to, $result['status'], $skipped, $result['error']);
        return $result;
    };

    try {
        // (c) Maintenance mode on, then note which pages load on the site as it
        // is now — the baseline the updated site is held to.
        updater_set_maintenance(true);
        $baseline = updater_safe_boot_check($bootCheck, $root, null, null);

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
            if (!is_dir($dstDir)) {
                // Every folder this creates is noted, so a rollback can remove it again.
                $missing = [];
                for ($d = $dstDir; strlen($d) > strlen($root) && !is_dir($d); $d = dirname($d)) {
                    $missing[] = $d;
                }
                if (!@mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
                    throw new RuntimeException("Could not create directory for: {$rel}");
                }
                array_push($journal['created_dirs'], ...$missing);
            }
            $existed = is_file($dst);
            $blocks  = (updater_is_host_managed($rel) && $existed)
                ? updater_host_blocks((string) file_get_contents($dst)) : [];
            $merged  = $blocks === []
                ? null
                : updater_merge_host_blocks((string) file_get_contents($src), $blocks);

            if ($existed) {
                // Already identical (most of vendor/, on most updates): nothing
                // to write, so nothing to undo either.
                $same = $merged === null
                    ? (filesize($src) === filesize($dst) && hash_file('sha256', $src) === hash_file('sha256', $dst))
                    : hash('sha256', $merged) === hash_file('sha256', $dst);
                if ($same) {
                    $emit('apply', 'Обновяване на файловете…', $doneFiles, $totalFiles);
                    continue;
                }
                // Not in the backup zip (vendor/, content/): set the current
                // copy aside first, or a rollback could not bring it back.
                if (!updater_backup_covers($rel)) {
                    if ($journal['saved_dir'] === null) {
                        $backupsDir = updater_backups_dir();
                        if ($backupsDir === null) {
                            throw new RuntimeException("Could not set aside: {$rel}");
                        }
                        $journal['saved_dir'] = $backupsDir . '/rollback-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
                    }
                    $saved    = $journal['saved_dir'] . '/' . $rel;
                    $savedDir = dirname($saved);
                    if ((!is_dir($savedDir) && !@mkdir($savedDir, 0755, true) && !is_dir($savedDir))
                        || !@copy($dst, $saved)) {
                        throw new RuntimeException("Could not set aside: {$rel}");
                    }
                    $journal['saved'][$rel] = true;
                }
            }

            // Recorded before the write: a file that fails half-way through
            // writing has to be undone as well.
            $journal['written'][$rel] = $existed;
            $written = $merged === null
                ? @copy($src, $dst)
                : @file_put_contents($dst, $merged) !== false;
            if (!$written) {
                throw new RuntimeException("Could not write file: {$rel}");
            }
            $emit('apply', 'Обновяване на файловете…', $doneFiles, $totalFiles);
        }

        updater_rrmdir($stagingDir);
        $stagingDir = null;
        updater_opcache_invalidate($root, array_keys($journal['written']));

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

        // (g) Does the site still load? Only the pages that loaded before the
        // update are held to it.
        $emit('verify', 'Проверка дали сайтът работи…');
        $verdict = updater_boot_check_verdict($baseline, updater_boot_check_again($bootCheck, $root, $baseline));
        $result['boot_check'] = $verdict['ok'] === true ? 'passed' : ($verdict['ok'] === null ? 'not_checked' : 'failed');

        if ($verdict['ok'] === false) {
            $reason = $verdict['reason'];
            if (!$migrationResult['success']) {
                $reason .= ' A database migration had also failed: ' . ($migrationResult['error'] ?? 'unknown error');
            }
            return $rollBack($reason);
        }

        // (h) Finalize: stamp VERSION, drop maintenance mode, audit log.
        file_put_contents($root . '/VERSION', $to . "\n");
        updater_set_maintenance(false);
        if (!empty($journal['saved_dir'])) {
            updater_rrmdir($journal['saved_dir']);
        }
        // "Could not check" is written down, never treated as a failure.
        $note = $verdict['ok'] === null ? $verdict['reason'] : null;

        if (!$migrationResult['success']) {
            $result['status']  = 'failed';
            $result['skipped'] = array_values($skipped);
            $result['error']   = 'Files were updated but a database migration failed: '
                                . ($migrationResult['error'] ?? 'unknown error');
            $logger($from, $to, 'failed', $skipped, $result['error'] . ($note !== null ? ' ' . $note : ''));
            return $result;
        }

        // Host configuration is kept on purpose, so it is not something to
        // report as a conflict. The log still records every skipped path —
        // support needs the whole picture, the admin only the part that is
        // theirs and that they can do something about.
        $reportable = array_values(array_filter(
            $skipped,
            static fn(string $rel) => !updater_is_host_config($rel)
        ));

        $status = $reportable === [] ? 'success' : 'partial';
        $result['status']  = $status;
        $result['skipped'] = $reportable;
        $emit('done', 'Готово.');
        $logger($from, $to, $status, $skipped, $note);
        return $result;

    } catch (\Throwable $e) {
        if ($stagingDir !== null) {
            updater_rrmdir($stagingDir);
            @unlink($stagingDir . '.zip');
        }
        $reason = 'The update failed: ' . $e->getMessage();
        // Files were already written: a half-applied release is exactly the
        // state that takes a site down, so put the old one back.
        if ($journal['written'] !== [] || $journal['created_dirs'] !== []) {
            return $rollBack($reason);
        }
        // Never leave the site stuck in maintenance mode.
        updater_set_maintenance(false);
        $result['status'] = 'failed';
        $result['error']  = $reason;
        $logger($from, $to, 'failed', $result['skipped'], $result['error']);
        return $result;
    }
}
