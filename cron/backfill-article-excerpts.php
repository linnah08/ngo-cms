<?php
/**
 * Generate excerpts for BG/EN posts that don't have one — for posts written
 * before excerpts were filled in on save.
 *
 * Reuses article_auto_excerpt() (includes/ai_excerpt.php) — the same
 * Claude-then-truncation logic admin/article-edit.php now runs automatically
 * whenever an article is saved with a blank excerpt. Re-running this script
 * after that code has been live for a while is a no-op: it only ever touches
 * files whose excerpt is still empty.
 *
 * Run:  php cron/backfill-article-excerpts.php --dry     (preview, default-safe)
 *       php cron/backfill-article-excerpts.php --apply   (write changes)
 */

// Never runnable over HTTP — reads the Claude API key from settings and
// writes content files. CLI only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// config.php derives its paths from DOCUMENT_ROOT, which isn't set under CLI.
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_excerpt.php';

// „Помощ от изкуствен интелект“ switched off in Admin → Модули: nothing to do.
if (!module_enabled_with_needs('ai_helpers')) {
    echo "The AI helpers module is off (Admin → Модули) — nothing done.\n";
    exit(0);
}

$apply   = in_array('--apply', $argv, true);
$done    = 0;
$skipped = 0;

foreach (['bg', 'en'] as $lang) {
    $dir = ARTICLES_PATH . '/' . $lang;
    foreach (glob($dir . '/*.json') as $file) {
        $article = load_json($file);
        if (!empty($article['excerpt'])) {
            $skipped++;
            continue;
        }

        $title   = $article['title']   ?? '';
        $content = $article['content'] ?? '';
        if (trim(strip_tags($content)) === '') {
            echo "SKIP  [$lang] " . basename($file) . "  (no content to summarize)\n";
            $skipped++;
            continue;
        }

        $excerpt = article_auto_excerpt($title, $content, $lang);
        echo ($apply ? "SET   " : "DRY   ") . "[$lang] " . basename($file) . ": $excerpt\n";

        if ($apply) {
            $article['excerpt'] = $excerpt;
            save_json($file, $article);
        }
        $done++;
    }
}

echo "\n" . ($apply ? "" : "[dry-run] ") . "generated: $done   skipped: $skipped\n";
