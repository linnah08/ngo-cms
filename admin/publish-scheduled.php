<?php
/**
 * Scheduled article publisher
 *
 * Publishes draft articles that were scheduled („Публикувай автоматично на тази
 * дата“) once their date has arrived — see article_is_scheduled(). Ordinary
 * drafts are never touched. Run via cron — CLI only, not accessible from the
 * browser.
 *
 * Schedule + exact command: Admin → Автоматични задачи (includes/scheduled_jobs.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root          = dirname(__DIR__);
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $root;
}
require_once $root . '/includes/articles.php';
require_once $root . '/includes/scheduled_jobs.php';
scheduled_job_track('publish_articles');

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

        if (!article_due_for_publish($data, @filemtime($file) ?: null, $today)) { $skipped++; continue; }

        $date = $data['date'];
        $data['status']    = 'published';
        $data['scheduled'] = false;
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
