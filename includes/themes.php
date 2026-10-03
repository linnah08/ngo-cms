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

/** A local asset URL with a version stamp. Standalone (no config.php), for the error pages too. */
function theme_asset_url(string $rel): string {
    $rel = '/' . ltrim($rel, '/');
    $v   = @filemtime(dirname(__DIR__) . $rel);
    return $rel . ($v ? '?v=' . $v : '');
}

/**
 * The <link> tags for the theme's fonts only: its self-hosted stylesheet, or
 * Google's CDN when it has a font_url, or nothing. Standalone, so the error
 * pages can use it without config.php.
 */
function theme_font_links(array $theme): string {
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    if (!empty($theme['font_css'])) {
        // Self-hosted: no request leaves this domain, so no visitor IP reaches a
        // font CDN before the cookie banner has been answered.
        return '  <link rel="stylesheet" href="' . $e(theme_asset_url((string) $theme['font_css'])) . "\">\n";
    }
    if (!empty($theme['font_url'])) {
        return "  <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
             . "  <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
             . '  <link href="https://fonts.googleapis.com/css2?family=' . $e((string) $theme['font_url']) . "&display=swap\" rel=\"stylesheet\">\n";
    }
    return '';
}

/**
 * The <link> tags for the theme's fonts, main.css and the theme's own stylesheet,
 * in that order. Local files carry a version stamp, so a release's new main.css
 * is not hidden behind a stale cached copy.
 */
function theme_stylesheet_links(array $theme): string {
    $link = static fn(string $rel): string => '  <link rel="stylesheet" href="'
        . htmlspecialchars(theme_asset_url($rel), ENT_QUOTES, 'UTF-8') . "\">\n";
    $out  = theme_font_links($theme) . $link('assets/css/main.css');
    if (!empty($theme['theme_css'])) $out .= $link((string) $theme['theme_css']);
    return $out;
}

/** A font family name safe to put in CSS ('body' or 'display' face), or '' when it is not one. */
function theme_font_family(array $theme, string $role = 'body'): string {
    $name = (string) ($theme['font_' . $role] ?? $theme['font'] ?? '');
    return preg_match('/^[A-Za-z0-9 _-]{1,60}$/', $name) ? $name : '';
}

// ── Colour contrast (newsletter band) ────────────────────────────────────────

/** The two text colours a coloured band can carry: white, or the site's dark body text. */
const THEME_TEXT_LIGHT = '#FFFFFF';
const THEME_TEXT_DARK  = '#1A1916';

/** The band colour the shared footer falls back to when nothing else sets it. */
const THEME_NEWSLETTER_FALLBACK = '#0387A5';

/** WCAG 2 contrast ratio between two #RRGGBB colours (1.0 – 21.0). */
function theme_contrast(string $a, string $b): float {
    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v   = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$l1, $l2] = [$lum($a), $lum($b)];
    if ($l1 < $l2) [$l1, $l2] = [$l2, $l1];
    return ($l1 + 0.05) / ($l2 + 0.05);
}

/**
 * Text colour for a band of colour $bg: white or dark, whichever reads better.
 * 'readable' is true when it meets WCAG AA for body text (4.5:1); when neither
 * does, the better one is still returned and the admin is told.
 * @return array{fg: string, ratio: float, readable: bool}
 */
function theme_text_on(string $bg): array {
    $light = theme_contrast($bg, THEME_TEXT_LIGHT);
    $dark  = theme_contrast($bg, THEME_TEXT_DARK);
    $ratio = max($light, $dark);
    return ['fg' => $light >= $dark ? THEME_TEXT_LIGHT : THEME_TEXT_DARK, 'ratio' => round($ratio, 2), 'readable' => $ratio >= 4.5];
}

/** The newsletter band choices an admin has (Admin → Организация → Визия). */
function newsletter_band_choices(): array {
    return [
        'default' => 'Както е сега',
        'primary' => 'Основен цвят',
        'accent'  => 'Допълнителен цвят',
        'custom'  => 'Друг цвят',
    ];
}

/**
 * The admin's newsletter band colours, or null when the admin has not chosen
 * ('default'): then the theme's palette (--newsletter-bg / --newsletter-fg)
 * applies, and without one the footer's own fallback. The admin's choice wins.
 * @return ?array{bg: string, fg: string, ratio: float, readable: bool}
 */
function newsletter_band_colors(string $choice, string $custom, string $primary, string $accent): ?array {
    $bg = match ($choice) {
        'primary' => $primary,
        'accent'  => $accent,
        'custom'  => $custom,
        default   => '',
    };
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) return null;
    $bg = strtoupper($bg);
    return ['bg' => $bg] + theme_text_on($bg);
}

/**
 * The theme's palette as CSS declarations for the :root block in the <head>.
 * Emitted inside a <style> element, so only a property-shaped name and a
 * CSS-value-shaped value pass — no quotes, braces, semicolons or angle brackets.
 *
 * $band is the admin's newsletter band choice (newsletter_band_colors()). It wins
 * over the palette's --newsletter-bg / --newsletter-fg; without it the palette's
 * apply, and without those the footer's own fallback colours.
 */
function theme_palette_css(array $theme, ?array $band = null): string {
    $palette = (array) ($theme['palette'] ?? []);
    if ($band !== null) {
        $palette['--newsletter-bg'] = $band['bg'];
        $palette['--newsletter-fg'] = $band['fg'];
    }
    $out = '';
    foreach ($palette as $var => $val) {
        $val = is_scalar($val) ? trim((string) $val) : '';
        if (!is_string($var) || !preg_match('/^--[a-z0-9-]+$/i', $var)
            || $val === '' || !preg_match('/^[#a-zA-Z0-9 ,.()%\/_-]+$/', $val)) continue;
        $out .= '    ' . $var . ':' . $val . ";\n";
    }
    return $out;
}

/**
 * The band as it looks with no admin choice: the theme palette's colours, else
 * the footer's fallback teal and white.
 * @return array{bg: string, fg: string, ratio: float, readable: bool}
 */
function theme_newsletter_default(array $theme): array {
    $hex = static fn($c): bool => is_string($c) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $c);
    $p   = (array) ($theme['palette'] ?? []);
    $bg  = $hex($p['--newsletter-bg'] ?? null) ? strtoupper($p['--newsletter-bg']) : THEME_NEWSLETTER_FALLBACK;
    $fg  = $hex($p['--newsletter-fg'] ?? null) ? strtoupper($p['--newsletter-fg']) : THEME_TEXT_LIGHT;
    $ratio = theme_contrast($bg, $fg);
    return ['bg' => $bg, 'fg' => $fg, 'ratio' => round($ratio, 2), 'readable' => $ratio >= 4.5];
}

/** The admin's newsletter band for this site (BRAND_NEWSLETTER_* from Admin → Организация), or null. */
function site_newsletter_band(string $primary, string $accent): ?array {
    return newsletter_band_colors(
        defined('BRAND_NEWSLETTER_BAND')  ? (string) BRAND_NEWSLETTER_BAND  : 'default',
        defined('BRAND_NEWSLETTER_COLOR') ? (string) BRAND_NEWSLETTER_COLOR : '',
        $primary, $accent
    );
}
