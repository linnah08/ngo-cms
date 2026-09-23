<?php
/**
 * Put an existing site onto the update channel.
 *
 * The admin's "Обнови сега" button can only protect a site's own changes when
 * it has a baseline to compare against: checksums.json, the hashes of the files
 * exactly as the release shipped them. A site installed from a release zip gets
 * that file in the zip. A site that was deployed some other way — from git, by
 * hand, migrated from another host — has no baseline, and updater_apply() then
 * overwrites everything, including whatever was customized for that site.
 *
 * This script gives such a site its baseline, after showing exactly what the
 * next update would keep and what it would replace. Run it read-only first:
 *
 *   php adopt-release.php 0.8.0
 *   php adopt-release.php 0.8.0 --write
 *
 * The version to pass is the release the site's code currently matches — not
 * the newest one. Seeding a baseline from the wrong release marks every file
 * that moved in between as "customized", and those files then stay frozen at
 * their current contents through every future update.
 *
 * Options:
 *   --root=PATH   site root to adopt (default: the repo this script lives in)
 *   --repo=O/N    GitHub repo to fetch the release from (default: UPDATER_GITHUB_REPO)
 *   --write       actually write checksums.json + VERSION (default: report only)
 *   --force       write even when the site looks unlike the chosen release
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/updater.php';

// ── Arguments ─────────────────────────────────────────────────────────────────

$args    = array_slice($argv, 1);
$version = null;
$root    = __DIR__;
$repo    = UPDATER_GITHUB_REPO;
$write   = false;
$force   = false;

foreach ($args as $arg) {
    if ($arg === '--write')                   { $write = true; continue; }
    if ($arg === '--force')                   { $force = true; continue; }
    if (str_starts_with($arg, '--root='))     { $root = rtrim(substr($arg, 7), '/'); continue; }
    if (str_starts_with($arg, '--repo='))     { $repo = substr($arg, 7); continue; }
    if (str_starts_with($arg, '--'))          { fwrite(STDERR, "Unknown option: {$arg}\n"); exit(2); }
    $version = ltrim($arg, 'v');
}

if ($version === null || $version === '') {
    fwrite(STDERR, "Usage: php adopt-release.php <version> [--root=PATH] [--repo=OWNER/NAME] [--write] [--force]\n");
    exit(2);
}
if (!is_dir($root) || !is_file($root . '/config.php')) {
    fwrite(STDERR, "Not a site root (no config.php): {$root}\n");
    exit(2);
}

updater_set_root_override($root);

echo "Site   : {$root}\n";
echo "Release: v{$version} from {$repo}\n";
echo "Mode   : " . ($write ? 'WRITE' : 'report only') . "\n\n";

// ── Fetch the release zip ─────────────────────────────────────────────────────

$api  = "https://api.github.com/repos/{$repo}/releases/tags/v{$version}";
$resp = updater_http_get($api);
if (empty($resp['ok']) || ($resp['status'] ?? 0) !== 200) {
    fwrite(STDERR, "Could not read release v{$version} from GitHub (HTTP " . ($resp['status'] ?? '?') . ").\n");
    exit(1);
}
$data = json_decode((string) $resp['body'], true);
$zipUrl = null;
foreach ((array) ($data['assets'] ?? []) as $asset) {
    if (str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.zip')) {
        $zipUrl = (string) $asset['browser_download_url'];
        break;
    }
}
if ($zipUrl === null) {
    fwrite(STDERR, "Release v{$version} has no zip asset attached.\n");
    exit(1);
}

// updater_tmp_dir() only names a directory — creating it is the caller's job.
$tmp     = updater_tmp_dir('adopt');
$zipPath = $tmp . '/release.zip';
$stage   = $tmp . '/stage';

if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create a working directory at {$tmp}\n");
    exit(1);
}

$cleanup = static function () use ($tmp) { updater_rrmdir($tmp); };

echo "Downloading " . basename($zipUrl) . " …\n";
if (!updater_download_to_file($zipUrl, $zipPath)) {
    $cleanup();
    fwrite(STDERR, "Download failed.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true || !@mkdir($stage, 0755, true) || $zip->extractTo($stage) === false) {
    $zip->close();
    $cleanup();
    fwrite(STDERR, "Could not extract the release package.\n");
    exit(1);
}
$zip->close();

$baselineFile = $stage . '/checksums.json';
if (!is_file($baselineFile)) {
    $cleanup();
    fwrite(STDERR, "That release has no checksums.json — it predates the update feature.\n");
    exit(1);
}
$baseline = json_decode((string) file_get_contents($baselineFile), true);
if (!is_array($baseline) || $baseline === []) {
    $cleanup();
    fwrite(STDERR, "The release's checksums.json is unreadable.\n");
    exit(1);
}

// ── What would the next update do? ────────────────────────────────────────────
// Deliberately the same calls updater_apply() makes, so this is a rehearsal of
// the real thing rather than a second implementation that can drift from it.

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

$newFiles = updater_files_to_apply(updater_list_files($stage));
$kept     = updater_owner_files_to_keep($newFiles, $liveHasher);
$keptSet  = array_flip($kept);
$newFiles = array_values(array_filter($newFiles, static fn($r) => !isset($keptSet[$r])));
$skipped  = updater_diff_conflicts($baseline, $newFiles, $liveHasher);

$outsideVendor = static fn(array $paths) => array_values(
    array_filter($paths, static fn($r) => !str_starts_with($r, 'vendor/'))
);
$releaseFiles = $outsideVendor($newFiles);
$customized   = $outsideVendor($skipped);

// A baseline from the wrong release shows up as an implausible number of
// "customized" files. Freezing that many paths forever is worse than stopping.
$ratio = count($releaseFiles) > 0 ? count($customized) / count($releaseFiles) : 0.0;

echo "\nThis site's own files, kept through every update:\n";
foreach ($customized as $rel) echo "  keep    {$rel}\n";
if ($customized === []) echo "  (none — the site matches the release exactly)\n";

echo "\nOwner uploads, never overwritten:\n";
foreach ($kept as $rel) echo "  keep    {$rel}\n";
if ($kept === []) echo "  (none)\n";

printf(
    "\n%d of %d release files (outside vendor/) are customized here — %.1f%%.\n",
    count($customized), count($releaseFiles), $ratio * 100
);

if ($ratio > 0.2 && !$force) {
    $cleanup();
    fwrite(STDERR,
        "\nStopping: that is a lot of customization for a site said to be on v{$version}.\n"
      . "Usually it means the wrong version was given — pick the release this site's\n"
      . "code actually matches. Pass --force if the number really is expected.\n");
    exit(1);
}

if (!$write) {
    $cleanup();
    echo "\nReport only — nothing written. Re-run with --write to adopt this release.\n";
    exit(0);
}

// ── Write the baseline ────────────────────────────────────────────────────────
// VERSION goes with it: it is what the update check compares against, and a
// baseline describing v{$version} on a site still claiming 0.0.0-dev would
// offer the same release forever.

if (!@copy($baselineFile, $root . '/checksums.json')) {
    $cleanup();
    fwrite(STDERR, "Could not write {$root}/checksums.json\n");
    exit(1);
}
if (file_put_contents($root . '/VERSION', $version . "\n") === false) {
    $cleanup();
    fwrite(STDERR, "Could not write {$root}/VERSION\n");
    exit(1);
}

$cleanup();

echo "\nWritten:\n";
echo "  checksums.json  (" . count($baseline) . " entries)\n";
echo "  VERSION         ({$version})\n";
echo "\nThis site is now on the update channel. Set FEATURE_SELF_UPDATE to true in\n";
echo "site.config.php to show the update button in the admin.\n";
