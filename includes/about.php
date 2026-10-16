<?php
/**
 * The built-in About page (/za-nas/, /en/about/): its content lives in pages.json
 * under "about" — title, intro and an optional team list — edited in Admin →
 * Страници → За нас or on the page itself in Edit mode (admin/inline-save.php).
 */

/** The team members to show, keyed as stored (Edit mode saves a bio by that key). Empty when the page only tells the story. */
function about_team(array $about): array
{
    return array_filter(is_array($about['team'] ?? null) ? $about['team'] : [], 'is_array');
}

/** Edit-mode attributes for one field; the stored BG/EN values ride along only for an admin, so saving one language never blanks the other. */
function about_cms_attrs(string $field, mixed $bg, mixed $en, string $type = 'text'): string
{
    $out = ' data-cms-section="about" data-cms-field="' . h($field) . '" data-cms-type="' . h($type) . '"';
    if ($GLOBALS['_show_admin_bar'] ?? false) {
        $out .= ' data-cms-bg="' . h(is_string($bg) ? $bg : '') . '" data-cms-en="' . h(is_string($en) ? $en : '') . '"';
    }
    return $out;
}

/**
 * Save Edit-mode changes to the About page: title (plain text), intro and bio_<key>
 * (formatted text, cleaned like every other page's). Unknown fields and bios of
 * members that no longer exist are ignored. Returns the new "about" entry.
 */
function about_inline_apply(array $about, array $fields): array
{
    require_once __DIR__ . '/home.php';
    $team = is_array($about['team'] ?? null) ? $about['team'] : [];
    foreach ($fields as $field => $values) {
        if (!is_array($values)) continue;
        $bg = is_string($values['bg'] ?? null) ? $values['bg'] : '';
        $en = is_string($values['en'] ?? null) ? $values['en'] : '';
        if ($field === 'title') {
            $about['title']    = trim(home_plain_text($bg));
            $about['title_en'] = trim(home_plain_text($en));
        } elseif ($field === 'intro') {
            $about['intro']    = home_clean_html($bg);
            $about['intro_en'] = home_clean_html($en);
        } elseif (is_string($field) && preg_match('/^bio_(\d+)$/', $field, $m) && is_array($team[(int) $m[1]] ?? null)) {
            $team[(int) $m[1]]['bio']    = home_clean_html($bg);
            $team[(int) $m[1]]['bio_en'] = home_clean_html($en);
        }
    }
    if ($team) $about['team'] = $team;
    return $about;
}
