<?php
declare(strict_types=1);

/**
 * Router for TestServer: answers like the site's .htaccess does on Apache.
 *
 * Without it, `php -S` answers an address it doesn't know with the home page and
 * a 200, so a smoke test of a page that doesn't exist passes. Here, as in
 * production: real files and folders are served, the four slug rewrites go to
 * their list page, and anything else gets errors/404.php with a 404.
 */
$root = dirname(__DIR__, 2);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if (str_contains($path, '..')) {
    http_response_code(400);
    return true;
}

$file = $root . $path;
if (is_file($file)) return false;
if (is_dir($file) && (is_file(rtrim($file, '/') . '/index.php') || is_file(rtrim($file, '/') . '/index.html'))) return false;

// .htaccess: "Route article slugs / product slugs to … index.php"
$rewrites = [
    '#^/novini/[^/]+/?$#'  => '/novini/index.php',
    '#^/en/news/[^/]+/?$#' => '/en/news/index.php',
    '#^/magazin/[^/]+/?$#' => '/magazin/index.php',
    '#^/en/shop/[^/]+/?$#' => '/magazin/index.php',
];
$target = '/errors/404.php';
foreach ($rewrites as $pattern => $script) {
    if (preg_match($pattern, $path)) { $target = $script; break; }
}
if ($target === '/errors/404.php') http_response_code(404);

$_SERVER['SCRIPT_NAME']     = $target;
$_SERVER['PHP_SELF']        = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
