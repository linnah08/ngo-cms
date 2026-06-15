<?php
/**
 * Visual theme presets. Each is a bundle of design-system variables (colours,
 * font, corner radius) that the CSS already consumes (--teal, --font-body,
 * --radius, …). The install wizard lets the NGO pick one; the colour pickers
 * can still override the preset's default colours.
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
    ];
}

/** The active theme (BRAND_THEME from site.config), falling back to 'classic'. */
function current_theme(): array {
    $themes = brand_themes();
    $key = (defined('BRAND_THEME') && isset($themes[BRAND_THEME])) ? BRAND_THEME : 'classic';
    return $themes[$key];
}
