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
