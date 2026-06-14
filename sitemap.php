<?php
/**
 * Dynamic XML sitemap — served at /sitemap.xml (rewritten in .htaccess).
 * Lists static pages plus active products and published articles (BG + EN),
 * so it stays current without a build step.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/includes/db.php';

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(SITE_URL, '/');
$urls = []; // [loc, lastmod|null]

// ── Static pages (BG + EN) ───────────────────────────────────────────────
$static = [
    '/', '/za-nas/', '/proekti/', '/novini/', '/kontakti/', '/kak-da-pomogna/',
    '/magazin/', '/usloviya/', '/pravna-informaciya/',
    '/politika-za-poveritelnost/', '/politika-za-biskvitki/',
    '/en/', '/en/about/', '/en/projects/', '/en/news/', '/en/contacts/',
    '/en/how-to-help/', '/en/shop/', '/en/terms/', '/en/legal/',
];
foreach ($static as $p) {
    $urls[] = [$base . $p, null];
}

// ── Active products → /magazin/SLUG/ and /en/shop/SLUG/ ──────────────────
try {
    $pdo   = get_pdo();
    $slugs = $pdo->query('SELECT slug FROM products WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($slugs as $slug) {
        $enc = rawurlencode($slug);
        $urls[] = [$base . '/magazin/' . $enc . '/',  null];
        $urls[] = [$base . '/en/shop/' . $enc . '/',  null];
    }
} catch (Throwable $e) {
    error_log('sitemap products error: ' . $e->getMessage());
}

// ── Published articles → /novini/SLUG/ (bg) and /en/news/SLUG/ (en) ───────
foreach (['bg' => '/novini/', 'en' => '/en/news/'] as $lang => $prefix) {
    foreach (get_articles($lang) as $article) {
        $enc     = rawurlencode($article['slug']);
        $lastmod = !empty($article['date']) ? date('Y-m-d', strtotime($article['date'])) : null;
        $urls[]  = [$base . $prefix . $enc . '/', $lastmod];
    }
}

// ── Render ───────────────────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $lastmod]) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($lastmod) {
        echo "    <lastmod>$lastmod</lastmod>\n";
    }
    echo "  </url>\n";
}
echo "</urlset>\n";
