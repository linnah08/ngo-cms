<?php
/**
 * The built-in About page (/za-nas/, /en/about/) is built from blocks, like the
 * pages admins create: content/pages/p_000about.json (CPAGE_ABOUT_ID), edited
 * in Admin → Страници → За нас and on the page itself in Edit mode.
 *
 * Sites from before kept it in pages.json under "about" (title, intro and an
 * optional team list). The first time the page is asked for, it is seeded once
 * from there; after that, pages.json → about is no longer read.
 */
require_once __DIR__ . '/home.php';

/** The team members stored in pages.json → about, in their stored order. Empty when the page only tells the story. */
function about_team(array $about): array
{
    return array_filter(is_array($about['team'] ?? null) ? $about['team'] : [], 'is_array');
}

/**
 * The About page built from pages.json → about: its intro as a "Текст и снимка"
 * block without photos (so the site looks as before until photos are added),
 * then, when there is a team, a "Хора" block with every member. Nobody is
 * dropped: a bad photo path just leaves the person without a photo, and a
 * member without a Bulgarian name takes the English one, or "—".
 */
function about_seed_page(array $about): array
{
    $str = fn(array $a, string $k): string => is_string($a[$k] ?? null) ? $a[$k] : '';
    $pair = fn(string $bg, string $en): array => ['bg' => $bg, 'en' => $en];
    $section = function (string $type, array $in): array {
        [$fields] = home_validate_section($type, $in);   // cleaned; real data never loses a field to an error here
        return ['id' => home_new_id(), 'type' => $type, 'visible' => true, 'fields' => $fields];
    };

    $sections = [$section('text_image', [
        'heading'    => $pair('', ''),
        'body'       => $pair(home_clean_html($str($about, 'intro')), home_clean_html($str($about, 'intro_en'))),
        'photos'     => [],
        'image_side' => 'left',
        'background' => 'white',
    ])];

    $people = [];
    foreach (about_team($about) as $m) {
        $name_bg = trim($str($m, 'name'));
        $name_en = trim($str($m, 'name_en'));
        if ($name_bg === '') $name_bg = $name_en !== '' ? $name_en : '—';
        $photo = trim($str($m, 'photo'));
        $people[] = [
            'id'        => home_list_item_id(),
            'image'     => home_valid_image_path($photo) ? $photo : '',
            'focus'     => null,
            'image_alt' => $pair('', ''),   // empty: the name is read instead, as before
            'name'      => $pair($name_bg, $name_en),
            'role'      => $pair($str($m, 'role'), $str($m, 'role_en')),
            'bio'       => $pair(home_clean_html($str($m, 'bio')), home_clean_html($str($m, 'bio_en'))),
        ];
    }
    if ($people) {
        $sections[] = $section('people', [
            'heading'    => $pair('Защо го правим?', 'Why we do it'),
            'people'     => $people,
            'background' => 'grey',
        ]);
    }

    $now = date('c');
    return [
        'version'  => 1,
        'rev'      => 0,
        'id'       => CPAGE_ABOUT_ID,
        'status'   => 'published',
        'title_bg' => cpage_clean_title($str($about, 'title')) ?: 'Кои сме ние?',
        'title_en' => cpage_clean_title($str($about, 'title_en')) ?: 'Who are we?',
        'slug_bg'  => 'za-nas',
        'slug_en'  => 'about',
        'sections' => $sections,
        'created'  => $now,
        'updated'  => $now,
    ];
}

/** The About page, seeded from pages.json → about the first time (never over an existing file). */
function about_page(): array
{
    return cpage_seed_once(CPAGE_ABOUT_ID, function (): array {
        $pages = load_json(CONTENT_PATH . '/pages.json');
        return about_seed_page(is_array($pages['about'] ?? null) ? $pages['about'] : []);
    });
}

/** Does the page show a team (a visible "Хора" block)? For the search-engine description. */
function about_has_team(array $page): bool
{
    foreach ($page['sections'] as $s) {
        if (($s['type'] ?? '') === 'people' && !empty($s['visible'])) return true;
    }
    return false;
}
