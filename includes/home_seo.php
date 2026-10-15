<?php
/**
 * The front page's title for search engines, BG and EN (Админ → Начална
 * страница → „Как изглежда в Google“). Kept in the settings table. Empty means
 * the built-in behaviour: the site's name. The description under it in Google
 * is the site's short description from Админ → Организация (org_site_description()).
 */
require_once __DIR__ . '/settings.php';

const HOME_SEO_TITLE_MAX = 120;
const HOME_SEO_FIELDS    = ['home_seo_title_bg', 'home_seo_title_en'];

/** The saved values, keyed by HOME_SEO_FIELDS ('' when not set). */
function home_seo_values(): array
{
    $out = [];
    foreach (HOME_SEO_FIELDS as $k) $out[$k] = setting_get($k);
    return $out;
}

/**
 * Posted values cleaned (plain text on one line), plus what to fix:
 * ['values' => [field => text], 'errors' => [field => message]].
 */
function home_seo_clean(array $post): array
{
    $values = $errors = [];
    foreach (HOME_SEO_FIELDS as $k) {
        $v = is_string($post[$k] ?? null) ? $post[$k] : '';
        $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags($v)));
        $values[$k] = $v;
        if (mb_strlen($v) > HOME_SEO_TITLE_MAX) {
            $errors[$k] = 'Заглавието' . (str_ends_with($k, '_en') ? ' на английски' : ' на български')
                . ' е твърде дълго — до ' . HOME_SEO_TITLE_MAX . ' знака.';
        }
    }
    return ['values' => $values, 'errors' => $errors];
}

/** Save cleaned values; an empty one is removed (back to the built-in behaviour). */
function home_seo_save(array $values): void
{
    foreach (HOME_SEO_FIELDS as $k) {
        $v = (string) ($values[$k] ?? '');
        $v === '' ? setting_delete($k) : setting_set($k, $v);
    }
}

/** The front page's title in $lang: the saved one, else $site_name. */
function home_seo_title(string $lang, string $site_name, ?array $values = null): string
{
    $values ??= home_seo_values();
    $title = trim((string) ($values['home_seo_title_' . ($lang === 'en' ? 'en' : 'bg')] ?? ''));
    return $title !== '' ? $title : $site_name;
}
