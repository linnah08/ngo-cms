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
        'tags'    => $f['tags'],
        'content' => $f['content'],
    ];
    foreach ($carry as $k) {
        $data[$k] = $existing[$k] ?? '';
    }
    return $data;
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
        'tags'    => $f['tags'],
        'content' => $f['content'],
    ];
}
