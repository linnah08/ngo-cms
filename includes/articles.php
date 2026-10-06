<?php
/**
 * Pure, testable helpers for admin/article-edit.php.
 *
 * The editor page used to inline all of this between request handling and HTML,
 * which made the fiddly parts (slug derivation, save-record assembly) impossible
 * to unit-test. These functions take plain arrays in and return plain arrays out:
 * no $_POST, no I/O, no globals. The page stays a thin controller that wires them
 * to request data and file storage.
 */

require_once __DIR__ . '/../config.php'; // slug(), ascii_slug()

/**
 * Convert HTML to plain text, turning <strong>/<b> into Unicode bold sans-serif.
 * Used to pre-fill the social textareas from article content.
 */
function html_to_social_text(string $html): string {
    static $bold_map = null;
    if ($bold_map === null) {
        $bold_map = [];
        foreach (range('A', 'Z') as $c) $bold_map[$c] = mb_chr(0x1D5D4 + (ord($c) - ord('A')));
        foreach (range('a', 'z') as $c) $bold_map[$c] = mb_chr(0x1D5EE + (ord($c) - ord('a')));
        foreach (range('0', '9') as $c) $bold_map[$c] = mb_chr(0x1D7EC + (ord($c) - ord('0')));
    }
    $html = preg_replace_callback(
        '/<(?:strong|b)(?:\s[^>]*)?>(.+?)<\/(?:strong|b)>/is',
        function ($m) use ($bold_map) {
            $inner = strip_tags($m[1]);
            return strtr($inner, $bold_map);
        },
        $html
    );
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Derive the BG and EN slugs from the submitted slug/title fields.
 *
 * BG keeps the source language (slug() allows Cyrillic; only path-unsafe chars are
 * stripped). EN prefers an explicit override, then a slug of the EN title, then an
 * ASCII slug of the BG title, and finally falls back to the BG slug. Returns
 * ['bg' => string, 'en' => string].
 *
 * @param string $slug_in    Raw BG slug field (may be empty/dirty).
 * @param string $slug_en_in Raw EN slug field.
 * @param string $title      BG title (fallback source for BG slug).
 * @param string $title_en   EN title (fallback source for EN slug).
 * @param string $now_suffix Deterministic suffix for the empty-title fallback (testability).
 */
function article_compute_slugs(string $slug_in, string $slug_en_in, string $title, string $title_en, string $now_suffix = ''): array {
    $clean = fn(string $s): string => str_replace(['..', "\0", '/'], '', trim($s));

    $bg = slug($clean($slug_in))
        ?: slug($title)
        ?: 'article-' . ($now_suffix !== '' ? $now_suffix : date('YmdHis'));

    $slug_en_input = slug($clean($slug_en_in));
    $en = $slug_en_input !== ''
        ? $slug_en_input
        : (($title_en !== '') ? slug($title_en) : ascii_slug($title));
    if (!$en) $en = $bg;

    return ['bg' => $bg, 'en' => $en];
}

/**
 * Assemble the BG article record from cleaned fields, preserving the social /
 * scheduling metadata from the existing record.
 *
 * @param array $f        Cleaned fields: title, slug, slug_en, date, author,
 *                        status, excerpt, image, tags, content.
 * @param array $existing The currently-stored article (for metadata carry-over).
 */
function article_build_bg_data(array $f, array $existing = []): array {
    $carry = [
        'linkedin_text', 'linkedin_url', 'linkedin_posted_at', 'linkedin_scheduled_at',
        'linkedin_due_at', 'buffer_post_id', 'social_text', 'fb_text', 'insta_text',
        'fb_buffer_post_id', 'fb_scheduled_at', 'fb_due_at', 'insta_buffer_post_id',
        'insta_scheduled_at', 'insta_due_at',
        'insta_story_buffer_post_id',
        'insta_story_scheduled_at', 'insta_story_due_at',
    ];
    $data = [
        'title'   => $f['title'],
        'slug'    => $f['slug'],
        'slug_en' => $f['slug_en'],
        'date'    => $f['date'],
        'author'  => $f['author'],
        'status'  => $f['status'],
        'excerpt' => $f['excerpt'],
        'image'   => $f['image'],
        'photos'  => $f['photos'] ?? [],
        'tags'    => $f['tags'],
        'content' => $f['content'],
        'scheduled' => article_scheduled_flag($f),
    ];
    foreach ($carry as $k) {
        $data[$k] = $existing[$k] ?? '';
    }
    // The author's Instagram crops (admin/social-ajax.php save_crop), kept for the photos
    // the post still has — a removed or replaced photo simply loses its crop.
    $srcs  = array_merge(array_column($data['photos'], 'src'), [$data['image']]);
    $crops = [];
    foreach (['post', 'story'] as $kind) {
        $set = $existing['insta_crops'][$kind] ?? null;
        if (!is_array($set)) continue;
        foreach ($set as $src => $rect) {
            if (is_array($rect) && in_array((string) $src, $srcs, true)) $crops[$kind][(string) $src] = $rect;
        }
    }
    if ($crops) $data['insta_crops'] = $crops;
    return $data;
}

/**
 * Fallback excerpt when AI generation is unavailable or fails: strip HTML,
 * collapse whitespace, and truncate to a word boundary. Used by both
 * admin/article-edit.php (article_auto_excerpt) and the backfill script.
 */
function article_excerpt_from_content(string $html, int $max_len = 160): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '' || mb_strlen($text) <= $max_len) return $text;
    $cut = mb_substr($text, 0, $max_len);
    $cut = preg_replace('/\s+\S*$/u', '', $cut);
    return rtrim($cut, " \t\n\r\0\x0B.,;:") . '…';
}

/**
 * Assemble the EN article record. Date, author, image, status and tags are shared
 * with the BG record by the caller; only the EN title/excerpt/content differ.
 */
function article_build_en_data(array $f): array {
    return [
        'title'   => $f['title'],
        'slug'    => $f['slug'],
        'date'    => $f['date'],
        'author'  => $f['author'],
        'status'  => $f['status'],
        'excerpt' => $f['excerpt'],
        'image'   => $f['image'],
        'photos'  => $f['photos'] ?? [],
        'tags'    => $f['tags'],
        'content' => $f['content'],
        'scheduled' => article_scheduled_flag($f),
    ];
}

/** The stored `scheduled` flag: only a draft can be scheduled. */
function article_scheduled_flag(array $f): bool {
    return ($f['status'] ?? '') === 'draft' && !empty($f['scheduled']);
}

/**
 * Whether a draft is set to publish itself on its date (admin/publish-scheduled.php).
 *
 * Articles saved with the „Публикувай автоматично на тази дата“ checkbox carry an
 * explicit `scheduled` flag. Older drafts don't: for those, the editor used to
 * fill the date with the day of saving, so only a date later than the file's
 * last save ($mtime) can have been chosen on purpose. Anything else is an
 * ordinary draft and is never published automatically.
 */
function article_is_scheduled(array $a, ?int $mtime = null): bool {
    if (($a['status'] ?? '') !== 'draft' || empty($a['date']) || !is_string($a['date'])) return false;
    if (array_key_exists('scheduled', $a)) return $a['scheduled'] === true;
    return $mtime !== null && $a['date'] > date('Y-m-d', $mtime);
}

/** A scheduled draft whose date has arrived. */
function article_due_for_publish(array $a, ?int $mtime, string $today): bool {
    return article_is_scheduled($a, $mtime) && $a['date'] <= $today;
}

/** Most photos a post can have — Instagram's carousel limit, so nothing is dropped on the way there. */
const ARTICLE_PHOTOS_MAX = 10;

/**
 * Is $src a site image a post may use? Under /assets/images/, no "..", no scheme,
 * and present on disk ($exists is injectable for tests).
 */
function article_photo_path_ok(string $src, ?callable $exists = null): bool {
    if (!preg_match('#^/assets/images/[a-zA-Z0-9_\-][a-zA-Z0-9/_.\-]*$#', $src)) return false;
    if (str_contains($src, '..')) return false;
    $exists ??= static fn(string $p): bool => is_file($_SERVER['DOCUMENT_ROOT'] . $p);
    return $exists($src);
}

/**
 * The post's photos in display order: [['src' => ..., 'caption' => ...], ...].
 * A post saved before photos existed has only `image` — it reads as one photo.
 * Invalid or missing files are skipped; at most ARTICLE_PHOTOS_MAX are returned.
 */
function article_photos(array $article, ?callable $exists = null): array {
    $raw = $article['photos'] ?? null;
    if (!is_array($raw)) {
        $img = is_string($article['image'] ?? null) ? $article['image'] : '';
        $raw = $img !== '' ? [['src' => $img, 'caption' => '']] : [];
    }
    $out = [];
    foreach ($raw as $p) {
        if (!is_array($p) || !is_string($p['src'] ?? null)) continue;
        if (!article_photo_path_ok($p['src'], $exists)) continue;
        $out[] = ['src' => $p['src'], 'caption' => is_string($p['caption'] ?? null) ? $p['caption'] : ''];
        if (count($out) === ARTICLE_PHOTOS_MAX) break;
    }
    return $out;
}

/** Index in $photos of the post's main photo (its `image`), or 0 when it isn't there. */
function article_main_photo_index(array $article, array $photos): int {
    $main = $article['image'] ?? '';
    foreach ($photos as $i => $p) {
        if ($p['src'] === $main) return $i;
    }
    return 0;
}

/** Longest caption kept — enough for a sentence, short enough for a slide. */
const ARTICLE_PHOTO_CAPTION_MAX = 300;

/**
 * Rebuild a post's photos from the editor's grid. Paths are re-checked here — the
 * browser only ever sends paths, never trusted ones. A dropped path drops its captions
 * with it, so captions never slide onto the wrong photo. $keep lists the post's stored
 * photos: those survive a missing file, new ones must exist on disk.
 *
 * @return array{bg: array, en: array, image: string}
 */
function article_photos_from_post(array $post, ?callable $exists = null, array $keep = []): array {
    $src  = is_array($post['photo_src'] ?? null) ? array_values($post['photo_src']) : [];
    $capB = is_array($post['photo_caption_bg'] ?? null) ? array_values($post['photo_caption_bg']) : [];
    $capE = is_array($post['photo_caption_en'] ?? null) ? array_values($post['photo_caption_en']) : [];
    $main = filter_var($post['photo_main'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $clean = static fn($c): string => is_string($c) ? mb_substr(trim($c), 0, ARTICLE_PHOTO_CAPTION_MAX) : '';

    $bg = $en = [];
    $image = '';
    foreach ($src as $i => $s) {
        if (!is_string($s)) continue;
        // A photo already on the post stays even if its file is missing here (a restore,
        // a local checkout without uploads) — dropping it is the author's call, not ours.
        $kept = in_array($s, $keep, true) && article_photo_path_ok($s, static fn(): bool => true);
        if (!$kept && !article_photo_path_ok($s, $exists)) continue;
        if (count($bg) === ARTICLE_PHOTOS_MAX) break;
        $bg[] = ['src' => $s, 'caption' => $clean($capB[$i] ?? '')];
        $en[] = ['src' => $s, 'caption' => $clean($capE[$i] ?? '')];
        if ($main === $i) $image = $s;
    }
    if ($image === '' && $bg) $image = $bg[0]['src'];
    return ['bg' => $bg, 'en' => $en, 'image' => $image];
}

/** The EN copy of a photo list: same photos, captions run through $translate (BG kept on failure). */
function article_photos_translated(array $photos, callable $translate): array {
    $out = [];
    foreach ($photos as $p) {
        $cap = $p['caption'];
        if ($cap !== '') {
            $t = $translate($cap);
            if (is_string($t) && $t !== '') $cap = $t;
        }
        $out[] = ['src' => $p['src'], 'caption' => $cap];
    }
    return $out;
}

/**
 * $article with its main photo swapped for $new_src (the public page's "📷 Replace").
 * The replaced photo keeps its place and caption; '' removes it and promotes the next.
 */
function article_with_main_photo(array $article, string $new_src, ?callable $exists = null): array {
    $photos = article_photos($article, $exists);
    $i = article_main_photo_index($article, $photos);
    if ($new_src === '') {
        if ($photos) array_splice($photos, $i, 1);
    } elseif ($photos) {
        $photos[$i]['src'] = $new_src;
    } else {
        $photos = [['src' => $new_src, 'caption' => '']];
    }
    $article['photos'] = $photos;
    $article['image']  = $new_src !== '' ? $new_src : ($photos[0]['src'] ?? '');
    return $article;
}

/** The editor's list: every stored photo with a valid path, whether or not its file exists here. */
function article_photos_for_editor(array $article): array {
    return article_photos($article, static fn(): bool => true);
}

/**
 * Save the public page's inline edits (title, excerpt, content, image) into one post file.
 * An image goes through article_with_main_photo(), so "📷 Replace" swaps the main photo
 * inside `photos` too; an invalid image path is ignored. False when the file can't be
 * read or written.
 */
function article_inline_save(string $full, string $lang, array $fields, ?callable $exists = null): bool {
    if (!is_file($full)) return false;
    $article = load_json($full);
    foreach (['title', 'excerpt', 'content', 'image'] as $f) {
        if (!isset($fields[$f])) continue;
        $val = $fields[$f][$lang] ?? '';
        if (!is_string($val)) continue;
        if ($f === 'image') {
            $val = trim(strip_tags($val));
            if ($val === '' || article_photo_path_ok($val, $exists)) $article = article_with_main_photo($article, $val, $exists);
            continue;
        }
        $article[$f] = ($f === 'content') ? $val : trim(strip_tags($val));
    }
    return save_json($full, $article) !== false;
}
