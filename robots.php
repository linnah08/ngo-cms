<?php
/**
 * robots.txt — served at /robots.txt (rewritten in .htaccess). Dynamic because
 * search engines need the sitemap's full address (a relative "Sitemap:" line is
 * ignored), and only SITE_URL knows the domain. Crawling stays allowed while the
 * site is hidden (SITE_NOINDEX): a crawler has to read a page to see its
 * "noindex" tag, and Search Console has to read the home page to verify it.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "User-agent: *\n";
foreach (['/admin/', '/cart/', '/checkout/', '/api/', '/content/'] as $path) {
    echo "Disallow: {$path}\n";
}
echo "\nSitemap: " . rtrim(SITE_URL, '/') . "/sitemap.xml\n";
