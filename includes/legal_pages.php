<?php
/**
 * The English text of a legal page as saved in Admin → Съдържание, or '' when
 * none has been written yet.
 *
 * The EN pages show it when it exists and fall back to their built-in text
 * when it doesn't — so a new site still gets a complete page, and once an admin
 * translates the page (with the organisation's real legal name, protected by
 * the translator's glossary), that is what visitors read. Before this, the
 * admin's English for these three pages was saved but never shown.
 *
 * @param string     $key   BG key in pages.json['legal']: 'privacy', 'legal_info' or 'terms'
 * @param array|null $pages pages.json contents; null reads the file
 */
function legal_en_content(string $key, ?array $pages = null): string
{
    $pages ??= load_json(CONTENT_PATH . '/pages.json');
    $html = $pages['legal'][$key . '_en'] ?? '';
    if (!is_string($html)) return '';

    // An editor emptied by hand still saves "<p>&nbsp;</p>": that is not a page.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($text, " \t\n\r\0\x0B\u{00A0}") === '' ? '' : $html;
}

/**
 * A site constant for the built-in fallback text, or '' when the site hasn't
 * set it (older site.config.php files predate some of them).
 */
function legal_const(string $name): string
{
    return defined($name) ? trim((string) constant($name)) : '';
}

/** Who the legal pages speak for: the registered legal name, else the site name. */
function legal_en_org_name(): string
{
    return legal_const('SITE_LEGAL_NAME_EN') !== '' ? legal_const('SITE_LEGAL_NAME_EN') : legal_const('SITE_NAME_EN');
}

/**
 * Address, company number and representative for the built-in text, as HTML
 * lines ending in <br>, from Админ → Организация (only what is filled in).
 */
function legal_en_identity_lines(bool $with_representative = false): string
{
    $lines = '';
    if (legal_const('SITE_ADDRESS') !== '') $lines .= 'Address: ' . h(legal_const('SITE_ADDRESS')) . "<br>\n";
    if (legal_const('SITE_EIK') !== '')     $lines .= 'UIC: ' . h(legal_const('SITE_EIK')) . "<br>\n";
    if ($with_representative && legal_const('SITE_MOL') !== '') $lines .= 'Representative: ' . h(legal_const('SITE_MOL')) . "<br>\n";
    return $lines;
}

/** Where a legal page's text is kept in pages.json['legal']: [BG key, EN key]. '' keys for an unknown page. */
function legal_storage_keys(string $key): array
{
    if (!in_array($key, ['privacy', 'legal_info', 'terms', 'cookies'], true)) return ['', ''];
    // The cookie policy pages read cookie_policy_bg / cookie_policy_en.
    return $key === 'cookies' ? ['cookie_policy_bg', 'cookie_policy_en'] : [$key, $key . '_en'];
}

/**
 * Attributes that make a legal page's body a rich text block in Edit mode
 * (section "legal" of admin/inline-save.php, the same text Admin → Съдържание edits).
 */
function legal_body_attrs(string $key, ?array $pages = null): string
{
    [$kbg, $ken] = legal_storage_keys($key);
    $out = ' data-cms-section="legal" data-cms-field="' . h($key) . '" data-cms-type="richtext"';
    if ($kbg !== '' && ($GLOBALS['_show_admin_bar'] ?? false)) {
        $pages ??= load_json(CONTENT_PATH . '/pages.json');
        $out .= ' data-cms-bg="' . h((string) ($pages['legal'][$kbg] ?? '')) . '" data-cms-en="' . h((string) ($pages['legal'][$ken] ?? '')) . '"';
    }
    return $out;
}

/**
 * Save a legal page's text edited on the page: [key => ['bg' => html, 'en' => html]].
 * A language the editor did not send keeps its text.
 *
 * @return array{ok: bool, error?: string}
 */
function legal_inline_save(array $fields): array
{
    require_once ROOT_PATH . '/includes/home.php';   // home_clean_html()
    $pages = load_json(CONTENT_PATH . '/pages.json');
    if (!isset($pages['legal']) || !is_array($pages['legal'])) $pages['legal'] = [];
    foreach ($fields as $key => $values) {
        [$kbg, $ken] = legal_storage_keys((string) $key);
        if ($kbg === '' || !is_array($values)) return ['ok' => false, 'error' => 'unknown section'];
        foreach (['bg' => $kbg, 'en' => $ken] as $l => $k) {
            if (!array_key_exists($l, $values)) continue;
            $pages['legal'][$k] = home_clean_html(is_string($values[$l]) ? $values[$l] : '');
        }
    }
    if (!save_json(CONTENT_PATH . '/pages.json', $pages)) return ['ok' => false, 'error' => 'Текстът не се запази. Опитайте отново.'];
    return ['ok' => true];
}
