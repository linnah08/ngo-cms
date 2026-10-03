<?php
/**
 * Release gate, step "update": runs an installed site's OWN updater against a
 * release ZIP on disk, in its own PHP process.
 *
 *   php apply.php <site dir> <new.zip> <new version>
 *
 * This is the code an adopter actually runs when they press "Обнови сега":
 * the site's config.php and includes/updater.php — the PREVIOUS release's —
 * with the real migrations and the real audit log. Only the network is
 * replaced: GitHub's "latest release" answer and the download both come from
 * the ZIP given here. The result is printed as the last line, as JSON.
 */
if (PHP_SAPI !== 'cli') { exit; }

$site    = rtrim((string) ($argv[1] ?? ''), '/');
$zipPath = (string) ($argv[2] ?? '');
$version = ltrim((string) ($argv[3] ?? ''), 'v');
if ($site === '' || !is_file($zipPath) || $version === '') {
    fwrite(STDERR, "usage: php apply.php <site dir> <new.zip> <new version>\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT']  = $site;
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/admin/update-apply-ajax.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
chdir($site);

require $site . '/config.php';
require $site . '/includes/updater.php';

$zipUrl  = 'https://release-gate.invalid/ngo-platform-v' . $version . '.zip';
$fetcher = static function (string $url) use ($zipUrl, $zipPath, $version): array {
    if (str_contains($url, 'api.github.com')) {
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => json_encode([
            'tag_name' => 'v' . $version,
            'body'     => 'Release gate',
            'assets'   => [['name' => basename($zipUrl), 'browser_download_url' => $zipUrl]],
        ])];
    }
    if ($url === $zipUrl) {
        return ['ok' => true, 'status' => 200, 'error' => null, 'body' => (string) file_get_contents($zipPath)];
    }
    return ['ok' => false, 'status' => 404, 'error' => 'unexpected URL', 'body' => ''];
};

$phases = [];
$result = updater_apply(
    static function (array $state) use (&$phases): void {
        $phases[(string) ($state['phase'] ?? '')] = true;
    },
    ['http' => $fetcher]
);
$result['phases']      = array_keys($phases);
$result['maintenance'] = function_exists('updater_is_maintenance_mode') ? updater_is_maintenance_mode() : null;

echo "\n@@RELEASE_GATE_RESULT@@" . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
