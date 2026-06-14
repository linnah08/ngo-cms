<?php
/**
 * Centralized SEO: canonical, hreflang alternates, Open Graph, Twitter Card,
 * and JSON-LD structured data. Included from templates/header.php so every
 * public page gets the core tags automatically; pages may set $og_image,
 * $seo_type, $seo_alternates, $seo_jsonld, $page_title_full to enrich.
 */

/** Static BG → EN page-path equivalents (with leading/trailing slash). */
function seo_static_alt_map(): array {
    return [
        '/'                    => '/en/',
        '/za-nas/'             => '/en/about/',
        '/proekti/'            => '/en/projects/',
        '/novini/'             => '/en/news/',
        '/kontakti/'           => '/en/contacts/',
        '/kak-da-pomogna/'     => '/en/how-to-help/',
        '/magazin/'            => '/en/shop/',
        '/usloviya/'           => '/en/terms/',
        '/pravna-informaciya/' => '/en/legal/',
    ];
}

/** Absolutise an image path against SITE_URL. */
function seo_abs_url(string $path): string {
    if ($path === '') return '';
    if (preg_match('#^https?://#', $path)) return $path;
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Resolve BG/EN alternates for $path. Pages with per-item slugs (articles)
 * pass $explicit = ['bg' => '/novini/..', 'en' => '/en/news/..']; pass null
 * for a side that has no counterpart.
 * Returns ['bg' => absUrl, 'en' => absUrl] (only sides that exist).
 */
function seo_resolve_alternates(string $path, array $explicit = []): array {
    $base = rtrim(SITE_URL, '/');

    if ($explicit) {
        $out = [];
        foreach (['bg', 'en'] as $lg) {
            if (!empty($explicit[$lg])) $out[$lg] = $base . $explicit[$lg];
        }
        return $out;
    }

    $map = seo_static_alt_map();
    if (isset($map[$path]))               return ['bg' => $base . $path, 'en' => $base . $map[$path]];
    $flip = array_flip($map);
    if (isset($flip[$path]))              return ['bg' => $base . $flip[$path], 'en' => $base . $path];

    // Products share a slug across languages.
    if (preg_match('#^/magazin/([^/]+)/?$#', $path, $m))  return ['bg' => $base . '/magazin/' . $m[1] . '/', 'en' => $base . '/en/shop/' . $m[1] . '/'];
    if (preg_match('#^/en/shop/([^/]+)/?$#', $path, $m))  return ['bg' => $base . '/magazin/' . $m[1] . '/', 'en' => $base . '/en/shop/' . $m[1] . '/'];

    return [];
}

/** Emit canonical, hreflang, Open Graph and Twitter tags. */
function seo_render_meta(array $ctx): void {
    $h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $url   = $ctx['url'];
    $title = $ctx['title'];
    $desc  = $ctx['description'] ?? '';
    $type  = $ctx['type'] ?? 'website';
    $lang  = ($ctx['lang'] ?? 'bg') === 'en' ? 'en' : 'bg';
    $img   = seo_abs_url($ctx['image'] ?? '/assets/images/og-default.png');
    $alts  = $ctx['alternates'] ?? [];

    echo '  <link rel="canonical" href="' . $h($url) . "\">\n";
    foreach ($alts as $lg => $u) {
        echo '  <link rel="alternate" hreflang="' . $h($lg) . '" href="' . $h($u) . "\">\n";
    }
    if (isset($alts['bg'])) {
        echo '  <link rel="alternate" hreflang="x-default" href="' . $h($alts['bg']) . "\">\n";
    }

    $locale     = $lang === 'bg' ? 'bg_BG' : 'en_US';
    $locale_alt = $lang === 'bg' ? 'en_US' : 'bg_BG';
    echo '  <meta property="og:type" content="' . $h($type) . "\">\n";
    echo '  <meta property="og:site_name" content="' . $h(SITE_NAME_BG . ' / ' . SITE_NAME_EN) . "\">\n";
    echo '  <meta property="og:title" content="' . $h($title) . "\">\n";
    echo '  <meta property="og:description" content="' . $h($desc) . "\">\n";
    echo '  <meta property="og:url" content="' . $h($url) . "\">\n";
    echo '  <meta property="og:image" content="' . $h($img) . "\">\n";
    echo '  <meta property="og:locale" content="' . $locale . "\">\n";
    echo '  <meta property="og:locale:alternate" content="' . $locale_alt . "\">\n";

    echo '  <meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '  <meta name="twitter:title" content="' . $h($title) . "\">\n";
    echo '  <meta name="twitter:description" content="' . $h($desc) . "\">\n";
    echo '  <meta name="twitter:image" content="' . $h($img) . "\">\n";
}

/** Organization/NGO JSON-LD — emitted on every page. */
function seo_org_jsonld(): array {
    return [
        '@context'      => 'https://schema.org',
        '@type'         => 'NGO',
        'name'          => SITE_NAME_EN,
        'alternateName' => SITE_NAME_BG,
        'url'           => rtrim(SITE_URL, '/') . '/',
        'logo'          => seo_abs_url('/assets/images/logo.png'),
        'email'         => SITE_EMAIL,
        'telephone'     => SITE_PHONE,
        'sameAs'        => [SOCIAL_FACEBOOK, SOCIAL_INSTAGRAM, SOCIAL_LINKEDIN],
    ];
}

/** Render an array of JSON-LD blocks (skips falsy entries). */
function seo_render_jsonld(array $blocks): void {
    foreach ($blocks as $b) {
        if (!$b) continue;
        echo '  <script type="application/ld+json">'
           . json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
           . "</script>\n";
    }
}
