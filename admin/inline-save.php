<?php
// admin/inline-save.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings.php';

// ── CORS: allow credentialed requests from subdomains of this site ────────────
$_origin    = $_SERVER['HTTP_ORIGIN'] ?? '';
$_site_host = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
$_site_origin = rtrim(SITE_URL, '/');
if ($_origin !== '') {
    $is_subdomain = (bool) preg_match(
        '/^https?:\/\/[a-z0-9\-]+\.' . preg_quote($_site_host, '/') . '(:\d+)?$/',
        $_origin
    );
    if ($_origin === $_site_origin || $is_subdomain) {
        header('Access-Control-Allow-Origin: ' . $_origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

function save_json_response(array $data): never { echo json_encode($data); exit; }

// Auth: session (main site) or signed admin-bar token (subdomain visitors)
$_session_auth = admin_logged_in();
$_token_auth   = !$_session_auth && admin_bar_token_verify();
if (!$_session_auth && !$_token_auth) {
    save_json_response(['ok' => false, 'error' => 'unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') save_json_response(['ok' => false, 'error' => 'method']);

// Support both raw JSON body (AJAX) and $GLOBALS override (tests)
$raw   = $GLOBALS['_om_raw_input'] ?? file_get_contents('php://input');
$body  = json_decode($raw, true) ?? [];

// CSRF is only required for session auth; token auth is CSRF-safe via SameSite=Lax + CORS allowlist
if ($_session_auth && !hash_equals(csrf_token(), $body['csrf_token'] ?? '')) {
    save_json_response(['ok' => false, 'error' => 'csrf']);
}

$section = $body['section'] ?? '';
$fields  = $body['fields']  ?? [];
if (!is_array($fields) || empty($section)) {
    save_json_response(['ok' => false, 'error' => 'invalid input']);
}

// Handle menus section separately (before the allowed-sections whitelist)
if ($section === 'menus') {
    $menus = load_json(CONTENT_PATH . '/menus.json');
    foreach ($fields as $menu_key => $items) {
        if (!in_array($menu_key, ['header', 'footer_nav', 'footer_help'], true)) continue;
        foreach (['bg', 'en'] as $lang) {
            if (!isset($items[$lang]) || !is_array($items[$lang])) continue;
            foreach ($items[$lang] as $idx => $item) {
                if (isset($menus[$menu_key][$lang][$idx])) {
                    $menus[$menu_key][$lang][$idx]['label'] = trim(strip_tags($item['label'] ?? ''));
                }
            }
        }
    }
    save_json(CONTENT_PATH . '/menus.json', $menus);
    save_json_response(['ok' => true]);
}

// Handle array-indexed sections (centre, partner) before the field whitelist
// Campaign UI strings (nav tabs, eyebrows, panel h2s) stored as JSON blob
if ($section === 'lf_ui') {
    $allowed_lf_keys = [
        'tab_idea', 'tab_faq', 'tab_budget', 'tab_rewards', 'tab_series', 'tab_event',
        'eyebrow_idea', 'eyebrow_faq', 'eyebrow_budget', 'eyebrow_rewards', 'eyebrow_series',
        'h2_faq', 'h2_budget',
    ];
    $lf_ui = json_decode(setting_get('lf_ui', '{}'), true) ?: [];
    foreach ($fields as $field => $values) {
        if (!in_array($field, $allowed_lf_keys, true)) continue;
        $lf_ui[$field]        = trim(strip_tags($values['bg'] ?? ''));
        $lf_ui[$field . '_en'] = trim(strip_tags($values['en'] ?? ''));
    }
    setting_set('lf_ui', json_encode($lf_ui, JSON_UNESCAPED_UNICODE));
    save_json_response(['ok' => true]);
}

if ($section === 'centre') {
    $centres = load_json(CENTRES_FILE);
    foreach ($fields as $field => $values) {
        if (!preg_match('/^image_(\d+)$/', $field, $m)) continue;
        $idx  = (int)$m[1];
        $path = trim(strip_tags($values['bg'] ?? ''));
        if ($path !== '' && isset($centres[$idx])) {
            $centres[$idx]['image'] = $path;
        }
    }
    save_json(CENTRES_FILE, $centres);
    save_json_response(['ok' => true]);
}

if ($section === 'partner') {
    $partners = load_json(PARTNERS_FILE);
    foreach ($fields as $field => $values) {
        if (!preg_match('/^logo_(\d+)$/', $field, $m)) continue;
        $idx  = (int)$m[1];
        $path = trim(strip_tags($values['bg'] ?? ''));
        if ($path !== '' && isset($partners[$idx])) {
            $partners[$idx]['logo'] = $path;
        }
    }
    save_json(PARTNERS_FILE, $partners);
    save_json_response(['ok' => true]);
}

// Campaign FAQ — stored in the DB `campaign_faq` setting as a JSON array of
// {q, a, q_en, a_en}. Fields arrive indexed: q_<idx> (plain text) and a_<idx>
// (richtext answer). Untouched language/field values are preserved.
if ($section === 'faq') {
    $faq = json_decode(setting_get('campaign_faq', '[]'), true) ?: [];
    $safe_tags = '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>';
    foreach ($fields as $field => $values) {
        if (!preg_match('/^([qa])_(\d+)$/', $field, $m)) continue;
        $which = $m[1];           // 'q' or 'a'
        $idx   = (int)$m[2];
        if (!isset($faq[$idx])) continue;
        if ($which === 'q') {
            $faq[$idx]['q']    = trim(strip_tags($values['bg'] ?? ''));
            $faq[$idx]['q_en'] = trim(strip_tags($values['en'] ?? ''));
        } else {
            $faq[$idx]['a']    = trim(strip_tags($values['bg'] ?? '', $safe_tags));
            $faq[$idx]['a_en'] = trim(strip_tags($values['en'] ?? '', $safe_tags));
        }
    }
    setting_set('campaign_faq', json_encode($faq, JSON_UNESCAPED_UNICODE));
    save_json_response(['ok' => true]);
}

// Campaign settings — stored in the DB settings table, not pages.json
if ($section === 'campaign_settings') {
    $allowed_settings = [
        'title'       => ['campaign_title',       'campaign_title_en'],
        'description' => ['campaign_description', 'campaign_description_en'],
    ];
    $safe_tags = '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>';
    foreach ($fields as $field => $values) {
        if (!isset($allowed_settings[$field])) continue;
        [$bg_key, $en_key] = $allowed_settings[$field];
        $bg_val = $field === 'description'
            ? trim(strip_tags($values['bg'] ?? '', $safe_tags))
            : trim(strip_tags($values['bg'] ?? ''));
        $en_val = $field === 'description'
            ? trim(strip_tags($values['en'] ?? '', $safe_tags))
            : trim(strip_tags($values['en'] ?? ''));
        setting_set($bg_key, $bg_val);
        setting_set($en_key, $en_val);
    }
    save_json_response(['ok' => true]);
}

// Allowed field map: section → [field_name => [bg_key, en_key]]
$allowed = [
    'home' => [
        'hero_title'           => ['hero_title',           'hero_title_en'],
        'hero_text'            => ['hero_text',            'hero_text_en'],
        'hero_cta_primary'     => ['hero_cta_primary',     'hero_cta_primary_en'],
        'hero_cta_secondary'   => ['hero_cta_secondary',   'hero_cta_secondary_en'],
        'hero_image'           => ['hero_image',           'hero_image'],
        'mission_title'        => ['mission_title',        'mission_title_en'],
        'mission_text'         => ['mission_text',         'mission_text_en'],
        'mission_cta_primary'  => ['mission_cta_primary',  'mission_cta_primary_en'],
        'mission_cta_secondary'=> ['mission_cta_secondary','mission_cta_secondary_en'],
        'mission_image'        => ['mission_image',        'mission_image'],
        'section_impact'       => ['section_impact',       'section_impact_en'],
        'section_centres'      => ['section_centres',      'section_centres_en'],
        'section_news'         => ['section_news',         'section_news_en'],
        'section_partners'     => ['section_partners',     'section_partners_en'],
        'section_mission'      => ['section_mission',      'section_mission_en'],
        'news_btn_all'         => ['news_btn_all',         'news_btn_all_en'],
        'cta_heading'          => ['cta_heading',          'cta_heading_en'],
        'cta_body'             => ['cta_body',             'cta_body_en'],
        'cta_btn_donate'       => ['cta_btn_donate',       'cta_btn_donate_en'],
        'cta_btn_help'         => ['cta_btn_help',         'cta_btn_help_en'],
    ],
    'campaign' => [
        'title' => ['title', 'title_en'],
        'text'  => ['text',  'text_en'],
        'cta'   => ['cta',   'cta_en'],
        'image' => ['image', 'image'],
    ],
    'shop' => [
        'donation_text' => ['donation_text_bg', 'donation_text_en'],
    ],
    'footer' => [
        'tagline'      => ['tagline',      'tagline_en'],
        'social_fb'    => ['social_fb',    'social_fb'],
        'social_ig'    => ['social_ig',    'social_ig'],
    ],
];

if (!isset($allowed[$section])) {
    save_json_response(['ok' => false, 'error' => 'unknown section']);
}

$pages = load_json(CONTENT_PATH . '/pages.json');
if (!isset($pages[$section])) $pages[$section] = [];

foreach ($fields as $field => $values) {
    if (!isset($allowed[$section][$field])) continue; // skip unknown fields
    [$bg_key, $en_key] = $allowed[$section][$field];
    $pages[$section][$bg_key] = trim(strip_tags($values['bg'] ?? '', '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>'));
    $pages[$section][$en_key] = trim(strip_tags($values['en'] ?? '', '<b><strong><em><i><a><ul><ol><li><br><p><h2><h3>'));
}

save_json(CONTENT_PATH . '/pages.json', $pages);
save_json_response(['ok' => true]);
