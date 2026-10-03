#!/usr/bin/env bash
# Builds the release ZIP from the current directory, exactly as the release
# workflow does: checksums.json for every shipped file, then the zip itself.
#
#   tests/release-gate/build-zip.sh <output.zip>
#
# Run it from the root of a tree that already has its production vendor/
# (composer install --no-dev) and its VERSION stamped. The workflow calls this
# script, so a local build and the published one cannot drift apart.
set -euo pipefail

OUT="${1:?usage: build-zip.sh <output.zip>}"

# Hashes every file that will end up in the release ZIP, so a future
# self-update (includes/updater.php) can detect locally-customized files by
# diffing live file hashes against this baseline before overwriting anything.
# Exclusions here MUST stay in sync with the `zip -x` list below — the
# checksums must describe exactly what's in the zip.
php -r '
$root        = getcwd();
$dirPrefixes = [".git", ".github", "tests", "node_modules", ".githooks", "relay", "docs"];
$exactFiles  = ["phpunit.xml", "playwright.config.js", "package.json", "package-lock.json", "deploy.php",
                "CLAUDE.md", ".gitignore"];
$exclude = function (string $rel) use ($dirPrefixes, $exactFiles): bool {
    if (in_array($rel, $exactFiles, true)) return true;
    if (str_ends_with($rel, ".zip")) return true;
    if (str_starts_with(basename($rel), ".phpunit")) return true;
    foreach ($dirPrefixes as $p) {
        if ($rel === $p || str_starts_with($rel, $p . "/")) return true;
    }
    return false;
};
$checksums = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $rel = ltrim(substr($file->getPathname(), strlen($root)), "/");
    if ($exclude($rel)) continue;
    $checksums[$rel] = hash_file("sha256", $file->getPathname());
}
ksort($checksums);
file_put_contents($root . "/checksums.json", json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDERR, "Generated checksums.json with " . count($checksums) . " entries\n");
'

rm -f "$OUT"
zip -rq "$OUT" . \
  -x ".git/*" ".github/*" "tests/*" "node_modules/*" \
     "*.zip" ".phpunit*" "phpunit.xml" "playwright.config.js" \
     "package.json" "package-lock.json" ".githooks/*" "deploy.php" \
     "relay/*" "docs/*" "CLAUDE.md" ".gitignore"
echo "Built $OUT"
