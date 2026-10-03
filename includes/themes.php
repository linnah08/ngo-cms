<?php
/**
 * Visual theme presets. Each is a bundle of design-system variables (colours,
 * font, corner radius) that the CSS already consumes (--teal, --font-body,
 * --radius, …). The install wizard lets the NGO pick one; the colour pickers
 * can still override the preset's default colours.
 *
 * A site adds its own theme(s) in includes/themes-site.php — a file this project
 * never ships, so a release can neither overwrite nor remove it (see site_themes()).
 *
 * Optional keys, all of which fall back to the original behaviour when absent:
 *
 *   font_display / font_body  separate faces for headings and body text.
 *                             Without them both roles use 'font', as before.
 *   font_css                  a stylesheet of @font-face rules served from this
 *                             domain (a path from the site root). When set, no
 *                             request is made to Google's font CDN at all —
 *                             which is what a site has to do if it must not send
 *                             visitor IPs to a third party before consent.
 *                             'font_url' is then ignored.
 *   theme_css                 a stylesheet loaded after main.css (a path from the
 *                             site root), for the parts of a brand that token
 *                             overrides cannot express.
 *   palette                   extra CSS custom properties ['--name' => 'value'],
 *                             emitted in the <head> after the built-in ones. Use
 *                             it to override the design system's own tokens
 *                             (--text, --border, …), the themeable colours of the
 *                             shared templates (--newsletter-bg / --newsletter-fg,
 *                             --banner-accent / --banner-accent-fg, --required-ink),
 *                             and to add brand tokens the theme's CSS consumes.
 *   home_types                a PHP file of the site's own front-page sections
 *                             (see home_site_types() in includes/home.php).
 *   home_start_hidden         built-in front-page sections that start switched off.
 */
function brand_themes(): array {
    return [
        'classic' => [
            'label'     => 'Classic',
            'primary'   => '#0387A5', 'accent' => '#04ADBF',
            'font'      => 'Jura',  'font_url' => 'Jura:wght@300;400;500;600',
            'radius'    => '4px',   'radius_lg' => '8px',
        ],
        'friendly' => [
            'label'     => 'Friendly',
            'primary'   => '#E0683C', 'accent' => '#F2A65A',
            'font'      => 'Nunito', 'font_url' => 'Nunito:wght@400;600;700;800',
            'radius'    => '14px',  'radius_lg' => '22px',
        ],
        'modern' => [
            'label'     => 'Modern',
            'primary'   => '#2D3A8C', 'accent' => '#5B6FD6',
            'font'      => 'Inter',  'font_url' => 'Inter:wght@400;500;600;700',
            'radius'    => '2px',   'radius_lg' => '4px',
        ],
        'editorial' => [
            'label'     => 'Editorial',
            'primary'   => '#1F6F54', 'accent' => '#C9A24B',
            'font'      => 'Lora',   'font_url' => 'Lora:wght@400;500;600;700',
            'radius'    => '6px',   'radius_lg' => '12px',
        ],
    ] + site_themes();
}

/** Where a site keeps its own themes. Site-owned: never in a release (tests point it elsewhere). */
function site_themes_file(): string {
    return (string) ($GLOBALS['_om_site_themes_file'] ?? dirname(__DIR__) . '/includes/themes-site.php');
}

/**
 * The site's own theme presets, from site_themes_file() when it exists. The file
 * returns [key => preset], each shaped like a built-in one above (label, primary,
 * accent, font, font_url, radius, radius_lg, plus any optional key). A site theme
 * cannot replace a built-in one, and an entry missing a required key is skipped —
 * a mistake in that file must never take the site's pages down.
 */
function site_themes(): array {
    static $cache = [];
    $file = site_themes_file();
    if (array_key_exists($file, $cache)) return $cache[$file];
    if ($file === '' || !is_file($file)) return $cache[$file] = [];

    $extra = (static fn(string $f) => require $f)($file);
    if (!is_array($extra)) {
        error_log('site_themes: ' . $file . ' does not return an array — its themes are not available');
        return $cache[$file] = [];
    }
    $builtin = ['classic', 'friendly', 'modern', 'editorial'];
    $out = [];
    foreach ($extra as $key => $def) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $key) || in_array($key, $builtin, true)) continue;
        if (!is_array($def)) continue;
        foreach (['primary', 'accent', 'font', 'radius', 'radius_lg'] as $req) {
            if (!is_string($def[$req] ?? null) || $def[$req] === '') {
                error_log('site_themes: theme "' . $key . '" has no ' . $req . ' — skipped');
                continue 2;
            }
        }
        $out[$key] = $def + ['label' => $key, 'font_url' => ''];
    }
    return $cache[$file] = $out;
}

/** The active theme (BRAND_THEME from site.config), falling back to 'classic'. */
function current_theme(): array {
    $themes = brand_themes();
    $key = (defined('BRAND_THEME') && isset($themes[BRAND_THEME])) ? BRAND_THEME : 'classic';
    return $themes[$key];
}

/**
 * The <link> tags for the theme's fonts, main.css and the theme's own stylesheet,
 * in that order. Local files carry a version stamp (versioned_asset()), so a
 * release's new main.css is not hidden behind a stale cached copy.
 */
function theme_stylesheet_links(array $theme): string {
    $link = fn(string $rel): string => '  <link rel="stylesheet" href="' . h(versioned_asset($rel)) . "\">\n";
    $out  = '';
    if (!empty($theme['font_css'])) {
        // Self-hosted: no request leaves this domain, so no visitor IP reaches a
        // font CDN before the cookie banner has been answered.
        $out .= $link((string) $theme['font_css']);
    } elseif (!empty($theme['font_url'])) {
        $out .= "  <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
              . "  <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
              . '  <link href="https://fonts.googleapis.com/css2?family=' . h((string) $theme['font_url']) . "&display=swap\" rel=\"stylesheet\">\n";
    }
    $out .= $link('assets/css/main.css');
    if (!empty($theme['theme_css'])) $out .= $link((string) $theme['theme_css']);
    return $out;
}

/**
 * The theme's palette as CSS declarations for the :root block in the <head>.
 * Emitted inside a <style> element, so only a property-shaped name and a
 * CSS-value-shaped value pass — no quotes, braces, semicolons or angle brackets.
 */
function theme_palette_css(array $theme): string {
    $out = '';
    foreach ((array) ($theme['palette'] ?? []) as $var => $val) {
        $val = is_scalar($val) ? trim((string) $val) : '';
        if (!is_string($var) || !preg_match('/^--[a-z0-9-]+$/i', $var)
            || $val === '' || !preg_match('/^[#a-zA-Z0-9 ,.()%\/_-]+$/', $val)) continue;
        $out .= '    ' . $var . ':' . $val . ";\n";
    }
    return $out;
}
