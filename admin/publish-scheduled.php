<?php
/**
 * Scheduled article publisher
 *
 * Finds draft articles whose date <= today and publishes them.
 * Run via cron — CLI only, not accessible from the browser.
 *
 * CRON SETUP (run once on the server):
 *   crontab -e
 *   Add this line (adjust path to match server):
 *   0 9 * * * php /path/to/site/admin/publish-scheduled.php >> /path/to/site/logs/publish-scheduled.log 2>&1
 *
 *   If the server is in UTC, use 0 6 * * * instead (06:00 UTC = 09:00 EEST / 07:00 EET).
 *   Check server timezone with: php -r "echo date_default_timezone_get();"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root          = dirname(__DIR__);
$articles_path = $root . '/content/articles';
$today         = date('Y-m-d');
$published     = 0;
$skipped       = 0;
$errors        = 0;

foreach (['bg', 'en'] as $lang) {
    $dir = $articles_path . '/' . $lang;
    if (!is_dir($dir)) continue;

    foreach (glob($dir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) { $errors++; continue; }

        if (($data['status'] ?? '') !== 'draft') { $skipped++; continue; }

        $date = $data['date'] ?? '';
        if (!$date || $date > $today) { $skipped++; continue; }

        $data['status'] = 'published';
        $result = file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($result === false) {
            log_line("ERROR writing $file");
            $errors++;
        } else {
            log_line("Published: $lang/" . basename($file) . " (date: $date)");
            $published++;
        }
    }
}

log_line("Done. published=$published skipped=$skipped errors=$errors");
exit($errors > 0 ? 1 : 0);

function log_line(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}
